<?php
/**
 * config.php — единственное место с настройками окружения.
 *
 * На shared-хостинге (cPanel): впиши сюда креды MySQL (DB_* ниже) и, при желании,
 * смени SEED_* до первого запуска setup.php. Идеально — вынести этот файл выше
 * webroot и подключать по абсолютному пути; на старте достаточно защиты .htaccess.
 *
 * Для локальной разработки без MySQL: поставь DB_DRIVER = 'sqlite' — база ляжет
 * в файл data/app.sqlite, всё заработает на встроенном сервере `php -S`.
 */

// ---- База данных -----------------------------------------------------------
// 'mysql' на хостинге, 'sqlite' для локального прогона без MySQL.
const DB_DRIVER = 'mysql';

// MySQL (заполнить на хостинге из cPanel → MySQL Databases)
const DB_HOST = 'localhost';
const DB_NAME = 'CHANGE_ME_dbname';
const DB_USER = 'CHANGE_ME_dbuser';
const DB_PASS = 'CHANGE_ME_dbpass';
const DB_CHARSET = 'utf8mb4';

// SQLite (используется только когда DB_DRIVER = 'sqlite')
const DB_SQLITE_PATH = __DIR__ . '/../data/app.sqlite';

// ---- Seed-агент (создаётся один раз через setup.php) -----------------------
// ПОМЕНЯЙ пароль до запуска setup.php или сразу после первого входа.
const SEED_EMAIL = 'agent@example.com';
const SEED_PASSWORD = 'changeme';

// ---- Приложение ------------------------------------------------------------
// Абсолютная папка с загрузками (панорамы). Должна быть доступна на запись.
const UPLOAD_DIR = __DIR__ . '/../uploads';
// Публичный префикс пути к загрузкам (как отдаёт веб-сервер).
const UPLOAD_URL = 'uploads';

// Лимиты загрузки панорамы.
const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;         // 25 МБ
const ALLOWED_MIME = ['image/jpeg', 'image/png'];
const MIN_PANO_WIDTH = 1000;                        // минимальная ширина equirect
// Допуск на соотношение сторон 2:1 (equirect). 0.15 = ±15%.
const ASPECT_TOLERANCE = 0.15;

// Имя cookie-сессии.
const SESSION_NAME = 'tour360_sess';
// Своя папка для файлов сессий (дефолтный save_path на shared-хостинге бывает
// недоступен на запись → сессия не сохраняется → «Неверный CSRF-токен»).
const SESSION_SAVE_PATH = __DIR__ . '/../data/sessions';
