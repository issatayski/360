<?php
// view.php?slug=... — публичный вьюер тура (Pannellum). Только опубликованные туры.
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/actions.php';

$slug = $_GET['slug'] ?? '';
$st = db()->prepare("SELECT * FROM tours WHERE slug = ? AND status = 'published'");
$st->execute([$slug]);
$tour = $st->fetch();

if (!$tour) {
    http_response_code(404);
    ?>
    <!doctype html><meta charset="utf-8">
    <title>Тур не найден</title>
    <style>body{font:16px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;display:flex;min-height:100vh;
      align-items:center;justify-content:center;margin:0;color:#444}</style>
    <div>Тур не найден или снят с публикации.</div>
    <?php
    exit;
}

$ss = db()->prepare('SELECT * FROM scenes WHERE tour_id = ? ORDER BY sort_order, id');
$ss->execute([$tour['id']]);
$scenes = $ss->fetchAll();

// Собираем конфиг Pannellum. Углы в БД — радианы, Pannellum ждёт градусы.
$RAD2DEG = 180.0 / M_PI;
$cfgScenes = [];
$order = [];
foreach ($scenes as $i => $s) {
    $sid = 'scene' . $i;
    $order[] = $sid;
    $cfgScenes[$sid] = [
        'type'     => 'equirectangular',
        'panorama' => $s['image_path'],
        'yaw'      => round((float)$s['init_yaw'] * $RAD2DEG, 2),
        'pitch'    => round((float)$s['init_pitch'] * $RAD2DEG, 2),
        'hfov'     => round((float)$s['init_fov'] * $RAD2DEG, 2),
        'title'    => $s['title'] !== '' ? $s['title'] : ('Сцена ' . ($i + 1)),
        'autoLoad' => true,
    ];
}
// Стрелки-переходы: клик по стрелке переключает на целевую сцену.
$keyById = [];
foreach ($scenes as $i => $s) $keyById[(int)$s['id']] = 'scene' . $i;
foreach (tour_hotspots((int)$tour['id']) as $hh) {
    $fk = $keyById[(int)$hh['from_scene_id']] ?? null;
    $tk = $keyById[(int)$hh['to_scene_id']] ?? null;
    if ($fk === null || $tk === null || !isset($cfgScenes[$fk])) continue;
    if (!isset($cfgScenes[$fk]['hotSpots'])) $cfgScenes[$fk]['hotSpots'] = [];
    $cfgScenes[$fk]['hotSpots'][] = [
        'pitch'    => round((float)$hh['pitch'] * $RAD2DEG, 2),
        'yaw'      => round((float)$hh['yaw'] * $RAD2DEG, 2),
        'type'     => 'scene',
        'sceneId'  => $tk,
        'cssClass' => 'hs-arrow',
        'text'     => $cfgScenes[$tk]['title'] ?? '',
    ];
}

$config = [
    'default' => [
        'firstScene'        => $order[0] ?? '',
        'autoLoad'          => true,
        'sceneFadeDuration' => 700,
        'showControls'      => true,
    ],
    'scenes' => $cfgScenes,
];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($tour['name']) ?></title>
<link rel="stylesheet" href="assets/pannellum.css">
<style>
  html,body{margin:0;height:100%;background:#000;font-family:-apple-system,Segoe UI,Roboto,sans-serif;}
  #pano{position:absolute;inset:0;}
  #menu{position:absolute;left:0;right:0;bottom:0;z-index:5;display:flex;gap:8px;overflow-x:auto;
    padding:12px 12px calc(12px + env(safe-area-inset-bottom));
    background:linear-gradient(transparent,rgba(0,0,0,.6));}
  #menu button{flex:0 0 auto;border:none;border-radius:999px;padding:9px 15px;font-size:14px;font-weight:600;
    background:rgba(255,255,255,.18);color:#fff;cursor:pointer;backdrop-filter:blur(6px);white-space:nowrap;}
  #menu button.active{background:#0a84ff;}
  #empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#bbb;}
  /* стрелки-переходы (класс задаётся через cssClass Pannellum → селектор без .pnlm-hotspot) */
  .hs-arrow{width:50px;height:50px;margin:-25px 0 0 -25px;border-radius:50%;
    background:rgba(10,132,255,.95);border:3px solid #fff;box-shadow:0 3px 14px rgba(0,0,0,.55);
    cursor:pointer;transition:transform .12s; pointer-events:auto;
    animation:hsPulse 1.8s ease-in-out infinite;}
  .hs-arrow:hover{transform:scale(1.15);}
  .hs-arrow::after{content:'➜';position:absolute;inset:0;display:flex;
    align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:900;}
  @keyframes hsPulse{0%,100%{box-shadow:0 3px 14px rgba(0,0,0,.55),0 0 0 0 rgba(10,132,255,.5);}
    50%{box-shadow:0 3px 14px rgba(0,0,0,.55),0 0 0 12px rgba(10,132,255,0);}}
</style>
</head>
<body>
  <div id="pano"></div>
  <?php if (count($scenes) > 1): ?>
    <div id="menu"></div>
  <?php endif; ?>
  <?php if (!$scenes): ?><div id="empty">В туре пока нет сцен.</div><?php endif; ?>

  <script src="assets/pannellum.js"></script>
  <script>
  (function () {
    var config = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var order = <?= json_encode($order, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    if (!order.length) return;

    var viewer = pannellum.viewer('pano', config);

    var menu = document.getElementById('menu');
    if (menu) {
      order.forEach(function (sid) {
        var b = document.createElement('button');
        b.textContent = config.scenes[sid].title;
        b.dataset.sid = sid;
        b.addEventListener('click', function () { viewer.loadScene(sid); });
        menu.appendChild(b);
      });
      function mark() {
        var cur = viewer.getScene();
        Array.prototype.forEach.call(menu.children, function (b) {
          b.classList.toggle('active', b.dataset.sid === cur);
        });
      }
      viewer.on('scenechange', mark);
      viewer.on('load', mark);
      mark();
    }
  })();
  </script>
</body>
</html>
