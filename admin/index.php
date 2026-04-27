<?php
require_once __DIR__ . '/../includes/functions.php';
if (!empty($_SESSION['store_id'])) {
    redirect(BASE_URL . '/admin/dashboard.php');
}
redirect(BASE_URL . '/admin/login.php');
