<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'إدارة الوسطاء';

// ─── Handle POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name  = clean_string($_POST['name'] ?? '', 150);
        $email = clean_email($_POST['email'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        $phone = clean_phone($_POST['phone'] ?? '');
        $whatsapp = clean_phone($_POST['whatsapp'] ?? '');
        $rate  = max(0, min(100, (float) ($_POST['commission_rate'] ?? 10)));
        $payMethod  = clean_string($_POST['payment_method'] ?? '', 50);
        $payDetails = clean_string($_POST['payment_details'] ?? '', 500);
        $notes = clean_string($_POST['notes'] ?? '', 1000);

        if (mb_strlen($name) < 2) {
            flash('error', 'الاسم قصير جداً');
        } elseif (!$email) {
            flash('error', 'البريد غير صالح');
        } elseif (mb_strlen($pass) < 8) {
            flash('error', 'كلمة المرور يجب 8 خانات على الأقل');
        } else {
            $exists = $pdo->prepare('SELECT id FROM affiliates WHERE email = ? LIMIT 1');
            $exists->execute([$email]);
            if ($exists->fetch()) {
                flash('error', 'هذا البريد مسجّل بالفعل');
            } else {
                $code = generateUniqueReferralCode($pdo, 6);
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO affiliates (name, email, password, phone, whatsapp, referral_code, commission_rate, payment_method, payment_details, notes, is_active)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
                    ->execute([$name, $email, $hash, $phone, $whatsapp, $code, $rate, $payMethod, $payDetails, $notes]);
                $newId = (int) $pdo->lastInsertId();
                log_activity_event('affiliate_created', ['affiliate_id' => $newId, 'name' => $name, 'code' => $code]);
                flash('success', 'تم إنشاء الوسيط بنجاح. الكود: ' . $code);
            }
        }
    } elseif ($action === 'update' && $id) {
        $name  = clean_string($_POST['name'] ?? '', 150);
        $email = clean_email($_POST['email'] ?? '');
        $phone = clean_phone($_POST['phone'] ?? '');
        $whatsapp = clean_phone($_POST['whatsapp'] ?? '');
        $rate  = max(0, min(100, (float) ($_POST['commission_rate'] ?? 10)));
        $payMethod  = clean_string($_POST['payment_method'] ?? '', 50);
        $payDetails = clean_string($_POST['payment_details'] ?? '', 500);
        $notes = clean_string($_POST['notes'] ?? '', 1000);
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $newPass = (string) ($_POST['new_password'] ?? '');

        if ($newPass !== '') {
            if (mb_strlen($newPass) < 8) {
                flash('error', 'كلمة المرور يجب 8 خانات على الأقل');
                redirect(BASE_URL . '/super/affiliates.php');
            }
            $pdo->prepare('UPDATE affiliates SET password = ? WHERE id = ?')
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $id]);
        }

        $pdo->prepare('UPDATE affiliates SET name=?, email=?, phone=?, whatsapp=?, commission_rate=?, payment_method=?, payment_details=?, notes=?, is_active=? WHERE id=?')
            ->execute([$name, $email, $phone, $whatsapp, $rate, $payMethod, $payDetails, $notes, $isActive, $id]);
        log_activity_event('affiliate_updated', ['affiliate_id' => $id]);
        flash('success', 'تم تحديث الوسيط');
    } elseif ($action === 'toggle_active' && $id) {
        $pdo->prepare('UPDATE affiliates SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', 'تم تحديث حالة الوسيط');
    } elseif ($action === 'delete' && $id) {
        // Soft check: don't delete if any stores still linked
        $linked = (int) $pdo->prepare('SELECT COUNT(*) FROM stores WHERE affiliate_id = ?')->execute([$id]) ?: 0;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM stores WHERE affiliate_id = ?');
        $stmt->execute([$id]);
        $linked = (int) $stmt->fetchColumn();
        if ($linked > 0) {
            flash('error', "لا يمكن حذف الوسيط — مرتبط بـ {$linked} متجر. عطّله بدلاً من ذلك.");
        } else {
            $pdo->prepare('DELETE FROM affiliates WHERE id = ?')->execute([$id]);
            log_activity_event('affiliate_deleted', ['affiliate_id' => $id]);
            flash('success', 'تم حذف الوسيط');
        }
    } elseif ($action === 'regen_code' && $id) {
        $newCode = generateUniqueReferralCode($pdo, 6);
        $pdo->prepare('UPDATE affiliates SET referral_code = ? WHERE id = ?')->execute([$newCode, $id]);
        flash('success', 'كود إحالة جديد: ' . $newCode);
    }
    redirect(BASE_URL . '/super/affiliates.php');
}

// ─── Load list ─────────────────────────────────────────────────────────────
$rows = $pdo->query("
    SELECT a.*,
        (SELECT COUNT(*) FROM stores s WHERE s.affiliate_id = a.id) AS stores_count,
        (SELECT COUNT(*) FROM stores s WHERE s.affiliate_id = a.id AND s.is_active = 1) AS active_stores,
        (SELECT COALESCE(SUM(p.affiliate_amount), 0) FROM payments p WHERE p.affiliate_id = a.id) AS total_earned,
        (SELECT COALESCE(SUM(p.affiliate_amount), 0) FROM payments p WHERE p.affiliate_id = a.id AND p.affiliate_paid = 1) AS total_paid
    FROM affiliates a
    ORDER BY a.is_active DESC, a.created_at DESC
")->fetchAll();

require_once __DIR__ . '/../includes/header_super.php';
?>

<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-black text-white mb-1">الوسطاء</h1>
            <p class="text-gray-400 text-sm">إدارة وسطاء الإحالة ونسبهم وأرباحهم</p>
        </div>
        <button onclick="openCreateModal()"
                class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold shadow-lg shadow-orange-500/30 hover:shadow-xl active:scale-95 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            إضافة وسيط
        </button>
    </div>

    <!-- Affiliates table -->
    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-white/5 border-b border-white/10">
                    <tr class="text-right text-gray-400 text-xs uppercase">
                        <th class="px-4 py-3 font-bold">#</th>
                        <th class="px-4 py-3 font-bold">الاسم</th>
                        <th class="px-4 py-3 font-bold">البريد</th>
                        <th class="px-4 py-3 font-bold">الكود</th>
                        <th class="px-4 py-3 font-bold">النسبة</th>
                        <th class="px-4 py-3 font-bold">المتاجر</th>
                        <th class="px-4 py-3 font-bold">إجمالي/مدفوع</th>
                        <th class="px-4 py-3 font-bold">الحالة</th>
                        <th class="px-4 py-3 font-bold text-center">إجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-gray-500">
                                <p class="text-4xl mb-2">👥</p>
                                <p>لا يوجد وسطاء بعد. اضغط «إضافة وسيط» للبدء.</p>
                            </td>
                        </tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr class="text-gray-300 hover:bg-white/5 transition">
                            <td class="px-4 py-3 text-gray-500">#<?= (int) $r['id'] ?></td>
                            <td class="px-4 py-3">
                                <p class="font-bold text-white"><?= e($r['name']) ?></p>
                                <?php if ($r['phone']): ?>
                                    <p class="text-xs text-gray-500" dir="ltr"><?= e($r['phone']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm" dir="ltr"><?= e($r['email']) ?></td>
                            <td class="px-4 py-3">
                                <span class="font-mono font-bold text-orange-400 bg-orange-500/10 px-2 py-1 rounded"><?= e($r['referral_code']) ?></span>
                            </td>
                            <td class="px-4 py-3 font-bold text-white"><?= number_format((float) $r['commission_rate'], 2) ?>%</td>
                            <td class="px-4 py-3 text-sm">
                                <?= (int) $r['active_stores'] ?> / <?= (int) $r['stores_count'] ?>
                                <span class="text-gray-500 text-xs">نشط</span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <p class="text-emerald-400"><?= number_format((float) $r['total_earned']) ?></p>
                                <p class="text-xs text-gray-500">
                                    مدفوع: <?= number_format((float) $r['total_paid']) ?>
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded-full text-xs font-bold
                                        <?= $r['is_active'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-500/20 text-gray-400' ?>">
                                    <?= $r['is_active'] ? 'نشط' : 'معطّل' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <button onclick='openEditModal(<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?>)'
                                            class="p-2 rounded-lg hover:bg-white/10 text-blue-400" title="تعديل">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('توليد كود جديد؟ الكود الحالي سيتوقّف عن العمل.')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="regen_code">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="p-2 rounded-lg hover:bg-white/10 text-amber-400" title="توليد كود جديد">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                            </svg>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="p-2 rounded-lg hover:bg-white/10 text-amber-400" title="<?= $r['is_active'] ? 'تعطيل' : 'تفعيل' ?>">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728L18.364 5.636M5.636 18.364l12.728-12.728"/>
                                            </svg>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return confirm('حذف الوسيط نهائياً؟')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="p-2 rounded-lg hover:bg-white/10 text-red-400" title="حذف">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ═══════ Create / Edit Modal ═══════ -->
<div id="affModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeModal()">
    <div class="bg-gray-900 border border-white/10 rounded-2xl p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <h2 id="modalTitle" class="text-xl font-black text-white mb-5">إضافة وسيط</h2>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="modalAction" value="create">
            <input type="hidden" name="id" id="modalId" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-300 mb-2">الاسم الكامل *</label>
                    <input type="text" name="name" id="f_name" required maxlength="150"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-300 mb-2">البريد الإلكتروني *</label>
                    <input type="email" name="email" id="f_email" required dir="ltr"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-300 mb-2">الهاتف</label>
                    <input type="tel" name="phone" id="f_phone" dir="ltr"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-300 mb-2">واتساب</label>
                    <input type="tel" name="whatsapp" id="f_whatsapp" dir="ltr"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-300 mb-2">نسبة العمولة % *</label>
                    <input type="number" name="commission_rate" id="f_rate" required min="0" max="100" step="0.01" value="10.00"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-300 mb-2">طريقة الدفع</label>
                    <select name="payment_method" id="f_pmethod"
                            class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                        <option value="">— اختر —</option>
                        <option value="bank">حوالة بنكية</option>
                        <option value="whish">Whish Money</option>
                        <option value="cash">نقدي</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-gray-300 mb-2">تفاصيل الدفع (IBAN، رقم محفظة، إلخ)</label>
                    <textarea name="payment_details" id="f_pdetails" rows="2" maxlength="500"
                              class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-gray-300 mb-2">ملاحظات داخلية (لا يراها الوسيط)</label>
                    <textarea name="notes" id="f_notes" rows="2" maxlength="1000"
                              class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white"></textarea>
                </div>
                <div id="passwordWrap">
                    <label class="block text-sm font-bold text-gray-300 mb-2">كلمة المرور *</label>
                    <input type="password" name="password" id="f_password" minlength="8"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white">
                </div>
                <div id="newPasswordWrap" class="hidden">
                    <label class="block text-sm font-bold text-gray-300 mb-2">كلمة مرور جديدة (اختياري)</label>
                    <input type="password" name="new_password" id="f_new_password" minlength="8"
                           class="w-full px-4 py-2.5 rounded-xl bg-white/5 border-2 border-white/10 text-white"
                           placeholder="اتركها فارغة للاحتفاظ بالحالية">
                </div>
                <div id="activeWrap" class="hidden md:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="checkbox" name="is_active" id="f_active" value="1" class="w-4 h-4">
                        <span>الحساب نشط (الوسيط يستطيع الدخول)</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-white/10">
                <button type="button" onclick="closeModal()" class="px-5 py-2.5 rounded-xl text-gray-400 hover:text-white hover:bg-white/5 font-bold transition">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold shadow-lg shadow-orange-500/30 active:scale-95 transition">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('affModal');

function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'إضافة وسيط جديد';
    document.getElementById('modalAction').value = 'create';
    document.getElementById('modalId').value = '';
    document.getElementById('f_name').value = '';
    document.getElementById('f_email').value = '';
    document.getElementById('f_phone').value = '';
    document.getElementById('f_whatsapp').value = '';
    document.getElementById('f_rate').value = '10.00';
    document.getElementById('f_pmethod').value = '';
    document.getElementById('f_pdetails').value = '';
    document.getElementById('f_notes').value = '';
    document.getElementById('f_password').value = '';
    document.getElementById('f_password').required = true;
    document.getElementById('passwordWrap').classList.remove('hidden');
    document.getElementById('newPasswordWrap').classList.add('hidden');
    document.getElementById('activeWrap').classList.add('hidden');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function openEditModal(aff) {
    document.getElementById('modalTitle').textContent = 'تعديل: ' + aff.name;
    document.getElementById('modalAction').value = 'update';
    document.getElementById('modalId').value = aff.id;
    document.getElementById('f_name').value = aff.name;
    document.getElementById('f_email').value = aff.email;
    document.getElementById('f_phone').value = aff.phone || '';
    document.getElementById('f_whatsapp').value = aff.whatsapp || '';
    document.getElementById('f_rate').value = aff.commission_rate;
    document.getElementById('f_pmethod').value = aff.payment_method || '';
    document.getElementById('f_pdetails').value = aff.payment_details || '';
    document.getElementById('f_notes').value = aff.notes || '';
    document.getElementById('f_password').required = false;
    document.getElementById('passwordWrap').classList.add('hidden');
    document.getElementById('newPasswordWrap').classList.remove('hidden');
    document.getElementById('newPasswordWrap').classList.add('md:col-span-2');
    document.getElementById('f_new_password').value = '';
    document.getElementById('activeWrap').classList.remove('hidden');
    document.getElementById('f_active').checked = !!aff.is_active && aff.is_active != '0';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>

<?php require_once __DIR__ . '/../includes/footer_super.php'; ?>
