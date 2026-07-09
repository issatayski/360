<?php
/**
 * api/stitch_cloud.php — облачная склейка: телефон шлёт сюда сырые кадры + позы,
 * PHP (с авторизацией) проксирует их воркеру OpenCV, получает резкую equirect и
 * сохраняет её сценой в тур. Секрет воркера наружу не отдаётся.
 *
 * POST (multipart): csrf, tour_id, title?, manifest (JSON: {hfov,width,frames:[{name,R[9]}]}),
 *                   и по файлу-картинке на каждый frames[].name.
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/actions.php';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Не авторизован'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Только POST'], 405);
csrf_check($_POST['csrf'] ?? null);

if (WORKER_URL === '') {
    json_out(['ok' => false, 'error' => 'Облачная склейка не настроена (WORKER_URL пуст)', 'worker' => false], 503);
}

$tour_id = (int)($_POST['tour_id'] ?? 0);
$title = $_POST['title'] ?? '';
if (!own_tour($tour_id, (int)$user['id'])) {
    json_out(['ok' => false, 'error' => 'Тур не найден'], 404);
}

$manifest = $_POST['manifest'] ?? '';
$man = json_decode($manifest, true);
if (!is_array($man) || empty($man['frames'])) {
    json_out(['ok' => false, 'error' => 'Пустой manifest'], 400);
}

// Собираем multipart для воркера: manifest + файлы по именам кадров.
$post = ['manifest' => $manifest];
foreach ($man['frames'] as $fr) {
    $name = $fr['name'] ?? '';
    if ($name === '' || empty($_FILES[$name]) || ($_FILES[$name]['error'] ?? 1) !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => "Нет файла кадра '$name'"], 400);
    }
    $post[$name] = new CURLFile($_FILES[$name]['tmp_name'], 'image/jpeg', $name . '.jpg');
}

$ch = curl_init(rtrim(WORKER_URL, '/') . '/stitch');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => WORKER_TIMEOUT,
    CURLOPT_HTTPHEADER     => ['X-Worker-Token: ' . WORKER_TOKEN],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$cerr = curl_error($ch);
curl_close($ch);

if ($body === false || $cerr) {
    json_out(['ok' => false, 'error' => 'Воркер недоступен: ' . $cerr], 502);
}
if ($code !== 200 || strpos($ctype, 'image/') === false) {
    $msg = 'Воркер вернул ошибку (' . $code . ')';
    $j = json_decode((string)$body, true);
    if (is_array($j) && !empty($j['error'])) $msg = $j['error'];
    json_out(['ok' => false, 'error' => $msg], 502);
}

// Сохраняем полученную панораму сценой в тур.
$tmp = tempnam(sys_get_temp_dir(), 'pano');
if ($tmp === false || @file_put_contents($tmp, $body) === false) {
    json_out(['ok' => false, 'error' => 'Не удалось записать результат'], 500);
}
try {
    $scene_id = add_scene($tour_id, (int)$user['id'], $tmp, $title);
    @unlink($tmp);
    json_out(['ok' => true, 'scene_id' => $scene_id, 'redirect' => 'tour_edit.php?id=' . $tour_id]);
} catch (Throwable $e) {
    @unlink($tmp);
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
