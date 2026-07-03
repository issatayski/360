# CLAUDE.md — Tour Pipeline

## 1. Что это за проект

Автоматический конвейер, превращающий загруженные 360° equirectangular-панорамы
в самохостящийся веб-тур на **Marzipano** — без ручной сборки в GUI.

Ключевая идея: тур Marzipano — это генерируемый артефакт (папка `index.html` +
`index.js` + `marzipano.js` + `data.js` + `tiles/`). Всё, кроме картинок, —
текст, который мы генерируем программно. Никакого GUI-редактора.

Вход усилен **приложением съёмки** (отдельный репозиторий), которое отдаёт
`manifest.json`: порядок сцен, граф связей, компас-ориентацию каждой точки и
отметку качества. Пайплайн читает пространство из manifest, а не реконструирует.

Путь A (этот проект) = связанные панорамы в Marzipano.
Путь B (Gaussian Splatting, walkable-3D) = ОТДЕЛЬНАЯ ветка, здесь НЕ трогаем.

## 2. Поток данных

```
input/ (панорамы + manifest.json)
  → triage    валидация: резкость, экспозиция, 2:1, покрытие → статус годно/улучшить/брак
  → enhance   (опц.) апскейл, нормализация экспозиции для помеченных
  → tiler     equirect → грани куба → multires-тайлы (или single-equirect в MVP)
  → generator сборка data.js (сцены, initialView, linkHotspots, infoHotspots)
  → assemble  копия templates/marzipano-base + tiles/ + data.js
  → package   output/<tour-id>/ (готовый статический сайт) + отчёт клиенту
```

Модули общаются через файловую систему и JSON. Каждая стадия — отдельная CLI-команда,
запускается изолированно и идемпотентно.

## 3. Стек

- **Node.js** — сборочный слой: `tiler`, `generator`, `assemble`, `orchestrate`.
  Библиотеки: `sharp` (обработка/нарезка изображений), `archiver` (zip-выдача).
  Marzipano-библиотека берётся из экспорта Marzipano Tool (`marzipano.js`).
- **Python** — тяжёлая обработка изображений: `triage` (OpenCV), `enhance`
  (Real-ESRGAN — опц., Phase 2+), стичинг плоских фото (Hugin CLI — отдельный путь).
  Библиотеки: `opencv-python`, `pillow`, `numpy`, `py360convert`.

Правило разделения: всё, что про пиксели и компьютерное зрение — Python;
всё, что про структуру тура и файлы — Node.

## 4. Готовые кирпичи (адаптировать, не писать с нуля)

- `jessehhydee/marzipano-pano-tiler` — Node-тайлер equirect → cube-map тайлы.
  Основа для модуля `tiler`. Проверить соблюдение ограничений размеров (см. §7).
- `codetricity/carlsbad-tour` — референс формата данных сцены (filename/yaw/pitch/
  hotspots/switchTo). Скелет для `generator`.
- Marzipano Tool (marzipano.net) — прогнать 2 панорамы, экспорт сохранить в
  `templates/marzipano-base/` как эталон формата `data.js` / `index.js`.
- Hugin (`nona`/`enblend`, CLI) — стичинг, если на входе плоские фото. Системный
  пакет, НЕ pip.

## 5. Структура репозитория

```
tour-pipeline/
├── CLAUDE.md
├── input/                    # панорамы клиента + manifest.json
├── work/                     # промежуточные артефакты (не коммитить)
├── templates/marzipano-base/ # эталонный экспорт Marzipano Tool
├── src/
│   ├── triage/    (Python)   validate.py
│   ├── enhance/   (Python)   enhance.py
│   ├── tiler/     (Node)     tile.js
│   ├── generator/ (Node)     build-data.js
│   ├── assemble/  (Node)     assemble.js
│   └── orchestrate.js        # весь пайплайн end-to-end
├── output/                   # готовые туры (не коммитить)
├── package.json
└── requirements.txt
```

## 6. Формат manifest.json (контракт с приложением съёмки)

```json
{
  "tour": { "id": "villa-01", "name": "Вилла на Абая" },
  "floorplan": { "image": "plan.png" },
  "scenes": [
    {
      "id": "01-hall",
      "file": "01-hall.jpg",
      "order": 1,
      "heading": 137.5,                  // компас, градусы (0=север), может отсутствовать
      "floorplan": { "x": 0.42, "y": 0.65 }, // нормализованные [0..1] координаты на плане
      "quality": { "status": "ok" },     // ok | enhance | reject, заполняет triage
      "links": ["02-kitchen"]            // граф переходов (из тапов по плану)
    }
  ],
  "settings": { "autorotate": false }
}
```

Поля `heading`, `floorplan`, `links` могут отсутствовать (MVP без приложения) —
тогда порядок берётся из `order`/имён файлов, навигация строится списком-меню.

## 7. Формат вывода Marzipano — инварианты

- `data.js`: объект `APP_DATA` с массивом `scenes`. Каждая сцена: `id`, `name`,
  `levels`, `faceSize`, `initialViewParameters {yaw, pitch, fov}`,
  `linkHotspots[]`, `infoHotspots[]`.
- Тайлы: `tiles/<sceneId>/{z}/{f}/{y}/{x}.jpg` + `tiles/<sceneId>/preview.jpg`.
- **Углы yaw/pitch/fov — В РАДИАНАХ.** Полный круг = 2π. Это частый источник багов.
- Ограничения тайлинга (иначе чёрные/битые грани): размер уровня кратен размеру
  тайла (512); кратен размеру родительского уровня; тайлы квадратные; число тайлов
  в уровне кратно числу в родителе. `sharp` ресайзит грани до кратного 512.
- MVP-упрощение: `type: 'equirect'` с одной картинкой через
  `ImageUrlSource.fromString(url)` — БЕЗ папки `tiles/`. Тайлинг включаем в Phase 2.

## 8. Инварианты бесшовности (критично)

Три разных шва, лечатся по-разному:

1. **Направленный шов (переход между сценами).** При входе в сцену B взгляд должен
   продолжать движение. Вычисляется из manifest:
   - Направление стрелки A→B (yaw хотспота в A) = азимут по координатам floorplan
     от A к B, минус `heading` сцены A.
   - `initialViewParameters.yaw` сцены B = продолжение того же мирового направления,
     скорректированное на `heading` B.
   - Нет heading/floorplan → yaw=0 и навигация меню-списком (шов виден, но приемлемо
     для MVP).

2. **Загрузочный шов.** Порядок загрузки: (1) `preview.jpg` мгновенно — сцена
   никогда не чёрная; (2) тайлы в поле зрения; (3) ПРЕФЕТЧ связанных сцен в фоне,
   пока пользователь осматривается → клик по стрелке = мгновенный переход.

3. **Физический шов панорамы.** Тайлер обязан считать гномоническую проекцию граней
   куба с перекрытием на границах, иначе швы на стыках. Надир/зенит (штатив, дырка
   вверху) — патчатся в `enhance`.

## 9. Фазы (что считается «готово»)

- **Phase 0** — `generator` собирает валидный `data.js` из списка панорам (single-
  equirect, пустые хотспоты). Готово: тур открывается в браузере.
- **Phase 1 (MVP)** — полный пайплайн на single-equirect: triage → generator →
  навигация меню-списком. Готово: из папки панорам получается кликабельный тур
  автоматически.
- **Phase 2** — `tiler` (multires) + `enhance`. Готово: большие панорамы грузятся
  плавно, тайлы без швов.
- **Phase 3** — связи из manifest: стрелки-переходы + направленный yaw + префетч.
  Готово: бесшовные переходы по направлению движения.
- **Phase 4** — `package` + отчёт клиенту (прошло/брак/переснять) + zip-выдача.

## 10. Команды пайплайна

```bash
# отдельные стадии
python src/triage/validate.py  --in input/  --manifest input/manifest.json
python src/enhance/enhance.py  --in input/  --out work/enhanced/
node   src/tiler/tile.js       --in work/enhanced/ --out work/tiles/
node   src/generator/build-data.js --manifest input/manifest.json --tiles work/tiles/ --out work/data.js
node   src/assemble/assemble.js --template templates/marzipano-base --data work/data.js --tiles work/tiles/ --out output/villa-01/

# весь конвейер
node src/orchestrate.js --tour villa-01

# локальный просмотр
cd output/villa-01 && python3 -m http.server 8000
```

## 11. Границы — чего НЕ делать

- **НЕ управлять камерой.** Приложение съёмки логирует контекст (тап по плану,
  heading, порядок, качество), а не снимает кадр. Кадры связываются с метаданными
  по таймстампу. Реальный захват через SDK камеры — не наша зона.
- **НЕ реконструировать пространство через SfM/COLMAP**, пока manifest даёт связи и
  ориентацию. SfM — только опциональный фолбэк, если manifest отсутствует. GPU здесь
  не нужен.
- **НЕ тянуть Gaussian Splatting** в этот репозиторий — Marzipano его не рендерит,
  это отдельная ветка/вьюер.
- **Минимум внешних зависимостей.** Никаких тяжёлых фреймворков ради фич. Вывод —
  статический сайт на чистом JS/HTML/CSS (как задумано в Marzipano boilerplate).
- Всегда держать в `input/` 3–4 тестовые панорамы разного качества и гонять пайплайн
  на них после каждого изменения.
