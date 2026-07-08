<?php
// hotspots.php?tour=ID&scene=SCENE — визуальный редактор переходов между комнатами.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/actions.php';

$user = require_login();
$tour_id = (int)($_GET['tour'] ?? 0);
$tour = own_tour($tour_id, (int)$user['id']);
if (!$tour) redirect('dashboard.php');

$scenes = tour_scenes($tour_id);
if (count($scenes) < 2) redirect('tour_edit.php?id=' . $tour_id);

// Текущая исходная сцена (по умолчанию первая).
$from_id = (int)($_GET['scene'] ?? 0);
$from = null;
foreach ($scenes as $s) if ((int)$s['id'] === $from_id) { $from = $s; break; }
if (!$from) { $from = $scenes[0]; $from_id = (int)$from['id']; }

$out = scene_out_hotspots($from_id);
$token = csrf_token();
$RAD2DEG = 180.0 / M_PI;

// Данные для JS
$jsHot = [];
foreach ($out as $h) {
    $jsHot[] = [
        'id'    => (int)$h['id'],
        'yaw'   => round((float)$h['yaw'] * $RAD2DEG, 2),
        'pitch' => round((float)$h['pitch'] * $RAD2DEG, 2),
        'title' => $h['to_title'] !== '' ? $h['to_title'] : ('Сцена #' . $h['to_scene_id']),
    ];
}
$sceneTitleById = [];
foreach ($scenes as $i => $s) $sceneTitleById[(int)$s['id']] = $s['title'] !== '' ? $s['title'] : ('Сцена ' . ($i + 1));
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Переходы — <?= h($tour['name']) ?></title>
<link rel="stylesheet" href="assets/pannellum.css">
<link rel="stylesheet" href="assets/app.css">
<style>
  #pano{position:relative;width:100%;height:60vh;min-height:320px;background:#000;border-radius:12px;overflow:hidden;}
  .hs-arrow{height:40px;width:40px;margin:-20px 0 0 -20px;background:#0a84ff;border:3px solid #fff;
    border-radius:50%;box-shadow:0 2px 10px rgba(0,0,0,.55);pointer-events:auto;}
  .hs-arrow::after{content:'➜';position:absolute;inset:0;display:flex;align-items:center;
    justify-content:center;color:#fff;font-size:20px;font-weight:900;}
  .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:12px 0;}
  .hint{font-size:13px;color:var(--muted);}
</style>
</head>
<body>
<div class="topbar">
  <span class="brand"><a href="tour_edit.php?id=<?= (int)$tour_id ?>">← <?= h($tour['name']) ?></a></span>
  <a class="btn ghost small" href="logout.php">Выйти</a>
</div>

<div class="wrap">
  <div class="card">
    <h2 style="margin-top:0">🔗 Переходы между комнатами</h2>
    <p class="muted small">Выбери комнату, наведи центр (или тапни точку) на дверь/проём, укажи, куда ведёт, — стрелка встанет. Обратная стрелка создаётся автоматически.</p>

    <div class="toolbar">
      <label class="small muted">Ставим переходы из:
        <select id="fromSel" style="margin-top:0;min-height:40px">
          <?php foreach ($scenes as $i => $s): $sid = (int)$s['id']; ?>
            <option value="<?= $sid ?>" <?= $sid === $from_id ? 'selected' : '' ?>>
              <?= h($s['title'] !== '' ? $s['title'] : 'Сцена ' . ($i + 1)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div id="pano"></div>

    <div id="moveBanner" style="display:none;background:#0d2a4a;border:1px solid #1e4d80;color:#cfe4ff;
      margin-top:12px;padding:10px 14px;border-radius:10px;font-size:14px">
      Переставляю стрелку «<b id="moveTitle"></b>»: наведи центр на нужное место и нажми «Поставить по центру» или тапни точку.
      <button class="btn small ghost" id="moveCancel" style="margin-left:8px">Отмена</button>
    </div>

    <div class="toolbar">
      <label class="small muted">Куда ведёт стрелка:
        <select id="toSel" style="margin-top:0;min-height:40px">
          <option value="">— выбери комнату —</option>
          <?php foreach ($scenes as $i => $s): $sid = (int)$s['id']; if ($sid === $from_id) continue; ?>
            <option value="<?= $sid ?>"><?= h($s['title'] !== '' ? $s['title'] : 'Сцена ' . ($i + 1)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn small primary" id="placeCenter">Поставить по центру взгляда</button>
      <span class="hint">или тапни точку на панораме</span>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Переходы из этой комнаты (<?= count($out) ?>)</h3>
    <?php if (!$out): ?>
      <p class="muted">Пока нет. Выбери «куда ведёт» и поставь стрелку.</p>
    <?php else: ?>
      <ul class="tour-list" id="hsList">
        <?php foreach ($out as $h): ?>
          <li class="tour-item" data-id="<?= (int)$h['id'] ?>">
            <span>→ <?= h($h['to_title'] !== '' ? $h['to_title'] : ('Сцена #' . $h['to_scene_id'])) ?>
              <span class="muted small">(yaw <?= round((float)$h['yaw'] * $RAD2DEG) ?>°)</span></span>
            <span class="row" style="gap:6px">
              <button class="btn small ghost hs-move" data-id="<?= (int)$h['id'] ?>"
                data-title="<?= h($h['to_title'] !== '' ? $h['to_title'] : ('Сцена #' . $h['to_scene_id'])) ?>">Переставить</button>
              <button class="btn small ghost hs-del" data-id="<?= (int)$h['id'] ?>">Удалить</button>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<script src="assets/pannellum.js"></script>
<script>
(function () {
  var TOUR_ID = <?= (int)$tour_id ?>;
  var FROM_ID = <?= (int)$from_id ?>;
  var CSRF = <?= json_encode($token) ?>;
  var PANO = <?= json_encode($from['image_path'], JSON_UNESCAPED_SLASHES) ?>;
  var HOTS = <?= json_encode($jsHot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

  // Смена исходной сцены — перезагрузка с ?scene=
  document.getElementById('fromSel').addEventListener('change', function () {
    location.href = 'hotspots.php?tour=' + TOUR_ID + '&scene=' + this.value;
  });

  var viewer = pannellum.viewer('pano', {
    type: 'equirectangular', panorama: PANO, autoLoad: true, showControls: true,
    hotSpots: HOTS.map(function (h) {
      return { pitch: h.pitch, yaw: h.yaw, cssClass: 'hs-arrow', text: '→ ' + h.title };
    })
  });

  function addHotspot(yawDeg, pitchDeg) {
    var to = document.getElementById('toSel').value;
    if (!to) { alert('Сначала выбери, куда ведёт стрелка.'); return; }
    var DEG2RAD = Math.PI / 180;
    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('tour_id', String(TOUR_ID));
    fd.append('from_scene_id', String(FROM_ID));
    fd.append('to_scene_id', String(to));
    fd.append('yaw', String(yawDeg * DEG2RAD));
    fd.append('pitch', String(pitchDeg * DEG2RAD));
    fetch('api/add_hotspot.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Ошибка');
        location.reload();
      })
      .catch(function (e) { alert('Не удалось: ' + e.message); });
  }

  // Режим перестановки существующей стрелки
  var repositionId = null;
  function enterReposition(id, title) {
    repositionId = id;
    document.getElementById('moveTitle').textContent = title;
    document.getElementById('moveBanner').style.display = 'block';
    document.getElementById('pano').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function exitReposition() {
    repositionId = null;
    document.getElementById('moveBanner').style.display = 'none';
  }
  document.getElementById('moveCancel').addEventListener('click', exitReposition);

  // Единая точка постановки: если идёт перестановка — двигаем, иначе создаём новую
  function placeAt(yawDeg, pitchDeg) {
    if (repositionId) {
      var DEG2RAD = Math.PI / 180;
      var fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('hotspot_id', String(repositionId));
      fd.append('yaw', String(yawDeg * DEG2RAD));
      fd.append('pitch', String(pitchDeg * DEG2RAD));
      fetch('api/move_hotspot.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(j.error || 'Ошибка'); location.reload(); })
        .catch(function (e) { alert('Не удалось: ' + e.message); });
      return;
    }
    addHotspot(yawDeg, pitchDeg);
  }

  document.getElementById('placeCenter').addEventListener('click', function () {
    placeAt(viewer.getYaw(), viewer.getPitch());
  });

  // Тап по панораме (не драг)
  var down = null;
  var el = document.getElementById('pano');
  el.addEventListener('pointerdown', function (e) { down = { x: e.clientX, y: e.clientY, t: Date.now() }; });
  el.addEventListener('pointerup', function (e) {
    if (!down) return;
    var moved = Math.hypot(e.clientX - down.x, e.clientY - down.y);
    var dt = Date.now() - down.t;
    down = null;
    if (moved < 10 && dt < 500 && (repositionId || document.getElementById('toSel').value)) {
      var c = viewer.mouseEventToCoords(e); // [pitch, yaw] в градусах
      placeAt(c[1], c[0]);
    }
  });

  // Кнопки «Переставить» в списке
  Array.prototype.forEach.call(document.querySelectorAll('.hs-move'), function (btn) {
    btn.addEventListener('click', function () {
      enterReposition(this.dataset.id, this.dataset.title || 'переход');
    });
  });

  // Удаление перехода
  Array.prototype.forEach.call(document.querySelectorAll('.hs-del'), function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Удалить переход?')) return;
      var fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('hotspot_id', this.dataset.id);
      fetch('api/delete_hotspot.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error(j.error || 'Ошибка'); location.reload(); })
        .catch(function (e) { alert('Не удалось: ' + e.message); });
    });
  });
})();
</script>
</body>
</html>
