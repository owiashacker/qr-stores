<?php
/**
 * Daily cron — checks every active store's subscription_expires_at and sends
 * a Telegram reminder via the dedicated expiry bot at the configured thresholds:
 *
 *      7 days BEFORE   →  expiring_7d
 *      3 days BEFORE   →  expiring_3d
 *      1 day  BEFORE   →  expiring_1d
 *      EXPIRY DAY      →  expiring_today
 *      1 day AFTER     →  expired_grace
 *      7 days AFTER    →  expired_recovery
 *
 * Idempotent — uses subscription_notifications table to ensure each (store, type)
 * is sent at most once. Safe to run hourly if you want; it'll just no-op.
 *
 * cPanel cron tab entry (recommended: 9 AM daily):
 *
 *      0 9 * * * /usr/local/bin/php /home/qrstores/public_html/cron/check_expiry.php >> /home/qrstores/logs/expiry_cron.log 2>&1
 *
 * To run manually:
 *      php cron/check_expiry.php
 *      php cron/check_expiry.php --dry-run     (no Telegram, no DB writes)
 *      php cron/check_expiry.php --verbose     (print every store decision)
 */

if (PHP_SAPI !== 'cli') {
    // Allow ?key=... web trigger only if a secret matches site setting
    require __DIR__ . '/../config/db.php';
    $key = $_GET['key'] ?? '';
    $expected = trim((string) siteSetting($pdo, 'cron_secret', ''));
    if ($expected === '' || $key !== $expected) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    require __DIR__ . '/../config/db.php';
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/expiry_bot.php';

// CLI flags
$args = PHP_SAPI === 'cli' ? array_slice($argv, 1) : [];
$dryRun  = in_array('--dry-run', $args, true);
$verbose = in_array('--verbose', $args, true) || $dryRun;

$startedAt = date('Y-m-d H:i:s');
echo "[{$startedAt}] check_expiry — start" . ($dryRun ? ' (DRY RUN)' : '') . PHP_EOL;

// ── Pull every store with an expiry date set ────────────────────
$stores = $pdo->query("
    SELECT
        s.id, s.name, s.email, s.phone, s.whatsapp,
        s.subscription_expires_at, s.is_active,
        p.name AS plan_name, p.code AS plan_code
    FROM stores s
    LEFT JOIN plans p ON s.plan_id = p.id
    WHERE s.subscription_expires_at IS NOT NULL
      AND s.is_active = 1
    ORDER BY s.subscription_expires_at ASC
")->fetchAll();

if (!$stores) {
    echo "  no stores with expiry date — nothing to do" . PHP_EOL;
    exit(0);
}

echo "  scanning " . count($stores) . " stores" . PHP_EOL;

$catalog = expiryNotificationCatalog();
$todayMidnight = strtotime(date('Y-m-d'));

$counters = [
    'sent'     => 0,
    'skipped'  => 0,
    'duplicates' => 0,
    'no_match' => 0,
];

foreach ($stores as $store) {
    $expTs = strtotime($store['subscription_expires_at']);
    if ($expTs === false) {
        $counters['skipped']++;
        continue;
    }
    $diffDays = (int) round(($expTs - $todayMidnight) / 86400);

    // Find which catalog entry (if any) matches today's diff
    $matchedType = null;
    foreach ($catalog as $type => $entry) {
        if ($entry['days_check'] === $diffDays) {
            $matchedType = $type;
            break;
        }
    }

    if ($matchedType === null) {
        $counters['no_match']++;
        if ($verbose) {
            echo "    · {$store['name']}: {$diffDays}d → no threshold match" . PHP_EOL;
        }
        continue;
    }

    // Skip if we already sent this type for this store
    if (expiryAlreadyNotified($pdo, (int) $store['id'], $matchedType)) {
        $counters['duplicates']++;
        if ($verbose) {
            echo "    · {$store['name']}: {$matchedType} — already sent" . PHP_EOL;
        }
        continue;
    }

    if ($dryRun) {
        echo "    [DRY] would send {$matchedType} → {$store['name']}"
            . " (expires {$store['subscription_expires_at']}, diff={$diffDays}d)" . PHP_EOL;
        $counters['sent']++;
        continue;
    }

    $ok = expiryNotifyStore($pdo, $store, $matchedType);
    if ($ok) {
        echo "    ✓ sent {$matchedType} → {$store['name']}" . PHP_EOL;
        $counters['sent']++;
    } else {
        echo "    ✗ FAILED {$matchedType} → {$store['name']}" . PHP_EOL;
        $counters['skipped']++;
    }
}

$endedAt = date('Y-m-d H:i:s');
echo "[{$endedAt}] check_expiry — done" . PHP_EOL;
echo "  sent: {$counters['sent']}"
   . " | duplicates: {$counters['duplicates']}"
   . " | no-match: {$counters['no_match']}"
   . " | failed: {$counters['skipped']}" . PHP_EOL;
