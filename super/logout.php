<?php
require_once __DIR__ . '/../includes/functions.php';
unset($_SESSION['admin_id']);
redirect(BASE_URL . '/super/login.php');
