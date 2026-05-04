<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'المتاجر المُحالة';
require_once __DIR__ . '/../includes/header_affiliate.php';

$affId = (int) $aff['id'];

// Search & filter
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$sql = "SELECT s.id, s.name, s.slug, s.email, s.phone, s.is_active, s.referred_at,
               s.affiliate_commission_rate AS store_rate,
               p.name AS plan_name, p.code AS plan_code,
               (SELECT COUNT(*) FROM payments py WHERE py.store_id = s.id AND py.affiliate_id = ?) AS payments_count,
               (SELECT COALESCE(SUM(py.affiliate_amount), 0) FROM payments py WHERE py.store_id = s.id AND py.affiliate_id = ?) AS total_commission
        FROM stores s
        LEFT JOIN plans p ON p.id = s.plan_id
        WHERE s.affiliate_id = ?";
$params = [$affId, $affId, $affId];

if ($search) {
    $sql .= ' AND (s.name LIKE ? OR s.slug LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status === 'active')   $sql .= ' AND s.is_active = 1';
if ($status === 'inactive') $sql .= ' AND s.is_active = 0';

$sql .= ' ORDER BY s.referred_at DESC, s.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stores = $stmt->fetchAll();
?>

<div class="max-w-6xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl md:text-3xl font-black text-gray-900 mb-1">المتاجر المُحالة</h1>
        <p class="text-gray-500 text-sm">جميع المتاجر التي اشتركت بالمنصّة عبر رابطك (<?= count($stores) ?>)</p>
    </div>

    <!-- Filter -->
    <form method="GET" class="bg-white rounded-2xl shadow-card p-4 flex flex-col sm:flex-row gap-3">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="ابحث باسم المتجر..."
               class="flex-1 px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition">
        <select name="status" class="px-4 py-2.5 rounded-xl border-2 border-gray-100 font-semibold">
            <option value="">كل الحالات</option>
            <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>نشط</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>موقوف</option>
        </select>
        <button class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold shadow">بحث</button>
    </form>

    <!-- Table -->
    <div class="bg-white rounded-2xl shadow-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-right text-xs text-gray-500 uppercase">
                        <th class="px-4 py-3 font-bold">المتجر</th>
                        <th class="px-4 py-3 font-bold">الباقة</th>
                        <th class="px-4 py-3 font-bold">تاريخ الإحالة</th>
                        <th class="px-4 py-3 font-bold">دفعاتي</th>
                        <th class="px-4 py-3 font-bold">إجمالي عمولاتي</th>
                        <th class="px-4 py-3 font-bold">الحالة</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (!$stores): ?>
                        <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">
                            <p class="text-4xl mb-2">📭</p>
                            <p>لا توجد متاجر مُحالة حسب الفلتر.</p>
                        </td></tr>
                    <?php else: foreach ($stores as $s): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-orange-100 to-amber-100 flex items-center justify-center text-orange-600 font-bold flex-shrink-0">
                                        <?= e(mb_substr($s['name'], 0, 1)) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-bold text-gray-900 truncate"><?= e($s['name']) ?></p>
                                        <p class="text-xs text-gray-400">/<?= e($s['slug']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-sm text-gray-700"><?= e($s['plan_name'] ?? '—') ?></span>
                                <?php if ($s['store_rate'] !== null): ?>
                                    <p class="text-[10px] text-gray-400">عمولة هذا المتجر: <?= number_format((float) $s['store_rate'], 2) ?>%</p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                <?= $s['referred_at'] ? date('Y-m-d', strtotime($s['referred_at'])) : '—' ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-center">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-orange-50 text-orange-600 font-bold text-xs">
                                    <?= (int) $s['payments_count'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-bold text-emerald-600 text-sm whitespace-nowrap">
                                <?= number_format((float) $s['total_commission']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 rounded-full text-xs font-bold
                                    <?= $s['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' ?>">
                                    <?= $s['is_active'] ? 'نشط' : 'موقوف' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_affiliate.php'; ?>
