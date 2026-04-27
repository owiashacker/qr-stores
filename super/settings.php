<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'إعدادات المنصة';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $allowedKeys = ['site_name', 'site_tagline', 'site_description', 'contact_email', 'contact_whatsapp', 'primary_color', 'footer_text', 'watermark_text', 'currency_symbol', 'bank_details', 'allow_registration', 'allowed_payment_methods'];
        $stmt = $pdo->prepare('INSERT INTO site_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($allowedKeys as $key) {
            $value = $_POST[$key] ?? '';
            if (is_array($value)) $value = implode(',', $value);
            $stmt->execute([$key, $value]);
        }
        flash('success', 'تم حفظ الإعدادات');
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $admin['password'])) {
            flash('error', 'كلمة المرور الحالية غير صحيحة');
        } elseif (strlen($new) < 8) {
            flash('error', 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل');
        } elseif ($new !== $confirm) {
            flash('error', 'كلمتا المرور غير متطابقتين');
        } else {
            $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['admin_id']]);
            flash('success', 'تم تغيير كلمة المرور');
        }
    }
    redirect(BASE_URL . '/super/settings.php');
}

// Load all settings
$settings = [];
foreach ($pdo->query('SELECT key_name, value FROM site_settings') as $row) {
    $settings[$row['key_name']] = $row['value'];
}

require __DIR__ . '/../includes/header_super.php';
?>

<div class="space-y-6">
    <!-- Platform Info -->
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="card rounded-2xl p-6 md:p-8 mb-6">
            <h3 class="text-lg font-bold text-white mb-1">معلومات المنصة</h3>
            <p class="text-sm text-gray-500 mb-6">تظهر في صفحة الهبوط والعلامة المائية</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">اسم المنصة</label>
                    <input type="text" name="site_name" value="<?= e($settings['site_name'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">العلامة المائية</label>
                    <input type="text" name="watermark_text" value="<?= e($settings['watermark_text'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-300 mb-2">الشعار التسويقي</label>
                    <input type="text" name="site_tagline" value="<?= e($settings['site_tagline'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-300 mb-2">الوصف (SEO)</label>
                    <textarea name="site_description" rows="2" class="w-full px-4 py-3 rounded-xl border-2"><?= e($settings['site_description'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">اللون الأساسي</label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="primary_color" value="<?= e($settings['primary_color'] ?? '#059669') ?>" class="w-14 h-14 rounded-xl border-2 cursor-pointer">
                        <span class="text-sm text-gray-500">لون صفحة الهبوط</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">نص الفوتر</label>
                    <input type="text" name="footer_text" value="<?= e($settings['footer_text'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="card rounded-2xl p-6 md:p-8 mb-6">
            <h3 class="text-lg font-bold text-white mb-1">معلومات التواصل</h3>
            <p class="text-sm text-gray-500 mb-6">يستخدمها العملاء للتواصل بخصوص الترقيات والدعم</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">📧 البريد الإلكتروني</label>
                    <input type="email" name="contact_email" value="<?= e($settings['contact_email'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">💬 واتساب</label>
                    <input type="text" name="contact_whatsapp" value="<?= e($settings['contact_whatsapp'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-300 mb-2">🏦 تفاصيل الحساب البنكي</label>
                    <textarea name="bank_details" rows="4" class="w-full px-4 py-3 rounded-xl border-2" placeholder="اسم البنك، رقم الحساب، IBAN..."><?= e($settings['bank_details'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Other -->
        <div class="card rounded-2xl p-6 md:p-8 mb-6">
            <h3 class="text-lg font-bold text-white mb-1">إعدادات عامة</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">العملة الافتراضية</label>
                    <input type="text" name="currency_symbol" value="<?= e($settings['currency_symbol'] ?? '') ?>" class="w-full px-4 py-3 rounded-xl border-2">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">السماح بالتسجيل</label>
                    <select name="allow_registration" class="w-full px-4 py-3 rounded-xl border-2">
                        <option value="1" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'selected' : '' ?>>نعم، مفتوح للجميع</option>
                        <option value="0" <?= ($settings['allow_registration'] ?? '1') === '0' ? 'selected' : '' ?>>موقوف مؤقتاً</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold shadow-lg">حفظ الإعدادات</button>
        </div>
    </form>

    <!-- Password -->
    <div class="card rounded-2xl p-6 md:p-8">
        <h3 class="text-lg font-bold text-white mb-1">تغيير كلمة المرور</h3>
        <p class="text-sm text-gray-500 mb-6">للحفاظ على أمان حسابك كمسؤول</p>
        <form method="POST" class="space-y-4 max-w-md">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">كلمة المرور الحالية</label>
                <input type="password" name="current_password" required class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">كلمة المرور الجديدة</label>
                <input type="password" name="new_password" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">تأكيد كلمة المرور</label>
                <input type="password" name="confirm_password" required minlength="8" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <button type="submit" class="w-full py-3 rounded-xl bg-red-500 hover:bg-red-600 text-white font-bold">تغيير كلمة المرور</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
