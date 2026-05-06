<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/countries.php';
requireAdminLogin();
$pageTitle = 'إدارة المطاعم';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve' && $id) {
        // Compute expiry based on the store's current plan period (7days/monthly/yearly).
        // This means a freshly-approved store on the free plan gets a 7-day trial window.
        $expiry = null;
        $stmt = $pdo->prepare('SELECT p.period FROM stores s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?');
        $stmt->execute([$id]);
        $period = $stmt->fetchColumn();
        if ($period === '7days') {
            $expiry = date('Y-m-d H:i:s', strtotime('+7 days'));
        } elseif ($period === 'monthly') {
            $expiry = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($period === 'yearly') {
            $expiry = date('Y-m-d H:i:s', strtotime('+1 year'));
        }
        // 'forever' or unknown → leave NULL (permanent)

        $pdo->prepare(
            'UPDATE stores SET approval_status = ?, approved_at = NOW(), approved_by = ?, is_active = 1,
                rejection_reason = NULL,
                subscription_expires_at = COALESCE(subscription_expires_at, ?),
                subscription_status = ?
             WHERE id = ?'
        )->execute(['approved', $_SESSION['admin_id'], $expiry, 'active', $id]);
        log_activity_event('store_approved', ['store_id' => $id, 'period' => $period, 'expiry' => $expiry]);
        $msg = 'تم تفعيل المتجر';
        if ($expiry) {
            $msg .= ' — صالح حتى ' . date('Y-m-d', strtotime($expiry));
        }
        flash('success', $msg);
    } elseif ($action === 'reject' && $id) {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $pdo->prepare(
            'UPDATE stores SET approval_status = ?, approved_at = NOW(), approved_by = ?, is_active = 0, rejection_reason = ? WHERE id = ?'
        )->execute(['rejected', $_SESSION['admin_id'], $reason !== '' ? $reason : null, $id]);
        log_activity_event('store_rejected', ['store_id' => $id, 'reason' => $reason]);
        flash('success', 'تم رفض طلب المتجر');
    } elseif ($action === 'toggle_active' && $id) {
        $pdo->prepare('UPDATE stores SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', 'تم تحديث حالة المطعم');
    } elseif ($action === 'change_plan' && $id) {
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $expires = trim($_POST['expires'] ?? '');
        $expiresAt = $expires ? date('Y-m-d H:i:s', strtotime($expires)) : null;
        $pdo->prepare('UPDATE stores SET plan_id = ?, subscription_expires_at = ?, subscription_status = ? WHERE id = ?')
            ->execute([$planId, $expiresAt, 'active', $id]);
        flash('success', 'تم تحديث باقة المطعم');
    } elseif ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$id]);
        flash('success', 'تم حذف المطعم');
    } elseif ($action === 'change_affiliate' && $id) {
        $affId = (int) ($_POST['affiliate_id'] ?? 0);
        $rate  = trim($_POST['affiliate_commission_rate'] ?? '');
        $rateVal = ($rate === '') ? null : max(0, min(100, (float) $rate));
        $newAffId = $affId > 0 ? $affId : null;
        // If linking to an affiliate for the first time, set referred_at to now
        $stmt = $pdo->prepare('SELECT affiliate_id, referred_at FROM stores WHERE id = ?');
        $stmt->execute([$id]);
        $cur = $stmt->fetch();
        $referredAt = $cur['referred_at'] ?: null;
        if ($newAffId && !$cur['affiliate_id']) {
            $referredAt = date('Y-m-d H:i:s');
        } elseif (!$newAffId) {
            $referredAt = null; // unlinking → clear timestamp
        }
        $pdo->prepare('UPDATE stores SET affiliate_id = ?, affiliate_commission_rate = ?, referred_at = ? WHERE id = ?')
            ->execute([$newAffId, $rateVal, $referredAt, $id]);
        log_activity_event('store_affiliate_changed', ['store_id' => $id, 'affiliate_id' => $newAffId, 'rate' => $rateVal]);
        flash('success', $newAffId ? 'تم ربط المتجر بالوسيط' : 'تم فكّ ارتباط المتجر بالوسيط');
    } elseif ($action === 'reset_password' && $id) {
        // Super admin force-resets a store's password.
        // Minimum 8 chars; confirmation must match.
        $newPass     = (string) ($_POST['new_password'] ?? '');
        $confirmPass = (string) ($_POST['confirm_password'] ?? '');

        if (mb_strlen($newPass) < 8) {
            flash('error', 'كلمة المرور يجب أن تكون 8 خانات على الأقل');
        } elseif ($newPass !== $confirmPass) {
            flash('error', 'كلمة المرور والتأكيد غير متطابقين');
        } else {
            // Capture store name for the confirmation message + activity log
            $stmt = $pdo->prepare('SELECT name, email FROM stores WHERE id = ?');
            $stmt->execute([$id]);
            $store = $stmt->fetch();

            if (!$store) {
                flash('error', 'المتجر غير موجود');
            } else {
                $pdo->prepare('UPDATE stores SET password = ? WHERE id = ?')
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $id]);
                log_activity_event('store_password_reset', ['store_id' => $id, 'store_name' => $store['name']]);
                flash('success', 'تم إعادة تعيين كلمة المرور لمتجر «' . $store['name'] . '» بنجاح');
            }
        }
    }
    redirect(BASE_URL . '/super/stores.php' . (!empty($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

$search = trim($_GET['q'] ?? '');
$planFilter = (int) ($_GET['plan'] ?? 0);
$status = $_GET['status'] ?? '';
$affFilter = (int) ($_GET['affiliate'] ?? 0); // 0 = all, -1 = no affiliate, >0 = specific

$sql = 'SELECT r.*, p.name AS plan_name, p.code AS plan_code, p.price AS plan_price,
        (SELECT COUNT(*) FROM items WHERE store_id = r.id) AS items_count,
        a.name AS affiliate_name, a.referral_code AS affiliate_code, a.commission_rate AS affiliate_default_rate
        FROM stores r
        LEFT JOIN plans p ON r.plan_id = p.id
        LEFT JOIN affiliates a ON a.id = r.affiliate_id
        WHERE 1=1';
$params = [];
if ($search) { $sql .= ' AND (r.name LIKE ? OR r.email LIKE ? OR r.slug LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($planFilter) { $sql .= ' AND r.plan_id = ?'; $params[] = $planFilter; }
if ($status === 'active') $sql .= ' AND r.is_active = 1';
elseif ($status === 'inactive') $sql .= ' AND r.is_active = 0';
elseif ($status === 'rejected') $sql .= " AND r.approval_status = 'rejected'";
elseif ($status === 'pending')  $sql .= " AND r.approval_status = 'pending'";
if ($affFilter > 0) { $sql .= ' AND r.affiliate_id = ?'; $params[] = $affFilter; }
elseif ($affFilter === -1) { $sql .= ' AND r.affiliate_id IS NULL'; }
$sql .= ' ORDER BY r.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stores = $stmt->fetchAll();

// Load all affiliates for filter dropdown + change-affiliate modal
$allAffiliates = $pdo->query('SELECT id, name, referral_code, commission_rate FROM affiliates WHERE is_active = 1 ORDER BY name')->fetchAll();

// Mark expired subscriptions — they effectively operate as Free plan
// (apply_expired_downgrade runs in PHP only; plan_id in DB stays for easy renewal)
$now = time();
foreach ($stores as &$rest) {
    $exp = $rest['subscription_expires_at'] ?? null;
    $rest['is_expired'] = $exp && strtotime($exp) <= $now;
}
unset($rest);

$plans = $pdo->query('SELECT * FROM plans ORDER BY sort_order')->fetchAll();

// Pending approval requests — shown at the top of the page, separately from
// the regular store list, so the super-admin notices them immediately.
$pendingStores = $pdo->query("
    SELECT s.id, s.name, s.email, s.phone, s.whatsapp, s.country, s.created_at,
           bt.name_ar AS biz_name, bt.icon AS biz_icon,
           a.name AS affiliate_name, a.referral_code AS affiliate_code
    FROM stores s
    LEFT JOIN business_types bt ON bt.id = s.business_type_id
    LEFT JOIN affiliates a ON a.id = s.affiliate_id
    WHERE s.approval_status = 'pending'
    ORDER BY s.created_at ASC
")->fetchAll();

require __DIR__ . '/../includes/header_super.php';
?>

<!-- ═══ PENDING APPROVAL REQUESTS (shown only when there are any) ═══ -->
<?php if ($pendingStores): ?>
<div class="mb-6 rounded-2xl bg-gradient-to-l from-amber-500/15 to-orange-500/15 border-2 border-amber-500/40 p-5">
    <div class="flex items-center gap-3 mb-4">
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30 flex-shrink-0">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
        </div>
        <div class="flex-1">
            <h2 class="text-lg md:text-xl font-black text-white">
                طلبات بانتظار الموافقة
                <span class="inline-block px-2 py-0.5 rounded-full bg-amber-500 text-white text-sm font-bold mr-2"><?= count($pendingStores) ?></span>
            </h2>
            <p class="text-sm text-amber-200/80">راجع الطلبات الجديدة وتواصل مع أصحاب المتاجر عبر الواتساب قبل الموافقة</p>
        </div>
    </div>

    <div class="space-y-3">
        <?php foreach ($pendingStores as $ps): ?>
        <div class="bg-white/5 backdrop-blur rounded-xl p-4 border border-white/10">
            <div class="flex flex-col lg:flex-row gap-4 items-start">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white font-black text-lg flex-shrink-0">
                        <?= e(mb_substr($ps['name'], 0, 1)) ?>
                    </div>
                    <div class="min-w-0">
                        <p class="font-black text-white truncate"><?= e($ps['name']) ?></p>
                        <p class="text-xs text-gray-400">
                            <?= e($ps['biz_icon'] ?? '') ?>
                            <?= e($ps['biz_name'] ?? '—') ?>
                            <?php if ($ps['country']): ?>
                                · 🌍 <span class="text-amber-300 font-bold"><?= e(countryName($ps['country'])) ?></span>
                            <?php endif; ?>
                            · سُجِّل
                            <?= date('Y-m-d H:i', strtotime($ps['created_at'])) ?>
                        </p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row flex-wrap gap-2 text-xs flex-1 min-w-0">
                    <span class="px-3 py-1 rounded-lg bg-white/5 text-gray-300 truncate" dir="ltr" title="<?= e($ps['email']) ?>">
                        ✉ <?= e($ps['email']) ?>
                    </span>
                    <a href="https://wa.me/<?= e(preg_replace('/\D/', '', $ps['whatsapp'])) ?>" target="_blank"
                       class="px-3 py-1 rounded-lg bg-green-500/20 text-green-300 hover:bg-green-500/30 font-bold transition flex items-center gap-1.5 whitespace-nowrap" dir="ltr">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                        <?= e($ps['whatsapp']) ?>
                    </a>
                    <?php if (!empty($ps['phone']) && $ps['phone'] !== $ps['whatsapp']): ?>
                        <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $ps['phone'])) ?>"
                           class="px-3 py-1 rounded-lg bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 font-bold transition flex items-center gap-1.5 whitespace-nowrap" dir="ltr">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <?= e($ps['phone']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($ps['affiliate_name']): ?>
                        <span class="px-3 py-1 rounded-lg bg-orange-500/20 text-orange-300 whitespace-nowrap">
                            وسيط: <?= e($ps['affiliate_name']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="flex gap-2 flex-shrink-0">
                    <form method="POST" class="inline" onsubmit="return confirm('تفعيل هذا المتجر؟ سيتمكّن صاحبه من الدخول فوراً.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" value="<?= (int) $ps['id'] ?>">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-sm shadow flex items-center gap-1.5 active:scale-95 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            موافقة
                        </button>
                    </form>
                    <button type="button" onclick='openRejectModal(<?= (int) $ps['id'] ?>, <?= json_encode($ps['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'
                            class="px-4 py-2 rounded-lg bg-red-500/20 hover:bg-red-500/30 text-red-300 font-bold text-sm flex items-center gap-1.5 active:scale-95 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                        رفض
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Reject modal -->
<div id="rejectModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeRejectModal()">
    <div class="bg-gray-900 border border-white/10 rounded-2xl p-6 max-w-md w-full">
        <h3 class="text-lg font-black text-white mb-1">رفض طلب المتجر</h3>
        <p class="text-sm text-gray-400 mb-4">المتجر: <span id="rejectStoreName" class="text-red-400 font-bold"></span></p>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="rejectStoreId" value="">
            <div>
                <label class="block text-sm font-bold text-gray-300 mb-2">سبب الرفض (سيُعرض لصاحب المتجر عند محاولة الدخول)</label>
                <textarea name="rejection_reason" rows="3" maxlength="500"
                          class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white"
                          placeholder="مثال: البيانات غير مكتملة — يرجى إعادة التسجيل بمعلومات صحيحة."></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl text-gray-400 hover:text-white">إلغاء</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold">تأكيد الرفض</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(id, name) {
    const m = document.getElementById('rejectModal');
    document.getElementById('rejectStoreId').value = id;
    document.getElementById('rejectStoreName').textContent = name;
    m.classList.remove('hidden');
    m.classList.add('flex');
}
function closeRejectModal() {
    const m = document.getElementById('rejectModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
</script>
<?php endif; ?>

<!-- Filters -->
<div class="card rounded-2xl p-4 mb-6">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
        <div class="flex-1 relative">
            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="ابحث عن مطعم..." class="w-full pr-10 pl-4 py-2.5 rounded-xl border-2 focus:border-emerald-500 transition">
        </div>
        <select name="plan" class="px-4 py-2.5 rounded-xl border-2 font-semibold">
            <option value="0">كل الباقات</option>
            <?php foreach ($plans as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $planFilter == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="px-4 py-2.5 rounded-xl border-2 font-semibold">
            <option value="">كل الحالات</option>
            <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>نشط</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>موقوف</option>
            <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>بانتظار الموافقة</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>مرفوض</option>
        </select>
        <select name="affiliate" class="px-4 py-2.5 rounded-xl border-2 font-semibold">
            <option value="0">كل المتاجر (وسطاء)</option>
            <option value="-1" <?= $affFilter === -1 ? 'selected' : '' ?>>بدون وسيط</option>
            <?php foreach ($allAffiliates as $af): ?>
                <option value="<?= (int) $af['id'] ?>" <?= $affFilter === (int) $af['id'] ? 'selected' : '' ?>>
                    <?= e($af['name']) ?> (<?= e($af['referral_code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <button class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold">بحث</button>
    </form>
</div>

<!-- Table -->
<div class="card rounded-2xl overflow-hidden">
    <div class="overflow-x-auto -webkit-overflow-scrolling-touch">
    <table class="w-full min-w-[900px]">
        <thead>
            <tr class="bg-white/5 text-xs text-gray-400 border-b border-white/5">
                <th class="text-right py-3 px-4 font-semibold">المطعم</th>
                <th class="text-right py-3 px-4 font-semibold">جهة الاتصال</th>
                <th class="text-right py-3 px-4 font-semibold">الباقة</th>
                <th class="text-right py-3 px-4 font-semibold">الوسيط</th>
                <th class="text-right py-3 px-4 font-semibold">ينتهي في</th>
                <th class="text-right py-3 px-4 font-semibold">الأصناف</th>
                <th class="text-right py-3 px-4 font-semibold">المشاهدات</th>
                <th class="text-right py-3 px-4 font-semibold">الحالة</th>
                <th class="text-right py-3 px-4 font-semibold">التسجيل</th>
                <th class="text-right py-3 px-4 font-semibold">إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$stores): ?>
                <tr><td colspan="10" class="text-center py-12 text-gray-500">لا توجد مطاعم</td></tr>
            <?php else: ?>
                <?php foreach ($stores as $rest):
                    $badgeClass = ['free' => 'bg-gray-500/20 text-gray-300', 'pro' => 'bg-emerald-500/20 text-emerald-300', 'max' => 'bg-amber-500/20 text-amber-300'];
                ?>
                <tr class="border-b border-white/5 hover:bg-white/5">
                    <td class="py-4 px-4">
                        <div class="flex items-center gap-3">
                            <?php if ($rest['logo']): ?>
                                <img src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($rest['logo']) ?>" class="w-10 h-10 rounded-lg object-cover">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white font-bold"><?= e(mb_substr($rest['name'], 0, 1)) ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="font-bold text-white text-sm"><?= e($rest['name']) ?></p>
                                <p class="text-xs text-gray-500">/<?= e($rest['slug']) ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="py-4 px-4 text-sm">
                        <p class="text-gray-300 truncate max-w-[200px]" dir="ltr" title="<?= e($rest['email']) ?>"><?= e($rest['email']) ?></p>
                        <?php if (!empty($rest['whatsapp'])): ?>
                            <a href="https://wa.me/<?= e(preg_replace('/\D/', '', $rest['whatsapp'])) ?>" target="_blank"
                               class="text-xs text-green-400 hover:text-green-300 font-mono inline-flex items-center gap-1 mt-0.5" dir="ltr">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                                <?= e($rest['whatsapp']) ?>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($rest['phone']) && $rest['phone'] !== $rest['whatsapp']): ?>
                            <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $rest['phone'])) ?>"
                               class="text-xs text-blue-400 hover:text-blue-300 font-mono inline-flex items-center gap-1 mt-0.5 ms-2" dir="ltr">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                <?= e($rest['phone']) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="py-4 px-4">
                        <?php if ($rest['is_expired']): ?>
                            <div class="flex flex-col gap-1">
                                <span class="px-2 py-1 rounded-lg text-xs font-bold bg-gray-500/20 text-gray-300">مجاني</span>
                                <span class="text-[10px] text-red-400 font-bold">↓ انتهى <?= e($rest['plan_name'] ?? '') ?></span>
                            </div>
                        <?php else: ?>
                            <span class="px-2 py-1 rounded-lg text-xs font-bold <?= $badgeClass[$rest['plan_code']] ?? 'bg-gray-500/20 text-gray-300' ?>"><?= e($rest['plan_name'] ?? '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-4 px-4">
                        <?php if ($rest['affiliate_name']): ?>
                            <button type="button"
                                    onclick='openAffiliateModal(<?= json_encode([
                                        'id' => (int) $rest['id'],
                                        'name' => $rest['name'],
                                        'affiliate_id' => (int) $rest['affiliate_id'],
                                        'affiliate_commission_rate' => $rest['affiliate_commission_rate'],
                                        'affiliate_default_rate' => $rest['affiliate_default_rate'],
                                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'
                                    class="text-right block hover:bg-white/5 rounded-lg p-1 -m-1 transition">
                                <p class="text-xs font-bold text-orange-400 truncate max-w-[120px]" title="<?= e($rest['affiliate_name']) ?>"><?= e($rest['affiliate_name']) ?></p>
                                <p class="text-[10px] text-gray-500 font-mono"><?= e($rest['affiliate_code']) ?>
                                    · <?= number_format((float) ($rest['affiliate_commission_rate'] ?? $rest['affiliate_default_rate']), 1) ?>%
                                </p>
                            </button>
                        <?php else: ?>
                            <button type="button"
                                    onclick='openAffiliateModal(<?= json_encode(['id' => (int) $rest['id'], 'name' => $rest['name'], 'affiliate_id' => 0, 'affiliate_commission_rate' => null, 'affiliate_default_rate' => null], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'
                                    class="text-xs text-gray-500 hover:text-orange-400 transition">
                                + إضافة وسيط
                            </button>
                        <?php endif; ?>
                    </td>
                    <td class="py-4 px-4">
                        <?php
                        $exp = $rest['subscription_expires_at'];
                        if (!$exp) {
                            echo '<span class="text-xs text-gray-500">— دائم —</span>';
                        } else {
                            $expTs = strtotime($exp);
                            $diff = $expTs - time();
                            $daysLeft = floor($diff / 86400);
                            if ($diff <= 0) {
                                $color = 'text-red-400';
                                $badge = '<span class="text-xs font-bold">منتهي</span>';
                            } elseif ($daysLeft <= 7) {
                                $color = 'text-amber-300';
                                $badge = '<span class="text-xs font-bold">' . ($daysLeft > 0 ? "بعد $daysLeft يوم" : 'اليوم') . '</span>';
                            } else {
                                $color = 'text-emerald-300';
                                $badge = '<span class="text-xs font-bold">بعد ' . $daysLeft . ' يوم</span>';
                            }
                            echo '<div class="' . $color . '">';
                            echo '<div class="text-xs font-mono">' . date('Y-m-d H:i', $expTs) . '</div>';
                            echo $badge;
                            echo '</div>';
                        }
                        ?>
                    </td>
                    <td class="py-4 px-4 text-sm text-white font-semibold"><?= $rest['items_count'] ?></td>
                    <td class="py-4 px-4">
                        <div class="flex flex-col gap-0.5">
                            <span class="inline-flex items-center gap-1.5 text-indigo-300 text-sm font-bold">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <?= number_format((int)($rest['views_count'] ?? 0)) ?>
                            </span>
                            <span class="text-[10px] text-gray-500"><?= number_format((int)($rest['unique_views'] ?? 0)) ?> فريد</span>
                        </div>
                    </td>
                    <td class="py-4 px-4">
                        <?php if (($rest['approval_status'] ?? '') === 'rejected'): ?>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-red-500/15 text-red-400 text-xs font-black border border-red-500/30">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                    مرفوض
                                </span>
                                <?php if (!empty($rest['rejection_reason'])): ?>
                                    <button type="button"
                                            onclick='openReasonModal(<?= json_encode($rest['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>, <?= json_encode($rest['rejection_reason'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'
                                            class="p-1 rounded-md hover:bg-white/10 text-amber-400" title="عرض سبب الرفض">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php elseif (($rest['approval_status'] ?? '') === 'pending'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-amber-500/15 text-amber-400 text-xs font-black border border-amber-500/30">
                                <svg class="w-3 h-3 animate-pulse" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="6"/></svg>
                                بانتظار الموافقة
                            </span>
                        <?php elseif (!$rest['is_active']): ?>
                            <span class="inline-flex items-center gap-1 text-gray-500 text-xs font-bold"><span class="w-1.5 h-1.5 rounded-full bg-gray-500"></span>موقوف</span>
                        <?php elseif ($rest['is_expired']): ?>
                            <span class="inline-flex items-center gap-1 text-red-400 text-xs font-bold"><span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>منتهي</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-emerald-400 text-xs font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>نشط</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-4 px-4 text-xs text-gray-500"><?= date('Y-m-d', strtotime($rest['created_at'])) ?></td>
                    <td class="py-4 px-4">
                        <div class="flex items-center gap-1">
                            <a href="<?= BASE_URL ?>/public/store.php?r=<?= urlencode($rest['slug']) ?>" target="_blank" class="p-2 rounded-lg hover:bg-white/5 text-emerald-400" title="معاينة">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </a>
                            <button onclick='openPlanModal(<?= json_encode(["id"=>$rest["id"],"name"=>$rest["name"],"plan_id"=>$rest["plan_id"],"expires"=>$rest["subscription_expires_at"]]) ?>)' class="p-2 rounded-lg hover:bg-white/5 text-blue-400" title="تغيير الباقة">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </button>
                            <button onclick='openResetPwdModal(<?= json_encode(["id"=>$rest["id"],"name"=>$rest["name"],"email"=>$rest["email"]]) ?>)' class="p-2 rounded-lg hover:bg-white/5 text-amber-400" title="إعادة تعيين كلمة المرور">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            </button>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= $rest['id'] ?>">
                                <button type="submit" class="p-2 rounded-lg hover:bg-white/5 text-gray-400" title="<?= $rest['is_active'] ? 'إيقاف' : 'تفعيل' ?>">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <?php if ($rest['is_active']): ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                        <?php else: ?>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        <?php endif; ?>
                                    </svg>
                                </button>
                            </form>
                            <form method="POST" class="inline" onsubmit="return confirm('حذف هذا المطعم وكل بياناته نهائياً؟')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $rest['id'] ?>">
                                <button type="submit" class="p-2 rounded-lg hover:bg-red-500/10 text-red-400" title="حذف">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Plan Modal -->
<div id="planModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closePlanModal()">
    <div class="card rounded-2xl w-full max-w-md">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_plan">
            <input type="hidden" name="id" id="pm-id">
            <div class="p-6 border-b border-white/5 flex items-center justify-between">
                <h3 class="text-lg font-bold text-white">تغيير باقة <span id="pm-name"></span></h3>
                <button type="button" onclick="closePlanModal()" class="p-1 hover:bg-white/5 rounded-lg text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">الباقة الجديدة</label>
                    <select name="plan_id" id="pm-plan" required class="w-full px-4 py-3 rounded-xl border-2">
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> — $<?= (int) $p['price'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">تاريخ وساعة انتهاء الاشتراك</label>
                    <input type="datetime-local" name="expires" id="pm-expires" step="60" class="w-full px-4 py-3 rounded-xl border-2">
                    <div class="flex flex-wrap gap-2 mt-2">
                        <button type="button" onclick="setExpiryPreset(30)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+30 يوم</button>
                        <button type="button" onclick="setExpiryPreset(90)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+3 أشهر</button>
                        <button type="button" onclick="setExpiryPreset(180)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+6 أشهر</button>
                        <button type="button" onclick="setExpiryPreset(365)" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-gray-300">+سنة</button>
                        <button type="button" onclick="document.getElementById('pm-expires').value=''" class="px-3 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-bold text-amber-300">دائم ∞</button>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">اتركه فارغاً للاشتراك الدائم (بدون انتهاء)</p>
                </div>
            </div>
            <div class="p-6 border-t border-white/5 flex justify-end gap-3">
                <button type="button" onclick="closePlanModal()" class="px-5 py-2.5 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold">حفظ</button>
            </div>
        </form>
    </div>
</div>

<!-- View Rejection Reason Modal (always present, opened from rejected-status badge) -->
<div id="reasonModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeReasonModal()">
    <div class="bg-gray-900 border border-red-500/30 rounded-2xl p-6 max-w-md w-full">
        <div class="flex items-start gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-red-500/20 text-red-400 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-base font-black text-white mb-1">سبب رفض المتجر</h3>
                <p class="text-xs text-gray-400 truncate">المتجر: <span id="reasonStoreName" class="text-red-400 font-bold"></span></p>
            </div>
        </div>
        <div class="rounded-xl bg-red-500/10 border border-red-500/30 p-4 mb-4">
            <p id="reasonText" class="text-sm text-red-200 leading-relaxed whitespace-pre-wrap"></p>
        </div>
        <div class="flex justify-end">
            <button type="button" onclick="closeReasonModal()" class="px-5 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-gray-200 font-bold">إغلاق</button>
        </div>
    </div>
</div>
<script>
function openReasonModal(name, reason) {
    const m = document.getElementById('reasonModal');
    document.getElementById('reasonStoreName').textContent = name;
    document.getElementById('reasonText').textContent = reason || '— لم يُحدّد سبب —';
    m.classList.remove('hidden');
    m.classList.add('flex');
}
function closeReasonModal() {
    const m = document.getElementById('reasonModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
</script>

<!-- Reset Password Modal -->
<div id="resetPwdModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeResetPwdModal()">
    <div class="card rounded-2xl w-full max-w-md">
        <form method="POST" onsubmit="return validateResetPwd()">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="rp-id">
            <div class="p-6 border-b border-white/5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white">إعادة تعيين كلمة المرور</h3>
                        <p class="text-xs text-gray-500">لمتجر <span id="rp-name" class="text-amber-300 font-bold"></span></p>
                    </div>
                </div>
                <button type="button" onclick="closeResetPwdModal()" class="p-1 hover:bg-white/5 rounded-lg text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-200 leading-relaxed flex items-start gap-2">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <p class="font-bold mb-0.5">تنبيه</p>
                        <p>سيتم استبدال كلمة مرور المتجر الحالية. البريد المسجّل: <span id="rp-email" class="font-mono font-bold"></span></p>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">كلمة المرور الجديدة <span class="text-red-400">*</span></label>
                    <input type="password" name="new_password" id="rp-new" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2" placeholder="8 خانات على الأقل" autocomplete="new-password">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">تأكيد كلمة المرور <span class="text-red-400">*</span></label>
                    <input type="password" name="confirm_password" id="rp-confirm" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2" placeholder="أعد إدخال كلمة المرور" autocomplete="new-password">
                    <p id="rp-mismatch" class="text-xs text-red-400 mt-1 hidden">كلمة المرور والتأكيد غير متطابقين</p>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-400">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="rp-show" onchange="toggleResetPwdVisibility()" class="w-3.5 h-3.5">
                        <span>إظهار كلمة المرور</span>
                    </label>
                    <span class="mx-2 text-gray-600">|</span>
                    <button type="button" onclick="generateResetPwd()" class="text-emerald-400 hover:text-emerald-300 font-semibold">🎲 توليد كلمة مرور قوية</button>
                </div>
            </div>
            <div class="p-6 border-t border-white/5 flex justify-end gap-3">
                <button type="button" onclick="closeResetPwdModal()" class="px-5 py-2.5 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-bold shadow-lg">إعادة التعيين</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPlanModal(data) {
    document.getElementById('pm-id').value = data.id;
    document.getElementById('pm-name').textContent = data.name;
    document.getElementById('pm-plan').value = data.plan_id || '';
    // Convert "YYYY-MM-DD HH:MM:SS" → "YYYY-MM-DDTHH:MM" for datetime-local input
    document.getElementById('pm-expires').value = data.expires ? data.expires.replace(' ', 'T').substring(0, 16) : '';
    document.getElementById('planModal').classList.remove('hidden');
    document.getElementById('planModal').classList.add('flex');
}
function setExpiryPreset(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    // Format to "YYYY-MM-DDTHH:MM" in local time
    const pad = n => String(n).padStart(2, '0');
    const val = `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    document.getElementById('pm-expires').value = val;
}
function closePlanModal() {
    document.getElementById('planModal').classList.add('hidden');
    document.getElementById('planModal').classList.remove('flex');
}

// ----- Reset Password Modal -----
function openResetPwdModal(data) {
    document.getElementById('rp-id').value = data.id;
    document.getElementById('rp-name').textContent = data.name;
    document.getElementById('rp-email').textContent = data.email;
    document.getElementById('rp-new').value = '';
    document.getElementById('rp-confirm').value = '';
    document.getElementById('rp-show').checked = false;
    document.getElementById('rp-new').type = 'password';
    document.getElementById('rp-confirm').type = 'password';
    document.getElementById('rp-mismatch').classList.add('hidden');
    document.getElementById('resetPwdModal').classList.remove('hidden');
    document.getElementById('resetPwdModal').classList.add('flex');
    setTimeout(() => document.getElementById('rp-new').focus(), 100);
}
function closeResetPwdModal() {
    document.getElementById('resetPwdModal').classList.add('hidden');
    document.getElementById('resetPwdModal').classList.remove('flex');
}
function toggleResetPwdVisibility() {
    const show = document.getElementById('rp-show').checked;
    document.getElementById('rp-new').type = show ? 'text' : 'password';
    document.getElementById('rp-confirm').type = show ? 'text' : 'password';
}
function generateResetPwd() {
    // 12-char password: 4 letters upper + 4 lowercase + 2 digits + 2 symbols, shuffled
    const up = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lo = 'abcdefghijkmnpqrstuvwxyz';
    const di = '23456789';
    const sy = '!@#$%&*';
    const pick = (src, n) => Array.from({length: n}, () => src[Math.floor(Math.random()*src.length)]).join('');
    const chars = (pick(up,4) + pick(lo,4) + pick(di,2) + pick(sy,2)).split('');
    // Fisher-Yates shuffle
    for (let i = chars.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    const pwd = chars.join('');
    document.getElementById('rp-new').value = pwd;
    document.getElementById('rp-confirm').value = pwd;
    // Show briefly so admin can copy it
    document.getElementById('rp-show').checked = true;
    toggleResetPwdVisibility();
}
function validateResetPwd() {
    const n = document.getElementById('rp-new').value;
    const c = document.getElementById('rp-confirm').value;
    if (n.length < 8) {
        alert('كلمة المرور يجب أن تكون 8 خانات على الأقل');
        return false;
    }
    if (n !== c) {
        document.getElementById('rp-mismatch').classList.remove('hidden');
        return false;
    }
    return confirm('هل أنت متأكد من إعادة تعيين كلمة مرور هذا المتجر؟');
}

// ─── Affiliate change modal (lazy DOM lookup — modal HTML comes after this script) ────
const affDefaultRates = <?= json_encode(array_column($allAffiliates, 'commission_rate', 'id')) ?>;

function openAffiliateModal(s) {
    const modal = document.getElementById('changeAffiliateModal');
    if (!modal) { console.error('changeAffiliateModal not found in DOM'); return; }
    document.getElementById('aff_store_id').value = s.id;
    document.getElementById('aff_store_name').textContent = s.name;
    document.getElementById('aff_select').value = s.affiliate_id || '';
    document.getElementById('aff_rate').value = s.affiliate_commission_rate || '';
    updateAffHelp();
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeAffiliateModal() {
    const modal = document.getElementById('changeAffiliateModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
function updateAffHelp() {
    const sel = document.getElementById('aff_select');
    const help = document.getElementById('aff_default_help');
    if (!sel || !help) return;
    const id = sel.value;
    if (id && affDefaultRates[id] !== undefined) {
        help.textContent = 'النسبة الافتراضية لهذا الوسيط: ' + parseFloat(affDefaultRates[id]).toFixed(2) + '% (اتركه فارغاً لاستخدامها)';
        help.classList.remove('hidden');
    } else {
        help.classList.add('hidden');
    }
}
</script>

<!-- Affiliate change modal -->
<div id="changeAffiliateModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeAffiliateModal()">
    <div class="bg-gray-900 border border-white/10 rounded-2xl p-6 max-w-md w-full">
        <h3 class="text-lg font-black text-white mb-1">ربط بوسيط</h3>
        <p class="text-sm text-gray-400 mb-5">المتجر: <span id="aff_store_name" class="text-orange-400 font-bold"></span></p>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_affiliate">
            <input type="hidden" name="id" id="aff_store_id" value="">

            <div>
                <label class="block text-sm font-bold text-gray-300 mb-2">الوسيط</label>
                <select name="affiliate_id" id="aff_select" onchange="updateAffHelp()"
                        class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                    <option value="">— بدون وسيط —</option>
                    <?php foreach ($allAffiliates as $af): ?>
                        <option value="<?= (int) $af['id'] ?>">
                            <?= e($af['name']) ?> (<?= e($af['referral_code']) ?>) — <?= number_format((float) $af['commission_rate'], 2) ?>%
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-300 mb-2">نسبة العمولة لهذا المتجر تحديداً (%)</label>
                <input type="number" name="affiliate_commission_rate" id="aff_rate"
                       min="0" max="100" step="0.01" placeholder="اتركه فارغاً للقيمة الافتراضية"
                       class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                <p id="aff_default_help" class="text-xs text-gray-500 mt-2 hidden"></p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeAffiliateModal()" class="px-4 py-2 rounded-xl text-gray-400 hover:text-white">إلغاء</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold">حفظ</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
