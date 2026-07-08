<?php
/**
 * api/add_hotspot.php — добавить переход from_scene → to_scene (+ авто-обратный).
 * POST: csrf, tour_id, from_scene_id, to_scene_id, yaw (рад), pitch (рад)
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/actions.php';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Не авторизован'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Только POST'], 405);
csrf_check($_POST['csrf'] ?? null);

$tour_id = (int)($_POST['tour_id'] ?? 0);
$from    = (int)($_POST['from_scene_id'] ?? 0);
$to      = (int)($_POST['to_scene_id'] ?? 0);
$yaw     = (float)($_POST['yaw'] ?? 0);
$pitch   = (float)($_POST['pitch'] ?? 0);

try {
    $id = add_hotspot($tour_id, (int)$user['id'], $from, $to, $yaw, $pitch);
    json_out(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
