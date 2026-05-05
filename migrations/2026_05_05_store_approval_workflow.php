<?php
/**
 * MIGRATION 2026-05-05: Store Approval Workflow
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Adds the columns needed to support a "store-owner signs up → super
 * approves manually before the account becomes active" flow.
 *
 * Existing stores keep working (they default to 'approved' automatically).
 *
 * How to run on production:
 *   cd ~/public_html && php migrations/2026_05_05_store_approval_workflow.php
 */

if (PHP_SAPI !== 'cli' && !isset($_GET['confirm_run'])) {
    http_response_code(403);
    exit('CLI only — run via: php migrations/2026_05_05_store_approval_workflow.php');
}

require __DIR__ . '/../config/db.php';

$out = function (string $msg) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, $msg . "\n");
    else echo nl2br(htmlspecialchars($msg)) . "<br>";
};

$out("\n═══════════════════════════════════════════════════════");
$out("  MIGRATION: Store Approval Workflow");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

$alters = [
    'approval_status'   => "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER is_active",
    'approved_at'       => "DATETIME NULL AFTER approval_status",
    'approved_by'       => "INT NULL AFTER approved_at",
    'rejection_reason'  => "TEXT NULL AFTER approved_by",
];

$out("[1/2] Adding approval columns to stores table...");
foreach ($alters as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE stores ADD COLUMN $col $def");
        $out("  + stores.$col");
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $out("  ℹ stores.$col already exists");
        } else { throw $e; }
    }
}

// FK for approved_by → admins.id
try {
    $pdo->exec("ALTER TABLE stores ADD CONSTRAINT fk_stores_approved_by
                FOREIGN KEY (approved_by) REFERENCES admins(id) ON DELETE SET NULL");
    $out("  + FK stores.approved_by → admins.id");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'errno: 121')) {
        $out("  ℹ FK already present");
    } else { throw $e; }
}

// Backfill: existing active stores get approved_at = created_at to match the new schema's logic
$out("\n[2/2] Backfilling existing stores...");
$n = $pdo->exec("UPDATE stores SET approved_at = created_at WHERE approval_status = 'approved' AND approved_at IS NULL");
$out("  ✓ backfilled approved_at on $n existing approved store(s)");

$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ MIGRATION COMPLETE");
$out("═══════════════════════════════════════════════════════");
$pending  = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE approval_status='pending'")->fetchColumn();
$approved = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE approval_status='approved'")->fetchColumn();
$rejected = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE approval_status='rejected'")->fetchColumn();
$out("  pending:  $pending");
$out("  approved: $approved");
$out("  rejected: $rejected");
$out("");
