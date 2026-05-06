<?php
/**
 * MIGRATION 2026-05-09: Add Teacher (courses) + Jewelry business types
 * ──────────────────────────────────────────────────────────────────
 * Idempotent — safe to re-run.
 *
 * 1. teacher  (معلم / مدرس) — services-oriented (each item = a course)
 * 2. jewelry  (مجوهرات)     — luxury products with detailed attributes
 *
 * Run on production:
 *   cd ~/public_html && php migrations/2026_05_09_add_teacher_jewelry.php
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
$out("  MIGRATION: Add 'teacher' + 'jewelry' business types");
$out("  Target DB: " . DB_NAME);
$out("═══════════════════════════════════════════════════════\n");

$types = [
    [
        'code'           => 'teacher',
        'name_ar'        => 'معلم',
        'icon'           => '🎓',
        'label_singular' => 'كورس',
        'label_plural'   => 'الكورسات',
        'label_category' => 'المجال',
        'order_verb'     => 'سجّل',
        'sort_order'     => 120,
        'fields' => [
            ['key' => 'level',         'type' => 'select', 'label' => 'المستوى',
                'options' => ['مبتدئ', 'متوسط', 'متقدم', 'احترافي', 'لكل المستويات']],
            ['key' => 'duration',      'type' => 'text',   'label' => 'مدة الكورس', 'placeholder' => 'مثال: 4 أسابيع — 12 حصة'],
            ['key' => 'session_count', 'type' => 'number', 'label' => 'عدد الحصص'],
            ['key' => 'session_length','type' => 'text',   'label' => 'مدة الحصة', 'placeholder' => 'مثال: 90 دقيقة'],
            ['key' => 'mode',          'type' => 'select', 'label' => 'نوع التعليم',
                'options' => ['أونلاين (مباشر)', 'أونلاين (مسجّل)', 'حضوري', 'هجين']],
            ['key' => 'language',      'type' => 'select', 'label' => 'لغة التدريس',
                'options' => ['العربية', 'الإنكليزية', 'الفرنسية', 'التركية', 'أخرى']],
            ['key' => 'max_students',  'type' => 'number', 'label' => 'عدد الطلاب (الحد الأقصى)'],
            ['key' => 'certificate',   'type' => 'select', 'label' => 'شهادة بعد إكمال الكورس',
                'options' => ['نعم — معتمدة', 'نعم — حضور', 'لا']],
            ['key' => 'prerequisites', 'type' => 'text',   'label' => 'المتطلّبات السابقة', 'placeholder' => 'مثال: معرفة أساسية بالحاسب'],
        ],
    ],
    [
        'code'           => 'jewelry',
        'name_ar'        => 'مجوهرات',
        'icon'           => '💎',
        'label_singular' => 'قطعة',
        'label_plural'   => 'القطع',
        'label_category' => 'النوع',
        'order_verb'     => 'اطلب',
        'sort_order'     => 130,
        'fields' => [
            ['key' => 'metal',         'type' => 'select', 'label' => 'المعدن',
                'options' => ['ذهب عيار 24', 'ذهب عيار 22', 'ذهب عيار 21', 'ذهب عيار 18', 'ذهب أبيض', 'فضة 925', 'بلاتين', 'أخرى']],
            ['key' => 'weight_grams',  'type' => 'number', 'label' => 'الوزن (بالغرامات)'],
            ['key' => 'gemstone',      'type' => 'select', 'label' => 'الحجر الكريم',
                'options' => ['بدون', 'ألماس', 'ياقوت', 'زمرد', 'سفير', 'لؤلؤ', 'فيروز', 'عقيق', 'أوبال', 'أخرى']],
            ['key' => 'gemstone_carat','type' => 'text',   'label' => 'وزن الحجر (قيراط)', 'placeholder' => 'مثال: 0.50 قيراط'],
            ['key' => 'design_style',  'type' => 'select', 'label' => 'الطراز',
                'options' => ['كلاسيكي', 'عصري', 'تراثي', 'إيطالي', 'تركي', 'هندي', 'مصمَّم خصيصاً']],
            ['key' => 'occasion',      'type' => 'select', 'label' => 'المناسبة',
                'options' => ['خطوبة', 'زفاف', 'يومي', 'سهرة ومناسبات', 'هدية', 'كل المناسبات']],
            ['key' => 'gender',        'type' => 'select', 'label' => 'الفئة',
                'options' => ['نسائي', 'رجالي', 'أطفال', 'للجنسين']],
            ['key' => 'certified',     'type' => 'select', 'label' => 'شهادة الجودة',
                'options' => ['نعم — معتمدة دولياً', 'نعم — معتمدة محلياً', 'لا']],
            ['key' => 'warranty',      'type' => 'text',   'label' => 'الضمان', 'placeholder' => 'مثال: ضمان مدى الحياة على التصميم'],
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
