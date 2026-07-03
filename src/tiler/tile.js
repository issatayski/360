'use strict';
/*
 * tile.js — equirect → multires cube-тайлы Marzipano (CLAUDE.md §7, §8.3).
 *
 *   node src/tiler/tile.js --in input/ --out work/tiles/ --face 2048
 *   node src/tiler/tile.js --in input/01-hall.jpg --out work/tiles/
 *
 * Пиксельную часть (equirect → 6 граней) делает src/tiler/e2c.py (py360convert).
 * Здесь — нарезка граней на квадратные тайлы 512, пирамида уровней и preview.jpg.
 *
 * Раскладка (Marzipano): tiles/<id>/{z}/{f}/{y}/{x}.jpg + tiles/<id>/preview.jpg
 *   z — индекс уровня (0 = самый мелкий), f — буква грани (f r b l u d),
 *   x — столбец, y — строка. Тайлы встык (грани py360 уже сходятся идеально —
 *   перекрытие тайлов Marzipano не требует; бесшовность даёт гномоническая проекция).
 *
 * Инварианты (§7): size уровня кратен 512 и размеру родителя; тайлы квадратные;
 * число тайлов кратно родительскому. Обеспечивается сеткой size = 512·2^k.
 *
 * Ориентация полюсов U/D относительно Marzipano headless не проверить — если на
 * зените/надире виден шов/скрутка, поменяй --pole (см. e2c-маппинг).
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const sharp = require('sharp');

const TILE = 512;
const FACES = ['f', 'r', 'b', 'l', 'u', 'd'];
const PREVIEW_ORDER = ['b', 'd', 'f', 'l', 'r', 'u']; // cubeMapPreviewFaceOrder = 'bdflru'
const PREVIEW_FACE = 256;

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const next = argv[i + 1];
      if (next && !next.startsWith('--')) { args[a.slice(2)] = next; i++; }
      else args[a.slice(2)] = true;
    }
  }
  return args;
}

function pythonBin() {
  const win = path.join('.venv', 'Scripts', 'python.exe');
  const nix = path.join('.venv', 'bin', 'python');
  if (fs.existsSync(win)) return win;
  if (fs.existsSync(nix)) return nix;
  return 'python';
}

// Размеры уровней: 512, 1024, ..., faceSize (каждый вдвое, кратен 512).
function levelSizes(faceSize) {
  const sizes = [];
  for (let s = TILE; s <= faceSize; s *= 2) sizes.push(s);
  if (sizes[sizes.length - 1] !== faceSize) {
    throw new Error(`--face ${faceSize} должен быть 512·2^k (512,1024,2048,4096...)`);
  }
  return sizes;
}

async function tileScene(id, srcJpg, outRoot, faceSize, py) {
  const facesDir = path.join('work', 'faces', id);
  // 1) equirect → грани (Python)
  const r = spawnSync(py, ['src/tiler/e2c.py', '--in', srcJpg, '--out', facesDir, '--size', String(faceSize)],
    { stdio: 'inherit', env: { ...process.env, PYTHONUTF8: '1', PYTHONIOENCODING: 'utf-8' } });
  if (r.status !== 0) throw new Error(`e2c упал на ${id}`);

  const sceneDir = path.join(outRoot, id);
  fs.rmSync(sceneDir, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
  fs.mkdirSync(sceneDir, { recursive: true });

  const sizes = levelSizes(faceSize);

  // 2) нарезка тайлов по уровням и граням
  for (let z = 0; z < sizes.length; z++) {
    const S = sizes[z];
    const n = S / TILE;
    for (const f of FACES) {
      const facePng = path.join(facesDir, `${f}.png`);
      const resized = await sharp(facePng).resize(S, S, { fit: 'fill' }).toBuffer();
      for (let y = 0; y < n; y++) {
        for (let x = 0; x < n; x++) {
          const dir = path.join(sceneDir, String(z), f, String(y));
          fs.mkdirSync(dir, { recursive: true });
          await sharp(resized)
            .extract({ left: x * TILE, top: y * TILE, width: TILE, height: TILE })
            .jpeg({ quality: 85 })
            .toFile(path.join(dir, `${x}.jpg`));
        }
      }
    }
  }

  // 3) preview.jpg — 6 граней стопкой по вертикали в порядке 'bdflru'
  const previewFaces = [];
  for (const f of PREVIEW_ORDER) {
    previewFaces.push(await sharp(path.join(facesDir, `${f}.png`))
      .resize(PREVIEW_FACE, PREVIEW_FACE, { fit: 'fill' }).toBuffer());
  }
  await sharp({ create: {
      width: PREVIEW_FACE, height: PREVIEW_FACE * 6, channels: 3, background: '#000',
    } })
    .composite(previewFaces.map((buf, i) => ({ input: buf, top: i * PREVIEW_FACE, left: 0 })))
    .jpeg({ quality: 80 })
    .toFile(path.join(sceneDir, 'preview.jpg'));

  // 4) метаданные для генератора
  const levels = sizes.map((size, i) => (
    i === 0 ? { tileSize: TILE, size, fallbackOnly: true } : { tileSize: TILE, size }
  ));
  fs.writeFileSync(path.join(sceneDir, 'tiles.json'),
    JSON.stringify({ id, faceSize, tileSize: TILE, levels }, null, 2));

  const totalTiles = sizes.reduce((acc, S) => acc + 6 * (S / TILE) ** 2, 0);
  console.log(`  ${id}: face=${faceSize}, levels=${sizes.join('/')}, ${totalTiles} tiles + preview`);
}

async function main() {
  const args = parseArgs(process.argv);
  const inPath = args.in || 'input';
  const outRoot = args.out || 'work/tiles';
  const faceSize = parseInt(args.face || '2048', 10);
  const py = pythonBin();

  levelSizes(faceSize); // ранняя валидация

  let jobs = [];
  const stat = fs.statSync(inPath);
  if (stat.isDirectory()) {
    jobs = fs.readdirSync(inPath)
      .filter((f) => /\.(jpe?g|png)$/i.test(f))
      .map((f) => ({ id: path.basename(f, path.extname(f)), src: path.join(inPath, f) }));
  } else {
    jobs = [{ id: path.basename(inPath, path.extname(inPath)), src: inPath }];
  }

  fs.mkdirSync(outRoot, { recursive: true });
  console.log(`tiler: ${jobs.length} scene(s) -> ${outRoot} (face=${faceSize}, python=${py})`);
  for (const j of jobs) {
    await tileScene(j.id, j.src, outRoot, faceSize, py);
  }
  console.log('tiler: готово');
}

main().catch((e) => { console.error(e.message || e); process.exit(1); });
