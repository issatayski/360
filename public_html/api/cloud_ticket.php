<?php
/**
 * api/cloud_ticket.php — выдать телефону билет + URL воркера, чтобы он отправил
 * кадры на склейку НАПРЯМУЮ (минуя PHP-прокси и таймаут шлюза хостинга).
 * POST: csrf, tour_id
 */
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/actions.php';
require_once __DIR__ . '/../lib/ticket.php';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Не авторизован'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'Только POST'], 405);
csrf_check($_POST['csrf'] ?? null);

if (WORKER_URL === '') {
    json_out(['ok' => false, 'error' => 'Облачная склейка не настроена (WORKER_URL пуст)', 'worker' => false], 503);
}
$tour_id = (int)($_POST['tour_id'] ?? 0);
if (!own_tour($tour_id, (int)$user['id'])) {
    json_out(['ok' => false, 'error' => 'Тур не найден'], 404);
}

json_out([
    'ok'     => true,
    'worker' => rtrim(WORKER_URL, '/') . '/stitch',
    'ticket' => ticket_issue($tour_id, (int)$user['id']),
]);
