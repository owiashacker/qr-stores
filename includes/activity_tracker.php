<?php
/**
 * Activity Tracker — logs every PHP request to `activity_logs`.
 *
 * Hooks into the request lifecycle via register_shutdown_function so the
 * HTTP response code, duration, and final context are all known at log time.
 *
 * Captures:
 *   - method + URL + referrer
 *   - HTTP response status (200, 301, 404, 500, ...)
 *   - user (admin / restaurant / visitor) + id
 *   - IP, user agent
 *   - request duration in ms
 *   - POST body size + event type (auto-inferred or set manually via log_activity_event)
 *
 * Skip list: assets (served by Apache, not PHP), favicon, the activity page
 * itself (avoid feedback loops), and any URL marked by activity_skip().
 *
 * Manual annotation: call log_activity_event('upload', ['file' => 'pic.jpg'])
 * from inside your controller to set a specific event_type + details.
 *
 * The whole thing fails silently if the DB is unreachable — tracking must
 * NEVER break the app.
 */

$GLOBALS['__activity_pdo'] = null;
$GLOBALS['__activity_event'] = null;      // optional manual event_type
$GLOBALS['__activity_details'] = null;    // optional manual details array
$GLOBALS['__activity_skip'] = false;      // skip this request entirely
$GLOBALS['__activity_start'] = null;

function activityTrackerInit($pdo)
{
    $GLOBALS['__activity_pdo'] = $pdo;
    $GLOBALS['__activity_start'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);

    // Register shutdown LAST so it runs after output (and after the error
    // handler's shutdown, so we can record the final HTTP status).
    register_shutdown_function('activityTrackerRecord');
}

/**
 * Set the event type + optional details for the current request.
 * Call this from any controller before exit/redirect.
 *
 *   log_activity_event('login_success');
 *   log_activity_event('upload', ['file' => $filename, 'size' => $bytes]);
 */
function log_activity_event($eventType, array $details = null)
{
    $GLOBALS['__activity_event'] = $eventType;
    if ($details !== null) $GLOBALS['__activity_details'] = $details;
}

/**
 * Mark this request so it is NOT logged. Use for health checks / pings.
 */
function activity_skip()
{
    $GLOBALS['__activity_skip'] = true;
}

/**
 * Shutdown handler — records the request to activity_logs.
 */
function activityTrackerRecord()
{
    if ($GLOBALS['__activity_skip']) return;

    $pdo = $GLOBALS['__activity_pdo'] ?? null;
    if (!$pdo) return;

    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    if ($method === 'CLI') return; // skip command-line scripts

    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // Skip self to avoid feedback loop (super/activity.php paginating blows up)
    if (strpos($uri, '/super/activity.php') !== false) return;

    // Skip static/asset-like URLs even if routed through PHP
    $pathOnly = strtok($uri, '?');
    $skipExt = ['.ico', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.css', '.js', '.woff', '.woff2', '.ttf', '.map'];
    foreach ($skipExt as $ext) {
        if (str_ends_with(strtolower($pathOnly), $ext)) return;
    }

    try {
        // Build the full URL
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $fullUrl = $host ? ($scheme . '://' . $host . $uri) : $uri;

        // Identify the user
        $userType = 'visitor';
        $userId = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['admin_id'])) {
                $userType = 'admin';
                $userId = (int) $_SESSION['admin_id'];
            } elseif (!empty($_SESSION['store_id'])) {
                $userType = 'store';
                $userId = (int) $_SESSION['store_id'];
            }
        }

        // IP (trusts X-Forwarded-For only if you run behind a proxy)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
        }

        // HTTP status — at shutdown, http_response_code() returns what we set
        $status = (int) http_response_code();
        if ($status <= 0) $status = 200;

        // Duration
        $start = $GLOBALS['__activity_start'] ?? microtime(true);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // Auto-infer event type (unless explicitly set via log_activity_event)
        $eventType = $GLOBALS['__activity_event'] ?? activityInferEventType($method, $pathOnly, $status);

        // Build details
        $details = $GLOBALS['__activity_details'] ?? [];
        if (!empty($_FILES)) {
            $fileInfo = [];
            foreach ($_FILES as $k => $f) {
                if (is_array($f['name'] ?? null)) {
                    $fileInfo[$k] = ['count' => count($f['name']), 'total_bytes' => array_sum(array_map('intval', $f['size'] ?? []))];
                } else {
                    $fileInfo[$k] = ['name' => $f['name'] ?? '', 'size' => (int) ($f['size'] ?? 0), 'error' => (int) ($f['error'] ?? 0)];
                }
            }
            $details['files'] = $fileInfo;
        }
        if ($method !== 'GET' && !empty($_POST)) {
            $details['post_keys'] = array_keys($_POST);
        }
        if ($status >= 400) {
            // For error statuses, capture a bit more context
            $details['status_class'] = $status >= 500 ? 'server_error' : 'client_error';
        }

        $postSize = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        $stmt = $pdo->prepare('INSERT INTO activity_logs
            (event_type, user_type, user_id, method, url, referrer,
             http_status, ip, user_agent, duration_ms, post_size, details)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            mb_substr($eventType, 0, 50),
            $userType,
            $userId,
            mb_substr($method, 0, 10),
            mb_substr($fullUrl, 0, 1000),
            mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000) ?: null,
            $status,
            $ip,
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            $durationMs,
            $postSize,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
        ]);
    } catch (Throwable $e) {
        // Never break the app — tracking is best-effort
        error_log('[activity_tracker] ' . $e->getMessage());
    }
}

/**
 * Infer the event_type from request signals when caller didn't set one.
 */
function activityInferEventType($method, $path, $status)
{
    $path = strtolower($path);

    // Error statuses first
    if ($status === 404) return 'not_found';
    if ($status === 403) return 'forbidden';
    if ($status === 500 || $status === 503) return 'server_error';
    if ($status === 401) return 'auth_required';
    if ($status === 301 || $status === 302) return 'redirect';

    // Upload request
    if ($method === 'POST' && !empty($_FILES)) return 'upload';

    // Login/logout pattern
    if (strpos($path, 'login.php') !== false) {
        return $method === 'POST' ? 'login_attempt' : 'login_page';
    }
    if (strpos($path, 'logout.php') !== false) return 'logout';
    if (strpos($path, 'register.php') !== false) {
        return $method === 'POST' ? 'register_attempt' : 'register_page';
    }

    // Form submission (non-upload POST)
    if ($method === 'POST') return 'form_submit';

    // Admin area vs. public
    if (strpos($path, '/super/') !== false) return 'super_view';
    if (strpos($path, '/admin/') !== false) return 'admin_view';
    if (strpos($path, '/public/') !== false || strpos($path, '/menu') !== false) return 'menu_view';

    return 'page_view';
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        if ($needle === '' || $needle === null) return true;
        $nl = strlen($needle);
        return $nl <= strlen($haystack) && substr($haystack, -$nl) === $needle;
    }
}
