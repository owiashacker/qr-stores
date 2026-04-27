<?php
// Seed demo data - creates a sample restaurant with categories and items
require_once __DIR__ . '/includes/functions.php';

$demo = $pdo->prepare('SELECT id FROM stores WHERE email = ?');
$demo->execute(['demo@qrmenu.sy']);
$existing = $demo->fetchColumn();

if ($existing) {
    $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$existing]);
}

// Use Max plan for demo restaurant so it showcases all features
$maxPlanId = (int) $pdo->query("SELECT id FROM plans WHERE code = 'max' LIMIT 1")->fetchColumn();

// Insert demo restaurant
$stmt = $pdo->prepare('INSERT INTO stores (name, slug, email, password, phone, whatsapp, address, description, primary_color, currency, working_hours, plan_id, subscription_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    'مطعم الشام',
    'demo',
    'demo@qrmenu.sy',
    password_hash('demo1234', PASSWORD_DEFAULT),
    '+963 11 123 4567',
    '+963 944 123 456',
    'دمشق - شارع الثورة',
    'ألذ الأكلات الشامية الأصيلة منذ 1990',
    '#d97706',
    'ل.س',
    '10 صباحاً - 1 بعد منتصف الليل',
    $maxPlanId ?: 3,
    'active'
]);
$rid = $pdo->lastInsertId();

// Categories
$cats = [
    ['🥗', 'مقبلات'],
    ['🍗', 'أطباق رئيسية'],
    ['🥙', 'مشاوي'],
    ['🍰', 'حلويات'],
    ['🥤', 'مشروبات'],
];
$catIds = [];
$order = 1;
foreach ($cats as [$icon, $name]) {
    $stmt = $pdo->prepare('INSERT INTO categories (store_id, name, icon, sort_order) VALUES (?, ?, ?, ?)');
    $stmt->execute([$rid, $name, $icon, $order++]);
    $catIds[$name] = $pdo->lastInsertId();
}

// Items
$items = [
    ['مقبلات', 'حمص بالطحينة', 'حمص كريمي مع زيت الزيتون البكر وحبوب الصنوبر', 15000, null, 1],
    ['مقبلات', 'متبل باذنجان', 'باذنجان مشوي على الفحم مع طحينة وثوم', 13000, null, 0],
    ['مقبلات', 'تبولة', 'بقدونس، برغل، طماطم، نعناع، ليمون', 18000, 22000, 0],
    ['مقبلات', 'فتوش', 'خس، خضار موسمية، خبز محمص، دبس رمان', 20000, null, 0],

    ['أطباق رئيسية', 'مقلوبة دجاج', 'أرز بسمتي، دجاج، باذنجان، زهرة، صنوبر', 55000, null, 1],
    ['أطباق رئيسية', 'كبة بالصينية', 'برغل محشو بلحم عجل وصنوبر', 65000, null, 0],
    ['أطباق رئيسية', 'يالنجي', 'ورق عنب محشي بالأرز والخضار', 45000, null, 0],

    ['مشاوي', 'شيش طاووق', 'دجاج متبل مشوي على الفحم، أرز، خبز', 50000, 60000, 1],
    ['مشاوي', 'كباب حلبي', 'لحم عجل مفروم بتتبيلة حلبية خاصة', 60000, null, 0],
    ['مشاوي', 'شاورما لحم', 'شاورما لحم عجل، خضار، صلصة خاصة', 35000, null, 0],
    ['مشاوي', 'شاورما دجاج', 'شاورما دجاج، ثوم، خيار مخلل، بطاطا', 30000, null, 1],

    ['حلويات', 'كنافة نابلسية', 'كنافة بالجبنة، قطر، فستق حلبي', 25000, null, 1],
    ['حلويات', 'بقلاوة', 'تشكيلة بقلاوة شامية', 22000, null, 0],
    ['حلويات', 'مهلبية', 'مهلبية بماء الورد والفستق', 15000, null, 0],

    ['مشروبات', 'عصير ليمون نعناع', 'منعش وبارد', 8000, null, 0],
    ['مشروبات', 'شاي بالنعناع', 'شاي تركي مع نعناع طازج', 5000, null, 0],
    ['مشروبات', 'قهوة عربية', 'قهوة عربية بالهيل', 6000, null, 1],
    ['مشروبات', 'مياه', 'عبوة 500 مل', 2000, null, 0],
];

$order = 1;
foreach ($items as [$cat, $name, $desc, $price, $oldPrice, $featured]) {
    $stmt = $pdo->prepare('INSERT INTO items (store_id, category_id, name, description, price, old_price, is_available, is_featured, sort_order) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    $stmt->execute([$rid, $catIds[$cat], $name, $desc, $price, $oldPrice, $featured, $order++]);
}

$url = BASE_URL . '/public/store.php?r=demo';
$adminUrl = BASE_URL . '/admin/login.php';
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>تم إنشاء البيانات التجريبية</title>
    <style>
        body{font-family:system-ui,Tahoma;background:linear-gradient(135deg,#ecfdf5,#f0fdfa);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:20px}
        .card{background:#fff;padding:40px;border-radius:24px;box-shadow:0 20px 60px rgba(0,0,0,.08);max-width:600px;width:100%;text-align:center}
        h1{color:#059669;margin:0 0 10px}
        .info{background:#f0fdf4;padding:16px;border-radius:12px;margin:20px 0;text-align:right;font-size:14px;line-height:1.8}
        .btn{display:inline-block;margin:8px;padding:14px 32px;border-radius:12px;text-decoration:none;font-weight:bold;transition:all .2s}
        .btn-primary{background:#059669;color:#fff;box-shadow:0 4px 15px rgba(5,150,105,.3)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(5,150,105,.4)}
        .btn-secondary{background:#f3f4f6;color:#111}
        code{background:#1f2937;color:#fff;padding:2px 8px;border-radius:6px;font-size:13px}
    </style>
</head>
<body>
    <div class="card">
        <h1>🎉 البيانات التجريبية جاهزة</h1>
        <p>تم إنشاء مطعم تجريبي كامل مع 18 صنف في 5 أقسام</p>

        <div class="info">
            <strong>📧 البريد:</strong> <code>demo@qrmenu.sy</code><br>
            <strong>🔑 كلمة المرور:</strong> <code>demo1234</code><br>
            <strong>🔗 رابط القائمة:</strong> <code>?r=demo</code>
        </div>

        <a href="<?= $url ?>" class="btn btn-primary" target="_blank">👁️ معاينة القائمة العامة</a>
        <a href="<?= $adminUrl ?>" class="btn btn-secondary">🔐 دخول المشرف</a>
    </div>
</body>
</html>
