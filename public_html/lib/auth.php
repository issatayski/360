<?php
/**
 * auth.php — сессии и авторизация агента (Фаза 0: один seed-агент, вход по паролю).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function auth_boot(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;

    // Пишем сессии в свою папку — дефолтный save_path на shared-хостинге часто
    // недоступен, из-за чего $_SESSION не сохраняется и падает проверка CSRF.
    $dir = SESSION_SAVE_PATH;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) @session_save_path($dir);

    // HTTPS может терминироваться на прокси — учитываем X-Forwarded-Proto и порт.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

/** Текущий пользователь (строка из users) или null. */
function current_user(): ?array
{
    auth_boot();
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

/** Требует входа: иначе редирект на login. */
function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

/** Проверка логина/пароля. Возвращает true при успехе и заводит сессию. */
function attempt_login(string $email, string $password): bool
{
    auth_boot();
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([trim($email)]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        return true;
    }
    return false;
}

function logout(): void
{
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
