<?php
/**
 * MIGRATION 2026-05-10: Add "Other" (غير ذلك) business type
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Catch-all bucket for project owners whose business doesn't fit any of
 * the existing categories. Uses generic labels (منتج / المنتجات / الفئة)
 * and a generic icon palette so the UI doesn't lean toward food/clothes/etc.
 *
 * Sort order = 999 → always appears LAST in the type-selector grid.
 *
 * Run on production:
 *   cd ~/public_html && php migrations/2026_05_10_add_other_business_type.php
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
$out("  MIGRATION: Add 'other' (غير ذلك) business type");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

$type = [
    'code'           => 'other',
    'name_ar'        => 'غير ذلك',
    'icon'           => '🏪',
    'label_singular' => 'منتج',
    'label_plural'   => 'المنتجات',
    'label_category' => 'الفئة',
    'order_verb'     => 'اطلب',
    'sort_order'     => 999,
    // Minimal generic schema — owners can describe the item freely.
    // We avoid sector-specific fields (size, weight, color, etc.) on purpose
    // so the form stays clean for any kind of product/service.
    'fields' => [
        ['key' => 'description_extra', 'type' => 'textarea', 'label' => 'تفاصيل إضافية',
            'placeholder' => 'أي معلومات إضافية تريد عرضها للزبون (المواصفات، الضمان، شروط الاستخدام…)'],
    ],
];

$exists = $pdo->prepare('SELECT id FROM business_types WHERE code = ?');
$exists->execute([$type['code']]);
if ($exists->fetch()) {
    $out("  ℹ {$type['code']} already exists — skipping");
} else {
    $insert = $pdo->prepare(
        'INSERT INTO business_types
            (code, name_ar, icon, label_singular, label_plural, label_category, order_verb, fields_schema, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $schemaJson = json_encode(['fields' => $type['fields']], JSON_UNESCAPED_UNICODE);
    $insert->execute([
        $type['code'], $type['name_ar'], $type['icon'],
        $type['label_singular'], $type['label_plural'], $type['label_category'], $type['order_verb'],
        $schemaJson, $type['sort_order'],
    ]);
    $newId = (int) $pdo->lastInsertId();
    $out("  ✓ Added '{$type['code']}' ({$type['name_ar']}) — id=$newId, sort={$type['sort_order']}");
}

$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ MIGRATION COMPLETE");
$out("═══════════════════════════════════════════════════════");
$out("  business_types total: " . (int) $pdo->query('SELECT COUNT(*) FROM business_types')->fetchColumn());
$out("");
