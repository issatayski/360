<?php
// tour_edit.php — управление одним туром: сцены, загрузка, порядок, публикация, embed.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/actions.php';

$user = require_login();
$tour_id = (int)($_GET['id'] ?? $_POST['tour_id'] ?? 0);
$tour = own_tour($tour_id, (int)$user['id']);
if (!$tour) redirect('dashboard.php');

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'rename') {
            rename_tour($tour_id, (int)$user['id'], $_POST['name'] ?? '');
        } elseif ($do === 'publish') {
            if (!set_tour_status($tour_id, (int)$user['id'], 'published')) {
                $error = 'Нельзя опубликовать тур без сцен.';
            }
        } elseif ($do === 'unpublish') {
            set_tour_status($tour_id, (int)$user['id'], 'draft');
        } elseif ($do === 'delete_scene') {
            delete_scene((int)($_POST['scene_id'] ?? 0), (int)$user['id']);
        } elseif ($do === 'move') {
            move_scene((int)($_POST['scene_id'] ?? 0), (int)$user['id'], $_POST['dir'] ?? '');
        } elseif ($do === 'upload') {
            if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'Файл не загрузился (проверь размер и формат).';
            } else {
                add_scene($tour_id, (int)$user['id'], $_FILES['image']['tmp_name'], $_POST['title'] ?? '');
                $notice = 'Сцена добавлена.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    // PRG: перечитываем состояние (кроме случая с сообщением об ошибке — оставим на экране)
    $tour = own_tour($tour_id, (int)$user['id']);
}

$scenes = tour_scenes($tour_id);
$token = csrf_token();
$base = base_url();
$public_url = $base . '/view.php?slug=' . $tour['slug'];
$iframe = '<iframe src="' . $public_url . '" width="640" height="400" style="border:0" allow="fullscreen; gyroscope; accelerometer" allowfullscreen></iframe>';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($tour['name']) ?> — 360 Tour</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="topbar">
  <span class="brand"><a href="dashboard.php">← Мои туры</a></span>
  <a class="btn ghost small" href="logout.php">Выйти</a>
</div>

<div class="wrap">
  <?php if ($notice): ?><div class="card" style="border-color:#bfe6c8"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="do" value="rename">
      <input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>">
      <input type="text" name="name" value="<?= h($tour['name']) ?>" style="flex:1;min-width:220px" required>
      <button class="btn" type="submit">Переименовать</button>
    </form>
    <div class="row" style="margin-top:12px">
      <?php if ($tour['status'] === 'published'): ?>
        <span class="badge pub">опубликован</span>
        <a class="btn small primary" href="<?= h($public_url) ?>" target="_blank">Открыть тур ↗</a>
        <form method="post"><input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="do" value="unpublish"><input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>">
          <button class="btn small ghost" type="submit">Снять с публикации</button></form>
      <?php else: ?>
        <span class="badge">черновик</span>
        <form method="post"><input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="do" value="publish"><input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>">
          <button class="btn small good" type="submit" <?= $scenes ? '' : 'disabled' ?>>Опубликовать</button></form>
        <?php if (!$scenes): ?><span class="muted small">добавь хотя бы одну сцену</span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Добавить сцену</h3>
    <div class="row" style="gap:18px; align-items:flex-start">
      <form method="post" enctype="multipart/form-data" style="flex:1; min-width:240px">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="do" value="upload">
        <input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>">
        <label>Название сцены (необязательно)
          <input type="text" name="title" placeholder="Гостиная">
        </label>
        <label>Готовое 360°-фото (equirect, 2:1, JPEG/PNG)
          <input type="file" name="image" accept="image/jpeg,image/png" required>
        </label>
        <button class="btn primary" type="submit" style="margin-top:10px">Загрузить</button>
      </form>
      <div style="flex:1; min-width:200px">
        <p class="muted small" style="margin-top:22px">…или сними в браузере (камера + гироскоп, склейка на месте):</p>
        <a class="btn good" href="capture.php?tour=<?= (int)$tour['id'] ?>">📷 Снять режимом A</a>
      </div>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Сцены (<?= count($scenes) ?>)</h3>
    <?php if (!$scenes): ?>
      <p class="muted">Сцен пока нет.</p>
    <?php else: ?>
      <div class="scene-grid">
        <?php foreach ($scenes as $i => $s): ?>
          <div class="scene">
            <img src="<?= h($s['image_path']) ?>" alt="">
            <div class="meta">
              <span><?= $s['title'] !== '' ? h($s['title']) : 'Сцена ' . ($i + 1) ?></span>
              <span class="row" style="gap:4px">
                <form method="post"><input type="hidden" name="csrf" value="<?= h($token) ?>">
                  <input type="hidden" name="do" value="move"><input type="hidden" name="dir" value="up">
                  <input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="scene_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn small ghost" type="submit" <?= $i === 0 ? 'disabled' : '' ?>>↑</button></form>
                <form method="post"><input type="hidden" name="csrf" value="<?= h($token) ?>">
                  <input type="hidden" name="do" value="move"><input type="hidden" name="dir" value="down">
                  <input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="scene_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn small ghost" type="submit" <?= $i === count($scenes) - 1 ? 'disabled' : '' ?>>↓</button></form>
                <form method="post" onsubmit="return confirm('Удалить сцену?')"><input type="hidden" name="csrf" value="<?= h($token) ?>">
                  <input type="hidden" name="do" value="delete_scene">
                  <input type="hidden" name="tour_id" value="<?= (int)$tour['id'] ?>"><input type="hidden" name="scene_id" value="<?= (int)$s['id'] ?>">
                  <button class="btn small ghost" type="submit">✕</button></form>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($tour['status'] === 'published'): ?>
  <div class="card">
    <h3 style="margin-top:0">Встроить на сайт</h3>
    <p class="muted small">Скопируй код и вставь в объявление/страницу:</p>
    <textarea class="embed-box" rows="3" readonly onclick="this.select()"><?= h($iframe) ?></textarea>
    <p class="muted small" style="margin-top:8px">Прямая ссылка: <a href="<?= h($public_url) ?>" target="_blank"><?= h($public_url) ?></a></p>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
