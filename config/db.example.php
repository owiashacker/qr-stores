<?php
/**
 * نموذج لإعدادات قاعدة البيانات.
 *
 * كيفية الاستخدام:
 * 1. انسخ هذا الملف إلى config/db.php
 * 2. عدّل القيم لتطابق بيئتك (محلية أو إنتاجية)
 * 3. db.php مستثنى من Git — لن يُرفع
 *
 * Production (cPanel):
 *   DB_HOST = 'localhost'
 *   DB_NAME = 'qrstores_qr_stores'        (cpanel_user_dbname)
 *   DB_USER = 'qrstores_subaldua'         (cpanel_user_dbuser)
 *   DB_PASS = '...'                       (من cPanel → MySQL Databases)
 *
 * Local (Ampps):
 *   DB_HOST = 'localhost'
 *   DB_NAME = 'qr_stores'
 *   DB_USER = 'root'
 *   DB_PASS = ''                          (افتراضي Ampps)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASS');

date_default_timezone_set('Asia/Damascus');

if (!defined('BASE_URL')) {
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {
        define('BASE_URL', rtrim(substr($appRoot, strlen($docRoot)), '/'));
    } else {
        define('BASE_URL', '/qr_stores');
    }
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException) {
    die('خطأ في الاتصال بقاعدة البيانات');
}
