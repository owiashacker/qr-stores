<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';
requireAdminLogin();
$pageTitle = 'إعدادات المنصة';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $allowedKeys = [
            'site_name', 'site_tagline', 'site_description', 'contact_email', 'contact_whatsapp',
            'primary_color', 'footer_text', 'watermark_text', 'currency_symbol', 'bank_details',
            'allow_registration', 'allowed_payment_methods',
            // Telegram bot integration
            'telegram_enabled', 'telegram_bot_token', 'telegram_chat_id',
            'telegram_notify_store_signup', 'telegram_notify_plan_upgrade',
        ];
        // Checkbox keys default to '0' when unchecked (browsers omit unchecked checkboxes)
        $checkboxKeys = ['telegram_enabled', 'telegram_notify_store_signup', 'telegram_notify_plan_upgrade'];

        $stmt = $pdo->prepare('INSERT INTO site_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($allowedKeys as $key) {
            if (in_array($key, $checkboxKeys, true)) {
                $value = !empty($_POST[$key]) ? '1' : '0';
            } else {
                $value = $_POST[$key] ?? '';
                if (is_array($value)) $value = implode(',', $value);
            }
            $stmt->execute([$key, $value]);
        }
        flash('success', 'تم حفظ الإعدادات');
    } elseif ($action === 'test_telegram') {
        $ok = tgNotifyEvent($pdo, 'test');
        if ($ok) {
            flash('success', 'تم إرسال رسالة اختبار إلى تيليغرام بنجاح ✓ تحقّق من قناتك.');
        } else {
            flash('error', '✕ فشل إرسال رسالة الاختبار. تحقّق من Token + Chat ID وأن البوت مُفعّل.');
        }
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

        <!-- ═══ Telegram bot integration ═══ -->
        <div class="card rounded-2xl p-6 md:p-8 mb-6 border-r-4 border-sky-400">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-400 to-blue-600 flex items-center justify-center text-white text-xl">✈</div>
                <div>
                    <h3 class="text-lg font-bold text-white">تنبيهات تيليغرام</h3>
                    <p class="text-xs text-gray-500">احصل على إشعار فوري عند تسجيل متجر جديد أو طلب ترقية باقة</p>
                </div>
            </div>

            <!-- Quick setup guide -->
            <div class="rounded-xl bg-sky-500/10 border border-sky-500/30 p-4 mb-5 text-xs text-sky-200 leading-relaxed">
                <p class="font-bold text-sky-300 mb-2">طريقة الإعداد (مرّة واحدة):</p>
                <ol class="space-y-1 list-decimal list-inside">
                    <li>افتح تيليغرام وابحث عن <span class="font-mono bg-white/10 px-1 rounded">@BotFather</span> → أنشئ بوت جديد <span class="font-mono">/newbot</span> → احفظ الـ Token.</li>
                    <li>افتح محادثة مع البوت الجديد وأرسل أي رسالة (مثلاً: <span class="font-mono">hi</span>).</li>
                    <li>افتح في المتصفح:
                        <span class="font-mono bg-white/10 px-1 rounded text-[10px]" dir="ltr">https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</span>
                        وانسخ <span class="font-mono">chat.id</span> الذي يظهر في الـ JSON.
                    </li>
                    <li>الصق الـ Token + Chat ID هنا واضغط <b>اختبار البوت</b>.</li>
                </ol>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="telegram_enabled" value="1"
                               <?= ($settings['telegram_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                               class="w-5 h-5 rounded">
                        <span class="text-sm font-bold text-white">تفعيل البوت</span>
                    </label>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">Bot Token</label>
                    <input type="text" name="telegram_bot_token" dir="ltr"
                           value="<?= e($settings['telegram_bot_token'] ?? '') ?>"
                           placeholder="123456789:ABCdefGHI..."
                           class="w-full px-4 py-3 rounded-xl border-2 font-mono text-sm">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chat ID</label>
                    <input type="text" name="telegram_chat_id" dir="ltr"
                           value="<?= e($settings['telegram_chat_id'] ?? '') ?>"
                           placeholder="123456789 أو -100123456789 (للقنوات)"
                           class="w-full px-4 py-3 rounded-xl border-2 font-mono text-sm">
                </div>

                <div class="md:col-span-2 mt-2">
                    <p class="text-sm font-bold text-gray-300 mb-3">أنواع التنبيهات:</p>
                    <div class="space-y-2">
                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-white/5 hover:bg-white/10 transition">
                            <input type="checkbox" name="telegram_notify_store_signup" value="1"
                                   <?= ($settings['telegram_notify_store_signup'] ?? '1') === '1' ? 'checked' : '' ?>
                                   class="w-5 h-5">
                            <div class="flex-1">
                                <p class="font-bold text-white">🆕 تسجيل متجر جديد</p>
                                <p class="text-xs text-gray-400">إشعار عند تسجيل صاحب متجر — مع إيميله ورقم واتسابه</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-white/5 hover:bg-white/10 transition">
                            <input type="checkbox" name="telegram_notify_plan_upgrade" value="1"
                                   <?= ($settings['telegram_notify_plan_upgrade'] ?? '1') === '1' ? 'checked' : '' ?>
                                   class="w-5 h-5">
                            <div class="flex-1">
                                <p class="font-bold text-white">💎 طلب ترقية باقة</p>
                                <p class="text-xs text-gray-400">إشعار عند طلب صاحب متجر ترقية باقته (Free → Pro/Max)</p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold shadow-lg">حفظ الإعدادات</button>
        </div>
    </form>

    <!-- Test Telegram (separate form so it doesn't trigger save_settings) -->
    <form method="POST" class="-mt-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="test_telegram">
        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2 rounded-xl bg-sky-500 hover:bg-sky-600 text-white font-bold shadow text-sm">
                ✈ اختبار البوت (إرسال رسالة)
            </button>
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
