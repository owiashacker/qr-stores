<?php
/**
 * MIGRATION 2026-05-07: Free plan becomes a 7-day trial
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Changes:
 *   1. plans.period for code='free': 'forever' → '7days'
 *   2. (optional) renames plans.name: 'مجاني' → 'تجريبي 7 أيام'
 *
 * Existing stores on the free plan that have NO expiry (NULL) keep
 * working as before — we don't retroactively expire them.
 * Only NEW signups (or stores re-approved after this date) will get
 * the 7-day expiry applied at approval time.
 *
 * Run on production:
 *   cd ~/public_html && php migrations/2026_05_07_free_plan_7days_trial.php
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
$out("  MIGRATION: Free plan = 7-day trial");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

// ─── 1. Update period ──────────────────────────────────────────────────────
$updated = $pdo->prepare("UPDATE plans SET period = ?, name = ? WHERE code = ?");
$updated->execute(['7days', 'تجريبي 7 أيام', 'free']);
$out("  ✓ free plan: period='7days', name='تجريبي 7 أيام'");

// ─── 2. Verify ─────────────────────────────────────────────────────────────
$out("\n=== Plans now ===");
foreach ($pdo->query('SELECT id, code, name, period, price FROM plans ORDER BY sort_order') as $p) {
    $out(sprintf('  #%d  %-8s  %-25s  period=%-10s  price=%s',
        $p['id'], $p['code'], $p['name'], $p['period'], $p['price']));
}

$noExpiry = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE plan_id = (SELECT id FROM plans WHERE code='free') AND subscription_expires_at IS NULL")->fetchColumn();
$out("\nNote: $noExpiry existing free-plan store(s) have no expiry set.");
$out("They keep working as-is. New signups get expiry = approval_date + 7 days.");
$out("");
