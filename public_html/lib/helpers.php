<?php
/**
 * helpers.php — общие утилиты: экранирование, JSON-ответы, редиректы, CSRF,
 * slug и валидация загружаемой панорамы.
 */
require_once __DIR__ . '/config.php';

/** HTML-экранирование для вывода. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON-ответ и выход. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Редирект и выход. */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** Базовый URL приложения (scheme://host + путь к папке скрипта), без хвостового /. */
function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/** CSRF-токен текущей сессии (создаётся при первом обращении). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Проверка CSRF-токена из запроса; при провале — 403. */
function csrf_check(?string $token): void
{
    if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        json_out(['ok' => false, 'error' => 'Неверный CSRF-токен'], 403);
    }
}

/** Уникальный slug для тура (латиница/цифры + короткий суффикс). */
function make_slug(string $name): string
{
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') $base = 'tour';
    if (strlen($base) > 40) $base = substr($base, 0, 40);
    return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
}

/**
 * Валидация и сохранение загруженной equirect-панорамы.
 * Принимает путь к временному файлу (из $_FILES или php://input, уже сохранённого).
 * Возвращает публичный путь image_path (например uploads/xxxx.jpg) либо кидает Exception.
 */
function store_panorama(string $tmp_path, int $tour_id): string
{
    $size = @filesize($tmp_path);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('Пустой файл');
    }
    if ($size > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Файл больше ' . round(MAX_UPLOAD_BYTES / 1048576) . ' МБ');
    }

    $info = @getimagesize($tmp_path);
    if ($info === false) {
        throw new RuntimeException('Это не изображение');
    }
    [$w, $h] = $info;
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ALLOWED_MIME, true)) {
        throw new RuntimeException('Разрешены только JPEG/PNG');
    }
    if ($w < MIN_PANO_WIDTH) {
        throw new RuntimeException('Панорама слишком маленькая (мин. ширина ' . MIN_PANO_WIDTH . 'px)');
    }
    // equirect должен быть примерно 2:1
    $ratio = $w / max(1, $h);
    if (abs($ratio - 2.0) > 2.0 * ASPECT_TOLERANCE) {
        throw new RuntimeException('Панорама должна быть 2:1 (equirectangular). Сейчас ' . $w . '×' . $h);
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true)) {
        throw new RuntimeException('Нет папки uploads/ (создать с правами на запись)');
    }

    $ext = ($mime === 'image/png') ? 'png' : 'jpg';
    $name = 't' . $tour_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = UPLOAD_DIR . '/' . $name;

    // Загруженные файлы переносим через move_uploaded_file (не обходим проверку
    // источника). Прочие (CLI/тесты) — обычным rename/copy.
    $moved = is_uploaded_file($tmp_path)
        ? @move_uploaded_file($tmp_path, $dest)
        : (@rename($tmp_path, $dest) || @copy($tmp_path, $dest));
    if (!$moved) {
        throw new RuntimeException('Не удалось сохранить файл');
    }
    @chmod($dest, 0644);
    return UPLOAD_URL . '/' . $name;
}
