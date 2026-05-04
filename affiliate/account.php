<?php
require_once __DIR__ . '/../includes/functions.php';
$pageTitle = 'إعدادات الحساب';
require_once __DIR__ . '/../includes/header_affiliate.php';

$affId = (int) $aff['id'];

// ─── Handle POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name     = clean_string($_POST['name'] ?? '', 150);
        $phone    = clean_phone($_POST['phone'] ?? '');
        $whatsapp = clean_phone($_POST['whatsapp'] ?? '');
        $payDetails = clean_string($_POST['payment_details'] ?? '', 500);

        if (mb_strlen($name) < 2) {
            flash('error', 'الاسم قصير جداً');
        } else {
            $pdo->prepare('UPDATE affiliates SET name=?, phone=?, whatsapp=?, payment_details=? WHERE id=?')
                ->execute([$name, $phone, $whatsapp, $payDetails, $affId]);
            flash('success', 'تم حفظ بياناتك');
        }
        redirect(BASE_URL . '/affiliate/account.php');
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!verify_password($current, $aff['password'])) {
            flash('error', 'كلمة المرور الحالية غير صحيحة');
        } elseif (mb_strlen($new) < 8) {
            flash('error', 'كلمة المرور الجديدة يجب 8 خانات على الأقل');
        } elseif ($new !== $confirm) {
            flash('error', 'كلمة المرور الجديدة والتأكيد غير متطابقين');
        } else {
            $pdo->prepare('UPDATE affiliates SET password=? WHERE id=?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $affId]);
            flash('success', 'تم تغيير كلمة المرور');
        }
        redirect(BASE_URL . '/affiliate/account.php');
    }
}

// Reload after potential update (header_affiliate already loaded $aff)
$payMethodLabels = [
    'bank'  => 'حوالة بنكية',
    'whish' => 'Whish Money',
    'cash'  => 'نقدي',
    'other' => 'أخرى',
    ''      => '— غير محدد —',
];
?>

<div class="max-w-3xl mx-auto space-y-6">

    <div>
        <h1 class="text-2xl md:text-3xl font-black text-gray-900 mb-1">إعدادات الحساب</h1>
        <p class="text-gray-500 text-sm">عدّل بياناتك الشخصية وكلمة المرور</p>
    </div>

    <!-- Read-only info -->
    <div class="bg-gradient-to-l from-orange-50 to-amber-50 border border-orange-200 rounded-2xl p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <p class="text-xs text-gray-500 mb-1">البريد الإلكتروني</p>
            <p class="font-bold text-gray-900" dir="ltr"><?= e($aff['email']) ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">كود الإحالة</p>
            <p class="font-mono font-black text-orange-600 text-lg"><?= e($aff['referral_code']) ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">نسبة العمولة الافتراضية</p>
            <p class="font-bold text-gray-900"><?= number_format((float) $aff['commission_rate'], 2) ?>%</p>
        </div>
        <div class="sm:col-span-3">
            <p class="text-xs text-gray-500 mb-1">طريقة الدفع المعتمدة</p>
            <p class="font-bold text-gray-700"><?= e($payMethodLabels[$aff['payment_method'] ?? ''] ?? '—') ?></p>
            <p class="text-xs text-gray-400 mt-1">للتعديل، تواصل مع إدارة المنصة</p>
        </div>
    </div>

    <!-- Profile form -->
    <form method="POST" class="bg-white rounded-2xl shadow-card p-6 space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_profile">
        <h2 class="text-lg font-black text-gray-900 mb-3">المعلومات الشخصية</h2>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">الاسم الكامل</label>
            <input type="text" name="name" required maxlength="150" value="<?= e($aff['name']) ?>"
                   class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">الهاتف</label>
                <input type="tel" name="phone" value="<?= e($aff['phone']) ?>" dir="ltr"
                       class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">واتساب</label>
                <input type="tel" name="whatsapp" value="<?= e($aff['whatsapp']) ?>" dir="ltr"
                       class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition">
            </div>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">تفاصيل الدفع (IBAN، رقم محفظة)</label>
            <textarea name="payment_details" rows="2" maxlength="500"
                      class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition"><?= e($aff['payment_details']) ?></textarea>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold shadow-lg shadow-orange-500/30 active:scale-95 transition">
                حفظ التعديلات
            </button>
        </div>
    </form>

    <!-- Password form -->
    <form method="POST" class="bg-white rounded-2xl shadow-card p-6 space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <h2 class="text-lg font-black text-gray-900 mb-3">تغيير كلمة المرور</h2>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-2">كلمة المرور الحالية</label>
            <input type="password" name="current_password" required
                   class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">كلمة المرور الجديدة</label>
                <input type="password" name="new_password" required minlength="8"
                       class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition"
                       placeholder="8 خانات على الأقل">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">تأكيد كلمة المرور</label>
                <input type="password" name="confirm_password" required minlength="8"
                       class="w-full px-4 py-2.5 rounded-xl border-2 border-gray-100 focus:border-orange-500 transition">
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="px-6 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold shadow-lg active:scale-95 transition">
                تغيير كلمة المرور
            </button>
        </div>
    </form>

</div>

<?php require_once __DIR__ . '/../includes/footer_affiliate.php'; ?>
