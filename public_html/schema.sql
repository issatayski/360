-- schema.sql — MySQL/MariaDB DDL для Фазы 0.
-- Способ 1 (рекомендуется на cPanel): открой setup.php в браузере — он создаст
--   таблицы и заведёт seed-агента с уже захешированным паролем.
-- Способ 2: импортируй этот файл через phpMyAdmin, затем открой setup.php,
--   чтобы создать агента (пароль хешируется в рантайме, здесь его нет намеренно).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL UNIQUE,
    pass_hash  VARCHAR(255) NOT NULL,
    plan       VARCHAR(32)  NOT NULL DEFAULT 'free',   -- заготовка под подписку
    credits    INT          NOT NULL DEFAULT 0,        -- заготовка под кредиты
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tours (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(190) NOT NULL,
    slug       VARCHAR(64)  NOT NULL UNIQUE,           -- публичная ссылка view.php?slug=
    status     ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tours_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scenes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tour_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(190) NOT NULL DEFAULT '',
    image_path VARCHAR(255) NOT NULL,                  -- напр. uploads/tXX_abcd.jpg
    sort_order INT          NOT NULL DEFAULT 0,
    init_yaw   DOUBLE       NOT NULL DEFAULT 0,         -- радианы
    init_pitch DOUBLE       NOT NULL DEFAULT 0,         -- радианы
    init_fov   DOUBLE       NOT NULL DEFAULT 1.5708,    -- радианы (~90°)
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_scenes_tour FOREIGN KEY (tour_id) REFERENCES tours(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Переходы-стрелки между сценами (Фаза 1)
CREATE TABLE IF NOT EXISTS hotspots (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tour_id       INT UNSIGNED NOT NULL,
    from_scene_id INT UNSIGNED NOT NULL,
    to_scene_id   INT UNSIGNED NOT NULL,
    yaw           DOUBLE       NOT NULL DEFAULT 0,      -- радианы, позиция стрелки на панораме
    pitch         DOUBLE       NOT NULL DEFAULT 0,      -- радианы
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hs_tour  FOREIGN KEY (tour_id)       REFERENCES tours(id)  ON DELETE CASCADE,
    CONSTRAINT fk_hs_from  FOREIGN KEY (from_scene_id) REFERENCES scenes(id) ON DELETE CASCADE,
    CONSTRAINT fk_hs_to    FOREIGN KEY (to_scene_id)   REFERENCES scenes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
