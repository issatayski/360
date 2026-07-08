<?php
// index.php — точка входа: залогинен → dashboard, иначе → login.
require_once __DIR__ . '/lib/auth.php';
redirect(current_user() ? 'dashboard.php' : 'login.php');
