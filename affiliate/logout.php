<?php
require_once __DIR__ . '/../includes/functions.php';

unset($_SESSION['affiliate_id']);
session_regenerate_id(true);
redirect(BASE_URL . '/affiliate/login.php');
