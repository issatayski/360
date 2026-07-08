<?php
// logout.php — выход.
require_once __DIR__ . '/lib/auth.php';
logout();
redirect('login.php');
