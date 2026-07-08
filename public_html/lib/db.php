<?php
/**
 * db.php — PDO-подключение (MySQL на хостинге, SQLite для локальной разработки)
 * и идемпотентное создание схемы (используется setup.php).
 */
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        if (DB_DRIVER === 'sqlite') {
            $dir = dirname(DB_SQLITE_PATH);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        }
    } catch (PDOException $e) {
        // Понятное сообщение вместо голого 500 (частая причина — неверные креды в config.php).
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h2>Нет подключения к базе данных</h2>';
        echo '<p>Проверь <code>lib/config.php</code>: DB_NAME / DB_USER / DB_PASS / DB_HOST. '
           . 'На cPanel имя базы и пользователя обычно с префиксом аккаунта.</p>';
        echo '<p style="color:#888">Деталь: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        exit;
    }
    return $pdo;
}

/**
 * Идемпотентно создаёт таблицы под текущий драйвер. Вызывается из setup.php.
 * Схема совпадает по колонкам со schema.sql (тот — для импорта через phpMyAdmin).
 */
function ensure_schema(): void
{
    $pdo = db();
    if (DB_DRIVER === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            pass_hash TEXT NOT NULL,
            plan TEXT NOT NULL DEFAULT 'free',
            credits INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tours (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'draft',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS scenes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tour_id INTEGER NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            image_path TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            init_yaw REAL NOT NULL DEFAULT 0,
            init_pitch REAL NOT NULL DEFAULT 0,
            init_fov REAL NOT NULL DEFAULT 1.5708,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS hotspots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tour_id INTEGER NOT NULL,
            from_scene_id INTEGER NOT NULL,
            to_scene_id INTEGER NOT NULL,
            yaw REAL NOT NULL DEFAULT 0,
            pitch REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE,
            FOREIGN KEY (from_scene_id) REFERENCES scenes(id) ON DELETE CASCADE,
            FOREIGN KEY (to_scene_id) REFERENCES scenes(id) ON DELETE CASCADE
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            pass_hash VARCHAR(255) NOT NULL,
            plan VARCHAR(32) NOT NULL DEFAULT 'free',
            credits INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tours (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(64) NOT NULL UNIQUE,
            status ENUM('draft','published') NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_tours_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS scenes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tour_id INT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',
            image_path VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            init_yaw DOUBLE NOT NULL DEFAULT 0,
            init_pitch DOUBLE NOT NULL DEFAULT 0,
            init_fov DOUBLE NOT NULL DEFAULT 1.5708,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_scenes_tour FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS hotspots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tour_id INT UNSIGNED NOT NULL,
            from_scene_id INT UNSIGNED NOT NULL,
            to_scene_id INT UNSIGNED NOT NULL,
            yaw DOUBLE NOT NULL DEFAULT 0,
            pitch DOUBLE NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_hs_tour FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE,
            CONSTRAINT fk_hs_from FOREIGN KEY (from_scene_id) REFERENCES scenes(id) ON DELETE CASCADE,
            CONSTRAINT fk_hs_to FOREIGN KEY (to_scene_id) REFERENCES scenes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
