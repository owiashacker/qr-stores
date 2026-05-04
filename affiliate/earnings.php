<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'الأرباح والمدفوعات';
require_once __DIR__ . '/../includes/header_affiliate.php';

$affId = (int) $aff['id'];

// Filter
$status = $_GET['status'] ?? ''; // '', 'pending', 'paid'
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');

$sql = "SELECT pay.id, pay.amount, pay.currency, pay.paid_at,
               pay.affiliate_amount, pay.affiliate_commission_rate,
               pay.affiliate_paid, pay.affiliate_paid_at,
               s.name AS store_name, s.slug AS store_slug,
               pl.name AS plan_name
        FROM payments pay
        LEFT JOIN stores s ON s.id = pay.store_id
        LEFT JOIN plans pl ON pl.id = pay.plan_id
        WHERE pay.affiliate_id = ?";
$params = [$affId];
if ($status === 'pending') $sql .= ' AND pay.affiliate_paid = 0';
if ($status === 'paid')    $sql .= ' AND pay.affiliate_paid = 1';
if ($from) { $sql .= ' AND pay.paid_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($from)); }
if ($to)   { $sql .= ' AND pay.paid_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($to)); }
$sql .= ' ORDER BY pay.paid_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Aggregate
$totalEarned = 0;
$totalPaid = 0;
foreach ($rows as $r) {
    $totalEarned += (float) $r['affiliate_amount'];
    if ($r['affiliate_paid']) $totalPaid += (float) $r['affiliate_amount'];
}
$totalPending = $totalEarned - $totalPaid;
?>

<div class="max-w-6xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl md:text-3xl font-black text-gray-900 mb-1">الأرباح والمدفوعات</h1>
        <p class="text-gray-500 text-sm">سجل كامل لكل عمولاتك من إحالاتك</p>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-3 gap-3 md:gap-4">
        <div class="bg-white rounded-2xl shadow-card p-4 md:p-5">
            <p class="text-xs text-gray-500 mb-2">الإجمالي</p>
            <p class="text-2xl md:text-3xl font-black text-gray-900"><?= number_format($totalEarned) ?></p>
        </div>
        <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-4 md:p-5">
            <p class="text-xs text-emerald-700 mb-2">مدفوع</p>
            <p class="text-2xl md:text-3xl font-black text-emerald-700"><?= number_format($totalPaid) ?></p>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 md:p-5">
            <p class="text-xs text-amber-700 mb-2">معلّق</p>
            <p class="text-2xl md:text-3xl font-black text-amber-700"><?= number_format($totalPending) ?></p>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" class="bg-white rounded-2xl shadow-card p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
        <select name="status" class="px-4 py-2.5 rounded-xl border-2 border-gray-100 font-semibold">
            <option value="">كل العمولات</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>المعلّقة</option>
            <option value="paid"    <?= $status === 'paid'    ? 'selected' : '' ?>>المدفوعة</option>
        </select>
        <input type="date" name="from" value="<?= e($from) ?>" placeholder="من" class="px-4 py-2.5 rounded-xl border-2 border-gray-100">
        <input type="date" name="to"   value="<?= e($to)   ?>" placeholder="إلى" class="px-4 py-2.5 rounded-xl border-2 border-gray-100">
        <button class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold shadow">تطبيق</button>
    </form>

    <!-- Table -->
    <div class="bg-white rounded-2xl shadow-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-right text-xs text-gray-500 uppercase">
                        <th class="px-4 py-3 font-bold">التاريخ</th>
                        <th class="px-4 py-3 font-bold">المتجر</th>
                        <th class="px-4 py-3 font-bold">الباقة</th>
                        <th class="px-4 py-3 font-bold">مبلغ الفاتورة</th>
                        <th class="px-4 py-3 font-bold">النسبة</th>
                        <th class="px-4 py-3 font-bold">عمولتي</th>
                        <th class="px-4 py-3 font-bold">حالة الدفع</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (!$rows): ?>
                        <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">
                            <p class="text-4xl mb-2">💸</p>
                            <p>لا توجد عمولات بعد. ستظهر هنا بعد أول فاتورة لمتجر مُحال.</p>
                        </td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap"><?= date('Y-m-d', strtotime($r['paid_at'])) ?></td>
                            <td class="px-4 py-3">
                                <p class="font-bold text-gray-900"><?= e($r['store_name'] ?? '(محذوف)') ?></p>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700"><?= e($r['plan_name'] ?? '—') ?></td>
                            <td class="px-4 py-3 font-mono text-sm text-gray-700 whitespace-nowrap">
                                <?= number_format((float) $r['amount']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm font-bold text-orange-600"><?= number_format((float) $r['affiliate_commission_rate'], 2) ?>%</td>
                            <td class="px-4 py-3 font-mono font-bold text-emerald-600 whitespace-nowrap">
                                <?= number_format((float) $r['affiliate_amount']) ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($r['affiliate_paid']): ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">
                                        ✓ مدفوع
                                    </span>
                                    <p class="text-[10px] text-gray-400 mt-1">
                                        <?= $r['affiliate_paid_at'] ? date('Y-m-d', strtotime($r['affiliate_paid_at'])) : '' ?>
                                    </p>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-xs font-bold">
                                        ⏱ معلّق
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_affiliate.php'; ?>
