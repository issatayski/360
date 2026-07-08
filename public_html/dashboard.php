<?php
// dashboard.php — список туров агента + создание нового тура.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/actions.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['do'] ?? '') === 'create') {
        $t = create_tour((int)$user['id'], $_POST['name'] ?? '');
        redirect('tour_edit.php?id=' . $t['id']);
    }
    if (($_POST['do'] ?? '') === 'delete_tour') {
        delete_tour((int)($_POST['tour_id'] ?? 0), (int)$user['id']);
        redirect('dashboard.php');
    }
}

$tours = list_tours((int)$user['id']);
$token = csrf_token();
$base = base_url();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Мои туры — 360 Tour</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="topbar">
  <span class="brand">360 Tour</span>
  <span class="row">
    <span class="muted small"><?= h($user['email']) ?> · план: <?= h($user['plan']) ?></span>
    <a class="btn ghost small" href="logout.php">Выйти</a>
  </span>
</div>

<div class="wrap">
  <div class="card">
    <h2 style="margin-top:0">Новый тур</h2>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="do" value="create">
      <input type="text" name="name" placeholder="Название (напр. Квартира на Абая)" style="flex:1;min-width:220px" required>
      <button class="btn primary" type="submit">Создать</button>
    </form>
  </div>

  <h2>Мои туры</h2>
  <?php if (!$tours): ?>
    <p class="muted">Пока пусто. Создай первый тур выше.</p>
  <?php else: ?>
    <ul class="tour-list">
      <?php foreach ($tours as $t): ?>
        <li class="card tour-item">
          <div>
            <div style="font-weight:600"><?= h($t['name']) ?></div>
            <div class="muted small">
              сцен: <?= (int)$t['scene_count'] ?> ·
              <?php if ($t['status'] === 'published'): ?>
                <span class="badge pub">опубликован</span>
                · <a href="<?= h($base) ?>/view.php?slug=<?= h($t['slug']) ?>" target="_blank">открыть ссылку ↗</a>
              <?php else: ?>
                <span class="badge">черновик</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="row">
            <a class="btn small primary" href="tour_edit.php?id=<?= (int)$t['id'] ?>">Открыть</a>
            <form method="post" onsubmit="return confirm('Удалить тур и все его сцены?')">
              <input type="hidden" name="csrf" value="<?= h($token) ?>">
              <input type="hidden" name="do" value="delete_tour">
              <input type="hidden" name="tour_id" value="<?= (int)$t['id'] ?>">
              <button class="btn small ghost" type="submit">Удалить</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
</body>
</html>
