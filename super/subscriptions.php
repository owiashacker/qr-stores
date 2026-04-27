<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'طلبات الاشتراك';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';
    $reqId = (int) ($_POST['request_id'] ?? 0);

    if ($action === 'approve' && $reqId) {
        $req = $pdo->prepare('SELECT * FROM subscription_requests WHERE id = ?');
        $req->execute([$reqId]);
        $req = $req->fetch();
        if ($req && $req['status'] === 'pending') {
            $plan = $pdo->prepare('SELECT * FROM plans WHERE id = ?');
            $plan->execute([$req['plan_id']]);
            $plan = $plan->fetch();

            // Admin-chosen expiry takes priority; falls back to plan default
            $customExpiry = trim($_POST['custom_expiry'] ?? '');
            if ($customExpiry) {
                $expires = date('Y-m-d H:i:s', strtotime($customExpiry));
            } elseif ($plan && $plan['period'] === 'monthly') {
                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
            } elseif ($plan && $plan['period'] === 'yearly') {
                $expires = date('Y-m-d H:i:s', strtotime('+365 days'));
            } else {
                $expires = null;
            }

            $pdo->prepare('UPDATE stores SET plan_id = ?, subscription_expires_at = ?, subscription_status = ? WHERE id = ?')
                ->execute([$req['plan_id'], $expires, 'active', $req['store_id']]);
            $pdo->prepare('UPDATE subscription_requests SET status = ?, handled_by = ?, handled_at = NOW(), admin_notes = ? WHERE id = ?')
                ->execute(['approved', $_SESSION['admin_id'], trim($_POST['admin_notes'] ?? ''), $reqId]);

            // Record the payment as part of the same approval action so the
            // super-admin payments log always reflects every activated subscription.
            // Admin can override the charged amount (e.g. discount / promo price)
            // — if not supplied, we snapshot the plan's advertised price.
            $chargedAmount = isset($_POST['amount']) && $_POST['amount'] !== ''
                ? (float) $_POST['amount']
                : (float) ($plan['price'] ?? 0);
            $paymentRef = trim($_POST['payment_reference'] ?? ($req['payment_ref'] ?? ''));
            $paidAtInput = trim($_POST['paid_at'] ?? '');
            $paidAt = $paidAtInput ? date('Y-m-d H:i:s', strtotime($paidAtInput)) : date('Y-m-d H:i:s');
            $paymentNotes = trim($_POST['admin_notes'] ?? '');

            if ($chargedAmount > 0) {
                $pdo->prepare('INSERT INTO payments (store_id, plan_id, subscription_request_id, amount, currency, period, payment_method, payment_reference, notes, paid_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $req['store_id'],
                        $req['plan_id'],
                        $reqId,
                        $chargedAmount,
                        $plan['currency'] ?? 'USD',
                        $plan['period'] ?? null,
                        $req['payment_method'],
                        $paymentRef ?: null,
                        $paymentNotes ?: null,
                        $paidAt,
                        $_SESSION['admin_id'],
                    ]);
            }

            flash('success', 'تم قبول الطلب وتفعيل الباقة وتسجيل الدفعة');
        }
    } elseif ($action === 'reject' && $reqId) {
        $pdo->prepare('UPDATE subscription_requests SET status = ?, handled_by = ?, handled_at = NOW(), admin_notes = ? WHERE id = ?')
            ->execute(['rejected', $_SESSION['admin_id'], trim($_POST['admin_notes'] ?? ''), $reqId]);
        flash('success', 'تم رفض الطلب');
    }
    redirect(BASE_URL . '/super/subscriptions.php' . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
}

$filter = $_GET['filter'] ?? 'pending';
$sql = "SELECT sr.*, r.name AS restaurant_name, r.email, r.slug, r.whatsapp, p.name AS plan_name, p.price, p.period FROM subscription_requests sr JOIN stores r ON sr.store_id = r.id JOIN plans p ON sr.plan_id = p.id";
if ($filter !== 'all') $sql .= " WHERE sr.status = " . $pdo->quote($filter);
$sql .= " ORDER BY sr.created_at DESC";
$requests = $pdo->query($sql)->fetchAll();

$counts = [
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE status = 'pending'")->fetchColumn(),
    'approved' => (int) $pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE status = 'approved'")->fetchColumn(),
    'rejected' => (int) $pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE status = 'rejected'")->fetchColumn(),
];

require __DIR__ . '/../includes/header_super.php';
?>

<!-- Filter Tabs -->
<div class="flex gap-2 overflow-x-auto scrollbar-hide mb-6">
    <?php
    $tabs = [
        'pending' => ['المعلقة', $counts['pending']],
        'approved' => ['المقبولة', $counts['approved']],
        'rejected' => ['المرفوضة', $counts['rejected']],
        'all' => ['الكل', $counts['pending'] + $counts['approved'] + $counts['rejected']],
    ];
    foreach ($tabs as $key => [$label, $count]):
        $isActive = $filter === $key;
    ?>
    <a href="?filter=<?= $key ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl <?= $isActive ? 'bg-gradient-to-r from-emerald-500 to-teal-500 text-white' : 'bg-white/5 text-gray-300 hover:bg-white/10' ?> font-bold whitespace-nowrap transition">
        <?= $label ?>
        <span class="<?= $isActive ? 'bg-white/20' : 'bg-white/10' ?> px-2 py-0.5 rounded-full text-xs"><?= $count ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Requests List -->
<?php if (!$requests): ?>
    <div class="card rounded-2xl p-12 text-center">
        <div class="text-6xl mb-4">📭</div>
        <h3 class="text-xl font-bold text-white mb-2">لا توجد طلبات</h3>
        <p class="text-gray-500">لا يوجد طلبات في هذا القسم حالياً</p>
    </div>
<?php else: ?>
<div class="space-y-4">
    <?php foreach ($requests as $req):
        $methodIcons = ['whatsapp' => '💬', 'bank_transfer' => '🏦', 'cash' => '💵'];
        $methodLabels = ['whatsapp' => 'واتساب', 'bank_transfer' => 'تحويل بنكي', 'cash' => 'نقداً'];
        $statusBadge = [
            'pending' => 'bg-amber-500/20 text-amber-300',
            'approved' => 'bg-emerald-500/20 text-emerald-300',
            'rejected' => 'bg-red-500/20 text-red-300',
        ];
        $statusLabel = ['pending' => 'معلق', 'approved' => 'مقبول', 'rejected' => 'مرفوض'];
    ?>
    <div class="card rounded-2xl p-6">
        <div class="flex flex-col lg:flex-row lg:items-start gap-4">
            <div class="flex-1">
                <div class="flex items-center gap-3 mb-3 flex-wrap">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white font-bold"><?= e(mb_substr($req['restaurant_name'], 0, 1)) ?></div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-white"><?= e($req['restaurant_name']) ?></h3>
                        <p class="text-sm text-gray-400"><?= e($req['email']) ?></p>
                    </div>
                    <span class="px-3 py-1 rounded-lg text-xs font-bold <?= $statusBadge[$req['status']] ?>"><?= $statusLabel[$req['status']] ?></span>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mb-4">
                    <div class="p-3 rounded-xl bg-white/5">
                        <p class="text-xs text-gray-500 mb-1">الباقة المطلوبة</p>
                        <p class="font-bold text-white"><?= e($req['plan_name']) ?></p>
                    </div>
                    <div class="p-3 rounded-xl bg-white/5">
                        <p class="text-xs text-gray-500 mb-1">السعر</p>
                        <p class="font-bold text-emerald-400">$<?= (int) $req['price'] ?>/<?= $req['period'] === 'monthly' ? 'شهر' : ($req['period'] === 'yearly' ? 'سنة' : 'دائم') ?></p>
                    </div>
                    <div class="p-3 rounded-xl bg-white/5">
                        <p class="text-xs text-gray-500 mb-1">الدفع</p>
                        <p class="font-bold text-white"><?= ($methodIcons[$req['payment_method']] ?? '') . ' ' . ($methodLabels[$req['payment_method']] ?? $req['payment_method']) ?></p>
                    </div>
                    <div class="p-3 rounded-xl bg-white/5">
                        <p class="text-xs text-gray-500 mb-1">التاريخ</p>
                        <p class="font-bold text-white text-xs"><?= date('Y-m-d H:i', strtotime($req['created_at'])) ?></p>
                    </div>
                </div>

                <?php if ($req['notes']): ?>
                <div class="p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 mb-3">
                    <p class="text-xs text-blue-300 mb-1 font-bold">ملاحظة العميل:</p>
                    <p class="text-sm text-gray-200"><?= nl2br(e($req['notes'])) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($req['admin_notes']): ?>
                <div class="p-3 rounded-xl bg-gray-500/10 border border-white/10 mb-3">
                    <p class="text-xs text-gray-400 mb-1 font-bold">ملاحظة الإدارة:</p>
                    <p class="text-sm text-gray-200"><?= nl2br(e($req['admin_notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($req['status'] === 'pending'): ?>
            <div class="flex flex-col gap-2 lg:min-w-[200px]">
                <?php if ($req['whatsapp']): ?>
                    <a href="https://wa.me/<?= preg_replace('/\D/', '', $req['whatsapp']) ?>?text=<?= urlencode('مرحباً، بخصوص طلب ترقية باقة ' . $req['plan_name']) ?>" target="_blank" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-green-500 text-white font-bold text-sm transition hover:bg-green-600">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
                        راسل
                    </a>
                <?php endif; ?>
                <button onclick='openApproveModal(<?= json_encode(["id"=>$req["id"],"name"=>$req["restaurant_name"]]) ?>)' class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    قبول وتفعيل
                </button>
                <button onclick='openRejectModal(<?= json_encode(["id"=>$req["id"],"name"=>$req["restaurant_name"]]) ?>)' class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-white/5 hover:bg-red-500/10 text-red-400 font-bold text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    رفض
                </button>
            </div>
            <?php else: ?>
                <div class="text-xs text-gray-500">
                    <?= $req['handled_at'] ? 'تم التعامل معه: ' . date('Y-m-d H:i', strtotime($req['handled_at'])) : '' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Approve Modal -->
<div id="approveModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="card rounded-2xl w-full max-w-md">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" id="app-id">
            <div class="p-6 border-b border-white/5">
                <h3 class="text-lg font-bold text-white">قبول طلب <span id="app-name"></span></h3>
                <p class="text-sm text-gray-400 mt-1">سيتم تفعيل الباقة للمطعم فوراً</p>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">تاريخ وساعة انتهاء الاشتراك</label>
                    <input type="datetime-local" name="custom_expiry" id="app-expiry" step="60" class="w-full px-4 py-3 rounded-xl border-2">
                    <div class="flex flex-wrap gap-2 mt-2">
                        <button type="button" onclick="setAppExpiry(30)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+30 يوم</button>
                        <button type="button" onclick="setAppExpiry(90)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+3 أشهر</button>
                        <button type="button" onclick="setAppExpiry(180)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+6 أشهر</button>
                        <button type="button" onclick="setAppExpiry(365)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+سنة</button>
                        <button type="button" onclick="document.getElementById('app-expiry').value=''" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-amber-300">استخدم الافتراضي</button>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">اتركه فارغاً لاستخدام المدة الافتراضية للباقة (30 للشهري، 365 للسنوي، لا شيء للدائم)</p>
                </div>

                <div class="p-4 rounded-xl bg-emerald-500/5 border border-emerald-500/20 space-y-4">
                    <p class="text-xs font-bold text-emerald-300 uppercase tracking-wide">تسجيل الدفعة في السجل</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-300 mb-1.5">المبلغ المحصّل</label>
                            <input type="number" step="0.01" min="0" name="amount" id="app-amount" class="w-full px-4 py-2.5 rounded-xl border-2" placeholder="0.00">
                            <p class="text-xs text-gray-500 mt-1">اتركه فارغاً لاستخدام سعر الباقة</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-300 mb-1.5">تاريخ الدفع الفعلي</label>
                            <input type="datetime-local" name="paid_at" id="app-paid-at" step="60" class="w-full px-4 py-2.5 rounded-xl border-2">
                            <p class="text-xs text-gray-500 mt-1">اتركه فارغاً = الآن</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-300 mb-1.5">رقم/مرجع العملية <span class="text-gray-500 font-normal">(اختياري)</span></label>
                        <input type="text" name="payment_reference" class="w-full px-4 py-2.5 rounded-xl border-2" placeholder="رقم التحويل، رقم الإيصال...">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">ملاحظات الإدارة <span class="text-gray-500 font-normal">(اختياري)</span></label>
                    <textarea name="admin_notes" rows="3" class="w-full px-4 py-3 rounded-xl border-2" placeholder="تم التحقق من الدفع..."></textarea>
                </div>
            </div>
            <div class="p-6 border-t border-white/5 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('approveModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold">تأكيد القبول</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="card rounded-2xl w-full max-w-md">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rej-id">
            <div class="p-6 border-b border-white/5">
                <h3 class="text-lg font-bold text-white">رفض طلب <span id="rej-name"></span></h3>
            </div>
            <div class="p-6">
                <label class="block text-sm font-semibold text-gray-300 mb-2">سبب الرفض</label>
                <textarea name="admin_notes" rows="3" class="w-full px-4 py-3 rounded-xl border-2" placeholder="..."></textarea>
            </div>
            <div class="p-6 border-t border-white/5 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('rejectModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-red-500 hover:bg-red-600 text-white font-bold">تأكيد الرفض</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(r) {
    document.getElementById('app-id').value = r.id;
    document.getElementById('app-name').textContent = r.name;
    document.getElementById('app-expiry').value = '';
    const amountField = document.getElementById('app-amount');
    if (amountField) amountField.value = '';
    const paidAtField = document.getElementById('app-paid-at');
    if (paidAtField) paidAtField.value = '';
    document.getElementById('approveModal').classList.remove('hidden');
    document.getElementById('approveModal').classList.add('flex');
}
function setAppExpiry(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    const pad = n => String(n).padStart(2, '0');
    document.getElementById('app-expiry').value = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
function openRejectModal(r) {
    document.getElementById('rej-id').value = r.id;
    document.getElementById('rej-name').textContent = r.name;
    document.getElementById('rejectModal').classList.remove('hidden');
    document.getElementById('rejectModal').classList.add('flex');
}
</script>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
