<?php
// worker_ping.php — проверка, может ли PHP на хостинге достучаться до воркера.
// Открой в браузере: https://твой-домен/api/worker_ping.php  → пришли вывод.
// Потом удали файл.
require_once __DIR__ . '/../lib/config.php';
header('Content-Type: text/plain; charset=utf-8');

echo "curl доступен: " . (function_exists('curl_init') ? 'да' : 'НЕТ (расширение curl не включено!)') . "\n";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'вкл' : 'выкл') . "\n";
echo "WORKER_URL: " . (WORKER_URL !== '' ? WORKER_URL : '(ПУСТО — не прописан!)') . "\n";
echo "WORKER_TOKEN задан: " . (WORKER_TOKEN !== '' ? 'да' : 'нет') . "\n";
echo str_repeat('-', 40) . "\n";

if (WORKER_URL === '' || !function_exists('curl_init')) {
    echo "Нечего проверять (см. выше).\n";
    exit;
}

$url = rtrim(WORKER_URL, '/') . '/health';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,   // health лёгкий, но у free-Render холодный старт ~50с
    CURLOPT_HTTPHEADER     => ['X-Worker-Token: ' . WORKER_TOKEN],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
$err  = curl_error($ch);
curl_close($ch);

echo "GET $url\n";
echo "HTTP код: " . $code . "\n";
echo "время: " . round($time, 1) . "с\n";
echo "curl ошибка: " . ($err !== '' ? $err : '(нет)') . "\n";
echo "ответ: " . substr((string)$body, 0, 300) . "\n";
echo str_repeat('-', 40) . "\n";

if ($code === 200 && strpos((string)$body, 'stitch-worker') !== false) {
    echo "ИТОГ: PHP → воркер РАБОТАЕТ. Проблема была в размере/таймауте большого POST.\n";
} elseif ($err !== '') {
    echo "ИТОГ: PHP НЕ может достучаться до воркера (исходящие блокируются/таймаут). Нужен другой путь.\n";
} else {
    echo "ИТОГ: странный ответ — пришли вывод целиком.\n";
}
