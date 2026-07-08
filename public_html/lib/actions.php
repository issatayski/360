<?php
/**
 * actions.php — операции над турами и сценами (общий слой для страниц и API).
 * Все функции проверяют владение туром текущим агентом.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Тур, принадлежащий пользователю, или null. */
function own_tour(int $tour_id, int $uid): ?array
{
    $st = db()->prepare('SELECT * FROM tours WHERE id = ? AND user_id = ?');
    $st->execute([$tour_id, $uid]);
    $t = $st->fetch();
    return $t ?: null;
}

/** Список туров пользователя (со счётчиком сцен). */
function list_tours(int $uid): array
{
    $st = db()->prepare(
        'SELECT t.*, (SELECT COUNT(*) FROM scenes s WHERE s.tour_id = t.id) AS scene_count
         FROM tours t WHERE t.user_id = ? ORDER BY t.id DESC'
    );
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** Сцены тура по порядку. */
function tour_scenes(int $tour_id): array
{
    $st = db()->prepare('SELECT * FROM scenes WHERE tour_id = ? ORDER BY sort_order, id');
    $st->execute([$tour_id]);
    return $st->fetchAll();
}

/** Создать черновик тура, вернуть его строку. */
function create_tour(int $uid, string $name): array
{
    $name = trim($name) !== '' ? trim($name) : 'Новый тур';
    $slug = make_slug($name);
    $st = db()->prepare('INSERT INTO tours (user_id, name, slug, status) VALUES (?,?,?,?)');
    $st->execute([$uid, $name, $slug, 'draft']);
    $id = (int)db()->lastInsertId();
    return own_tour($id, $uid);
}

function rename_tour(int $tour_id, int $uid, string $name): bool
{
    if (!own_tour($tour_id, $uid)) return false;
    $name = trim($name);
    if ($name === '') return false;
    $st = db()->prepare('UPDATE tours SET name = ? WHERE id = ? AND user_id = ?');
    return $st->execute([$name, $tour_id, $uid]);
}

function set_tour_status(int $tour_id, int $uid, string $status): bool
{
    if (!in_array($status, ['draft', 'published'], true)) return false;
    if (!own_tour($tour_id, $uid)) return false;
    // публиковать пустой тур нельзя
    if ($status === 'published' && count(tour_scenes($tour_id)) === 0) return false;
    $st = db()->prepare('UPDATE tours SET status = ? WHERE id = ? AND user_id = ?');
    return $st->execute([$status, $tour_id, $uid]);
}

function delete_tour(int $tour_id, int $uid): bool
{
    if (!own_tour($tour_id, $uid)) return false;
    foreach (tour_scenes($tour_id) as $s) {
        $f = __DIR__ . '/../' . $s['image_path'];
        if (is_file($f)) @unlink($f);
    }
    // сцены удалятся каскадом (FK), но подчистим и явно на случай отсутствия FK в SQLite-настройке
    db()->prepare('DELETE FROM scenes WHERE tour_id = ?')->execute([$tour_id]);
    return db()->prepare('DELETE FROM tours WHERE id = ? AND user_id = ?')->execute([$tour_id, $uid]);
}

/** Добавить сцену из уже сохранённого временного файла. Возвращает scene_id. */
function add_scene(int $tour_id, int $uid, string $tmp_path, string $title): int
{
    if (!own_tour($tour_id, $uid)) {
        throw new RuntimeException('Тур не найден');
    }
    $image_path = store_panorama($tmp_path, $tour_id);
    $max = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) AS m FROM scenes WHERE tour_id = ?');
    $max->execute([$tour_id]);
    $order = (int)$max->fetch()['m'] + 1;
    $st = db()->prepare(
        'INSERT INTO scenes (tour_id, title, image_path, sort_order) VALUES (?,?,?,?)'
    );
    $st->execute([$tour_id, trim($title), $image_path, $order]);
    return (int)db()->lastInsertId();
}

/** Сцена вместе с её туром, если тур принадлежит пользователю. */
function own_scene(int $scene_id, int $uid): ?array
{
    $st = db()->prepare(
        'SELECT s.* FROM scenes s JOIN tours t ON t.id = s.tour_id
         WHERE s.id = ? AND t.user_id = ?'
    );
    $st->execute([$scene_id, $uid]);
    $s = $st->fetch();
    return $s ?: null;
}

function delete_scene(int $scene_id, int $uid): bool
{
    $s = own_scene($scene_id, $uid);
    if (!$s) return false;
    $f = __DIR__ . '/../' . $s['image_path'];
    if (is_file($f)) @unlink($f);
    return db()->prepare('DELETE FROM scenes WHERE id = ?')->execute([$scene_id]);
}

/* ---------------- Хотспоты (переходы между сценами, Фаза 1) ---------------- */

/** Нормализация угла в диапазон [-π, π]. */
function wrap_pi(float $a): float
{
    $tau = 2 * M_PI;
    $a = fmod($a + M_PI, $tau);
    if ($a < 0) $a += $tau;
    return $a - M_PI;
}

/** Все переходы тура (для вьюера). */
function tour_hotspots(int $tour_id): array
{
    $st = db()->prepare('SELECT * FROM hotspots WHERE tour_id = ? ORDER BY id');
    $st->execute([$tour_id]);
    return $st->fetchAll();
}

/** Исходящие переходы сцены + название целевой сцены (для редактора). */
function scene_out_hotspots(int $scene_id): array
{
    $st = db()->prepare(
        'SELECT hs.*, s.title AS to_title FROM hotspots hs
         JOIN scenes s ON s.id = hs.to_scene_id
         WHERE hs.from_scene_id = ? ORDER BY hs.id'
    );
    $st->execute([$scene_id]);
    return $st->fetchAll();
}

/** Проверяет, что сцена принадлежит туру. */
function scene_in_tour(int $scene_id, int $tour_id): bool
{
    $st = db()->prepare('SELECT 1 FROM scenes WHERE id = ? AND tour_id = ?');
    $st->execute([$scene_id, $tour_id]);
    return (bool)$st->fetch();
}

/** Есть ли уже переход from→to. */
function hotspot_exists(int $from, int $to): bool
{
    $st = db()->prepare('SELECT 1 FROM hotspots WHERE from_scene_id = ? AND to_scene_id = ?');
    $st->execute([$from, $to]);
    return (bool)$st->fetch();
}

/** Добавить переход from→to и авто-обратный to→from. Возвращает id прямого перехода. */
function add_hotspot(int $tour_id, int $uid, int $from, int $to, float $yaw, float $pitch): int
{
    if (!own_tour($tour_id, $uid)) throw new RuntimeException('Тур не найден');
    if ($from === $to) throw new RuntimeException('Нельзя связать сцену саму с собой');
    if (!scene_in_tour($from, $tour_id) || !scene_in_tour($to, $tour_id)) {
        throw new RuntimeException('Сцена не из этого тура');
    }
    $yaw = wrap_pi($yaw);
    $pitch = max(-M_PI / 2, min(M_PI / 2, $pitch));

    $ins = db()->prepare(
        'INSERT INTO hotspots (tour_id, from_scene_id, to_scene_id, yaw, pitch) VALUES (?,?,?,?,?)'
    );
    if (!hotspot_exists($from, $to)) {
        $ins->execute([$tour_id, $from, $to, $yaw, $pitch]);
    }
    $id = (int)db()->lastInsertId();

    // Авто-обратная стрелка: ставим «за спиной» (yaw+π), pitch 0. Агент может убрать.
    if (!hotspot_exists($to, $from)) {
        $ins->execute([$tour_id, $to, $from, wrap_pi($yaw + M_PI), 0.0]);
    }
    return $id;
}

/** Удалить один переход (проверка владения через тур). */
function delete_hotspot(int $hotspot_id, int $uid): bool
{
    $st = db()->prepare(
        'SELECT hs.id FROM hotspots hs JOIN tours t ON t.id = hs.tour_id
         WHERE hs.id = ? AND t.user_id = ?'
    );
    $st->execute([$hotspot_id, $uid]);
    if (!$st->fetch()) return false;
    return db()->prepare('DELETE FROM hotspots WHERE id = ?')->execute([$hotspot_id]);
}

/** Переставить сцену вверх/вниз в порядке тура. */
function move_scene(int $scene_id, int $uid, string $dir): bool
{
    $s = own_scene($scene_id, $uid);
    if (!$s) return false;
    $scenes = tour_scenes((int)$s['tour_id']);
    $idx = null;
    foreach ($scenes as $i => $sc) if ((int)$sc['id'] === $scene_id) { $idx = $i; break; }
    if ($idx === null) return false;
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($scenes)) return false;
    $a = $scenes[$idx]; $b = $scenes[$swap];
    $u = db()->prepare('UPDATE scenes SET sort_order = ? WHERE id = ?');
    $u->execute([(int)$b['sort_order'], (int)$a['id']]);
    $u->execute([(int)$a['sort_order'], (int)$b['id']]);
    return true;
}
