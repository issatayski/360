'use strict';
/*
 * package.js — Phase 4: выдача клиенту (CLAUDE.md §9).
 *   1) HTML-отчёт качества из work/triage-report.json (прошло / улучшить / переснять)
 *   2) zip готового тура output/<tour>/ -> output/<tour>.zip
 *
 *   node src/package/package.js --tour villa-01 \
 *     --dir output/villa-01 --report work/triage-report.json --out output/villa-01.zip
 */
const fs = require('fs');
const path = require('path');
const archiverPkg = require('archiver');

// archiver сменил API: v7 и раньше — функция-фабрика archiver('zip'),
// v8+ — класс { Archiver }. Поддерживаем оба.
function createZipArchive(opts) {
  if (typeof archiverPkg === 'function') return archiverPkg('zip', opts);
  if (archiverPkg && typeof archiverPkg.Archiver === 'function') return new archiverPkg.Archiver('zip', opts);
  throw new Error('archiver: не удалось создать zip (неизвестный экспорт пакета)');
}

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

const STATUS = {
  ok:      { label: 'Годно',      cls: 'ok',  hint: 'Используется как есть' },
  enhance: { label: 'Улучшить',   cls: 'enh', hint: 'Можно улучшить (резкость/экспозиция)' },
  reject:  { label: 'Переснять',  cls: 'rej', hint: 'В тур не вошло — переснять' },
};

function esc(s) {
  return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function buildReport(tour, report) {
  const n = report.length;
  const by = (st) => report.filter((r) => (r.status || 'reject') === st).length;
  const ok = by('ok'), enh = by('enhance'), rej = by('reject');

  const rows = report.map((r) => {
    const st = STATUS[r.status] || STATUS.reject;
    const reasons = (r.reasons && r.reasons.length) ? r.reasons.join('; ') : '—';
    const metrics = [
      r.sharpness != null ? `резкость ${r.sharpness}` : null,
      r.brightness != null ? `яркость ${r.brightness}` : null,
      r.aspect != null ? `формат ${r.aspect}` : null,
    ].filter(Boolean).join(' · ');
    return `<tr class="${st.cls}">
      <td class="id">${esc(r.id)}</td>
      <td><span class="badge ${st.cls}">${st.label}</span></td>
      <td>${esc(reasons)}</td>
      <td class="metrics">${esc(metrics)}</td>
    </tr>`;
  }).join('\n');

  const reshoot = report.filter((r) => r.status === 'reject').map((r) => esc(r.id));
  const reshootBlock = reshoot.length
    ? `<div class="reshoot"><strong>Переснять (${reshoot.length}):</strong> ${reshoot.join(', ')}</div>`
    : `<div class="allgood">Все панорамы прошли контроль качества ✓</div>`;

  return `<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Отчёт качества — ${esc(tour)}</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.5 -apple-system, Segoe UI, Roboto, sans-serif; margin: 0; padding: 32px;
    background: #f6f7f9; color: #1a1a1a; }
  @media (prefers-color-scheme: dark) { body { background: #16181d; color: #e8e8ea; } }
  .wrap { max-width: 860px; margin: 0 auto; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .sub { opacity: .6; margin-bottom: 24px; }
  .cards { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
  .card { flex: 1; min-width: 120px; padding: 14px 16px; border-radius: 12px; background: rgba(127,127,127,.1); }
  .card .num { font-size: 28px; font-weight: 700; }
  .card.ok .num { color: #1a9a5a; } .card.enh .num { color: #c98a00; } .card.rej .num { color: #d64545; }
  table { width: 100%; border-collapse: collapse; background: rgba(127,127,127,.06); border-radius: 12px; overflow: hidden; }
  th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid rgba(127,127,127,.15); }
  th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; opacity: .6; }
  td.id { font-weight: 600; } td.metrics { opacity: .6; font-size: 13px; }
  .badge { padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
  .badge.ok { background: rgba(26,154,90,.18); color: #1a9a5a; }
  .badge.enh { background: rgba(201,138,0,.18); color: #c98a00; }
  .badge.rej { background: rgba(214,69,69,.18); color: #d64545; }
  .reshoot { margin-top: 20px; padding: 14px 16px; border-radius: 12px;
    background: rgba(214,69,69,.12); border: 1px solid rgba(214,69,69,.3); }
  .allgood { margin-top: 20px; padding: 14px 16px; border-radius: 12px;
    background: rgba(26,154,90,.12); border: 1px solid rgba(26,154,90,.3); }
</style></head>
<body><div class="wrap">
  <h1>Отчёт качества съёмки</h1>
  <div class="sub">Тур: <strong>${esc(tour)}</strong> · сцен: ${n}</div>
  <div class="cards">
    <div class="card ok"><div class="num">${ok}</div>Годно</div>
    <div class="card enh"><div class="num">${enh}</div>Улучшить</div>
    <div class="card rej"><div class="num">${rej}</div>Переснять</div>
  </div>
  <table>
    <thead><tr><th>Сцена</th><th>Статус</th><th>Замечания</th><th>Метрики</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>
  ${reshootBlock}
</div></body></html>`;
}

function zipDir(srcDir, zipPath, folderName) {
  return new Promise((resolve, reject) => {
    const out = fs.createWriteStream(zipPath);
    const archive = createZipArchive({ zlib: { level: 9 } });
    out.on('close', () => resolve(archive.pointer()));
    archive.on('error', reject);
    archive.pipe(out);
    archive.directory(srcDir, folderName);
    archive.finalize();
  });
}

async function main() {
  const args = parseArgs(process.argv);
  const tour = args.tour || 'tour';
  const dir = args.dir || path.join('output', tour);
  const reportPath = args.report || 'work/triage-report.json';
  const zipPath = args.out || path.join('output', `${tour}.zip`);

  if (!fs.existsSync(dir)) { console.error(`тур не найден: ${dir}`); process.exit(1); }

  // 1) отчёт качества
  let report = [];
  if (fs.existsSync(reportPath)) {
    try { report = JSON.parse(fs.readFileSync(reportPath, 'utf8')); }
    catch (e) { console.warn(`warn: не прочитать ${reportPath}: ${e.message}`); }
  } else {
    console.warn(`warn: нет ${reportPath} — отчёт без данных triage`);
  }
  const reportHtml = buildReport(tour, report);
  const reportOut = path.join('output', `${tour}-report.html`);
  fs.writeFileSync(reportOut, reportHtml);
  const rej = report.filter((r) => r.status === 'reject').length;
  console.log(`отчёт: ${reportOut} (годно ${report.filter(r=>r.status==='ok').length}, `
    + `улучшить ${report.filter(r=>r.status==='enhance').length}, переснять ${rej})`);

  // 2) zip тура
  const bytes = await zipDir(dir, zipPath, tour);
  console.log(`zip: ${zipPath} (${(bytes / 1024).toFixed(0)} КБ)`);
}

main().catch((e) => { console.error(e.message || e); process.exit(1); });
