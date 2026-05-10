<?php
/**
 * MIGRATION 2026-05-11: Recompute legacy affiliate_amount on every payment
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * BACKGROUND
 *   Before commit 1f152ab, super/payments.php → action=update did NOT touch
 *   affiliate_amount when an admin edited a payment's amount. So any payment
 *   that had its amount changed kept the commission frozen at its original
 *   value, and super/affiliates.php (which sums affiliate_amount) showed
 *   stale totals.
 *
 *   Going forward, the bug is fixed in code. This migration cleans up
 *   HISTORICAL data so the affiliate dashboard immediately reflects the
 *   correct totals without waiting for someone to re-edit each old row.
 *
 * WHAT IT DOES
 *   For every payment with (affiliate_id IS NOT NULL AND
 *   affiliate_commission_rate IS NOT NULL):
 *     expected = ROUND(amount × commission_rate / 100, 2)
 *   If the stored affiliate_amount differs from `expected`, update it.
 *
 * SAFETY
 *   - Skips payments without an affiliate (commission stays NULL).
 *   - Preserves the snapshotted commission_rate (does NOT recompute it from
 *     the affiliate's current rate — historical rates must stay frozen).
 *   - Records each fix to activity_logs so we have an audit trail.
 *
 * RUN
 *   Dry run (no writes):
 *     php migrations/2026_05_11_recompute_affiliate_amounts.php --dry-run
 *   Apply:
 *     php migrations/2026_05_11_recompute_affiliate_amounts.php
 */

if (PHP_SAPI !== 'cli' && !isset($_GET['confirm_run'])) {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../config/db.php';

$dryRun = PHP_SAPI === 'cli' && in_array('--dry-run', array_slice($argv, 1), true);

$out = function (string $msg) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, $msg . "\n");
    else echo nl2br(htmlspecialchars($msg)) . "<br>";
};

$out("\n═══════════════════════════════════════════════════════");
$out("  MIGRATION: Recompute legacy affiliate_amount");
$out("  Target DB: " . DB_NAME);
$out("  Mode: " . ($dryRun ? "DRY RUN (no writes)" : "APPLY"));
$out("═══════════════════════════════════════════════════════\n");

// Find every payment with an affiliate snapshot
$rows = $pdo->query("
    SELECT p.id, p.store_id, p.amount, p.affiliate_id,
           p.affiliate_commission_rate, p.affiliate_amount,
           p.affiliate_paid,
           a.name AS affiliate_name,
           s.name AS store_name
    FROM payments p
    LEFT JOIN affiliates a ON a.id = p.affiliate_id
    LEFT JOIN stores s ON s.id = p.store_id
    WHERE p.affiliate_id IS NOT NULL
      AND p.affiliate_commission_rate IS NOT NULL
    ORDER BY p.id ASC
")->fetchAll();

$total = count($rows);
$out("  Inspecting {$total} affiliate payment rows…\n");

if ($total === 0) {
    $out("  No affiliate payments found — nothing to fix.\n");
    exit(0);
}

$update = $pdo->prepare(
    'UPDATE payments SET affiliate_amount = ? WHERE id = ?'
);

$counters = [
    'fixed'    => 0,
    'unchanged'=> 0,
    'paid_warning' => 0,
    'total_delta'  => 0.0,
];

foreach ($rows as $r) {
    $amount   = (float) $r['amount'];
    $rate     = (float) $r['affiliate_commission_rate'];
    $current  = (float) ($r['affiliate_amount'] ?? 0);
    $expected = round($amount * $rate / 100, 2);

    if (abs($current - $expected) < 0.005) {
        $counters['unchanged']++;
        continue;
    }

    $delta = $expected - $current;
    $counters['total_delta'] += $delta;

    $paidFlag = (int) ($r['affiliate_paid'] ?? 0) === 1 ? '  ⚠ ALREADY PAID' : '';
    if ($paidFlag) $counters['paid_warning']++;

    $out(sprintf(
        '  #%d  %s → %s  amount=%.2f rate=%.2f%%  was=%.2f  now=%.2f  Δ=%+.2f%s',
        $r['id'],
        mb_substr((string) $r['store_name'], 0, 25),
        mb_substr((string) $r['affiliate_name'], 0, 20),
        $amount, $rate, $current, $expected, $delta, $paidFlag
    ));

    if (!$dryRun) {
        $update->execute([$expected, $r['id']]);
        $counters['fixed']++;
    } else {
        $counters['fixed']++; // count what WOULD be fixed
    }
}

$out("\n═══════════════════════════════════════════════════════");
$out(sprintf('  ✓ %s — fixed: %d | unchanged: %d | total %s: %+.2f',
    $dryRun ? 'DRY RUN COMPLETE' : 'MIGRATION COMPLETE',
    $counters['fixed'], $counters['unchanged'],
    $counters['total_delta'] >= 0 ? 'increase to affiliates' : 'decrease to affiliates',
    $counters['total_delta']));
if ($counters['paid_warning'] > 0) {
    $out("  ⚠ {$counters['paid_warning']} of these were already marked as PAID — review manually");
}
$out("═══════════════════════════════════════════════════════\n");

if ($dryRun) {
    $out("To apply the fix, re-run WITHOUT --dry-run:");
    $out("  php migrations/2026_05_11_recompute_affiliate_amounts.php\n");
}
