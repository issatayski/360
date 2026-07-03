'use strict';
/*
 * orchestrate.js — весь MVP-конвейер end-to-end (CLAUDE.md §2, §10).
 *
 *   node src/orchestrate.js --tour villa-01
 *
 * Стадии (Phase 1, single-equirect):
 *   1. triage    — валидация качества, статусы в manifest
 *   2. generator — manifest → work/data.js (APP_DATA)
 *   3. assemble  — шаблон + data.js + панорамы → output/<tour>/
 *
 * Флаги:
 *   --in <dir>        входные панорамы + manifest.json  (default input/)
 *   --manifest <p>    путь к manifest.json              (default <in>/manifest.json)
 *   --out <dir>       выходная папка тура               (default output/<tour>)
 *   --skip-triage     не гонять triage
 *   --allow-reject    не падать, если triage нашёл брак (по умолчанию — стоп)
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const key = a.slice(2);
      const next = argv[i + 1];
      if (next && !next.startsWith('--')) { args[key] = next; i++; }
      else args[key] = true;
    }
  }
  return args;
}

function pythonBin() {
  const win = path.join('.venv', 'Scripts', 'python.exe');
  const nix = path.join('.venv', 'bin', 'python');
  if (fs.existsSync(win)) return win;
  if (fs.existsSync(nix)) return nix;
  return 'python'; // фолбэк на системный
}

function run(label, cmd, cmdArgs) {
  console.log(`\n=== ${label} ===`);
  console.log(`$ ${cmd} ${cmdArgs.join(' ')}`);
  const res = spawnSync(cmd, cmdArgs, {
    stdio: 'inherit',
    env: { ...process.env, PYTHONUTF8: '1', PYTHONIOENCODING: 'utf-8' },
  });
  if (res.error) throw res.error;
  return res.status;
}

function main() {
  const args = parseArgs(process.argv);
  const indir = args.in || 'input';
  const manifest = args.manifest || path.join(indir, 'manifest.json');

  if (!fs.existsSync(manifest)) {
    console.error(`manifest не найден: ${manifest}`);
    process.exit(1);
  }
  const tour = args.tour || JSON.parse(fs.readFileSync(manifest, 'utf8')).tour?.id || 'tour';
  const out = args.out || path.join('output', tour);
  const dataOut = path.join('work', 'data.js');
  const py = pythonBin();

  console.log(`orchestrate: tour=${tour}  in=${indir}  out=${out}  python=${py}`);

  // 1. triage
  if (!args['skip-triage']) {
    const code = run('1/3 triage', py, [
      'src/triage/validate.py', '--in', indir, '--manifest', manifest,
    ]);
    if (code === 2 && !args['allow-reject']) {
      console.error('\n✗ triage нашёл брак (reject). Переснять/исправить или запустить с --allow-reject.');
      process.exit(2);
    }
    if (code !== 0 && code !== 2) { console.error(`triage упал (код ${code})`); process.exit(code || 1); }
  } else {
    console.log('\n=== 1/3 triage — пропущено (--skip-triage) ===');
  }

  // 2. tiler (Phase 2, опционально: --tile). Иначе single-equirect (Phase 1).
  let tilesArgs = [];
  if (args.tile) {
    const face = String(args.face || 2048);
    const code = run('tiler', 'node', [
      'src/tiler/tile.js', '--in', indir, '--out', 'work/tiles', '--face', face,
    ]);
    if (code !== 0) { console.error(`tiler упал (код ${code})`); process.exit(code || 1); }
    tilesArgs = ['--tiles', 'work/tiles'];
  }

  // 3. generator
  let code = run('generator', 'node', [
    'src/generator/build-data.js', '--manifest', manifest, '--images', indir, '--out', dataOut,
    ...tilesArgs,
  ]);
  if (code !== 0) { console.error(`generator упал (код ${code})`); process.exit(code || 1); }

  // 4. assemble
  code = run('assemble', 'node', [
    'src/assemble/assemble.js',
    '--template', 'templates/marzipano-base',
    '--data', dataOut, '--images', indir, '--out', out,
    ...tilesArgs,
  ]);
  if (code !== 0) { console.error(`assemble упал (код ${code})`); process.exit(code || 1); }

  console.log(`\n✓ готово: ${out}`);
  console.log(`  просмотр:  cd ${out} && python -m http.server 8000  →  http://localhost:8000`);
}

main();
