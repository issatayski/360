'use strict';
/*
 * build-data.js — генерирует Marzipano `data.js` (объект APP_DATA) из manifest.json.
 *
 * Phase 0/1: single-equirect (одна картинка на сцену, без тайлов), пустые хотспоты,
 * навигация меню-списком. Углы — В РАДИАНАХ (см. CLAUDE.md §7).
 *
 *   node src/generator/build-data.js \
 *     --manifest input/manifest.json \
 *     --images   input/ \
 *     --out      work/data.js
 *
 * Пути картинок в data.js указываются как `img/<file>` — assemble кладёт панорамы
 * в <output>/img/.
 */
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) args[a.slice(2)] = argv[++i];
  }
  return args;
}

const DEG = Math.PI / 180;
const DEFAULT_FOV = 90 * DEG; // радианы
const LINK_PITCH = 0.12;      // стрелки чуть ниже горизонта (радианы)

// Нормализовать угол в (-π, π].
function normRad(a) {
  a = a % (2 * Math.PI);
  if (a > Math.PI) a -= 2 * Math.PI;
  if (a <= -Math.PI) a += 2 * Math.PI;
  return a;
}

// Компас-азимут A→B по координатам плана (0=север=вверх, по часовой), градусы.
// План: x вправо, y вниз → север = -y, восток = +x.
function azimuthDeg(a, b) {
  return Math.atan2(b.x - a.x, -(b.y - a.y)) * 180 / Math.PI;
}

// Есть ли у сцены геометрия для направленного yaw (§8.1).
function hasGeo(s) {
  return s && s.floorplan && typeof s.floorplan.x === 'number'
    && typeof s.floorplan.y === 'number' && typeof s.heading === 'number';
}

// Мировой азимут A→B → yaw в панораме сцены `viewer` (учёт её heading), радианы.
function worldToYaw(az, heading) {
  return normRad((az - heading) * DEG);
}

async function main() {
  const args = parseArgs(process.argv);
  const manifestPath = args.manifest || 'input/manifest.json';
  const imagesDir = args.images || path.dirname(manifestPath);
  const tilesDir = args.tiles || null; // Phase 2: если задан и есть tiles.json — cube-режим
  const outPath = args.out || 'work/data.js';

  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

  // Порядок: по order, затем по имени файла (MVP без приложения — §6).
  const scenesIn = (manifest.scenes || [])
    .slice()
    .sort((a, b) => (a.order ?? 1e9) - (b.order ?? 1e9) || String(a.file).localeCompare(String(b.file)));

  // Проход 1: принять годные сцены, прочитать размеры. src — исходная запись manifest.
  const accepted = [];
  for (const s of scenesIn) {
    if (s.quality && s.quality.status === 'reject') {
      console.warn(`skip (reject): ${s.id}`);
      continue;
    }
    const file = s.file || `${s.id}.jpg`;

    // Phase 2: cube-режим, если тайлер оставил tiles.json для сцены.
    const tilesMeta = tilesDir && path.join(tilesDir, s.id, 'tiles.json');
    const tiled = tilesMeta && fs.existsSync(tilesMeta)
      ? JSON.parse(fs.readFileSync(tilesMeta, 'utf8')) : null;

    const out = {
      id: s.id,
      name: s.name || s.id,
      initialViewParameters: { yaw: 0, pitch: 0, fov: DEFAULT_FOV },
      linkHotspots: [],
      infoHotspots: []
    };

    if (tiled) {
      out.type = 'cube';
      out.faceSize = tiled.faceSize;
      out.levels = tiled.levels;
    } else {
      out.type = 'equirect';
      const imgPath = path.join(imagesDir, file);
      let width = 4000;
      try {
        const meta = await sharp(imgPath).metadata();
        if (meta.width) width = meta.width;
        if (meta.width && meta.height && Math.abs(meta.width / meta.height - 2) > 0.02) {
          console.warn(`warn: ${file} не 2:1 (${meta.width}x${meta.height}) — equirect ждёт 2:1`);
        }
      } catch (e) {
        console.warn(`warn: не прочитать ${imgPath} (${e.message}) — width=${width}`);
      }
      out.equirectUrl = `img/${file}`;
      out.equirectWidth = width;
    }

    accepted.push({ src: s, out });
  }

  const byId = new Map(accepted.map((a) => [a.src.id, a]));
  const isAccepted = (id) => byId.has(id);

  // Проход 2: направленные yaw хотспотов и initialView (§8.1).
  for (const a of accepted) {
    const s = a.src;
    // Стрелки-переходы: только на принятые сцены.
    for (const targetId of s.links || []) {
      if (!isAccepted(targetId)) continue;
      const t = byId.get(targetId).src;
      let yaw = 0;
      if (hasGeo(s) && hasGeo(t)) {
        yaw = worldToYaw(azimuthDeg(s.floorplan, t.floorplan), s.heading);
      }
      a.out.linkHotspots.push({ target: targetId, yaw, pitch: LINK_PITCH });
    }

    // initialView.yaw: продолжить движение из предшественника (§8.1, направленный шов).
    // Предшественник = ближайшая по order сцена, ссылающаяся на нас (или первая такая).
    if (hasGeo(s)) {
      const preds = accepted
        .filter((p) => (p.src.links || []).includes(s.id) && hasGeo(p.src))
        .sort((p, q) => (p.src.order ?? 1e9) - (q.src.order ?? 1e9));
      const below = preds.filter((p) => (p.src.order ?? 1e9) <= (s.order ?? 1e9));
      const pred = (below.length ? below[below.length - 1] : preds[0]);
      if (pred) {
        const az = azimuthDeg(pred.src.floorplan, s.floorplan);
        a.out.initialViewParameters.yaw = worldToYaw(az, s.heading);
      }
    }
  }

  const scenes = accepted.map((a) => a.out);

  const appData = {
    scenes,
    name: (manifest.tour && manifest.tour.name) || 'Tour',
    settings: {
      autorotateEnabled: !!(manifest.settings && manifest.settings.autorotate),
      mouseViewMode: 'drag'
    }
  };

  const banner = '// GENERATED by src/generator/build-data.js — не редактировать вручную.\n';
  const body = `var APP_DATA = ${JSON.stringify(appData, null, 2)};\n`;
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, banner + body);
  console.log(`wrote ${outPath} — ${scenes.length} scene(s)`);
}

main().catch((e) => { console.error(e); process.exit(1); });
