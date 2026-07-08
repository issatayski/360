<?php
/**
 * api/upload_scene.php — приём одной equirect-панорамы (JSON-ответ).
 * Используется режимом A (capture.php): собранный в браузере equirect уходит сюда.
 * Обычная загрузка файла через форму идёт в tour_edit.php (do=upload) — обе дороги
 * ведут в actions.php::add_scene, чтобы валидация/хранение были едины.
 *
 * Запрос: multipart/form-data
 *   csrf     — токен
 *   tour_id  — id тура (владелец = текущий агент)
 *   title    — необязательно
 *   image    — файл equirect (JPEG/PNG, ~2:1)
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/actions.php';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Не авторизован'], 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Только POST'], 405);
}
csrf_check($_POST['csrf'] ?? null);

$tour_id = (int)($_POST['tour_id'] ?? 0);
$title = $_POST['title'] ?? '';

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_out(['ok' => false, 'error' => 'Файл не получен'], 400);
}

try {
    $scene_id = add_scene($tour_id, (int)$user['id'], $_FILES['image']['tmp_name'], $title);
    json_out([
        'ok'       => true,
        'scene_id' => $scene_id,
        'redirect' => 'tour_edit.php?id=' . $tour_id,
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
