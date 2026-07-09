<?php
/**
 * api/save_scene.php — сохранить готовую панораму (собранную воркером) сценой в тур.
 * Телефон присылает сюда результат склейки + билет.
 * POST (multipart): csrf, ticket, title?, image (equirect JPEG)
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/actions.php';
require_once __DIR__ . '/../lib/ticket.php';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Не авторизован'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Только POST'], 405);
csrf_check($_POST['csrf'] ?? null);

$data = ticket_verify($_POST['ticket'] ?? '');
if (!$data) json_out(['ok' => false, 'error' => 'Билет недействителен или истёк'], 403);

$tour_id = (int)$data['tour_id'];
if ((int)$data['uid'] !== (int)$user['id'] || !own_tour($tour_id, (int)$user['id'])) {
    json_out(['ok' => false, 'error' => 'Тур не найден'], 404);
}

if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    json_out(['ok' => false, 'error' => 'Нет файла панорамы'], 400);
}

try {
    $scene_id = add_scene($tour_id, (int)$user['id'], $_FILES['image']['tmp_name'], $_POST['title'] ?? '');
    json_out(['ok' => true, 'scene_id' => $scene_id, 'redirect' => 'tour_edit.php?id=' . $tour_id]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
