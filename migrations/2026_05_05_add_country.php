<?php
/**
 * MIGRATION 2026-05-05: Add country column to stores
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Adds a 2-character ISO 3166-1 alpha-2 country code to each store.
 *
 * Run on production:
 *   cd ~/public_html && php migrations/2026_05_05_add_country.php
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
$out("  MIGRATION: Add country column to stores");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

try {
    $pdo->exec("ALTER TABLE stores ADD COLUMN country VARCHAR(2) NULL AFTER address");
    $out("  + stores.country (VARCHAR(2), nullable)");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        $out("  ℹ stores.country already exists");
    } else { throw $e; }
}

// Optional: backfill existing stores to a sensible default (Syria)
$n = $pdo->exec("UPDATE stores SET country = 'SY' WHERE country IS NULL");
$out("  ✓ backfilled $n existing store(s) to country='SY'");

$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ MIGRATION COMPLETE");
$out("═══════════════════════════════════════════════════════\n");
