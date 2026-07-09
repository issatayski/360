<?php
/**
 * ticket.php — короткоживущий HMAC-билет, которым PHP разрешает телефону
 * обратиться к воркеру напрямую (и потом сохранить результат). Подпись —
 * общим секретом WORKER_TOKEN (тем же, что в env воркера).
 */
require_once __DIR__ . '/config.php';

function _b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function _b64url_decode(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/'));
}

/** Выдать билет на тур для пользователя (по умолчанию на 20 минут). */
function ticket_issue(int $tour_id, int $uid, int $ttl = 1200): string
{
    $payload = _b64url(json_encode(['tour_id' => $tour_id, 'uid' => $uid, 'exp' => time() + $ttl]));
    $sig = _b64url(hash_hmac('sha256', $payload, WORKER_TOKEN, true));
    return $payload . '.' . $sig;
}

/** Проверить билет; вернуть данные (tour_id,uid,exp) или null. */
function ticket_verify(string $ticket): ?array
{
    if (strpos($ticket, '.') === false) return null;
    [$payload, $sig] = explode('.', $ticket, 2);
    $calc = _b64url(hash_hmac('sha256', $payload, WORKER_TOKEN, true));
    if (!hash_equals($calc, $sig)) return null;
    $data = json_decode(_b64url_decode($payload), true);
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) return null;
    return $data;
}
