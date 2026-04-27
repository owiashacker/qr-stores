<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'نظرة عامة';

$stats = [
    'restaurants' => (int) $pdo->query("SELECT COUNT(*) FROM stores")->fetchColumn(),
    'active' => (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE is_active = 1")->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE status = 'pending'")->fetchColumn(),
    'items' => (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn(),
    'total_views' => (int) $pdo->query("SELECT COALESCE(SUM(views_count),0) FROM stores")->fetchColumn(),
    'total_unique_views' => (int) $pdo->query("SELECT COALESCE(SUM(unique_views),0) FROM stores")->fetchColumn(),
    'total_favorites' => (int) $pdo->query("SELECT COALESCE(SUM(favorites_count),0) FROM items")->fetchColumn(),
];

// Today / last 7 days platform-wide
$stats['views_today'] = (int) $pdo->query("SELECT COALESCE(SUM(views_count),0) FROM store_views_daily WHERE view_date = CURDATE()")->fetchColumn();
$stats['views_7d'] = (int) $pdo->query("SELECT COALESCE(SUM(views_count),0) FROM store_views_daily WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

// Top 5 restaurants by views
$topRestaurantsByViews = $pdo->query("SELECT r.id, r.name, r.slug, r.logo, r.views_count, r.unique_views, p.name AS plan_name, p.code AS plan_code FROM stores r LEFT JOIN plans p ON r.plan_id = p.id WHERE r.views_count > 0 ORDER BY r.views_count DESC LIMIT 5")->fetchAll();

$byPlan = $pdo->query("SELECT p.name, p.code, COUNT(r.id) AS count FROM plans p LEFT JOIN stores r ON r.plan_id = p.id GROUP BY p.id ORDER BY p.sort_order")->fetchAll();

$recentRestaurants = $pdo->query("SELECT r.*, p.name AS plan_name, p.code AS plan_code FROM stores r LEFT JOIN plans p ON r.plan_id = p.id ORDER BY r.created_at DESC LIMIT 5")->fetchAll();

$recentRequests = $pdo->query("SELECT sr.*, r.name AS restaurant_name, p.name AS plan_name FROM subscription_requests sr JOIN stores r ON sr.store_id = r.id JOIN plans p ON sr.plan_id = p.id WHERE sr.status = 'pending' ORDER BY sr.created_at DESC LIMIT 5")->fetchAll();

// Monthly revenue estimate (active paid subscriptions)
$monthlyRev = (float) $pdo->query("SELECT COALESCE(SUM(p.price), 0) FROM stores r JOIN plans p ON r.plan_id = p.id WHERE r.is_active = 1 AND r.subscription_status = 'active' AND p.price > 0")->fetchColumn();

require __DIR__ . '/../includes/header_super.php';
?>

<!-- Welcome -->
<div class="rounded-3xl bg-gradient-to-br from-emerald-600 via-teal-700 to-emerald-800 p-6 md:p-8 mb-6 relative overflow-hidden">
    <div class="absolute top-0 right-0 w-64 h-64 bg-white/10 rounded-full -translate-y-32 translate-x-32 blur-2xl"></div>
    <div class="relative">
        <h2 class="text-2xl md:text-3xl font-black text-white mb-2">مرحباً، <?= e($admin['name']) ?> 👋</h2>
        <p class="text-emerald-100">نظرة عامة على أداء منصتك اليوم</p>
    </div>
</div>

<!-- Analytics Hero -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <!-- Total Platform Views -->
    <div class="md:col-span-2 relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-600 p-6 text-white shadow-xl">
        <div class="absolute -top-10 -right-10 w-48 h-48 bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-white/10 rounded-full blur-2xl"></div>
        <div class="relative">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                </div>
                <div>
                    <p class="text-white/70 text-xs font-bold uppercase tracking-wider">مشاهدات المنصة</p>
                    <p class="text-white/90 text-sm">إجمالي زيارات القوائم لكل المطاعم</p>
                </div>
            </div>
            <p class="text-5xl md:text-6xl font-black leading-none mb-3"><?= number_format($stats['total_views']) ?></p>
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
                    زوار فريدون: <?= number_format($stats['total_unique_views']) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Total Favorites -->
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-rose-500 to-red-600 p-6 text-white shadow-xl">
        <div class="absolute -top-6 -right-6 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
        <div class="relative">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                </div>
                <div>
                    <p class="text-white/70 text-xs font-bold uppercase tracking-wider">مفضلات الزوار</p>
                    <p class="text-white/90 text-sm">عبر جميع المطاعم</p>
                </div>
            </div>
            <p class="text-5xl md:text-6xl font-black leading-none mb-3"><?= number_format($stats['total_favorites']) ?></p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $cards = [
        ['إجمالي المطاعم', $stats['restaurants'], 'from-blue-500 to-blue-600', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5'],
        ['المطاعم النشطة', $stats['active'], 'from-emerald-500 to-emerald-600', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
        ['طلبات معلقة', $stats['pending'], 'from-amber-500 to-orange-500', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['الدخل الشهري', '$' . number_format($monthlyRev, 0), 'from-purple-500 to-pink-500', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
    foreach ($cards as [$label, $value, $grad, $icon]):
    ?>
    <div class="card rounded-2xl p-5">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?> flex items-center justify-center mb-3 shadow-lg">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/></svg>
        </div>
        <p class="text-3xl font-black text-white"><?= $value ?></p>
        <p class="text-sm text-gray-400 mt-1"><?= $label ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Top Restaurants by Views -->
<?php if ($topRestaurantsByViews): ?>
<div class="card rounded-2xl p-6 mb-6">
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div>
                <h3 class="text-lg font-black text-white">أكثر المطاعم مشاهدة</h3>
                <p class="text-xs text-gray-400">Top 5 من حيث عدد الزيارات</p>
            </div>
        </div>
    </div>
    <div class="space-y-2">
        <?php foreach ($topRestaurantsByViews as $idx => $rest):
            $badgeClass = ['free' => 'bg-gray-500/20 text-gray-300', 'pro' => 'bg-emerald-500/20 text-emerald-300', 'max' => 'bg-amber-500/20 text-amber-300'];
        ?>
        <div class="flex items-center gap-4 p-3 rounded-xl bg-white/5 hover:bg-white/10 transition">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500/30 to-purple-500/30 border border-indigo-400/30 text-indigo-200 flex items-center justify-center font-black text-sm flex-shrink-0">
                <?= $idx + 1 ?>
            </div>
            <?php if ($rest['logo']): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($rest['logo']) ?>" class="w-11 h-11 rounded-xl object-cover flex-shrink-0 border border-white/10">
            <?php else: ?>
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white font-bold flex-shrink-0"><?= e(mb_substr($rest['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <p class="font-bold text-white truncate"><?= e($rest['name']) ?></p>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?= $badgeClass[$rest['plan_code']] ?? 'bg-gray-500/20 text-gray-300' ?>"><?= e($rest['plan_name']) ?></span>
                    <span class="text-xs text-gray-400"><?= number_format($rest['unique_views']) ?> زائر فريد</span>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0 bg-indigo-500/15 border border-indigo-400/20 px-3 py-1.5 rounded-full">
                <svg class="w-4 h-4 text-indigo-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                <span class="font-black text-sm text-indigo-200"><?= number_format($rest['views_count']) ?></span>
            </div>
            <a href="<?= BASE_URL ?>/public/store.php?r=<?= urlencode($rest['slug']) ?>" target="_blank" class="text-emerald-400 hover:underline text-xs flex-shrink-0">عرض ←</a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Plan Distribution -->
    <div class="card rounded-2xl p-6">
        <h3 class="text-lg font-bold mb-4 text-white">توزيع المطاعم حسب الباقة</h3>
        <?php
        $total = array_sum(array_column($byPlan, 'count'));
        $colors = ['free' => 'bg-gray-500', 'pro' => 'bg-emerald-500', 'max' => 'bg-amber-500'];
        foreach ($byPlan as $p):
            $pct = $total ? round(($p['count'] / $total) * 100) : 0;
        ?>
        <div class="mb-4">
            <div class="flex items-center justify-between mb-2">
                <span class="font-semibold text-sm text-gray-300"><?= e($p['name']) ?></span>
                <span class="text-sm text-gray-400"><?= $p['count'] ?> (<?= $pct ?>%)</span>
            </div>
            <div class="h-2 bg-white/5 rounded-full overflow-hidden">
                <div class="h-full <?= $colors[$p['code']] ?? 'bg-blue-500' ?> rounded-full transition-all" style="width: <?= $pct ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pending Requests -->
    <div class="card rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-white">طلبات الاشتراك المعلقة</h3>
            <a href="subscriptions.php" class="text-sm text-emerald-400 font-semibold hover:underline">عرض الكل ←</a>
        </div>
        <?php if (!$recentRequests): ?>
            <p class="text-gray-500 text-sm text-center py-8">لا توجد طلبات معلقة</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($recentRequests as $req): ?>
                <div class="flex items-center justify-between p-3 rounded-xl bg-white/5">
                    <div>
                        <p class="font-semibold text-sm text-white"><?= e($req['restaurant_name']) ?></p>
                        <p class="text-xs text-gray-400">ترقية إلى <?= e($req['plan_name']) ?></p>
                    </div>
                    <span class="text-xs text-gray-500"><?= date('m/d', strtotime($req['created_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Restaurants -->
<div class="card rounded-2xl p-6">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-white">آخر المطاعم المسجلة</h3>
        <a href="stores.php" class="text-sm text-emerald-400 font-semibold hover:underline">عرض الكل ←</a>
    </div>
    <?php if (!$recentRestaurants): ?>
        <p class="text-gray-500 text-sm text-center py-8">لا توجد مطاعم بعد</p>
    <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[560px]">
            <thead>
                <tr class="text-xs text-gray-500 border-b border-white/5">
                    <th class="text-right py-3 px-3 font-semibold">المطعم</th>
                    <th class="text-right py-3 px-3 font-semibold">الباقة</th>
                    <th class="text-right py-3 px-3 font-semibold">تاريخ التسجيل</th>
                    <th class="text-right py-3 px-3 font-semibold"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentRestaurants as $rest):
                    $badgeClass = ['free' => 'bg-gray-500/20 text-gray-300', 'pro' => 'bg-emerald-500/20 text-emerald-300', 'max' => 'bg-amber-500/20 text-amber-300'];
                ?>
                <tr class="border-b border-white/5 hover:bg-white/5">
                    <td class="py-3 px-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white font-bold text-sm"><?= e(mb_substr($rest['name'], 0, 1)) ?></div>
                            <span class="font-semibold text-white text-sm"><?= e($rest['name']) ?></span>
                        </div>
                    </td>
                    <td class="py-3 px-3">
                        <span class="px-2 py-1 rounded-lg text-xs font-bold <?= $badgeClass[$rest['plan_code']] ?? 'bg-gray-500/20 text-gray-300' ?>"><?= e($rest['plan_name']) ?></span>
                    </td>
                    <td class="py-3 px-3 text-sm text-gray-400"><?= date('Y-m-d', strtotime($rest['created_at'])) ?></td>
                    <td class="py-3 px-3">
                        <a href="<?= BASE_URL ?>/public/store.php?r=<?= urlencode($rest['slug']) ?>" target="_blank" class="text-emerald-400 hover:underline text-sm">عرض ←</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
