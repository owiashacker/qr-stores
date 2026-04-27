<?php

/**
 * Centralized error logging system.
 *
 * Every uncaught exception, fatal error, or PHP warning is captured here,
 * given a unique short code (e.g. "E-A1B2C3"), and written to `error_logs`.
 *
 * Deduplication: same error type+file+line+first-line-of-message maps to
 * the same row (via MD5 hash). Repeat occurrences just bump `count` and
 * `last_seen_at` — keeps the table small and highlights recurring issues.
 *
 * The user sees a friendly Arabic page showing only the short code and a
 * "contact support" CTA. Super Admin sees the full detail in super/errors.php.
 *
 * Bootstrap: call errorLogInit($pdo) once from includes/functions.php after
 * the PDO connection is established.
 */

// Holds the global PDO handle for handlers (which run outside function scope)
$GLOBALS['__error_log_pdo'] = null;
$GLOBALS['__error_log_active'] = false;

/**
 * Register all handlers. Call once from functions.php.
 */
function errorLogInit($pdo)
{
    $GLOBALS['__error_log_pdo'] = $pdo;
    $GLOBALS['__error_log_active'] = true;

    // Baseline php.ini config — never expose errors to the visitor
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT);

    set_exception_handler('errorLogHandleException');
    set_error_handler('errorLogHandleError');
    register_shutdown_function('errorLogHandleShutdown');
}

/**
 * Handle uncaught exceptions (including PDOException, TypeError, etc.)
 */
function errorLogHandleException($e)
{
    if (!($e instanceof Throwable)) return;

    $type = get_class($e);
    $severity = errorLogMapExceptionSeverity($e);

    $data = [
        'type' => $type,
        'severity' => $severity,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => (int) $e->getLine(),
        'stack_trace' => $e->getTraceAsString(),
    ];

    $code = errorLogStore($data);

    http_response_code(500);
    errorLogRenderUserPage($code, $data);
    exit;
}

/**
 * Handle PHP errors (warnings, notices, user errors).
 * Return false lets PHP continue its default handling if we can't log.
 */
function errorLogHandleError($errno, $errstr, $errfile = '', $errline = 0)
{
    // Respect @ suppression and error_reporting level
    if (!(error_reporting() & $errno)) return false;

    $typeMap = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];
    $type = $typeMap[$errno] ?? 'E_UNKNOWN';
    $severity = errorLogMapErrnoSeverity($errno);

    $trace = '';
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
    foreach ($backtrace as $i => $f) {
        $trace .= "#$i " . ($f['file'] ?? '?') . '(' . ($f['line'] ?? '?') . '): '
            . (isset($f['class']) ? $f['class'] . '->' : '') . ($f['function'] ?? '?') . "()\n";
    }

    $data = [
        'type' => $type,
        'severity' => $severity,
        'message' => $errstr,
        'file' => $errfile,
        'line' => (int) $errline,
        'stack_trace' => $trace,
    ];

    errorLogStore($data);

    // Warnings/notices should NOT halt the script — only fatals do.
    // Return true tells PHP we handled it; default error printing is skipped.
    return true;
}

/**
 * Shutdown handler — catches fatal errors (E_ERROR, E_PARSE) that bypass
 * set_error_handler because they're unrecoverable.
 */
function errorLogHandleShutdown()
{
    $e = error_get_last();
    if (!$e) return;

    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($e['type'], $fatal, true)) return;

    $typeMap = [
        E_ERROR => 'E_ERROR',
        E_PARSE => 'E_PARSE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_USER_ERROR => 'E_USER_ERROR',
    ];

    $data = [
        'type' => $typeMap[$e['type']] ?? 'FATAL',
        'severity' => 'critical',
        'message' => $e['message'],
        'file' => $e['file'],
        'line' => (int) $e['line'],
        'stack_trace' => '(fatal errors have no trace available)',
    ];

    $code = errorLogStore($data);

    // If headers not sent yet, render the user-facing page
    if (!headers_sent()) {
        http_response_code(500);
        errorLogRenderUserPage($code, $data);
    }
}

/**
 * Insert or update the error_logs row and return its short code.
 * Fails silently if DB is unreachable — returns a fallback code.
 */
function errorLogStore(array $data)
{
    $pdo = $GLOBALS['__error_log_pdo'] ?? null;

    // Build dedup hash: same type+file+line+first-line-of-message = same bug
    $msgFirstLine = strtok((string) ($data['message'] ?? ''), "\n");
    $hashInput = ($data['type'] ?? '') . '|' . ($data['file'] ?? '') . '|' . ($data['line'] ?? 0) . '|' . $msgFirstLine;
    $hash = md5($hashInput);
    $code = 'E-' . strtoupper(substr($hash, 0, 6));

    if (!$pdo) {
        error_log("[error_handler] DB unavailable — $code: " . ($data['message'] ?? ''));
        return $code;
    }

    try {
        // Dedup: if hash already exists, bump count and refresh last_seen
        $stmt = $pdo->prepare('SELECT id FROM error_logs WHERE hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $pdo->prepare('UPDATE error_logs SET count = count + 1, last_seen_at = CURRENT_TIMESTAMP, status = IF(status = "resolved", "new", status) WHERE id = ?')
                ->execute([$existing]);
            return $code;
        }

        // New error — insert full row
        $ctx = errorLogBuildContext();
        $stmt = $pdo->prepare('INSERT INTO error_logs
            (code, hash, type, severity, message, file, line, stack_trace,
             url, method, ip, user_agent, user_type, user_id, context, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new")');
        $stmt->execute([
            $code,
            $hash,
            mb_substr((string) ($data['type'] ?? 'unknown'), 0, 50),
            $data['severity'] ?? 'medium',
            mb_substr((string) ($data['message'] ?? ''), 0, 65000),
            mb_substr((string) ($data['file'] ?? ''), 0, 500),
            (int) ($data['line'] ?? 0),
            mb_substr((string) ($data['stack_trace'] ?? ''), 0, 16000000),
            mb_substr($ctx['url'], 0, 1000),
            $ctx['method'],
            $ctx['ip'],
            mb_substr($ctx['user_agent'], 0, 500),
            $ctx['user_type'],
            $ctx['user_id'],
            json_encode($ctx['payload'], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    } catch (Throwable $logErr) {
        // Never let logging break the app. Fallback: PHP error log.
        error_log("[error_handler] failed to write log ($code): " . $logErr->getMessage());
        error_log("[error_handler] original: " . ($data['message'] ?? '') . ' in ' . ($data['file'] ?? '') . ':' . ($data['line'] ?? 0));
    }

    return $code;
}

/**
 * Assemble request + user context for logging. Sanitizes sensitive fields.
 */
function errorLogBuildContext()
{
    $url = ($_SERVER['REQUEST_URI'] ?? '');
    if ($_SERVER['HTTP_HOST'] ?? '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $url;
    }

    // Identify the user. Sessions may not be started yet.
    $userType = null;
    $userId = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['admin_id'])) {
            $userType = 'admin';
            $userId = (int) $_SESSION['admin_id'];
        } elseif (!empty($_SESSION['store_id'])) {
            $userType = 'store';
            $userId = (int) $_SESSION['store_id'];
        } else {
            $userType = 'visitor';
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }

    $payload = [
        'get' => errorLogSanitizeArray($_GET ?? []),
        'post' => errorLogSanitizeArray($_POST ?? [], true),
        'session_keys' => errorLogSanitizeSession(),
        'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
    ];

    return [
        'url' => $url,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'ip' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'user_type' => $userType,
        'user_id' => $userId,
        'payload' => $payload,
    ];
}

/**
 * Redact sensitive keys + truncate huge values. When $isPost=true, redact
 * anything that smells like a credential.
 */
function errorLogSanitizeArray(array $arr, $isPost = false)
{
    $sensitiveKeys = [
        'password',
        'pass',
        'pwd',
        'token',
        'csrf',
        'api_key',
        'secret',
        'credit_card',
        'cvv',
        'pin',
        'auth',
        'new_password',
        'current_password',
        'password_confirm'
    ];

    $out = [];
    foreach ($arr as $k => $v) {
        $keyLower = strtolower((string) $k);
        if ($isPost && in_array($keyLower, $sensitiveKeys, true)) {
            $out[$k] = '[REDACTED]';
            continue;
        }
        if (is_array($v)) {
            $out[$k] = errorLogSanitizeArray($v, $isPost);
        } elseif (is_scalar($v) || $v === null) {
            $str = (string) $v;
            if (mb_strlen($str) > 500) $str = mb_substr($str, 0, 500) . '...[truncated]';
            $out[$k] = $str;
        } else {
            $out[$k] = '[' . gettype($v) . ']';
        }
    }
    return $out;
}

/**
 * Return just the session keys (no values) — we never want to log session data.
 */
function errorLogSanitizeSession()
{
    if (session_status() !== PHP_SESSION_ACTIVE) return [];
    $keys = array_keys($_SESSION);
    // Exclude CSRF and other sensitive tokens from being revealed (even as keys)
    return array_values(array_filter($keys, fn($k) => !in_array($k, ['csrf'])));
}

function errorLogMapExceptionSeverity($e)
{
    if ($e instanceof PDOException) return 'critical';
    if ($e instanceof Error) return 'critical';        // TypeError, ParseError, etc.
    return 'high'; // plain Exception
}

function errorLogMapErrnoSeverity($errno)
{
    $critical = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $high = [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR];
    if (in_array($errno, $critical, true)) return 'critical';
    if (in_array($errno, $high, true)) return 'high';
    return 'low'; // notices, deprecations
}

/**
 * Render the user-facing error page. If the request is AJAX/JSON, return JSON.
 * Super admins see the stack trace; everyone else sees just the code.
 */
function errorLogRenderUserPage($code, array $data = [])
{
    // AJAX → JSON response
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($isAjax) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => true,
            'code' => $code,
            'message' => 'حدث خطأ داخلي. معرف الخطأ: ' . $code,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $isAdmin = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin_id']));
    $base = defined('BASE_URL') ? BASE_URL : '';
    $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

?>
    <!DOCTYPE html>
    <html dir="rtl" lang="ar">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>حدث خطأ — <?= $codeEsc ?></title>
        <style>
            * {
                box-sizing: border-box
            }

            body {
                font-family: 'Cairo', 'Tahoma', system-ui, sans-serif;
                background: linear-gradient(135deg, #fef2f2 0%, #fff7ed 100%);
                margin: 0;
                padding: 24px;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #1f2937
            }

            .card {
                background: #fff;
                border-radius: 24px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, .08);
                max-width: 560px;
                width: 100%;
                overflow: hidden
            }

            .head {
                background: linear-gradient(135deg, #fee2e2, #fef3c7);
                padding: 32px 28px;
                text-align: center;
                border-bottom: 1px solid #fde68a
            }

            .icon {
                width: 72px;
                height: 72px;
                border-radius: 50%;
                background: #fff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 12px;
                box-shadow: 0 8px 24px rgba(239, 68, 68, .18)
            }

            .icon svg {
                width: 36px;
                height: 36px;
                color: #dc2626
            }

            h1 {
                margin: 0;
                font-size: 22px;
                font-weight: 900;
                color: #991b1b
            }

            .subtitle {
                margin: 8px 0 0;
                color: #9a3412;
                font-size: 14px
            }

            .body {
                padding: 28px
            }

            .code-box {
                background: #111827;
                color: #fff;
                padding: 18px;
                border-radius: 14px;
                text-align: center;
                margin: 0 0 20px;
                font-family: 'Courier New', monospace;
                position: relative
            }

            .code-box small {
                color: #9ca3af;
                display: block;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 2px;
                text-transform: uppercase;
                margin-bottom: 6px;
                font-family: 'Cairo', sans-serif
            }

            .code-box strong {
                font-size: 28px;
                font-weight: 900;
                letter-spacing: 3px;
                color: #fbbf24
            }

            .copy-btn {
                position: absolute;
                top: 12px;
                left: 12px;
                background: rgba(255, 255, 255, .1);
                border: 0;
                color: #fbbf24;
                padding: 4px 10px;
                border-radius: 6px;
                font-size: 11px;
                cursor: pointer;
                font-weight: 700
            }

            .copy-btn:hover {
                background: rgba(255, 255, 255, .2)
            }

            .info {
                background: #f9fafb;
                border-radius: 12px;
                padding: 16px;
                font-size: 14px;
                line-height: 1.8;
                color: #4b5563;
                margin-bottom: 20px
            }

            .info strong {
                color: #111827
            }

            .actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap
            }

            .btn {
                flex: 1;
                min-width: 130px;
                padding: 12px 18px;
                border-radius: 12px;
                text-decoration: none;
                text-align: center;
                font-weight: 700;
                font-size: 14px;
                border: 0;
                cursor: pointer;
                transition: transform .1s
            }

            .btn:hover {
                transform: translateY(-1px)
            }

            .btn-primary {
                background: #059669;
                color: #fff
            }

            .btn-secondary {
                background: #e5e7eb;
                color: #1f2937
            }

            .admin-trace {
                margin-top: 24px;
                background: #0f172a;
                color: #e2e8f0;
                padding: 18px;
                border-radius: 12px;
                font-size: 12px;
                max-height: 320px;
                overflow: auto;
                font-family: 'Courier New', monospace;
                white-space: pre-wrap;
                word-break: break-word;
                direction: ltr;
                text-align: left
            }

            .admin-trace h4 {
                margin: 0 0 8px;
                color: #fbbf24;
                font-size: 13px;
                font-family: 'Cairo', sans-serif;
                direction: rtl;
                text-align: right
            }
        </style>
    </head>

    <body>
        <div class="card">
            <div class="head">
                <div class="icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16a2 2 0 001.73 3z" />
                    </svg>
                </div>
                <h1>حدث خطأ غير متوقع</h1>
                <p class="subtitle">نعتذر عن الإزعاج — فريقنا يسجّل هذا الخطأ الآن</p>
            </div>
            <div class="body">
                <div class="code-box">
                    <button class="copy-btn" onclick="navigator.clipboard.writeText('<?= $codeEsc ?>');this.textContent='✓ تم النسخ';setTimeout(()=>this.textContent='نسخ',1500)">نسخ</button>
                    <small>معرّف الخطأ / Error ID</small>
                    <strong><?= $codeEsc ?></strong>
                </div>
                <div class="info">
                    <strong>📞 للمساعدة:</strong> تواصل مع فريق الدعم وأرفق هذا المعرّف — سيتم حل المشكلة بأسرع وقت.
                </div>
                <div class="actions">
                    <a href="javascript:history.back()" class="btn btn-secondary">← الرجوع</a>
                    <a href="<?= htmlspecialchars($base ?: '/', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">الصفحة الرئيسية</a>
                </div>

                <?php if ($isAdmin && !empty($data)): ?>
                    <div class="admin-trace">
                        <h4>🔧 تفاصيل الخطأ (Super Admin فقط)</h4><?= htmlspecialchars(
                                                                        ($data['type'] ?? '') . ': ' . ($data['message'] ?? '') . "\n\n"
                                                                            . 'File: ' . ($data['file'] ?? '') . ':' . ($data['line'] ?? '') . "\n\n"
                                                                            . ($data['stack_trace'] ?? ''),
                                                                        ENT_QUOTES,
                                                                        'UTF-8'
                                                                    ) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>

    </html>
<?php
}
