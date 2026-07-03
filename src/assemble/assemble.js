'use strict';
/*
 * assemble.js — собирает готовый статический тур: копия шаблона + data.js + панорамы.
 *
 *   node src/assemble/assemble.js \
 *     --template templates/marzipano-base \
 *     --data     work/data.js \
 *     --images   input/ \
 *     --out      output/villa-01
 *
 * Phase 0/1: панорамы кладутся в <out>/img/ (single-equirect, без tiles/).
 * Идемпотентно: чистит <out> и пересобирает.
 */
const fs = require('fs');
const path = require('path');

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) args[a.slice(2)] = argv[++i];
  }
  return args;
}

function copyDir(src, dst) {
  fs.mkdirSync(dst, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, entry.name);
    const d = path.join(dst, entry.name);
    if (entry.isDirectory()) copyDir(s, d);
    else fs.copyFileSync(s, d);
  }
}

function main() {
  const args = parseArgs(process.argv);
  const template = args.template || 'templates/marzipano-base';
  const dataPath = args.data || 'work/data.js';
  const imagesDir = args.images || 'input/';
  const tilesDir = args.tiles || null; // Phase 2: cube-тайлы, если есть
  const out = args.out || 'output/tour';

  // чистая пересборка (на Windows папку может держать открытый http-сервер/Проводник)
  try {
    fs.rmSync(out, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
  } catch (e) {
    if (e.code === 'EPERM' || e.code === 'EBUSY') {
      console.error(`✗ не удалить ${out} — папку кто-то держит.`);
      console.error('  Останови http-сервер этого тура и закрой папку в Проводнике, затем повтори.');
      process.exit(1);
    }
    throw e;
  }

  // 1) шаблон (index.html, index.js, style.css, marzipano.js, stub data.js)
  copyDir(template, out);

  // 2) сгенерированный data.js — поверх stub
  fs.copyFileSync(dataPath, path.join(out, 'data.js'));

  // Разобрать APP_DATA как реальные объекты (надёжнее регэкспа по тексту).
  // Генератор пишет `var APP_DATA = <JSON>;` — берём объект и JSON.parse.
  const dataSrc = fs.readFileSync(dataPath, 'utf8');
  const jsonStr = dataSrc.slice(dataSrc.indexOf('{'), dataSrc.lastIndexOf('}') + 1);
  const appData = JSON.parse(jsonStr);
  const sceneList = (appData && appData.scenes) || [];

  // 3) equirect-панорамы (equirectUrl = img/<file>)
  const imgOut = path.join(out, 'img');
  fs.mkdirSync(imgOut, { recursive: true });
  let copied = 0, equirectN = 0;
  for (const sc of sceneList) {
    if (sc.type === 'cube' || !sc.equirectUrl) continue;
    equirectN++;
    const file = sc.equirectUrl.replace(/^img\//, '');
    const src = path.join(imagesDir, file);
    if (fs.existsSync(src)) { fs.copyFileSync(src, path.join(imgOut, file)); copied++; }
    else console.warn(`warn: панорама не найдена: ${src}`);
  }

  // 4) cube-тайлы (tiles/<id>/...), без служебного tiles.json
  let tiledScenes = 0;
  for (const sc of sceneList) {
    if (sc.type !== 'cube') continue;
    if (!tilesDir) { console.warn(`warn: сцена ${sc.id} cube, но --tiles не задан`); continue; }
    const src = path.join(tilesDir, sc.id);
    if (!fs.existsSync(src)) { console.warn(`warn: тайлы не найдены: ${src}`); continue; }
    const dst = path.join(out, 'tiles', sc.id);
    copyDir(src, dst);
    fs.rmSync(path.join(dst, 'tiles.json'), { force: true });
    tiledScenes++;
  }

  console.log(`assembled ${out} — ${equirectN} equirect (${copied} copied), ${tiledScenes} cube scene(s)`);
}

main();
