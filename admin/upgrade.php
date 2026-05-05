<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram.php';
requireLogin();
$pageTitle = 'الباقات والترقية';
$rid = $_SESSION['store_id'];

// Handle upgrade request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck()) {
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'whatsapp');
    $notes = trim($_POST['notes'] ?? '');

    $plan = $pdo->prepare('SELECT * FROM plans WHERE id = ? AND is_active = 1');
    $plan->execute([$planId]);
    $plan = $plan->fetch();

    if ($plan) {
        $existing = $pdo->prepare('SELECT id FROM subscription_requests WHERE store_id = ? AND plan_id = ? AND status = ?');
        $existing->execute([$rid, $planId, 'pending']);
        if ($existing->fetch()) {
            flash('error', 'لديك طلب معلق لهذه الباقة. سنتواصل معك قريباً.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO subscription_requests (store_id, plan_id, payment_method, notes) VALUES (?, ?, ?, ?)');
            $stmt->execute([$rid, $planId, $paymentMethod, $notes]);
            flash('success', 'تم إرسال طلب الترقية إلى باقة ' . $plan['name'] . '. سنتواصل معك خلال 24 ساعة.');

            // Telegram notification (silent, never blocks the response)
            $storeRow = $pdo->prepare('SELECT s.name AS store_name, p.name AS current_plan FROM stores s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?');
            $storeRow->execute([$rid]);
            $sr = $storeRow->fetch();
            tgNotifyEvent($pdo, 'plan_upgrade', [
                'store_id'        => $rid,
                'store_name'      => $sr['store_name'] ?? '—',
                'current_plan'    => $sr['current_plan'] ?? '—',
                'requested_plan'  => $plan['name'],
                'note'            => $notes,
            ]);
        }
        redirect(BASE_URL . '/admin/upgrade.php');
    }
}

$r = currentStore($pdo);
$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price')->fetchAll();

// Sector-aware tokens for plan taglines, features_list, and comparison table.
// Plans text in the DB uses these placeholders so one row fits every business_type.
// Naming:
//   {items}/{item}          — indefinite noun (fits counts & adjectives: "3 أصناف", "صنف واحد")
//   {items_def}/{item_def}  — definite form ("الأصناف المميزة", "القسم الرئيسي")
//   {category}/{categories} — indefinite ("قسم واحد", "أقسام غير محدودة")
//   {category_def}/{categories_def} — definite ("القسم", "الأقسام")
//   {biz}                   — business-type name ("مطعم", "محل ألبسة")
//   {store}                 — biz name with Arabic definite article ("المطعم", "محل الألبسة")
//   {for_store}             — "for store" with proper ل-merging ("للمطعم", "لمحل الألبسة")

$stripAl = fn(string $s) => preg_replace('/^ال/u', '', $s) ?: $s;
$bizNameDef = bizNameDefinite($r);
// ل + ال = لل merge (المطعم → للمطعم). Compound names: just prepend ل (محل الألبسة → لمحل الألبسة).
$forStore = (mb_strpos($bizNameDef, 'ال') === 0)
    ? 'لل' . mb_substr($bizNameDef, mb_strlen('ال'))
    : 'ل' . $bizNameDef;

// Arabic gender agreement: nouns ending in ة are feminine and take واحدة instead of واحد.
$catIndef = $stripAl(bizLabel($r, 'category'));
$oneCategory = $catIndef . ' ' . (preg_match('/ة$/u', $catIndef) ? 'واحدة' : 'واحد');

$bizTokens = [
    '{items_def}'      => bizLabel($r, 'plural'),
    '{item_def}'       => 'ال' . bizLabel($r, 'singular'),
    '{categories_def}' => bizLabel($r, 'categories'),
    '{category_def}'   => bizLabel($r, 'category'),
    '{items}'          => $stripAl(bizLabel($r, 'plural')),         // أصناف / منتجات / موديلات
    '{item}'           => bizLabel($r, 'singular'),                 // صنف / منتج / موديل
    '{categories}'     => $stripAl(bizLabel($r, 'categories')),     // أقسام / فئات
    '{category}'       => $catIndef,                                // قسم / فئة
    '{one_category}'   => $oneCategory,                             // قسم واحد / فئة واحدة
    '{biz}'            => bizLabel($r, 'name'),                     // مطعم / محل ألبسة
    '{store}'          => $bizNameDef,                              // المطعم / محل الألبسة
    '{for_store}'      => $forStore,                                // للمطعم / لمحل الألبسة
];
$renderBizText = fn(string $t) => strtr($t, $bizTokens);

// Pending requests
$pending = $pdo->prepare('SELECT sr.*, p.name AS plan_name FROM subscription_requests sr JOIN plans p ON sr.plan_id = p.id WHERE sr.store_id = ? AND sr.status = ? ORDER BY sr.created_at DESC');
$pending->execute([$rid, 'pending']);
$pending = $pending->fetchAll();

$siteWhatsapp = siteSetting($pdo, 'contact_whatsapp', '');
$bankDetails = siteSetting($pdo, 'bank_details', '');

require __DIR__ . '/../includes/header_admin.php';
?>

<div>
    <div class="text-center mb-8">
        <h2 class="text-3xl md:text-4xl font-black mb-2">اختر الباقة المناسبة لك</h2>
        <p class="text-gray-500">باقتك الحالية: <span class="font-bold text-emerald-600"><?= e($r['plan_name']) ?></span></p>
    </div>

    <?php if ($pending): ?>
    <div class="mb-8 p-5 rounded-2xl bg-blue-50 border border-blue-200">
        <h3 class="font-bold text-blue-900 mb-2 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            طلبات معلقة
        </h3>
        <?php foreach ($pending as $req): ?>
            <p class="text-sm text-blue-800">طلب الترقية إلى <strong><?= e($req['plan_name']) ?></strong> — قيد المراجعة (<?= date('Y-m-d', strtotime($req['created_at'])) ?>)</p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Plans Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
        <?php foreach ($plans as $plan):
            $isCurrent = $plan['id'] == $r['plan_id'];
            $features = array_filter(array_map('trim', explode("\n", $plan['features_list'] ?? '')));
            $isPopular = $plan['is_popular'];
        ?>
        <div class="relative <?= $isPopular ? 'md:-mt-4' : '' ?>">
            <?php if ($isPopular): ?>
                <div class="absolute -top-3 right-1/2 translate-x-1/2 px-4 py-1 rounded-full bg-gradient-to-r from-amber-400 to-orange-500 text-white text-xs font-black shadow-lg z-10">الأكثر مبيعاً 🔥</div>
            <?php endif; ?>

            <div class="<?= $isCurrent ? 'ring-2 ring-emerald-500' : '' ?> bg-white rounded-3xl p-6 md:p-8 shadow-soft hover:shadow-xl transition h-full flex flex-col <?= $plan['code'] === 'max' ? 'bg-gradient-to-br from-gray-900 to-emerald-900 text-white' : '' ?>">
                <div class="mb-6">
                    <h3 class="text-2xl font-black mb-1 <?= $plan['code'] === 'max' ? 'text-white' : '' ?>"><?= e($plan['name']) ?></h3>
                    <p class="text-sm <?= $plan['code'] === 'max' ? 'text-emerald-200' : 'text-gray-500' ?>"><?= e($renderBizText($plan['tagline'])) ?></p>
                </div>
                <div class="mb-6 pb-6 border-b <?= $plan['code'] === 'max' ? 'border-white/20' : 'border-gray-100' ?>">
                    <?php if ($plan['price'] == 0): ?>
                        <div class="flex items-baseline gap-1">
                            <span class="text-5xl font-black">مجاني</span>
                        </div>
                    <?php else: ?>
                        <?php
                        // Prices are hidden on the public pricing cards by design:
                        // stores contact support on WhatsApp to get a quote/offer.
                        $periodLabel = $plan['period'] === 'monthly' ? 'شهرياً' : ($plan['period'] === 'yearly' ? 'سنوياً' : 'دائماً');
                        $waDigits = $siteWhatsapp ? preg_replace('/\D/', '', $siteWhatsapp) : '';
                        $waMsg = 'مرحباً، أريد الاستفسار عن سعر باقة ' . $plan['name'];
                        $waHref = $waDigits ? 'https://wa.me/' . $waDigits . '?text=' . rawurlencode($waMsg) : null;
                        $priceMuted = $plan['code'] === 'max' ? 'text-emerald-200' : 'text-gray-500';
                        $priceStrong = $plan['code'] === 'max' ? 'text-white' : 'text-gray-900';
                        ?>
                        <div class="space-y-2">
                            <div class="flex items-baseline gap-2">
                                <span class="text-2xl font-black <?= $priceStrong ?>">اتصل للسعر</span>
                                <span class="<?= $priceMuted ?> text-sm">/ <?= $periodLabel ?></span>
                            </div>
                            <?php if ($waHref): ?>
                                <a href="<?= e($waHref) ?>" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-2 text-sm font-bold <?= $plan['code'] === 'max' ? 'text-amber-300 hover:text-amber-200' : 'text-emerald-600 hover:text-emerald-700' ?> transition">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
                                    للاستفسار على واتساب
                                </a>
                            <?php else: ?>
                                <p class="<?= $priceMuted ?> text-xs">تواصل معنا لمعرفة السعر</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <ul class="space-y-3 mb-8 flex-1">
                    <?php foreach ($features as $feature): ?>
                        <li class="flex gap-2 text-sm">
                            <span class="<?= $plan['code'] === 'max' ? 'text-amber-400' : 'text-emerald-500' ?> flex-shrink-0">✓</span>
                            <span><?= e($renderBizText($feature)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($isCurrent): ?>
                    <button disabled class="w-full py-3 rounded-xl <?= $plan['code'] === 'max' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500' ?> font-bold">
                        ✓ باقتك الحالية
                    </button>
                <?php elseif ($plan['code'] === 'free'): ?>
                    <button disabled class="w-full py-3 rounded-xl bg-gray-100 text-gray-500 font-bold">المستوى الأساسي</button>
                <?php else: ?>
                    <button onclick='openUpgradeModal(<?= json_encode(["id"=>$plan["id"],"name"=>$plan["name"]]) ?>)'
                        class="w-full py-3 rounded-xl <?= $plan['code'] === 'max' ? 'bg-white text-gray-900 hover:bg-gray-100' : 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white shadow-lg hover:shadow-xl' ?> font-bold transition">
                        اطلب الترقية
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Comparison Table -->
    <div class="bg-white rounded-2xl shadow-soft p-6 md:p-8 overflow-x-auto">
        <h3 class="text-xl font-bold mb-6">مقارنة تفصيلية بين الباقات</h3>
        <table class="w-full">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="text-right py-3 px-3">الميزة</th>
                    <?php foreach ($plans as $plan): ?>
                        <th class="py-3 px-3 font-bold"><?= e($plan['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="text-sm">
                <?php
                // Sector-aware labels — tokens resolve via $renderBizText at render time.
                // {items_def} keeps ال ("الأصناف المميزة" reads better than "أصناف مميزة" here).
                $comparisons = [
                    ['max_categories', 'عدد {categories_def}', 'number'],
                    ['max_items', 'عدد {items_def}', 'number'],
                    ['max_qr_styles', 'تصاميم QR Code', 'qr'],
                    [null, 'رقم الهاتف والواتساب', 'all_yes'],
                    ['can_upload_logo', 'رفع شعار', 'bool'],
                    ['can_upload_cover', 'صورة غلاف', 'bool'],
                    ['can_customize_colors', 'تخصيص الألوان', 'bool'],
                    ['can_edit_contact', 'تعديل العنوان', 'bool'],
                    ['can_social_links', 'روابط السوشيال', 'bool'],
                    ['can_use_discount', 'الخصومات', 'bool'],
                    ['can_feature_items', '{items} مميزة', 'bool'],
                    ['can_multiple_media', 'صور وفيديوهات متعددة لكل {item}', 'bool'],
                    ['can_custom_message', 'استوديو تخصيص رسالة الطلب ✨', 'bool'],
                    ['can_remove_watermark', 'إزالة العلامة المائية', 'bool'],
                    // ['can_custom_domain', 'دومين خاص', 'bool'],
                    ['has_analytics', 'إحصائيات', 'bool'],
                    ['has_priority_support', 'دعم فني مميز', 'bool'],
                ];
                foreach ($comparisons as [$key, $label, $type]):
                ?>
                <tr class="border-b border-gray-50">
                    <td class="py-3 px-3 text-gray-700"><?= e($renderBizText($label)) ?></td>
                    <?php foreach ($plans as $plan): ?>
                        <td class="py-3 px-3 text-center">
                            <?php if ($type === 'number'): ?>
                                <?php $val = (int) $plan[$key]; ?>
                                <span class="font-semibold <?= $val === -1 ? 'text-emerald-600' : '' ?>"><?= $val === -1 ? '∞' : $val ?></span>
                            <?php elseif ($type === 'qr'): ?>
                                <?php $val = (int) $plan[$key]; ?>
                                <span class="font-semibold <?= $val >= 5 ? 'text-amber-600' : ($val >= 3 ? 'text-emerald-600' : 'text-gray-500') ?>"><?= $val ?> / 5</span>
                            <?php elseif ($type === 'all_yes'): ?>
                                <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                            <?php else: ?>
                                <?php if ($plan[$key]): ?>
                                    <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                <?php else: ?>
                                    <span class="text-gray-300">—</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Upgrade Modal -->
<div id="upgradeModal" class="fixed inset-0 bg-black/60 z-50 hidden items-center justify-center p-4" onclick="if(event.target===this)closeUpgradeModal()">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="plan_id" id="upgradePlanId">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-xl font-bold">طلب الترقية إلى <span id="upgradePlanName"></span></h3>
                <button type="button" onclick="closeUpgradeModal()" class="p-1 hover:bg-gray-100 rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100">
                    <p class="text-sm text-emerald-800 leading-relaxed">
                        بعد إرسال طلب الترقية، سيتواصل معك فريقنا خلال 24 ساعة لإتمام الدفع وتفعيل الباقة.
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">طريقة الدفع المفضلة</label>
                    <div class="grid grid-cols-1 gap-2">
                        <label class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-100 hover:border-emerald-500 cursor-pointer transition">
                            <input type="radio" name="payment_method" value="whatsapp" checked class="w-4 h-4 text-emerald-600">
                            <span class="text-2xl">💬</span>
                            <div class="flex-1">
                                <p class="font-semibold text-sm">تواصل عبر واتساب</p>
                                <p class="text-xs text-gray-500">سنرسل لك تفاصيل الدفع على واتساب</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-100 hover:border-emerald-500 cursor-pointer transition">
                            <input type="radio" name="payment_method" value="bank_transfer" class="w-4 h-4 text-emerald-600">
                            <span class="text-2xl">🏦</span>
                            <div class="flex-1">
                                <p class="font-semibold text-sm">تحويل بنكي</p>
                                <p class="text-xs text-gray-500">دفع عبر حوالة بنكية</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3 rounded-xl border-2 border-gray-100 hover:border-emerald-500 cursor-pointer transition">
                            <input type="radio" name="payment_method" value="cash" class="w-4 h-4 text-emerald-600">
                            <span class="text-2xl">💵</span>
                            <div class="flex-1">
                                <p class="font-semibold text-sm">نقداً / كاش</p>
                                <p class="text-xs text-gray-500">اتفاق محلي</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">ملاحظات <span class="text-gray-400 font-normal">(اختياري)</span></label>
                    <textarea name="notes" rows="3" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" placeholder="أخبرنا بأي تفاصيل أو أسئلة..."></textarea>
                </div>
            </div>
            <div class="p-6 border-t border-gray-100 flex justify-end gap-3">
                <button type="button" onclick="closeUpgradeModal()" class="px-5 py-2.5 rounded-xl text-gray-700 hover:bg-gray-100 font-semibold">إلغاء</button>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg">إرسال الطلب</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUpgradeModal(plan) {
    document.getElementById('upgradePlanId').value = plan.id;
    document.getElementById('upgradePlanName').textContent = plan.name;
    document.getElementById('upgradeModal').classList.remove('hidden');
    document.getElementById('upgradeModal').classList.add('flex');
}
function closeUpgradeModal() {
    document.getElementById('upgradeModal').classList.add('hidden');
    document.getElementById('upgradeModal').classList.remove('flex');
}
</script>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>
