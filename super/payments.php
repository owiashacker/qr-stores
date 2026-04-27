<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'سجل المدفوعات';

// -----------------------------------------------------------------------------
// Actions: manual create / delete / edit
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $storeId = (int) ($_POST['store_id'] ?? 0);
        $planId  = (int) ($_POST['plan_id'] ?? 0) ?: null;
        $amount  = (float) ($_POST['amount'] ?? 0);
        $method  = trim($_POST['payment_method'] ?? '');
        $ref     = trim($_POST['payment_reference'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        $paidAt  = trim($_POST['paid_at'] ?? '');
        $paidAt  = $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt)) : date('Y-m-d H:i:s');

        // Snapshot plan period/currency at the moment of the payment.
        $period = null;
        $currency = 'USD';
        if ($planId) {
            $pStmt = $pdo->prepare('SELECT period, currency FROM plans WHERE id = ?');
            $pStmt->execute([$planId]);
            $planRow = $pStmt->fetch();
            if ($planRow) {
                $period   = $planRow['period'];
                $currency = $planRow['currency'] ?: 'USD';
            }
        }

        if (!$storeId || $amount <= 0) {
            flash('error', 'يجب اختيار المطعم وإدخال مبلغ صحيح');
        } else {
            $pdo->prepare('INSERT INTO payments (store_id, plan_id, amount, currency, period, payment_method, payment_reference, notes, paid_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $storeId, $planId, $amount, $currency, $period,
                    $method ?: null, $ref ?: null, $notes ?: null, $paidAt,
                    $_SESSION['admin_id'],
                ]);
            flash('success', 'تم تسجيل الدفعة');
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
            flash('success', 'تم حذف الدفعة');
        }
    } elseif ($action === 'update') {
        $id       = (int) ($_POST['id'] ?? 0);
        $amount   = (float) ($_POST['amount'] ?? 0);
        $method   = trim($_POST['payment_method'] ?? '');
        $ref      = trim($_POST['payment_reference'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');
        $paidAt   = trim($_POST['paid_at'] ?? '');
        $paidAt   = $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt)) : date('Y-m-d H:i:s');

        if ($id && $amount > 0) {
            $pdo->prepare('UPDATE payments SET amount = ?, payment_method = ?, payment_reference = ?, notes = ?, paid_at = ? WHERE id = ?')
                ->execute([$amount, $method ?: null, $ref ?: null, $notes ?: null, $paidAt, $id]);
            flash('success', 'تم تعديل الدفعة');
        }
    }

    // Preserve filters on redirect
    $qs = http_build_query(array_filter([
        'store_id' => $_GET['store_id'] ?? null,
        'method'   => $_GET['method']   ?? null,
        'from'     => $_GET['from']     ?? null,
        'to'       => $_GET['to']       ?? null,
    ]));
    redirect(BASE_URL . '/super/payments.php' . ($qs ? "?$qs" : ''));
}

// -----------------------------------------------------------------------------
// Filters + list
// -----------------------------------------------------------------------------
$filterStoreId = (int) ($_GET['store_id'] ?? 0);
$filterMethod  = trim($_GET['method'] ?? '');
$filterFrom    = trim($_GET['from'] ?? '');
$filterTo      = trim($_GET['to'] ?? '');

$sql = "SELECT pay.*, s.name AS store_name, s.slug AS store_slug,
               pl.name AS plan_name, pl.code AS plan_code,
               a.name AS admin_name
        FROM payments pay
        LEFT JOIN stores s ON pay.store_id = s.id
        LEFT JOIN plans pl ON pay.plan_id  = pl.id
        LEFT JOIN admins a ON pay.recorded_by = a.id
        WHERE 1=1";
$params = [];

if ($filterStoreId) { $sql .= ' AND pay.store_id = ?'; $params[] = $filterStoreId; }
if ($filterMethod)  { $sql .= ' AND pay.payment_method = ?'; $params[] = $filterMethod; }
if ($filterFrom)    { $sql .= ' AND pay.paid_at >= ?'; $params[] = date('Y-m-d 00:00:00', strtotime($filterFrom)); }
if ($filterTo)      { $sql .= ' AND pay.paid_at <= ?'; $params[] = date('Y-m-d 23:59:59', strtotime($filterTo)); }

$sql .= ' ORDER BY pay.paid_at DESC, pay.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Summary KPIs (respect current filters)
// -----------------------------------------------------------------------------
$totalAmount = 0.0;
$totalCount  = count($payments);
foreach ($payments as $p) $totalAmount += (float) $p['amount'];

$thisMonthStart = date('Y-m-01 00:00:00');
$thisMonth = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE paid_at >= ?");
$thisMonth->execute([$thisMonthStart]);
$monthAmount = (float) $thisMonth->fetchColumn();

$grandTotal = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$grandCount = (int)   $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();

// -----------------------------------------------------------------------------
// Data for dropdowns
// -----------------------------------------------------------------------------
$storesList = $pdo->query('SELECT id, name FROM stores ORDER BY name')->fetchAll();
$plansList  = $pdo->query('SELECT id, name, price, period, currency FROM plans WHERE is_active = 1 ORDER BY sort_order, price')->fetchAll();

// Method labels/icons
$methodIcons  = ['whatsapp' => '💬', 'bank_transfer' => '🏦', 'cash' => '💵', 'other' => '💳'];
$methodLabels = ['whatsapp' => 'واتساب', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً', 'other' => 'أخرى'];

// Active filtered-store info (for page header chip)
$filteredStoreName = '';
if ($filterStoreId) {
    foreach ($storesList as $s) if ($s['id'] == $filterStoreId) { $filteredStoreName = $s['name']; break; }
}

require __DIR__ . '/../includes/header_super.php';
?>

<!-- Summary cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="card rounded-2xl p-5">
        <p class="text-xs text-gray-500 mb-1">إجمالي المدفوعات (الكل)</p>
        <p class="text-2xl font-black text-emerald-400">$<?= number_format($grandTotal, 2) ?></p>
        <p class="text-xs text-gray-500 mt-1"><?= $grandCount ?> عملية</p>
    </div>
    <div class="card rounded-2xl p-5">
        <p class="text-xs text-gray-500 mb-1">هذا الشهر</p>
        <p class="text-2xl font-black text-sky-400">$<?= number_format($monthAmount, 2) ?></p>
        <p class="text-xs text-gray-500 mt-1">منذ <?= date('Y-m-01') ?></p>
    </div>
    <div class="card rounded-2xl p-5">
        <p class="text-xs text-gray-500 mb-1">ضمن الفلتر الحالي</p>
        <p class="text-2xl font-black text-amber-400">$<?= number_format($totalAmount, 2) ?></p>
        <p class="text-xs text-gray-500 mt-1"><?= $totalCount ?> عملية ضمن النتائج</p>
    </div>
    <div class="card rounded-2xl p-5 flex flex-col justify-between">
        <p class="text-xs text-gray-500 mb-1">إجراء</p>
        <button onclick="openCreateModal()" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-sm">
            + تسجيل دفعة يدوية
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card rounded-2xl p-4 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <select name="store_id" class="px-4 py-2.5 rounded-xl border-2 font-semibold">
            <option value="0">كل المطاعم</option>
            <?php foreach ($storesList as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filterStoreId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="method" class="px-4 py-2.5 rounded-xl border-2 font-semibold">
            <option value="">كل طرق الدفع</option>
            <?php foreach ($methodLabels as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $filterMethod === $k ? 'selected' : '' ?>><?= $methodIcons[$k] ?? '' ?> <?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <div>
            <input type="date" name="from" value="<?= e($filterFrom) ?>" class="w-full px-4 py-2.5 rounded-xl border-2" title="من تاريخ">
        </div>
        <div>
            <input type="date" name="to" value="<?= e($filterTo) ?>" class="w-full px-4 py-2.5 rounded-xl border-2" title="إلى تاريخ">
        </div>
        <div class="flex gap-2">
            <button class="flex-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold">تطبيق</button>
            <?php if ($filterStoreId || $filterMethod || $filterFrom || $filterTo): ?>
                <a href="<?= BASE_URL ?>/super/payments.php" class="px-4 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-gray-300 font-bold">مسح</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($filteredStoreName): ?>
        <div class="mt-3 text-xs text-gray-400">
            تعرض الآن مدفوعات: <span class="font-bold text-emerald-400"><?= e($filteredStoreName) ?></span>
        </div>
    <?php endif; ?>
</div>

<!-- Table -->
<?php if (!$payments): ?>
    <div class="card rounded-2xl p-12 text-center">
        <div class="text-6xl mb-4">💰</div>
        <h3 class="text-xl font-bold text-white mb-2">لا توجد مدفوعات مسجّلة</h3>
        <p class="text-gray-500">ستظهر الدفعات هنا بمجرد قبول طلبات الاشتراك أو إضافتها يدوياً.</p>
    </div>
<?php else: ?>
    <div class="card rounded-2xl overflow-hidden">
        <div class="table-scroll">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-right text-xs text-gray-400 uppercase tracking-wider">
                        <th class="py-3 px-4">تاريخ الدفع</th>
                        <th class="py-3 px-4">المطعم</th>
                        <th class="py-3 px-4">الباقة</th>
                        <th class="py-3 px-4">المبلغ</th>
                        <th class="py-3 px-4">الطريقة</th>
                        <th class="py-3 px-4">المرجع / ملاحظات</th>
                        <th class="py-3 px-4">سجّلها</th>
                        <th class="py-3 px-4 text-center">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p):
                        $mIcon  = $methodIcons[$p['payment_method']]  ?? '';
                        $mLabel = $methodLabels[$p['payment_method']] ?? ($p['payment_method'] ?: '—');
                        $periodLabel = ['monthly' => 'شهري', 'yearly' => 'سنوي', 'lifetime' => 'دائم'][$p['period']] ?? '';
                    ?>
                        <tr class="border-t border-white/5 hover:bg-white/5">
                            <td class="py-3 px-4 whitespace-nowrap text-gray-300"><?= date('Y-m-d H:i', strtotime($p['paid_at'])) ?></td>
                            <td class="py-3 px-4">
                                <?php if ($p['store_name']): ?>
                                    <a href="?store_id=<?= (int) $p['store_id'] ?>" class="font-bold text-white hover:text-emerald-400 transition"><?= e($p['store_name']) ?></a>
                                <?php else: ?>
                                    <span class="text-gray-500 italic">(محذوف)</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4">
                                <?php if ($p['plan_name']): ?>
                                    <span class="text-gray-200"><?= e($p['plan_name']) ?></span>
                                    <?php if ($periodLabel): ?>
                                        <span class="text-xs text-gray-500">/ <?= $periodLabel ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-500">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 font-bold text-emerald-400 whitespace-nowrap">
                                <?= e($p['currency'] ?: 'USD') === 'USD' ? '$' : e($p['currency']) . ' ' ?><?= number_format((float) $p['amount'], 2) ?>
                            </td>
                            <td class="py-3 px-4 whitespace-nowrap">
                                <?php if ($mIcon || $mLabel !== '—'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg bg-white/5 text-xs font-semibold text-gray-200"><?= $mIcon ?> <?= e($mLabel) ?></span>
                                <?php else: ?>
                                    <span class="text-gray-500">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 max-w-xs">
                                <?php if ($p['payment_reference']): ?>
                                    <p class="text-xs text-gray-300 font-mono"><?= e($p['payment_reference']) ?></p>
                                <?php endif; ?>
                                <?php if ($p['notes']): ?>
                                    <p class="text-xs text-gray-400 mt-0.5 truncate" title="<?= e($p['notes']) ?>"><?= e($p['notes']) ?></p>
                                <?php endif; ?>
                                <?php if (!$p['payment_reference'] && !$p['notes']): ?>
                                    <span class="text-gray-500">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-xs text-gray-400 whitespace-nowrap"><?= $p['admin_name'] ? e($p['admin_name']) : '—' ?></td>
                            <td class="py-3 px-4">
                                <div class="flex items-center justify-center gap-1">
                                    <button type="button"
                                            onclick='openEditModal(<?= json_encode([
                                                "id"        => (int) $p["id"],
                                                "store"     => $p["store_name"] ?: "",
                                                "amount"    => (float) $p["amount"],
                                                "method"    => $p["payment_method"] ?: "",
                                                "reference" => $p["payment_reference"] ?: "",
                                                "notes"     => $p["notes"] ?: "",
                                                "paid_at"   => date("Y-m-d\\TH:i", strtotime($p["paid_at"])),
                                            ], JSON_UNESCAPED_UNICODE) ?>)'
                                            class="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-gray-300" title="تعديل">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('حذف هذه الدفعة نهائياً؟');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <button class="p-2 rounded-lg bg-white/5 hover:bg-red-500/20 text-red-400" title="حذف">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Create Modal -->
<div id="createModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeCreateModal()">
    <div class="card rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="p-6 border-b border-white/5">
                <h3 class="text-lg font-bold text-white">تسجيل دفعة يدوية</h3>
                <p class="text-sm text-gray-400 mt-1">لتسجيل دفعة نقدية أو تحويل لم يمرّ عبر نظام الطلبات</p>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">المطعم <span class="text-red-400">*</span></label>
                        <select name="store_id" required class="w-full px-4 py-2.5 rounded-xl border-2">
                            <option value="">— اختر —</option>
                            <?php foreach ($storesList as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filterStoreId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">الباقة <span class="text-gray-500 font-normal">(اختياري)</span></label>
                        <select name="plan_id" id="create-plan" class="w-full px-4 py-2.5 rounded-xl border-2" onchange="suggestAmount()">
                            <option value="0">— بدون —</option>
                            <?php foreach ($plansList as $pl): ?>
                                <option value="<?= $pl['id'] ?>" data-price="<?= (float) $pl['price'] ?>"><?= e($pl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">المبلغ <span class="text-red-400">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="create-amount" required class="w-full px-4 py-2.5 rounded-xl border-2" placeholder="0.00">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">تاريخ الدفع</label>
                        <input type="datetime-local" name="paid_at" step="60" class="w-full px-4 py-2.5 rounded-xl border-2">
                        <p class="text-xs text-gray-500 mt-1">اتركه فارغاً = الآن</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">طريقة الدفع</label>
                    <select name="payment_method" class="w-full px-4 py-2.5 rounded-xl border-2">
                        <?php foreach ($methodLabels as $k => $lbl): ?>
                            <option value="<?= $k ?>"><?= $methodIcons[$k] ?? '' ?> <?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">رقم/مرجع العملية <span class="text-gray-500 font-normal">(اختياري)</span></label>
                    <input type="text" name="payment_reference" class="w-full px-4 py-2.5 rounded-xl border-2" placeholder="رقم التحويل البنكي / رقم الإيصال...">
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">ملاحظات <span class="text-gray-500 font-normal">(اختياري)</span></label>
                    <textarea name="notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border-2" placeholder="مثلاً: دفع نقدي في الاجتماع..."></textarea>
                </div>
            </div>
            <div class="p-6 border-t border-white/5 flex justify-end gap-3">
                <button type="button" onclick="closeCreateModal()" class="px-5 py-2.5 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold">حفظ الدفعة</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeEditModal()">
    <div class="card rounded-2xl w-full max-w-lg">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit-id">
            <div class="p-6 border-b border-white/5">
                <h3 class="text-lg font-bold text-white">تعديل دفعة</h3>
                <p class="text-sm text-gray-400 mt-1" id="edit-store-info"></p>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">المبلغ</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="edit-amount" required class="w-full px-4 py-2.5 rounded-xl border-2">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">تاريخ الدفع</label>
                        <input type="datetime-local" name="paid_at" id="edit-paid-at" step="60" class="w-full px-4 py-2.5 rounded-xl border-2">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">طريقة الدفع</label>
                    <select name="payment_method" id="edit-method" class="w-full px-4 py-2.5 rounded-xl border-2">
                        <option value="">—</option>
                        <?php foreach ($methodLabels as $k => $lbl): ?>
                            <option value="<?= $k ?>"><?= $methodIcons[$k] ?? '' ?> <?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">رقم/مرجع العملية</label>
                    <input type="text" name="payment_reference" id="edit-reference" class="w-full px-4 py-2.5 rounded-xl border-2">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">ملاحظات</label>
                    <textarea name="notes" id="edit-notes" rows="2" class="w-full px-4 py-2.5 rounded-xl border-2"></textarea>
                </div>
            </div>
            <div class="p-6 border-t border-white/5 flex justify-end gap-3">
                <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold">حفظ التعديل</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
}
function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
}
// Auto-fill amount from selected plan price (only if amount field is empty).
function suggestAmount() {
    const sel = document.getElementById('create-plan');
    const amt = document.getElementById('create-amount');
    const price = parseFloat(sel.options[sel.selectedIndex]?.dataset.price || 0);
    if (!amt.value && price > 0) amt.value = price.toFixed(2);
}

function openEditModal(p) {
    document.getElementById('edit-id').value = p.id;
    document.getElementById('edit-store-info').textContent = p.store ? ('مطعم: ' + p.store) : '';
    document.getElementById('edit-amount').value = p.amount;
    document.getElementById('edit-method').value = p.method || '';
    document.getElementById('edit-reference').value = p.reference || '';
    document.getElementById('edit-notes').value = p.notes || '';
    document.getElementById('edit-paid-at').value = p.paid_at || '';
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('editModal').classList.remove('flex');
}
</script>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
