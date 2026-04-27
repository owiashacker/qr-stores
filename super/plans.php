<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();
$pageTitle = 'الباقات والأسعار';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id = (int) $_POST['id'];
        $fields = [
            'name' => trim($_POST['name'] ?? ''),
            'tagline' => trim($_POST['tagline'] ?? ''),
            'price' => (float) ($_POST['price'] ?? 0),
            'period' => trim($_POST['period'] ?? 'monthly'),
            'max_categories' => (int) ($_POST['max_categories'] ?? -1),
            'max_items' => (int) ($_POST['max_items'] ?? -1),
            'max_qr_styles' => max(1, min(5, (int) ($_POST['max_qr_styles'] ?? 1))),
            'features_list' => trim($_POST['features_list'] ?? ''),
            'is_popular' => isset($_POST['is_popular']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        $capFields = ['can_upload_logo', 'can_upload_cover', 'can_customize_colors', 'can_edit_contact', 'can_social_links', 'can_use_discount', 'can_feature_items', 'can_remove_watermark', 'can_custom_domain', 'can_multiple_media', 'can_custom_message', 'has_analytics', 'has_priority_support'];
        foreach ($capFields as $f) $fields[$f] = isset($_POST[$f]) ? 1 : 0;

        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE plans SET $set WHERE id = ?");
        $stmt->execute([...array_values($fields), $id]);
        flash('success', 'تم تحديث الباقة');
        redirect(BASE_URL . '/super/plans.php');
    }
}

$plans = $pdo->query('SELECT * FROM plans ORDER BY sort_order, id')->fetchAll();

$editId = (int) ($_GET['edit'] ?? 0);
$editPlan = null;
foreach ($plans as $p) if ($p['id'] == $editId) $editPlan = $p;

require __DIR__ . '/../includes/header_super.php';
?>

<?php if ($editPlan): ?>
<div class="card rounded-2xl p-6 md:p-8">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-bold text-white">تعديل باقة: <?= e($editPlan['name']) ?> <span class="text-xs text-gray-500">(<?= e($editPlan['code']) ?>)</span></h2>
        <a href="plans.php" class="p-2 rounded-lg hover:bg-white/5 text-gray-400">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </a>
    </div>
    <form method="POST" class="space-y-6">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $editPlan['id'] ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">اسم الباقة</label>
                <input type="text" name="name" value="<?= e($editPlan['name']) ?>" required class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">الشعار التسويقي</label>
                <input type="text" name="tagline" value="<?= e($editPlan['tagline']) ?>" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">السعر</label>
                <input type="number" step="0.01" name="price" value="<?= e($editPlan['price']) ?>" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">الفترة</label>
                <select name="period" class="w-full px-4 py-3 rounded-xl border-2">
                    <?php foreach (['monthly' => 'شهري', 'yearly' => 'سنوي', 'forever' => 'دائم'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $editPlan['period'] == $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">حد الأقسام <span class="text-xs text-gray-500">(-1 = غير محدود)</span></label>
                <input type="number" name="max_categories" value="<?= e($editPlan['max_categories']) ?>" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">حد الأصناف <span class="text-xs text-gray-500">(-1 = غير محدود)</span></label>
                <input type="number" name="max_items" value="<?= e($editPlan['max_items']) ?>" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-semibold text-gray-300 mb-2">عدد تصاميم QR Code المتاحة <span class="text-xs text-gray-500">(1 إلى 5 — مُرتبة: الكلاسيكي، بلون المطعم، الداكن، الذهبي، التدرج)</span></label>
                <input type="number" name="max_qr_styles" min="1" max="5" value="<?= e($editPlan['max_qr_styles'] ?? 1) ?>" class="w-full px-4 py-3 rounded-xl border-2">
            </div>
        </div>

        <div>
            <h3 class="text-lg font-bold text-white mb-3">الصلاحيات والميزات</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <?php
                $caps = [
                    'can_upload_logo' => 'رفع شعار المطعم',
                    'can_upload_cover' => 'رفع صورة غلاف',
                    'can_customize_colors' => 'تخصيص الألوان',
                    'can_edit_contact' => 'تعديل العنوان (الهاتف والواتساب متاحان للجميع)',
                    'can_social_links' => 'روابط السوشيال ميديا',
                    'can_use_discount' => 'استخدام الخصومات',
                    'can_feature_items' => 'تمييز الأصناف',
                    'can_multiple_media' => 'صور وفيديوهات متعددة لكل صنف',
                    'can_custom_message' => 'استوديو تخصيص رسالة الطلب (WhatsApp)',
                    'can_remove_watermark' => 'إزالة العلامة المائية',
                    'can_custom_domain' => 'دومين خاص',
                    'has_analytics' => 'الإحصائيات',
                    'has_priority_support' => 'دعم فني مميز',
                ];
                foreach ($caps as $key => $label):
                ?>
                    <label class="flex items-center gap-3 p-3 rounded-xl bg-white/5 cursor-pointer hover:bg-white/10">
                        <input type="checkbox" name="<?= $key ?>" <?= $editPlan[$key] ? 'checked' : '' ?> class="w-5 h-5 rounded text-emerald-500">
                        <span class="text-sm text-gray-200"><?= $label ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <label class="block text-sm font-semibold text-gray-300 mb-2">قائمة الميزات (كل سطر ميزة — تظهر في صفحة الباقات)</label>
            <textarea name="features_list" rows="8" class="w-full px-4 py-3 rounded-xl border-2 font-mono text-sm"><?= e($editPlan['features_list']) ?></textarea>
        </div>

        <div class="flex gap-4">
            <label class="flex items-center gap-3 p-3 rounded-xl bg-white/5 cursor-pointer">
                <input type="checkbox" name="is_popular" <?= $editPlan['is_popular'] ? 'checked' : '' ?> class="w-5 h-5 rounded text-emerald-500">
                <span class="text-sm text-gray-200">⭐ الأكثر مبيعاً</span>
            </label>
            <label class="flex items-center gap-3 p-3 rounded-xl bg-white/5 cursor-pointer">
                <input type="checkbox" name="is_active" <?= $editPlan['is_active'] ? 'checked' : '' ?> class="w-5 h-5 rounded text-emerald-500">
                <span class="text-sm text-gray-200">✓ نشط (متاح للعملاء)</span>
            </label>
        </div>

        <div class="flex justify-end gap-3">
            <a href="plans.php" class="px-6 py-3 rounded-xl text-gray-400 hover:bg-white/5 font-semibold">إلغاء</a>
            <button type="submit" class="px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold shadow-lg">حفظ التغييرات</button>
        </div>
    </form>
</div>

<?php else: ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <?php foreach ($plans as $plan):
        $badgeColors = ['free' => 'bg-gray-500/20 text-gray-300', 'pro' => 'bg-emerald-500/20 text-emerald-300', 'max' => 'bg-amber-500/20 text-amber-300'];
        $userCount = (int) $pdo->query("SELECT COUNT(*) FROM stores WHERE plan_id = " . $plan['id'])->fetchColumn();
    ?>
    <div class="card rounded-2xl p-6 hover:shadow-xl transition">
        <div class="flex items-start justify-between mb-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <h3 class="text-xl font-bold text-white"><?= e($plan['name']) ?></h3>
                    <?php if ($plan['is_popular']): ?><span class="text-xs">⭐</span><?php endif; ?>
                </div>
                <span class="px-2 py-0.5 rounded text-xs font-bold <?= $badgeColors[$plan['code']] ?? 'bg-gray-500/20 text-gray-300' ?>"><?= e($plan['code']) ?></span>
            </div>
            <?php if (!$plan['is_active']): ?>
                <span class="px-2 py-1 rounded bg-red-500/20 text-red-300 text-xs font-bold">موقوفة</span>
            <?php endif; ?>
        </div>
        <div class="mb-4 pb-4 border-b border-white/5">
            <p class="text-3xl font-black text-white">$<?= (int) $plan['price'] ?><span class="text-sm text-gray-500">/<?= $plan['period'] === 'monthly' ? 'شهرياً' : ($plan['period'] === 'yearly' ? 'سنوياً' : 'دائم') ?></span></p>
            <p class="text-xs text-gray-500 mt-1"><?= e($plan['tagline']) ?></p>
        </div>
        <div class="space-y-2 text-sm mb-4">
            <div class="flex justify-between text-gray-400"><span>الأقسام</span><span class="text-white font-bold"><?= $plan['max_categories'] === -1 ? '∞' : $plan['max_categories'] ?></span></div>
            <div class="flex justify-between text-gray-400"><span>الأصناف</span><span class="text-white font-bold"><?= $plan['max_items'] === -1 ? '∞' : $plan['max_items'] ?></span></div>
            <div class="flex justify-between text-gray-400"><span>المشتركون</span><span class="text-emerald-400 font-bold"><?= $userCount ?></span></div>
        </div>
        <a href="?edit=<?= $plan['id'] ?>" class="block text-center py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-white font-bold transition">تعديل</a>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer_super.php'; ?>
