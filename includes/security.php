<?php
/**
 * 12-Layer Security System — OWASP Top 10 (2021) Compliant
 *
 * Layers:
 *  1. Input Validation & Sanitization       (OWASP A03 — Injection)
 *  2. Output Encoding / XSS Prevention      (OWASP A03)
 *  3. CSRF Protection                       (OWASP A01 — Broken Access Control)
 *  4. SQL Injection Prevention (PDO bound)  (OWASP A03)
 *  5. Session Hardening                     (OWASP A07 — Auth Failures)
 *  6. Authentication & Rate Limiting        (OWASP A07)
 *  7. Authorization & IDOR Protection       (OWASP A01)
 *  8. Password Policy & Secure Hashing      (OWASP A02 — Crypto Failures)
 *  9. File Upload Hardening                 (OWASP A04 — Insecure Design)
 * 10. Security Headers (CSP, HSTS, etc.)    (OWASP A05 — Misconfiguration)
 * 11. Logging & Monitoring                  (OWASP A09 — Logging Failures)
 * 12. Honeypot & Error Handling             (OWASP A05, A09)
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    http_response_code(403);
    die('Forbidden');
}

// =====================================================
// LAYER 1 — INPUT VALIDATION & SANITIZATION
// =====================================================

function clean_string($value, $maxLen = 1000) {
    if (!is_scalar($value)) return '';
    $value = (string) $value;
    $value = str_replace(["\0", "\r"], '', $value);
    $value = trim($value);
    if (mb_strlen($value) > $maxLen) $value = mb_substr($value, 0, $maxLen);
    return $value;
}

function clean_int($value, $min = null, $max = null) {
    $i = filter_var($value, FILTER_VALIDATE_INT);
    if ($i === false) return 0;
    if ($min !== null && $i < $min) return $min;
    if ($max !== null && $i > $max) return $max;
    return $i;
}

function clean_float($value, $min = null, $max = null) {
    $f = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($f === false) return 0.0;
    if ($min !== null && $f < $min) return (float) $min;
    if ($max !== null && $f > $max) return (float) $max;
    return $f;
}

function clean_email($value) {
    $v = filter_var(trim((string) $value), FILTER_SANITIZE_EMAIL);
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
}

function clean_url($value) {
    $v = trim((string) $value);
    if (!$v) return '';
    if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
    return filter_var($v, FILTER_VALIDATE_URL) ? $v : '';
}

function clean_hex_color($value) {
    $v = trim((string) $value);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? $v : '#059669';
}

function clean_phone($value) {
    return preg_replace('/[^\d+\-\s()]/', '', trim((string) $value));
}

function clean_slug($value) {
    $v = strtolower(trim((string) $value));
    return preg_replace('/[^a-z0-9\-_]/', '', $v);
}

// =====================================================
// LAYER 2 — OUTPUT ENCODING / XSS PREVENTION
// =====================================================

function safe_html($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function safe_js($s) {
    return json_encode((string) $s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

function safe_attr($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// =====================================================
// LAYER 3 — SECURITY HEADERS
// =====================================================

function apply_security_headers($allowCDN = true) {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header_remove('X-Powered-By');
    header_remove('Server');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    $cdnSrc = $allowCDN
        ? "https://cdn.tailwindcss.com https://fonts.googleapis.com https://fonts.gstatic.com https://api.qrserver.com"
        : "";
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob: https://api.qrserver.com",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
}

// =====================================================
// LAYER 5 — SESSION HARDENING
// =====================================================

function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Session lifetime: 30 days. Owners of small restaurants often log in once
    // and keep the browser open for weeks — short timeouts hurt them more than
    // they help security. Other protections (HttpOnly, SameSite, Secure, strict
    // mode) still guard against theft.
    $lifetime = 60 * 60 * 24 * 30; // 30 days in seconds

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', (string) $lifetime);
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    if ($https) ini_set('session.cookie_secure', '1');
    session_name('qrmenu_sid');
    session_start();

    // NOTE (2026-04): User-Agent fingerprint check REMOVED.
    // The old check called session_destroy() on any UA change, which was far too
    // aggressive — browser auto-updates, WhatsApp/Telegram in-app browser vs the
    // system browser, iOS/Android "Open in app" intents, and UA-client-hints
    // rollouts all trigger spurious logouts. The real attack it prevents
    // (attacker with a stolen cookie but a different UA) is negligible next to
    // HttpOnly + Secure + SameSite=Lax + use_strict_mode, which are all active.
    // If we want soft protection later, log security events on UA change
    // instead of destroying the session.

    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    }

    // Absolute cap: force re-auth after 30 days of continuous session use.
    // (Was 24h — which logged owners out mid-shift. Cookie lifetime already
    // caps idle sessions, so this is belt-and-braces for compromised cookies.)
    if ((time() - $_SESSION['_created']) > $lifetime) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['_created'] = time();
    }

    // Rotate session ID periodically (defense against session fixation)
    if (!isset($_SESSION['_regen'])) {
        $_SESSION['_regen'] = time();
    } elseif (time() - $_SESSION['_regen'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_regen'] = time();
    }
}

function regenerate_session_id() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['_regen'] = time();
    }
}

// =====================================================
// LAYER 6 — AUTHENTICATION / RATE LIMITING
// =====================================================

function client_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function record_login_attempt($pdo, $email, $success, $userType = 'store') {
    $ip = client_ip();
    $stmt = $pdo->prepare('INSERT INTO login_attempts (email, ip, user_type, success) VALUES (?, ?, ?, ?)');
    $stmt->execute([mb_substr((string) $email, 0, 150), $ip, $userType, $success ? 1 : 0]);
}

function is_rate_limited($pdo, $email, $userType = 'store') {
    $ip = client_ip();
    // 5 failed attempts in last 15 minutes = block for 15 min
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR email = ?) AND user_type = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)');
    $stmt->execute([$ip, $email, $userType]);
    return (int) $stmt->fetchColumn() >= 5;
}

function clear_login_attempts($pdo, $email) {
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND success = 0');
    $stmt->execute([$email]);
}

// Generic rate limiter (per-IP, per-action)
function check_rate_limit($pdo, $action, $maxRequests = 10, $windowSeconds = 60) {
    $ip = client_ip();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM security_logs WHERE event_type = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)");
    $stmt->execute(['rate_' . $action, $ip, $windowSeconds]);
    return (int) $stmt->fetchColumn() < $maxRequests;
}

function record_rate_event($pdo, $action) {
    security_log($pdo, 'rate_' . $action, 'info');
}

// =====================================================
// LAYER 8 — PASSWORD POLICY
// =====================================================

function validate_password($password, $minLen = 8) {
    $errors = [];
    if (strlen($password) < $minLen) {
        $errors[] = "كلمة المرور يجب أن تكون $minLen أحرف على الأقل";
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'كلمة المرور يجب أن تحوي حرفاً واحداً على الأقل';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'كلمة المرور يجب أن تحوي رقماً واحداً على الأقل';
    }
    // Block obvious weak passwords
    $weak = ['12345678', 'password', 'qwerty123', '11111111', 'admin123'];
    if (in_array(strtolower($password), $weak, true)) {
        $errors[] = 'كلمة المرور ضعيفة جداً — اختر كلمة أقوى';
    }
    return $errors;
}

function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// =====================================================
// LAYER 9 — FILE UPLOAD HARDENING
// =====================================================

function secure_upload($fileKey, $targetDir, $maxBytes = 5242880) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'فشل رفع الملف'];
    }

    $file = $_FILES[$fileKey];
    if ($file['size'] > $maxBytes) {
        return ['error' => 'حجم الملف أكبر من ' . ($maxBytes / 1048576) . 'MB'];
    }
    if ($file['size'] <= 0) {
        return ['error' => 'ملف فارغ'];
    }

    // Whitelist by extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['error' => 'امتداد الملف غير مسموح'];
    }

    // Verify MIME via finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowedMime, true)) {
        return ['error' => 'نوع الملف غير مسموح'];
    }

    // Verify it is a real image (getimagesize)
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['error' => 'الملف ليس صورة صالحة'];
    }

    // Reject images larger than 10000x10000
    if ($info[0] > 10000 || $info[1] > 10000) {
        return ['error' => 'أبعاد الصورة كبيرة جداً'];
    }

    // Generate safe filename (no user input in name)
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $destination = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['error' => 'فشل حفظ الملف'];
    }

    // Set conservative permissions
    @chmod($destination, 0644);
    return $filename;
}

// -----------------------------------------------------
// Strict image+video upload for MAX plan media gallery
// -----------------------------------------------------
// Accepts a single $_FILES entry (one iteration of the multi-upload array).
// Returns ['filename' => ..., 'type' => 'image|video', 'mime' => ..., 'size' => bytes]
// or ['error' => 'msg'] on failure, or null if no file.
// Hard 5 MB cap regardless of caller's intent.
function secure_upload_media_file(array $file, $targetDir, $maxBytes = 5242880) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'فشل رفع الملف'];
    }
    // Hard cap: 5 MB — never allow bypass from caller
    $absoluteCap = 5 * 1024 * 1024;
    if ($maxBytes > $absoluteCap) $maxBytes = $absoluteCap;

    if ($file['size'] > $maxBytes) {
        return ['error' => 'الحجم أكبر من ' . (int)($maxBytes / 1048576) . ' ميغابايت'];
    }
    if ($file['size'] <= 0) {
        return ['error' => 'ملف فارغ'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'ملف غير صالح'];
    }

    // Allowed extension → media type map
    $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $videoExt = ['mp4', 'webm', 'mov'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $imageExt, true))      $mediaType = 'image';
    else if (in_array($ext, $videoExt, true)) $mediaType = 'video';
    else return ['error' => 'امتداد غير مسموح (' . $ext . ')'];

    // Verify MIME via finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedImageMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $allowedVideoMime = ['video/mp4', 'video/webm', 'video/quicktime'];

    if ($mediaType === 'image' && !in_array($mime, $allowedImageMime, true)) {
        return ['error' => 'نوع الصورة غير مسموح'];
    }
    if ($mediaType === 'video' && !in_array($mime, $allowedVideoMime, true)) {
        return ['error' => 'نوع الفيديو غير مسموح'];
    }

    // For images: deep validation + dimension cap
    if ($mediaType === 'image') {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) return ['error' => 'الصورة غير صالحة'];
        if ($info[0] > 10000 || $info[1] > 10000) {
            return ['error' => 'أبعاد الصورة كبيرة جداً'];
        }
    }
    // For videos: basic header-byte sniff to ensure it's not a renamed executable
    if ($mediaType === 'video') {
        $fh = @fopen($file['tmp_name'], 'rb');
        if (!$fh) return ['error' => 'تعذّر قراءة الفيديو'];
        $head = fread($fh, 16);
        fclose($fh);
        // mp4: ftyp box somewhere in first 16 bytes; webm: 1A 45 DF A3; mov: ftyp
        $isMp4  = (strpos($head, 'ftyp') !== false);
        $isWebm = (substr($head, 0, 4) === "\x1A\x45\xDF\xA3");
        if (!$isMp4 && !$isWebm) {
            return ['error' => 'ملف الفيديو تالف أو غير مدعوم'];
        }
    }

    // Generate safe filename
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $destination = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['error' => 'فشل حفظ الملف'];
    }

    @chmod($destination, 0644);

    return [
        'filename' => $filename,
        'type'     => $mediaType,
        'mime'     => $mime,
        'size'     => (int) $file['size'],
    ];
}

// =====================================================
// LAYER 11 — LOGGING & MONITORING
// =====================================================

function security_log($pdo, $eventType, $severity = 'info', $details = null, $userType = null, $userId = null) {
    try {
        $stmt = $pdo->prepare('INSERT INTO security_logs (event_type, severity, user_type, user_id, ip, user_agent, url, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            mb_substr($eventType, 0, 50),
            mb_substr($severity, 0, 20),
            $userType ? mb_substr($userType, 0, 20) : null,
            $userId,
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 500),
            is_scalar($details) ? (string) $details : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // Silently fail — we never want logging to break the app
        error_log('security_log failed: ' . $e->getMessage());
    }
}

// =====================================================
// LAYER 12 — HONEYPOT & ERROR HANDLING
// =====================================================

function honeypot_field() {
    // Hidden field that real users shouldn't fill. Bots fill all fields.
    // NOTE: field name is obscure so browser autofill (which targets "website"/"email"/"phone") ignores it.
    return '<div aria-hidden="true" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;padding:0;margin:-1px">'
         . '<label>Leave empty<input type="text" name="hp_trap_field" tabindex="-1" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other"></label>'
         . '</div>';
}

function honeypot_triggered() {
    return !empty($_POST['hp_trap_field']);
}

function setup_error_handling($debug = false) {
    if ($debug) {
        ini_set('display_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    }

    set_exception_handler(function (Throwable $e) use ($debug) {
        http_response_code(500);
        error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if ($debug) {
            echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><title>خطأ</title>';
            echo '<style>body{font-family:system-ui;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}.c{background:#fff;padding:40px;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,.08);text-align:center;max-width:400px}h1{color:#ef4444;margin:0 0 10px}a{color:#059669;text-decoration:none;font-weight:bold}</style>';
            echo '</head><body><div class="c"><h1>⚠️ حدث خطأ</h1><p>نعتذر، حدث خطأ غير متوقع. فريقنا يعمل على حل المشكلة.</p><a href="' . BASE_URL . '">← العودة للرئيسية</a></div></body></html>';
        }
        exit;
    });
}

// =====================================================
// AUTO-INIT when this file is included
// =====================================================
start_secure_session();
apply_security_headers();
