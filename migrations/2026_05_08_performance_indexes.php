<?php
/**
 * MIGRATION 2026-05-08: Performance Indexes
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Adds covering indexes for the hottest queries:
 *   - public/store.php  → SELECT FROM stores WHERE slug = ? AND is_active = 1
 *   - admin/items.php   → SELECT FROM items WHERE store_id = ? ORDER BY ...
 *   - super/stores.php  → various filters
 *
 * Run: php migrations/2026_05_08_performance_indexes.php
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
$out("  MIGRATION: Performance Indexes");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

$indexes = [
    // Stores — public lookups by slug (the hottest query)
    ['stores', 'idx_slug_active',        '(slug, is_active)'],
    ['stores', 'idx_approval_status',    '(approval_status)'],
    ['stores', 'idx_business_type',      '(business_type_id)'],
    ['stores', 'idx_plan',               '(plan_id, is_active)'],
    ['stores', 'idx_subscription_exp',   '(subscription_expires_at)'],

    // Items — filtered by store + category + sorted
    ['items', 'idx_store_available',     '(store_id, is_available)'],
    ['items', 'idx_store_category',      '(store_id, category_id, sort_order)'],
    ['items', 'idx_featured',            '(store_id, is_featured, sort_order)'],

    // Categories
    ['categories', 'idx_store_active',   '(store_id, is_active, sort_order)'],

    // Item media
    ['item_media', 'idx_item',           '(item_id, sort_order)'],

    // Activity logs (super admin views)
    ['activity_logs', 'idx_created',     '(created_at DESC)'],
    ['activity_logs', 'idx_status',      '(http_status, created_at)'],

    // Login attempts (rate limit lookups)
    ['login_attempts', 'idx_email_type', '(email, user_type, created_at)'],
    ['login_attempts', 'idx_ip',         '(ip, created_at)'],

    // Payments
    ['payments', 'idx_store_paid',       '(store_id, paid_at DESC)'],
    ['payments', 'idx_affiliate',        '(affiliate_id, affiliate_paid)'],

    // Subscription requests
    ['subscription_requests', 'idx_status',  '(status, created_at)'],
];

$added = 0;
$existed = 0;
$failed = 0;

foreach ($indexes as [$table, $name, $cols]) {
    // Check if index already exists
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                            WHERE table_schema = ? AND table_name = ? AND index_name = ?');
    $check->execute([DB_NAME, $table, $name]);
    if ((int) $check->fetchColumn() > 0) {
        $out("  ℹ $table.$name already exists");
        $existed++;
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` $cols");
        $out("  + $table.$name $cols");
        $added++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, "doesn't exist") || str_contains($msg, 'Unknown column')) {
            $out("  ⚠ $table.$name — table or column not found, skipping");
        } else {
            $out("  ✗ $table.$name — " . substr($msg, 0, 80));
            $failed++;
        }
    }
}

$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ DONE — Added: $added | Existed: $existed | Failed: $failed");
$out("═══════════════════════════════════════════════════════\n");
