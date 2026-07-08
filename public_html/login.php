<?php
// login.php — вход агента.
require_once __DIR__ . '/lib/auth.php';

if (current_user()) redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $email = $_POST['email'] ?? '';
    $pass  = $_POST['password'] ?? '';
    if (attempt_login($email, $pass)) {
        redirect('dashboard.php');
    }
    $error = 'Неверный email или пароль';
}
$token = csrf_token();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — 360 Tour</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="center">
  <form class="card auth" method="post" action="login.php">
    <h1>360 Tour</h1>
    <p class="muted">Вход для агента</p>
    <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h($token) ?>">
    <label>Email
      <input type="email" name="email" required autofocus autocomplete="username">
    </label>
    <label>Пароль
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn primary" type="submit">Войти</button>
  </form>
</body>
</html>
