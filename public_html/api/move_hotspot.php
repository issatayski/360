<?php
/**
 * api/move_hotspot.php — переставить существующую стрелку.
 * POST: csrf, hotspot_id, yaw (рад), pitch (рад)
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/actions.php';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Не авторизован'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Только POST'], 405);
csrf_check($_POST['csrf'] ?? null);

$id    = (int)($_POST['hotspot_id'] ?? 0);
$yaw   = (float)($_POST['yaw'] ?? 0);
$pitch = (float)($_POST['pitch'] ?? 0);

if (reposition_hotspot($id, (int)$user['id'], $yaw, $pitch)) {
    json_out(['ok' => true]);
}
json_out(['ok' => false, 'error' => 'Переход не найден'], 404);
