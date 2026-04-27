<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pageTitle = 'حسابي';

$r = currentStore($pdo);
if (!$r) {
    unset($_SESSION['store_id']);
    redirect(BASE_URL . '/admin/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_email') {
        $email = trim($_POST['email'] ?? '');

        if ($email === '') {
            flash('error', 'البريد الإلكتروني مطلوب');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'البريد الإلكتروني غير صالح');
        } else {
            // Ensure email isn't already used by another store.
            $dup = $pdo->prepare('SELECT id FROM stores WHERE email = ? AND id != ?');
            $dup->execute([$email, $r['id']]);
            if ($dup->fetch()) {
                flash('error', 'هذا البريد مستخدم من قبل متجر آخر');
            } else {
                $pdo->prepare('UPDATE stores SET email = ? WHERE id = ?')
                    ->execute([$email, $r['id']]);
                log_activity_event('store_email_updated', ['store_id' => $r['id']]);
                flash('success', 'تم تحديث بريدك الإلكتروني. استخدمه في المرة القادمة لتسجيل الدخول.');
            }
        }
        redirect(BASE_URL . '/admin/account.php');
    }

    if ($action === 'change_password') {
        $new     = (string) ($_POST['new_password']     ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        // Session auth is trusted (user is already logged in). We only validate
        // the new password quality + that it actually differs from the existing one.
        if (mb_strlen($new) < 8) {
            flash('error', 'كلمة المرور الجديدة يجب أن تكون 8 خانات على الأقل');
        } elseif ($new !== $confirm) {
            flash('error', 'كلمة المرور الجديدة والتأكيد غير متطابقين');
        } elseif (password_verify($new, $r['password'])) {
            flash('error', 'كلمة المرور الجديدة يجب أن تختلف عن القديمة');
        } else {
            $pdo->prepare('UPDATE stores SET password = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $r['id']]);
            log_activity_event('store_password_changed', ['store_id' => $r['id']]);
            flash('success', 'تم تغيير كلمة المرور بنجاح');
        }
        redirect(BASE_URL . '/admin/account.php');
    }
}

$planCode = $r['plan_code'] ?? 'free';
$planName = $r['plan_name'] ?? 'مجاني';
$isExpired = !empty($r['is_expired']);

require __DIR__ . '/../includes/header_admin.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Profile Info Card -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-2xl shadow-soft p-6 text-center">
            <?php if (!empty($r['logo'])): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($r['logo']) ?>" class="w-24 h-24 mx-auto rounded-2xl object-cover shadow-xl mb-4">
            <?php else: ?>
                <div class="w-24 h-24 mx-auto rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-black text-4xl shadow-xl mb-4">
                    <?= e(mb_substr($r['name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            <h2 class="text-xl font-black text-gray-900 mb-1"><?= e($r['name']) ?></h2>
            <p class="text-sm text-gray-500 mb-3 break-all"><?= e($r['email']) ?></p>
            <?php
            $planBadge = $isExpired
                ? 'bg-red-100 text-red-700'
                : ($planCode === 'max' ? 'bg-amber-100 text-amber-700'
                : ($planCode === 'pro' ? 'bg-emerald-100 text-emerald-700'
                : 'bg-gray-100 text-gray-700'));
            ?>
            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full <?= $planBadge ?> text-xs font-bold">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <?= $isExpired ? 'باقة منتهية' : 'باقة ' . e($planName) ?>
            </span>

            <div class="mt-6 pt-6 border-t border-gray-100 text-right space-y-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">تاريخ التسجيل</span>
                    <span class="text-gray-700 font-mono"><?= $r['created_at'] ? date('Y-m-d', strtotime($r['created_at'])) : '—' ?></span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">رابط المتجر</span>
                    <span class="text-gray-700 font-mono">/<?= e($r['slug']) ?></span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">الحالة</span>
                    <?php if ($r['is_active']): ?>
                        <span class="inline-flex items-center gap-1 text-emerald-600 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>نشط</span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 text-gray-500 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>موقوف</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-100 text-right space-y-2">
                <a href="<?= BASE_URL ?>/admin/settings.php" class="flex items-center justify-between text-sm text-gray-700 hover:text-emerald-600 transition">
                    <span>تعديل بيانات المتجر (اسم، شعار، هوية)</span>
                    <svg class="w-4 h-4 rtl-flip" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
                <a href="<?= BASE_URL ?>/admin/upgrade.php" class="flex items-center justify-between text-sm text-gray-700 hover:text-emerald-600 transition">
                    <span>الباقات والترقية</span>
                    <svg class="w-4 h-4 rtl-flip" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>

    <!-- Forms -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Update email (login identifier) -->
        <div class="bg-white rounded-2xl shadow-soft overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500 to-indigo-600 flex items-center justify-center text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">البريد الإلكتروني للدخول</h3>
                    <p class="text-xs text-gray-500">هذا البريد تستخدمه لتسجيل الدخول لحسابك</p>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_email">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">البريد الإلكتروني <span class="text-red-500">*</span></label>
                    <input type="email" name="email" value="<?= e($r['email']) ?>" required class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" dir="ltr">
                    <p class="text-xs text-gray-500 mt-2">تأكّد من صحة البريد — لن تستطيع الدخول لحسابك بدونه.</p>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 text-white font-bold shadow-lg hover:shadow-xl transition">حفظ البريد</button>
                </div>
            </form>
        </div>

        <!-- Change password -->
        <div class="bg-white rounded-2xl shadow-soft overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-gray-900">تغيير كلمة المرور</h3>
                    <p class="text-xs text-gray-500">استخدم كلمة مرور قوية لحماية متجرك</p>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-4" onsubmit="return validatePwdChange()">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">كلمة المرور الجديدة <span class="text-red-500">*</span></label>
                        <input type="password" name="new_password" id="cp-new" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" autocomplete="new-password" placeholder="8 خانات على الأقل">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">تأكيد كلمة المرور <span class="text-red-500">*</span></label>
                        <input type="password" name="confirm_password" id="cp-confirm" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" autocomplete="new-password" placeholder="أعد إدخال كلمة المرور">
                        <p id="cp-mismatch" class="text-xs text-red-600 mt-1 hidden">كلمة المرور والتأكيد غير متطابقين</p>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-gray-600">
                        <input type="checkbox" id="cp-show" onchange="togglePwdVisibility()" class="w-3.5 h-3.5">
                        <span>إظهار كلمات المرور</span>
                    </label>
                    <div class="text-gray-500">
                        <span id="cp-strength">قم بإدخال كلمة مرور جديدة لفحص قوتها</span>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 leading-relaxed flex items-start gap-2">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <div>
                        <p class="font-bold mb-0.5">نصائح لكلمة مرور قوية:</p>
                        <ul class="list-disc mr-4 space-y-0.5 opacity-90">
                            <li>طولها 12 خانة أو أكثر</li>
                            <li>تحتوي على أحرف كبيرة وصغيرة وأرقام ورموز</li>
                            <li>لا تستخدم كلمات سهلة التخمين (اسم المتجر، تاريخ ميلادك)</li>
                        </ul>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-bold shadow-lg hover:shadow-xl transition">تغيير كلمة المرور</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .rtl-flip { transform: scaleX(-1); }
</style>

<script>
function togglePwdVisibility() {
    const show = document.getElementById('cp-show').checked;
    ['cp-new', 'cp-confirm'].forEach(id => {
        document.getElementById(id).type = show ? 'text' : 'password';
    });
}

function computeStrength(pwd) {
    if (pwd.length < 8) return { label: 'ضعيفة جداً', class: 'text-red-600' };
    let score = 0;
    if (pwd.length >= 12) score++;
    if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
    if (/\d/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    if (pwd.length >= 16) score++;
    if (score <= 1) return { label: 'ضعيفة',   class: 'text-red-600' };
    if (score === 2) return { label: 'متوسطة', class: 'text-amber-600' };
    if (score === 3) return { label: 'قوية',   class: 'text-emerald-600' };
    return { label: 'قوية جداً', class: 'text-emerald-700' };
}

document.getElementById('cp-new').addEventListener('input', function() {
    const el = document.getElementById('cp-strength');
    if (!this.value) {
        el.textContent = 'قم بإدخال كلمة مرور جديدة لفحص قوتها';
        el.className = 'text-gray-500';
        return;
    }
    const { label, class: cls } = computeStrength(this.value);
    el.textContent = 'القوة: ' + label;
    el.className = cls + ' font-bold';
});

document.getElementById('cp-confirm').addEventListener('input', function() {
    const n = document.getElementById('cp-new').value;
    const ok = !this.value || n === this.value;
    document.getElementById('cp-mismatch').classList.toggle('hidden', ok);
});

function validatePwdChange() {
    const n = document.getElementById('cp-new').value;
    const c = document.getElementById('cp-confirm').value;
    if (n.length < 8) {
        alert('كلمة المرور الجديدة يجب أن تكون 8 خانات على الأقل');
        return false;
    }
    if (n !== c) {
        document.getElementById('cp-mismatch').classList.remove('hidden');
        return false;
    }
    return true;
}
</script>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>
