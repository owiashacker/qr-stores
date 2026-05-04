<?php
/**
 * MIGRATION 2026-05-04: Affiliate System + Bookstore Business Type
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * What it does:
 *   1. Creates `affiliates` table (if missing).
 *   2. Adds affiliate columns to `stores` (affiliate_id, affiliate_commission_rate, referred_at).
 *   3. Adds affiliate columns to `payments` (affiliate_id, affiliate_commission_rate,
 *      affiliate_amount, affiliate_paid, affiliate_paid_at).
 *   4. Inserts the new "bookstore" (مكتبة) business_type with its custom field schema.
 *
 * How to run on production (cPanel):
 *   Option A — via Terminal:
 *       cd ~/public_html && php migrations/2026_05_04_affiliates_and_bookstore.php
 *
 *   Option B — temporarily expose via web (then DELETE the URL trigger):
 *       Browse to /migrations/2026_05_04_affiliates_and_bookstore.php
 *       (You need to comment out the CLI guard below for that, then revert.)
 */

// Guard: CLI only by default — comment out only if running via browser
if (PHP_SAPI !== 'cli' && !isset($_GET['confirm_run'])) {
    http_response_code(403);
    exit('This migration must be run from CLI: php migrations/2026_05_04_affiliates_and_bookstore.php');
}

require __DIR__ . '/../config/db.php';

$out = function (string $msg) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
    } else {
        echo nl2br(htmlspecialchars($msg)) . "<br>";
        @ob_flush(); @flush();
    }
};

$out("\n═══════════════════════════════════════════════════════");
$out("  MIGRATION: Affiliate System + Bookstore Type");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

// ─── 1. affiliates table ───────────────────────────────────────────────────
$out("[1/4] Creating affiliates table...");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS affiliates (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(150) NOT NULL,
        email           VARCHAR(150) NOT NULL UNIQUE,
        password        VARCHAR(255) NOT NULL,
        phone           VARCHAR(30) NULL,
        whatsapp        VARCHAR(30) NULL,
        referral_code   VARCHAR(20) NOT NULL UNIQUE,
        commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        payment_method  VARCHAR(50) NULL,
        payment_details TEXT NULL,
        notes           TEXT NULL,
        is_active       TINYINT(1) NOT NULL DEFAULT 1,
        last_login      DATETIME NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_referral_code (referral_code),
        INDEX idx_email (email),
        INDEX idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$out("  ✓ affiliates table ready");

// ─── 2. stores columns ─────────────────────────────────────────────────────
$out("\n[2/4] Adding affiliate columns to stores...");
$storesAlters = [
    'affiliate_id'              => "INT NULL AFTER plan_id",
    'affiliate_commission_rate' => "DECIMAL(5,2) NULL AFTER affiliate_id",
    'referred_at'               => "DATETIME NULL AFTER affiliate_commission_rate",
];
foreach ($storesAlters as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE stores ADD COLUMN $col $def");
        $out("  + stores.$col");
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $out("  ℹ stores.$col already exists");
        } else { throw $e; }
    }
}
try {
    $pdo->exec("ALTER TABLE stores ADD CONSTRAINT fk_stores_affiliate
                FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL");
    $out("  + FK stores.affiliate_id → affiliates.id");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'errno: 121')) {
        $out("  ℹ FK already present");
    } else { throw $e; }
}

// ─── 3. payments columns ───────────────────────────────────────────────────
$out("\n[3/4] Adding affiliate columns to payments...");
$paymentsAlters = [
    'affiliate_id'              => "INT NULL AFTER recorded_by",
    'affiliate_commission_rate' => "DECIMAL(5,2) NULL AFTER affiliate_id",
    'affiliate_amount'          => "DECIMAL(14,2) NULL AFTER affiliate_commission_rate",
    'affiliate_paid'            => "TINYINT(1) NOT NULL DEFAULT 0 AFTER affiliate_amount",
    'affiliate_paid_at'         => "DATETIME NULL AFTER affiliate_paid",
];
foreach ($paymentsAlters as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE payments ADD COLUMN $col $def");
        $out("  + payments.$col");
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $out("  ℹ payments.$col already exists");
        } else { throw $e; }
    }
}
try {
    $pdo->exec("ALTER TABLE payments ADD CONSTRAINT fk_payments_affiliate
                FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE SET NULL");
    $out("  + FK payments.affiliate_id → affiliates.id");
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'errno: 121')) {
        $out("  ℹ FK already present");
    } else { throw $e; }
}

// ─── 4. bookstore business type ───────────────────────────────────────────
$out("\n[4/4] Adding 'bookstore' (مكتبة) business type...");
$exists = $pdo->prepare('SELECT id FROM business_types WHERE code = ?');
$exists->execute(['bookstore']);
if ($exists->fetch()) {
    $out("  ℹ bookstore already exists");
} else {
    $schema = json_encode([
        'fields' => [
            ['key' => 'author',     'type' => 'text',   'label' => 'المؤلف',   'placeholder' => 'مثال: نجيب محفوظ'],
            ['key' => 'publisher',  'type' => 'text',   'label' => 'دار النشر'],
            ['key' => 'year',       'type' => 'number', 'label' => 'سنة النشر'],
            ['key' => 'language',   'type' => 'select', 'label' => 'اللغة',
                'options' => ['عربية', 'إنكليزية', 'فرنسية', 'ألمانية', 'تركية', 'أخرى']],
            ['key' => 'pages',      'type' => 'number', 'label' => 'عدد الصفحات'],
            ['key' => 'cover_type', 'type' => 'select', 'label' => 'نوع الغلاف',
                'options' => ['مجلّد فاخر', 'مجلّد عادي', 'غلاف ورقي']],
            ['key' => 'condition',  'type' => 'select', 'label' => 'الحالة',
                'options' => ['جديد', 'مستعمل بحالة جيدة', 'مستعمل']],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare('INSERT INTO business_types
        (code, name_ar, icon, label_singular, label_plural, label_category, order_verb, fields_schema, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $stmt->execute([
        'bookstore', 'مكتبة', '📚', 'كتاب', 'الكتب', 'القسم', 'اطلب',
        $schema, 90,
    ]);
    $newId = (int) $pdo->lastInsertId();
    $out("  ✓ bookstore added (id=$newId)");
}

// ─── Final summary ─────────────────────────────────────────────────────────
$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ MIGRATION COMPLETE");
$out("═══════════════════════════════════════════════════════");
$out("  affiliates:     " . (int) $pdo->query('SELECT COUNT(*) FROM affiliates')->fetchColumn() . " row(s)");
$out("  business_types: " . (int) $pdo->query('SELECT COUNT(*) FROM business_types')->fetchColumn() . " row(s)");
$out("  bookstore:      " . ((int) $pdo->query("SELECT COUNT(*) FROM business_types WHERE code='bookstore'")->fetchColumn() ? '✓' : '✗'));
$out("");
