<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pageTitle = 'سجل المدفوعات';

$rid = (int) $_SESSION['store_id'];
$r   = currentStore($pdo);

// -----------------------------------------------------------------------------
// Filters (store-scoped — always constrained to the logged-in store)
// -----------------------------------------------------------------------------
$filterMethod = trim($_GET['method'] ?? '');
$filterFrom   = trim($_GET['from'] ?? '');
$filterTo     = trim($_GET['to'] ?? '');

$sql = "SELECT pay.*,
               pl.name AS plan_name, pl.code AS plan_code
        FROM payments pay
        LEFT JOIN plans pl ON pay.plan_id = pl.id
        WHERE pay.store_id = ?";
$params = [$rid];

if ($filterMethod) { $sql .= ' AND pay.payment_method = ?'; $params[] = $filterMethod; }
if ($filterFrom)   { $sql .= ' AND pay.paid_at >= ?';      $params[] = date('Y-m-d 00:00:00', strtotime($filterFrom)); }
if ($filterTo)     { $sql .= ' AND pay.paid_at <= ?';      $params[] = date('Y-m-d 23:59:59', strtotime($filterTo)); }

$sql .= ' ORDER BY pay.paid_at DESC, pay.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Summary KPIs — always computed against ALL payments for this store
// (independent of active filters, so the big picture stays visible)
// -----------------------------------------------------------------------------
$totalStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt, MAX(paid_at) AS last_paid FROM payments WHERE store_id = ?");
$totalStmt->execute([$rid]);
$totals = $totalStmt->fetch();
$grandTotal = (float) ($totals['total'] ?? 0);
$grandCount = (int)   ($totals['cnt']   ?? 0);
$lastPaid   = $totals['last_paid'] ?? null;

// Filtered total (for the table footer summary)
$filteredTotal = 0.0;
foreach ($payments as $p) $filteredTotal += (float) $p['amount'];

// Method labels / icons
$methodIcons  = ['whatsapp' => '💬', 'bank_transfer' => '🏦', 'cash' => '💵', 'other' => '💳'];
$methodLabels = ['whatsapp' => 'واتساب', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً', 'other' => 'أخرى'];

// Period labels
$periodLabels = ['7days' => '7 أيام', 'monthly' => 'شهري', 'yearly' => 'سنوي', 'forever' => 'دائم', 'lifetime' => 'دائم'];

// WhatsApp support link for payment questions
$supportWhatsapp = preg_replace('/\D/', '', siteSetting($pdo, 'contact_whatsapp', ''));
$waHref = '';
if ($supportWhatsapp) {
    $msg = 'مرحباً، لدي استفسار حول سجل المدفوعات لمتجر «' . $r['name'] . '»';
    $waHref = 'https://wa.me/' . $supportWhatsapp . '?text=' . rawurlencode($msg);
}

require __DIR__ . '/../includes/header_admin.php';
?>

<!-- Hero Banner -->
<div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-emerald-600 via-teal-600 to-emerald-700 p-6 md:p-7 mb-6 text-white shadow-xl">
    <div class="absolute top-0 right-0 w-56 h-56 bg-white/10 rounded-full -translate-y-24 translate-x-24 blur-2xl"></div>
    <div class="absolute bottom-0 left-0 w-44 h-44 bg-white/10 rounded-full translate-y-20 -translate-x-20 blur-2xl"></div>
    <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 bg-white/20 backdrop-blur px-3 py-1 rounded-full text-xs font-bold mb-3">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                سجل المدفوعات الخاص بك
            </div>
            <h2 class="text-2xl md:text-3xl font-bold mb-2">سجل مدفوعاتك الكامل</h2>
            <p class="text-emerald-50 text-sm md:text-base">جميع الدفعات التي تم تسجيلها باسم متجرك مع التفاصيل الكاملة.</p>
        </div>
        <?php if ($waHref): ?>
            <a href="<?= e($waHref) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 bg-white text-emerald-700 px-5 py-2.5 rounded-xl font-bold hover:bg-emerald-50 transition whitespace-nowrap shadow-lg">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86s.274.072.376-.043c.101-.116.433-.506.549-.68.116-.173.231-.145.39-.087s1.011.477 1.184.564c.173.087.288.13.332.202.043.72.043.419-.101.824zm-3.423-14.416c-6.627 0-12 5.373-12 12 0 2.251.61 4.36 1.666 6.17L0 24l4.374-1.625c1.76.98 3.82 1.544 6.05 1.525 6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>
                استفسار عبر واتساب
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Summary cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl p-5 shadow-soft border border-gray-100">
        <div class="flex items-start justify-between mb-2">
            <p class="text-xs text-gray-500 font-semibold">إجمالي ما دفعت</p>
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
            </div>
        </div>
        <p class="text-2xl md:text-3xl font-black text-gray-900">$<?= number_format($grandTotal, 2) ?></p>
        <p class="text-xs text-gray-500 mt-1">منذ بدء اشتراكك</p>
    </div>

    <div class="bg-white rounded-2xl p-5 shadow-soft border border-gray-100">
        <div class="flex items-start justify-between mb-2">
            <p class="text-xs text-gray-500 font-semibold">عدد الدفعات</p>
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500 to-indigo-600 flex items-center justify-center text-white">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
        </div>
        <p class="text-2xl md:text-3xl font-black text-gray-900"><?= $grandCount ?></p>
        <p class="text-xs text-gray-500 mt-1"><?= $grandCount === 1 ? 'دفعة واحدة' : 'دفعة' ?></p>
    </div>

    <div class="bg-white rounded-2xl p-5 shadow-soft border border-gray-100">
        <div class="flex items-start justify-between mb-2">
            <p class="text-xs text-gray-500 font-semibold">آخر دفعة</p>
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
        </div>
        <?php if ($lastPaid): ?>
            <p class="text-xl md:text-2xl font-black text-gray-900"><?= date('Y-m-d', strtotime($lastPaid)) ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= date('H:i', strtotime($lastPaid)) ?> — منذ <?= (int) floor((time() - strtotime($lastPaid)) / 86400) ?> يوم</p>
        <?php else: ?>
            <p class="text-xl md:text-2xl font-black text-gray-400">—</p>
            <p class="text-xs text-gray-500 mt-1">لا توجد دفعات بعد</p>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<?php if ($grandCount > 0): ?>
<div class="bg-white rounded-2xl p-4 mb-6 shadow-soft border border-gray-100">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <select name="method" class="px-4 py-2.5 rounded-xl border-2 border-gray-100 font-semibold focus:border-emerald-500 transition">
            <option value="">كل طرق الدفع</option>
            <?php foreach ($methodLabels as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $filterMethod === $k ? 'selected' : '' ?>><?= $methodIcons[$k] ?? '' ?> <?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($filterFrom) ?>" class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" title="من تاريخ">
        <input type="date" name="to" value="<?= e($filterTo) ?>" class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" title="إلى تاريخ">
        <div class="flex gap-2">
            <button class="flex-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold hover:opacity-95 transition">تطبيق</button>
            <?php if ($filterMethod || $filterFrom || $filterTo): ?>
                <a href="<?= BASE_URL ?>/admin/payments.php" class="px-4 py-2.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold transition">مسح</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($filterMethod || $filterFrom || $filterTo): ?>
        <div class="mt-3 flex items-center gap-2 text-xs">
            <span class="text-gray-500">النتائج المطابقة:</span>
            <span class="font-bold text-emerald-600"><?= count($payments) ?> دفعة</span>
            <span class="text-gray-400">•</span>
            <span class="font-bold text-emerald-600">$<?= number_format($filteredTotal, 2) ?></span>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Payments List -->
<?php if (!$payments): ?>
    <div class="bg-white rounded-2xl p-12 text-center shadow-soft border border-gray-100">
        <div class="w-20 h-20 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 flex items-center justify-center">
            <svg class="w-10 h-10 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
        </div>
        <?php if ($filterMethod || $filterFrom || $filterTo): ?>
            <h3 class="text-xl font-bold text-gray-900 mb-2">لا توجد نتائج مطابقة</h3>
            <p class="text-gray-500 mb-4">لا توجد دفعات ضمن الفلتر الحالي. جرّب تعديل معايير البحث.</p>
            <a href="<?= BASE_URL ?>/admin/payments.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-500 text-white font-bold hover:bg-emerald-600 transition">
                إظهار كل الدفعات
            </a>
        <?php else: ?>
            <h3 class="text-xl font-bold text-gray-900 mb-2">لا توجد دفعات بعد</h3>
            <p class="text-gray-500 mb-4">ستظهر دفعاتك هنا بعد أول اشتراك في أي باقة مدفوعة.</p>
            <a href="<?= BASE_URL ?>/admin/upgrade.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold hover:opacity-95 transition shadow-lg">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                تصفّح الباقات
            </a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <!-- Desktop table -->
    <div class="hidden md:block bg-white rounded-2xl overflow-hidden shadow-soft border border-gray-100">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-right text-xs text-gray-600 uppercase tracking-wider">
                        <th class="py-3 px-4 font-bold">التاريخ والوقت</th>
                        <th class="py-3 px-4 font-bold">الباقة</th>
                        <th class="py-3 px-4 font-bold">المبلغ</th>
                        <th class="py-3 px-4 font-bold">طريقة الدفع</th>
                        <th class="py-3 px-4 font-bold">المرجع / ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p):
                        $mIcon  = $methodIcons[$p['payment_method']]  ?? '';
                        $mLabel = $methodLabels[$p['payment_method']] ?? ($p['payment_method'] ?: '—');
                        $periodLabel = $periodLabels[$p['period']] ?? '';
                        $currency = $p['currency'] ?: 'USD';
                        $currencyPrefix = $currency === 'USD' ? '$' : $currency . ' ';
                    ?>
                        <tr class="border-t border-gray-100 hover:bg-gray-50 transition">
                            <td class="py-4 px-4 whitespace-nowrap">
                                <div class="font-semibold text-gray-900"><?= date('Y-m-d', strtotime($p['paid_at'])) ?></div>
                                <div class="text-xs text-gray-500"><?= date('H:i', strtotime($p['paid_at'])) ?></div>
                            </td>
                            <td class="py-4 px-4">
                                <?php if ($p['plan_name']): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-bold">
                                        <?= e($p['plan_name']) ?>
                                        <?php if ($periodLabel): ?>
                                            <span class="opacity-70">/ <?= $periodLabel ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">بدون باقة</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-4 font-black text-emerald-600 whitespace-nowrap text-base">
                                <?= $currencyPrefix ?><?= number_format((float) $p['amount'], 2) ?>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap">
                                <?php if ($p['payment_method']): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-gray-100 text-xs font-semibold text-gray-700"><?= $mIcon ?> <?= e($mLabel) ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-4 max-w-xs">
                                <?php if ($p['payment_reference']): ?>
                                    <div class="text-xs text-gray-700 font-mono bg-gray-50 inline-block px-2 py-0.5 rounded"><?= e($p['payment_reference']) ?></div>
                                <?php endif; ?>
                                <?php if ($p['notes']): ?>
                                    <div class="text-xs text-gray-500 mt-1 truncate" title="<?= e($p['notes']) ?>"><?= e($p['notes']) ?></div>
                                <?php endif; ?>
                                <?php if (!$p['payment_reference'] && !$p['notes']): ?>
                                    <span class="text-gray-400 text-xs">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mobile cards -->
    <div class="md:hidden space-y-3">
        <?php foreach ($payments as $p):
            $mIcon  = $methodIcons[$p['payment_method']]  ?? '';
            $mLabel = $methodLabels[$p['payment_method']] ?? ($p['payment_method'] ?: '—');
            $periodLabel = $periodLabels[$p['period']] ?? '';
            $currency = $p['currency'] ?: 'USD';
            $currencyPrefix = $currency === 'USD' ? '$' : $currency . ' ';
        ?>
            <div class="bg-white rounded-2xl p-4 shadow-soft border border-gray-100">
                <div class="flex items-start justify-between gap-3 mb-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs text-gray-500 mb-0.5"><?= date('Y-m-d • H:i', strtotime($p['paid_at'])) ?></div>
                        <?php if ($p['plan_name']): ?>
                            <div class="text-sm font-bold text-gray-900">
                                <?= e($p['plan_name']) ?>
                                <?php if ($periodLabel): ?>
                                    <span class="text-xs text-gray-500 font-normal">/ <?= $periodLabel ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-sm font-bold text-gray-700">دفعة</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-lg font-black text-emerald-600 whitespace-nowrap"><?= $currencyPrefix ?><?= number_format((float) $p['amount'], 2) ?></div>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <?php if ($p['payment_method']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-gray-100 font-semibold text-gray-700"><?= $mIcon ?> <?= e($mLabel) ?></span>
                    <?php endif; ?>
                    <?php if ($p['payment_reference']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-gray-50 font-mono text-gray-600"><?= e($p['payment_reference']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($p['notes']): ?>
                    <div class="mt-2 pt-2 border-t border-gray-100 text-xs text-gray-500"><?= e($p['notes']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Info footer -->
    <div class="mt-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-start gap-3">
        <svg class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div class="text-xs text-emerald-900 leading-relaxed">
            <p class="font-bold mb-1">ملاحظة:</p>
            <p>هذا السجل يعرض جميع الدفعات المسجّلة باسم متجرك. إذا لاحظت أي اختلاف أو كان لديك استفسار حول إحدى الدفعات، تواصل معنا عبر واتساب وسنرد عليك في أقرب وقت.</p>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>
