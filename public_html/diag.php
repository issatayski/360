<?php
// diag.php — самотест окружения. Открой в браузере, посмотри отчёт, потом УДАЛИ файл.
// Специально без include других файлов и без синтаксиса PHP 8, чтобы работал везде.
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
@ini_set('display_errors', '1');

function row($k, $ok, $val) {
    $c = $ok ? '#1a7f37' : '#b42318';
    echo '<tr><td style="padding:4px 10px">' . htmlspecialchars($k) . '</td>'
       . '<td style="padding:4px 10px;color:' . $c . '">' . ($ok ? 'OK' : 'ПРОБЛЕМА') . '</td>'
       . '<td style="padding:4px 10px;color:#555">' . htmlspecialchars($val) . '</td></tr>';
}

echo '<meta charset="utf-8"><title>diag</title>';
echo '<h2>Самотест окружения</h2><table style="border-collapse:collapse;font:14px sans-serif">';

$php = PHP_VERSION;
row('PHP версия', version_compare($php, '8.0.0', '>='), $php . (version_compare($php, '8.0.0', '>=') ? '' : ' — нужно 8.0+ (cPanel → MultiPHP Manager)'));
row('PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'есть' : 'нет');
row('pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'есть' : 'включи в cPanel');
row('gd (getimagesize)', function_exists('getimagesize'), function_exists('getimagesize') ? 'есть' : 'нет');

$cfg = __DIR__ . '/lib/config.php';
$hasCfg = is_file($cfg);
row('lib/config.php', $hasCfg, $hasCfg ? 'найден' : 'не залит');

$uploads = __DIR__ . '/uploads';
row('uploads/ на запись', is_dir($uploads) && is_writable($uploads), is_dir($uploads) ? ('права: ' . substr(sprintf('%o', @fileperms($uploads)), -4)) : 'папки нет');

// Сессии: пишем в свою папку и проверяем, что значение переживает запись.
$sdir = __DIR__ . '/data/sessions';
if (!is_dir($sdir)) @mkdir($sdir, 0700, true);
$swritable = is_dir($sdir) && is_writable($sdir);
if ($swritable) @session_save_path($sdir);
@session_start();
$_SESSION['diag_probe'] = 'ok';
$sess_ok = (session_status() === PHP_SESSION_ACTIVE) && isset($_SESSION['diag_probe']);
row('Сессии пишутся', $sess_ok && $swritable, 'save_path: ' . session_save_path() . ($swritable ? '' : ' (НЕ пишется!)'));

// Пробуем подключиться к БД, читая константы из config без его побочных эффектов.
if ($hasCfg) {
    require $cfg;
    if (defined('DB_DRIVER') && DB_DRIVER === 'mysql') {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            row('Подключение к БД (MySQL)', true, 'ок: ' . DB_NAME . '@' . DB_HOST);
        } catch (Throwable $e) {
            row('Подключение к БД (MySQL)', false, $e->getMessage());
        }
    } else {
        row('DB_DRIVER', true, defined('DB_DRIVER') ? DB_DRIVER : 'не задан');
    }
}

echo '</table><p style="font:13px sans-serif;color:#888">Готово — исправь «ПРОБЛЕМА» строки и удали diag.php.</p>';
