<?php
/**
 * setup.php — одноразовая инициализация: создать таблицы и seed-агента.
 * Открой в браузере ОДИН раз после заливки на хостинг, затем УДАЛИ этот файл.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';

header('Content-Type: text/html; charset=utf-8');

$log = [];
try {
    ensure_schema();
    $log[] = 'Схема создана/проверена (' . DB_DRIVER . ').';

    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([SEED_EMAIL]);
    if ($st->fetch()) {
        $log[] = 'Агент ' . SEED_EMAIL . ' уже существует — пропускаю seed.';
    } else {
        $hash = password_hash(SEED_PASSWORD, PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO users (email, pass_hash, plan, credits) VALUES (?,?,?,?)');
        $ins->execute([SEED_EMAIL, $hash, 'free', 0]);
        $log[] = 'Создан агент: ' . SEED_EMAIL . ' (пароль из config.php: SEED_PASSWORD).';
    }
    // Папка загрузок
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    $log[] = is_writable(UPLOAD_DIR)
        ? 'Папка uploads/ доступна на запись.'
        : 'ВНИМАНИЕ: uploads/ недоступна на запись — выстави права 0755/0775.';
    $ok = true;
} catch (Throwable $e) {
    $ok = false;
    $log[] = 'ОШИБКА: ' . $e->getMessage();
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Setup — 360 Tour</title>
<style>
  body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:40px auto;padding:0 20px;color:#16181d}
  .card{background:#f4f5f7;border:1px solid #e3e5e9;border-radius:12px;padding:20px 24px}
  li{margin:4px 0} .ok{color:#1a7f37} .bad{color:#b42318}
  code{background:#e9ecf1;padding:1px 6px;border-radius:5px}
</style>
<div class="card">
  <h1><?= $ok ? '✅ Готово' : '⚠️ Есть проблема' ?></h1>
  <ul>
    <?php foreach ($log as $line): $bad = (strpos($line, 'ОШИБКА') === 0 || strpos($line, 'ВНИМАНИЕ') === 0); ?>
      <li class="<?= $bad ? 'bad' : 'ok' ?>"><?= h($line) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($ok): ?>
    <p>Теперь: <a href="login.php">войти</a> под <code><?= h(SEED_EMAIL) ?></code> и
       <b>удалить setup.php</b> с хостинга. Пароль смени в <code>lib/config.php</code>
       (или через будущий экран профиля).</p>
  <?php endif; ?>
</div>
