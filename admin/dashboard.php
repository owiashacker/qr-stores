<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pageTitle = 'لوحة التحكم';

// Load restaurant data early — header_admin.php also loads this but we need it for $stats below
$r = currentStore($pdo);

$rid = $_SESSION['store_id'];
$stats = [
    'categories' => $pdo->query("SELECT COUNT(*) FROM categories WHERE store_id = $rid")->fetchColumn(),
    'items' => $pdo->query("SELECT COUNT(*) FROM items WHERE store_id = $rid")->fetchColumn(),
    'available' => $pdo->query("SELECT COUNT(*) FROM items WHERE store_id = $rid AND is_available = 1")->fetchColumn(),
    'featured' => $pdo->query("SELECT COUNT(*) FROM items WHERE store_id = $rid AND is_featured = 1")->fetchColumn(),
    'views' => (int) ($r['views_count'] ?? 0),
    'unique_views' => (int) ($r['unique_views'] ?? 0),
    'favorites' => (int) $pdo->query("SELECT COALESCE(SUM(favorites_count),0) FROM items WHERE store_id = $rid")->fetchColumn(),
];

// Views in last 7 days (for mini-trend display)
$viewsStmt = $pdo->prepare("SELECT COALESCE(SUM(views_count),0) FROM store_views_daily WHERE store_id = ? AND view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$viewsStmt->execute([$rid]);
$stats['views_7d'] = (int) $viewsStmt->fetchColumn();

$todayStmt = $pdo->prepare("SELECT COALESCE(views_count,0) FROM store_views_daily WHERE store_id = ? AND view_date = CURDATE()");
$todayStmt->execute([$rid]);
$stats['views_today'] = (int) $todayStmt->fetchColumn();

// Top favorited items
$topFavs = $pdo->prepare("SELECT i.*, c.name AS category_name FROM items i JOIN categories c ON i.category_id = c.id WHERE i.store_id = ? AND i.favorites_count > 0 ORDER BY i.favorites_count DESC, i.id DESC LIMIT 6");
$topFavs->execute([$rid]);
$topFavs = $topFavs->fetchAll();

$recentItems = $pdo->prepare("SELECT i.*, c.name AS category_name FROM items i JOIN categories c ON i.category_id = c.id WHERE i.store_id = ? ORDER BY i.created_at DESC LIMIT 6");
$recentItems->execute([$rid]);
$recentItems = $recentItems->fetchAll();

require __DIR__ . '/../includes/header_admin.php';
?>

<!-- Welcome Banner -->
<div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 via-teal-600 to-emerald-700 p-6 md:p-8 mb-6 text-white shadow-xl">
    <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full -translate-y-32 translate-x-32 blur-2xl"></div>
    <div class="absolute bottom-0 left-0 w-48 h-48 bg-white/10 rounded-full translate-y-24 -translate-x-24 blur-2xl"></div>
    <div class="relative">
        <h2 class="text-2xl md:text-3xl font-bold mb-2">مرحباً، <?= e($r['name']) ?> 👋</h2>
        <p class="text-emerald-50 mb-6 text-sm md:text-base">متجرك الإلكتروني جاهز. شاركه مع زبائنك عبر QR Code.</p>
        <div class="flex flex-wrap gap-3">
            <a href="items.php?new=1" class="inline-flex items-center gap-2 bg-white text-emerald-700 px-5 py-2.5 rounded-xl font-bold hover:bg-emerald-50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                إضافة <?= e(bizLabel($r, 'singular')) ?>
            </a>
            <a href="qr.php" class="inline-flex items-center gap-2 bg-white/20 backdrop-blur text-white px-5 py-2.5 rounded-xl font-bold hover:bg-white/30 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2v2h-2v-2z"/></svg>
                توليد QR Code
            </a>
        </div>
    </div>
</div>

<!-- Analytics Hero — Views & Favorites -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <!-- Total Views (Big) -->
    <div class="md:col-span-2 relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-600 p-6 text-white shadow-xl">
        <div class="absolute -top-10 -right-10 w-48 h-48 bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-white/10 rounded-full blur-2xl"></div>
        <div class="relative">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                </div>
                <div>
                    <p class="text-white/70 text-xs font-bold uppercase tracking-wider">عدد مشاهدات القائمة</p>
                    <p class="text-white/90 text-sm">إجمالي الزيارات منذ الإطلاق</p>
                </div>
            </div>
            <p class="text-5xl md:text-6xl font-black leading-none mb-3"><?= number_format($stats['views']) ?></p>
            <div class="flex flex-wrap gap-2 mt-4">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white/15 backdrop-blur rounded-full text-xs font-bold">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-300 animate-pulse"></span>
                    اليوم: <?= number_format($stats['views_today']) ?>
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white/15 backdrop-blur rounded-full text-xs font-bold">
                    آخر 7 أيام: <?= number_format($stats['views_7d']) ?>
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white/15 backdrop-blur rounded-full text-xs font-bold">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    زوار فريدون: <?= number_format($stats['unique_views']) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Favorites Total -->
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-rose-500 to-red-600 p-6 text-white shadow-xl">
        <div class="absolute -top-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
        <div class="relative">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                </div>
                <div>
                    <p class="text-white/70 text-xs font-bold uppercase tracking-wider">مفضلات الزوار</p>
                    <p class="text-white/90 text-sm">إجمالي الأعجاب</p>
                </div>
            </div>
            <p class="text-5xl md:text-6xl font-black leading-none mb-3"><?= number_format($stats['favorites']) ?></p>
            <?php if (count($topFavs) > 0): ?>
                <p class="text-xs text-white/80 mt-4"><?= count($topFavs) ?> <?= e(bizLabel($r, 'singular')) ?> محبوب من قبل الزوار</p>
            <?php else: ?>
                <p class="text-xs text-white/80 mt-4">لا توجد مفضلات بعد</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $statCards = [
        [bizLabel($r, 'categories'), $stats['categories'], 'bg-blue-500', 'M4 6h16M4 10h16M4 14h16M4 18h16'],
        [bizLabel($r, 'plural'), $stats['items'], 'bg-purple-500', 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['متوفر', $stats['available'], 'bg-emerald-500', 'M5 13l4 4L19 7'],
        ['مميز', $stats['featured'], 'bg-amber-500', 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z'],
    ];
    foreach ($statCards as [$label, $value, $color, $icon]):
    ?>
    <div class="bg-white p-5 rounded-2xl shadow-soft hover:shadow-card transition">
        <div class="flex items-center justify-between mb-3">
            <div class="<?= $color ?> w-10 h-10 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/>
                </svg>
            </div>
        </div>
        <p class="text-3xl font-bold text-gray-900"><?= $value ?></p>
        <p class="text-sm text-gray-500 mt-1"><?= $label ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Quick Setup Steps -->
<?php if ($stats['categories'] == 0 || $stats['items'] == 0): ?>
<div class="bg-white rounded-2xl shadow-soft p-6 mb-6">
    <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-700">✨</span>
        خطوات الإعداد السريع
    </h3>
    <div class="space-y-3">
        <a href="settings.php" class="flex items-center gap-4 p-4 rounded-xl border-2 <?= $r['logo'] ? 'border-emerald-100 bg-emerald-50' : 'border-gray-100 hover:border-emerald-200' ?> transition">
            <div class="w-10 h-10 rounded-full <?= $r['logo'] ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500' ?> flex items-center justify-center font-bold"><?= $r['logo'] ? '✓' : '1' ?></div>
            <div class="flex-1">
                <p class="font-semibold">أضف شعار المتجر ومعلوماته</p>
                <p class="text-xs text-gray-500">الاسم، الهاتف، العنوان، الشعار</p>
            </div>
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <a href="categories.php" class="flex items-center gap-4 p-4 rounded-xl border-2 <?= $stats['categories'] > 0 ? 'border-emerald-100 bg-emerald-50' : 'border-gray-100 hover:border-emerald-200' ?> transition">
            <div class="w-10 h-10 rounded-full <?= $stats['categories'] > 0 ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500' ?> flex items-center justify-center font-bold"><?= $stats['categories'] > 0 ? '✓' : '2' ?></div>
            <div class="flex-1">
                <p class="font-semibold">أنشئ <?= e(bizLabel($r, 'categories')) ?> متجرك</p>
                <p class="text-xs text-gray-500">نظّم <?= e(bizLabel($r, 'plural')) ?> في <?= e(bizLabel($r, 'categories')) ?> منطقية</p>
            </div>
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <a href="items.php" class="flex items-center gap-4 p-4 rounded-xl border-2 <?= $stats['items'] > 0 ? 'border-emerald-100 bg-emerald-50' : 'border-gray-100 hover:border-emerald-200' ?> transition">
            <div class="w-10 h-10 rounded-full <?= $stats['items'] > 0 ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-500' ?> flex items-center justify-center font-bold"><?= $stats['items'] > 0 ? '✓' : '3' ?></div>
            <div class="flex-1">
                <p class="font-semibold">أضف <?= e(bizLabel($r, 'plural')) ?> مع الصور والأسعار</p>
                <p class="text-xs text-gray-500">كل <?= e(bizLabel($r, 'plural')) ?> التي يعرضها متجرك</p>
            </div>
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <a href="qr.php" class="flex items-center gap-4 p-4 rounded-xl border-2 border-gray-100 hover:border-emerald-200 transition">
            <div class="w-10 h-10 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center font-bold">4</div>
            <div class="flex-1">
                <p class="font-semibold">اطبع QR Code وضعه في متجرك</p>
                <p class="text-xs text-gray-500">الزبون يمسح ويرى <?= e(bizLabel($r, 'plural')) ?></p>
            </div>
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Top Favorited Items (by visitors) -->
<?php if ($topFavs): ?>
<div class="bg-white rounded-2xl shadow-soft p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-500 to-red-600 flex items-center justify-center shadow-lg shadow-rose-500/30">
                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            </div>
            <div>
                <h3 class="text-lg font-black text-gray-900">أكثر <?= e(bizLabel($r, 'plural')) ?> تفضيلاً</h3>
                <p class="text-xs text-gray-500"><?= e(bizLabel($r, 'plural')) ?> التي أضافها الزوار إلى المفضلة</p>
            </div>
        </div>
    </div>
    <div class="space-y-2">
        <?php foreach ($topFavs as $idx => $item): ?>
        <div class="flex items-center gap-4 p-3 rounded-xl border border-gray-100 hover:border-rose-200 hover:bg-rose-50/30 transition">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-rose-100 to-red-100 text-rose-700 flex items-center justify-center font-black text-sm flex-shrink-0">
                <?= $idx + 1 ?>
            </div>
            <div class="w-14 h-14 rounded-xl overflow-hidden bg-gray-100 flex-shrink-0">
                <?php if ($item['image']): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/items/<?= e($item['image']) ?>" class="w-full h-full object-cover" alt="">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14"/></svg>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-gray-900 truncate"><?= e($item['name']) ?></p>
                <p class="text-xs text-gray-500 truncate"><?= e($item['category_name']) ?> • <?= formatPrice($item['price'], $r['currency']) ?></p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0 bg-rose-50 px-3 py-1.5 rounded-full border border-rose-100">
                <svg class="w-4 h-4 text-rose-500" fill="currentColor" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                <span class="font-black text-sm text-rose-700"><?= number_format($item['favorites_count']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Items -->
<?php if ($recentItems): ?>
<div class="bg-white rounded-2xl shadow-soft p-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold">آخر <?= e(bizLabel($r, 'plural')) ?> المضافة</h3>
        <a href="items.php" class="text-sm text-emerald-600 font-semibold hover:underline">عرض الكل ←</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <?php foreach ($recentItems as $item): ?>
        <div class="border border-gray-100 rounded-xl overflow-hidden hover:shadow-card transition">
            <div class="aspect-square bg-gray-50 relative">
                <?php if ($item['image']): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/items/<?= e($item['image']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                <?php endif; ?>
                <?php if (!$item['is_available']): ?>
                    <div class="absolute top-2 right-2 bg-red-500 text-white text-xs px-2 py-1 rounded-full">غير متوفر</div>
                <?php endif; ?>
            </div>
            <div class="p-3">
                <p class="font-semibold text-sm truncate"><?= e($item['name']) ?></p>
                <p class="text-xs text-gray-400"><?= e($item['category_name']) ?></p>
                <p class="text-emerald-600 font-bold mt-1"><?= formatPrice($item['price'], $r['currency']) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>
