<?php
/**
 * MIGRATION 2026-05-11: Expiry-Reminder Telegram Bot
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Adds a SECOND Telegram bot dedicated to subscription-expiry alerts.
 * Kept separate from the existing signup/upgrade bot so the super admin
 * can route it to a different chat (or mute one without affecting the other).
 *
 * Creates:
 *   1. site_settings rows:
 *        - expiry_bot_token       (BotFather token)
 *        - expiry_bot_chat_id     (where to send reminders)
 *        - expiry_bot_enabled     (master on/off)
 *   2. subscription_notifications table — tracks which reminders we sent
 *      to whom, so the daily cron never double-notifies.
 *
 * Run on production:
 *   cd ~/public_html && php migrations/2026_05_11_expiry_bot.php
 */

if (PHP_SAPI !== 'cli' && !isset($_GET['confirm_run'])) {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../config/db.php';

$out = function (string $msg) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, $msg . "\n");
    else echo nl2br(htmlspecialchars($msg)) . "<br>";
};

$out("\n═══════════════════════════════════════════════════════");
$out("  MIGRATION: Expiry-Reminder Telegram Bot");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

// ── 1. Seed default site_settings rows ──────────────────────────
$defaults = [
    'expiry_bot_token'    => '',
    'expiry_bot_chat_id'  => '',
    'expiry_bot_enabled'  => '0',
];

$insert = $pdo->prepare(
    'INSERT IGNORE INTO site_settings (key_name, value) VALUES (?, ?)'
);
foreach ($defaults as $k => $v) {
    $insert->execute([$k, $v]);
    $existed = $pdo->prepare('SELECT value FROM site_settings WHERE key_name = ?');
    $existed->execute([$k]);
    $current = $existed->fetchColumn();
    $out("  • {$k} = " . ($current === '' ? '(empty — needs setup)' : '(already set)'));
}

// ── 2. subscription_notifications table ─────────────────────────
$out("\n  Creating subscription_notifications table…");
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subscription_notifications (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            store_id        INT UNSIGNED NOT NULL,
            notification_type VARCHAR(32) NOT NULL,
            sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            telegram_ok     TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_store_type (store_id, notification_type),
            INDEX idx_sent (sent_at),
            INDEX idx_store (store_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $out("  ✓ subscription_notifications ready");
} catch (PDOException $e) {
    $out("  ✗ " . $e->getMessage());
}

// ── 3. Show current notification rows count (for sanity) ────────
$count = (int) $pdo->query('SELECT COUNT(*) FROM subscription_notifications')->fetchColumn();
$out("  Current rows: {$count}");

$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ MIGRATION COMPLETE");
$out("═══════════════════════════════════════════════════════");
$out("  Next steps:");
$out("    1. super/settings.php → fill in expiry_bot_token + chat_id");
$out("    2. Add cron job:  0 9 * * * php cron/check_expiry.php");
$out("");
