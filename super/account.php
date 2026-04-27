<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'حسابي';

$admin = currentAdmin($pdo);
if (!$admin) {
    unset($_SESSION['admin_id']);
    redirect(BASE_URL . '/super/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '' || $email === '') {
            flash('error', 'الاسم والبريد مطلوبان');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'البريد الإلكتروني غير صالح');
        } else {
            // Ensure email isn't already taken by another admin
            $dup = $pdo->prepare('SELECT id FROM admins WHERE email = ? AND id != ?');
            $dup->execute([$email, $admin['id']]);
            if ($dup->fetch()) {
                flash('error', 'هذا البريد مستخدم من قبل حساب آخر');
            } else {
                $pdo->prepare('UPDATE admins SET name = ?, email = ? WHERE id = ?')
                    ->execute([$name, $email, $admin['id']]);
                log_activity_event('admin_profile_updated', ['admin_id' => $admin['id']]);
                flash('success', 'تم تحديث بيانات الحساب');
            }
        }
        redirect(BASE_URL . '/super/account.php');
    }

    if ($action === 'change_password') {
        $new     = (string) ($_POST['new_password']     ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        // Session auth is trusted (admin is already logged in). We only validate
        // the new password quality + that it actually differs from the existing one.
        if (mb_strlen($new) < 8) {
            flash('error', 'كلمة المرور الجديدة يجب أن تكون 8 خانات على الأقل');
        } elseif ($new !== $confirm) {
            flash('error', 'كلمة المرور الجديدة والتأكيد غير متطابقين');
        } elseif (password_verify($new, $admin['password'])) {
            flash('error', 'كلمة المرور الجديدة يجب أن تختلف عن القديمة');
        } else {
            $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            log_activity_event('admin_password_changed', ['admin_id' => $admin['id']]);
            flash('success', 'تم تغيير كلمة المرور بنجاح');
        }
        redirect(BASE_URL . '/super/account.php');
    }
}

require __DIR__ . '/../includes/header_super.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Profile Info Card -->
    <div class="lg:col-span-1">
        <div class="card rounded-2xl p-6 text-center">
            <div class="w-24 h-24 mx-auto rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white font-black text-4xl shadow-xl mb-4">
                <?= e(mb_substr($admin['name'], 0, 1)) ?>
            </div>
            <h2 class="text-xl font-black text-white mb-1"><?= e($admin['name']) ?></h2>
            <p class="text-sm text-gray-400 mb-3"><?= e($admin['email']) ?></p>
            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-500/15 text-emerald-300 text-xs font-bold">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <?= $admin['role'] === 'super_admin' ? 'مدير عام' : e($admin['role']) ?>
            </span>

            <div class="mt-6 pt-6 border-t border-white/5 text-right space-y-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">تاريخ الإنشاء</span>
                    <span class="text-gray-300 font-mono"><?= $admin['created_at'] ? date('Y-m-d', strtotime($admin['created_at'])) : '—' ?></span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">آخر دخول</span>
                    <span class="text-gray-300 font-mono"><?= $admin['last_login'] ? date('Y-m-d H:i', strtotime($admin['last_login'])) : 'لم يسجل بعد' ?></span>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">الحالة</span>
                    <?php if ($admin['is_active']): ?>
                        <span class="inline-flex items-center gap-1 text-emerald-400 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>نشط</span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 text-gray-500 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-gray-500"></span>موقوف</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Forms -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Update profile -->
        <div class="card rounded-2xl overflow-hidden">
            <div class="p-6 border-b border-white/5 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500 to-indigo-600 flex items-center justify-center text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">بيانات الحساب</h3>
                    <p class="text-xs text-gray-500">عدّل اسمك وبريدك الإلكتروني</p>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_profile">
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">الاسم <span class="text-red-400">*</span></label>
                    <input type="text" name="name" value="<?= e($admin['name']) ?>" required class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">البريد الإلكتروني <span class="text-red-400">*</span></label>
                    <input type="email" name="email" value="<?= e($admin['email']) ?>" required class="w-full px-4 py-3 rounded-xl border-2" dir="ltr">
                    <p class="text-xs text-gray-500 mt-1">هذا البريد تستخدمه لتسجيل الدخول</p>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 text-white font-bold">حفظ البيانات</button>
                </div>
            </form>
        </div>

        <!-- Change password -->
        <div class="card rounded-2xl overflow-hidden">
            <div class="p-6 border-b border-white/5 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">تغيير كلمة المرور</h3>
                    <p class="text-xs text-gray-500">استخدم كلمة مرور قوية لحماية حسابك</p>
                </div>
            </div>
            <form method="POST" class="p-6 space-y-4" onsubmit="return validatePwdChange()">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">كلمة المرور الجديدة <span class="text-red-400">*</span></label>
                        <input type="password" name="new_password" id="cp-new" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2" autocomplete="new-password" placeholder="8 خانات على الأقل">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-300 mb-2">تأكيد كلمة المرور <span class="text-red-400">*</span></label>
                        <input type="password" name="confirm_password" id="cp-confirm" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2" autocomplete="new-password" placeholder="أعد إدخال كلمة المرور">
                        <p id="cp-mismatch" class="text-xs text-red-400 mt-1 hidden">كلمة المرور والتأكيد غير متطابقين</p>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs flex-wrap gap-2">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-gray-400">
                        <input type="checkbox" id="cp-show" onchange="togglePwdVisibility()" class="w-3.5 h-3.5">
                        <span>إظهار كلمات المرور</span>
                    </label>
                    <div class="text-gray-500">
                        <span id="cp-strength">قم بإدخال كلمة مرور جديدة لفحص قوتها</span>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-xs text-amber-200 leading-relaxed flex items-start gap-2">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <div>
                        <p class="font-bold mb-0.5">نصائح لكلمة مرور قوية:</p>
                        <ul class="list-disc mr-4 space-y-0.5 opacity-90">
                            <li>طولها 12 خانة أو أكثر</li>
                            <li>تحتوي على أحرف كبيرة وصغيرة وأرقام ورموز</li>
                            <li>لا تستخدم كلمات سهلة التخمين (اسمك، تاريخ ميلادك)</li>
                        </ul>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 text-white font-bold shadow-lg">تغيير كلمة المرور</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePwdVisibility() {
    const show = document.getElementById('cp-show').checked;
    ['cp-new', 'cp-confirm'].forEach(id => {
        document.getElementById(id).type = show ? 'text' : 'password';
    });
}

function computeStrength(pwd) {
    if (pwd.length < 8) return { label: 'ضعيفة جداً', class: 'text-red-400' };
    let score = 0;
    if (pwd.length >= 12) score++;
    if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
    if (/\d/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    if (pwd.length >= 16) score++;
    if (score <= 1) return { label: 'ضعيفة',   class: 'text-red-400' };
    if (score === 2) return { label: 'متوسطة', class: 'text-amber-400' };
    if (score === 3) return { label: 'قوية',   class: 'text-emerald-400' };
    return { label: 'قوية جداً', class: 'text-emerald-300' };
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

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
