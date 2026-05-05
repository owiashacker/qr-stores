<?php
/**
 * MIGRATION 2026-05-06: Add Offices & Perfumes business types
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * Adds two new business types:
 *   1. offices  (مكتب هندسي / معماري) — services-oriented
 *   2. perfumes (عطورات)              — products with rich attributes
 *
 * Run on production:
 *   cd ~/public_html && php migrations/2026_05_06_add_offices_perfumes.php
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
$out("  MIGRATION: Add 'offices' + 'perfumes' business types");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

$types = [
    [
        'code'          => 'offices',
        'name_ar'       => 'مكتب هندسي',
        'icon'          => '📐',
        'label_singular' => 'خدمة',
        'label_plural'   => 'الخدمات',
        'label_category' => 'النوع',
        'order_verb'     => 'استفسر',
        'sort_order'     => 100,
        'fields' => [
            ['key' => 'service_type', 'type' => 'select', 'label' => 'نوع الخدمة',
                'options' => ['تصميم معماري', 'تصميم إنشائي', 'تصميم داخلي', 'إشراف ومتابعة', 'استشارات', 'دراسات جدوى', 'مقاولات', 'تشطيبات']],
            ['key' => 'area_sqm',     'type' => 'number', 'label' => 'المساحة (متر مربع)'],
            ['key' => 'duration',     'type' => 'text',   'label' => 'مدة التنفيذ', 'placeholder' => 'مثال: 30 يوم'],
            ['key' => 'project_type', 'type' => 'select', 'label' => 'نوع المشروع',
                'options' => ['سكني', 'تجاري', 'صناعي', 'حكومي', 'سياحي', 'تعليمي', 'صحي']],
            ['key' => 'experience',   'type' => 'text',   'label' => 'سنوات الخبرة', 'placeholder' => 'مثال: 10 سنوات'],
            ['key' => 'license',      'type' => 'text',   'label' => 'رقم الترخيص', 'placeholder' => 'اختياري'],
        ],
    ],
    [
        'code'          => 'perfumes',
        'name_ar'       => 'عطورات',
        'icon'          => '🌸',
        'label_singular' => 'عطر',
        'label_plural'   => 'العطور',
        'label_category' => 'الفئة',
        'order_verb'     => 'اطلب',
        'sort_order'     => 110,
        'fields' => [
            ['key' => 'gender',         'type' => 'select', 'label' => 'الفئة',
                'options' => ['رجالي', 'نسائي', 'للجنسين']],
            ['key' => 'fragrance_type', 'type' => 'select', 'label' => 'نوع العطر',
                'options' => ['عطر (Parfum)', 'Eau de Parfum', 'Eau de Toilette', 'Eau de Cologne', 'مسك', 'بخور', 'دهن عود']],
            ['key' => 'volume_ml',      'type' => 'select', 'label' => 'الحجم',
                'options' => ['10 مل', '15 مل', '30 مل', '50 مل', '75 مل', '100 مل', '125 مل', '150 مل', '200 مل']],
            ['key' => 'brand',          'type' => 'text',   'label' => 'الماركة', 'placeholder' => 'مثال: Chanel, Dior'],
            ['key' => 'origin',         'type' => 'text',   'label' => 'بلد المنشأ', 'placeholder' => 'مثال: فرنسا'],
            ['key' => 'top_notes',      'type' => 'text',   'label' => 'النوتات الرئيسية', 'placeholder' => 'مثال: عود، ورد، فانيليا'],
            ['key' => 'longevity',      'type' => 'select', 'label' => 'الثبات',
                'options' => ['ضعيف (1-3 ساعات)', 'متوسط (3-6 ساعات)', 'جيد (6-9 ساعات)', 'ممتاز (9+ ساعات)']],
        ],
    ],
];

$insert = $pdo->prepare(
    'INSERT INTO business_types
        (code, name_ar, icon, label_singular, label_plural, label_category, order_verb, fields_schema, is_active, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
);

foreach ($types as $t) {
    $exists = $pdo->prepare('SELECT id FROM business_types WHERE code = ?');
    $exists->execute([$t['code']]);
    if ($exists->fetch()) {
        $out("  ℹ {$t['code']} already exists — skipping");
        continue;
    }
    $schemaJson = json_encode(['fields' => $t['fields']], JSON_UNESCAPED_UNICODE);
    $insert->execute([
        $t['code'], $t['name_ar'], $t['icon'],
        $t['label_singular'], $t['label_plural'], $t['label_category'], $t['order_verb'],
        $schemaJson, $t['sort_order'],
    ]);
    $newId = (int) $pdo->lastInsertId();
    $out("  ✓ Added '{$t['code']}' ({$t['name_ar']}) — id=$newId, sort={$t['sort_order']}");
}

$out("\n═══════════════════════════════════════════════════════");
$out("  ✓ MIGRATION COMPLETE");
$out("═══════════════════════════════════════════════════════");
$out("  business_types total: " . (int) $pdo->query('SELECT COUNT(*) FROM business_types')->fetchColumn());
$out("");
