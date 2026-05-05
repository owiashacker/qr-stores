<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_message.php';
requireLogin();

$rid = $_SESSION['store_id'];
$r = currentStore($pdo);
requireActivePlan($r);

// Sector-aware labels (safe even if store/biz type missing — bizLabel falls back).
// bizNameDefinite returns the biz-type name with proper Arabic "ال" prefix
// (مطعم → المطعم, محل ألبسة → محل الألبسة) so UI strings read grammatically.
$bizName = $r ? bizNameDefinite($r) : 'المتجر';
$pageTitle = 'إعدادات ' . $bizName;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfCheck()) {
        flash('error', 'انتهت صلاحية الجلسة');
        redirect(BASE_URL . '/admin/settings.php');
    }

    // RESET-TO-DEFAULTS action for the Order Message Studio (MAX only) —
    // sets every msg_* column back to NULL so orderMessageResolve() falls back
    // to the shipped defaults.
    if (($_POST['action'] ?? '') === 'reset_order_message') {
        if (!canDo($r, 'custom_message')) {
            flash('error', 'هذه الميزة متاحة فقط في باقة Max');
            redirect(BASE_URL . '/admin/settings.php');
        }
        $pdo->prepare('UPDATE stores SET
            msg_template=NULL, msg_greeting=NULL, msg_signature=NULL, msg_button_label=NULL,
            msg_modal_title=NULL, msg_modal_subtitle=NULL,
            msg_ask_name=0, msg_ask_phone=0, msg_ask_table=0, msg_ask_quantity=0,
            msg_ask_notes=0, msg_ask_address=0,
            msg_include_price=1, msg_include_link=0, msg_include_time=0,
            msg_emoji_style=\'standard\', msg_channel_priority=\'whatsapp\', msg_require_confirm=0
            WHERE id=?')->execute([$rid]);
        flash('success', 'تمت إعادة ضبط رسالة الطلب إلى الإعدادات الافتراضية');
        redirect(BASE_URL . '/admin/settings.php#order-message-studio');
    }

    // Always editable (basics)
    $name = trim($_POST['name'] ?? $r['name']);
    $description = trim($_POST['description'] ?? $r['description']);
    $currency = trim($_POST['currency'] ?? $r['currency']);
    $working_hours = trim($_POST['working_hours'] ?? $r['working_hours']);

    // Phone & WhatsApp are available on ALL plans (including free)
    $phone = trim($_POST['phone'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    // Address remains gated by plan capability
    $address = canDo($r, 'edit_contact') ? trim($_POST['address'] ?? '') : $r['address'];
    $facebook = canDo($r, 'social_links') ? trim($_POST['facebook'] ?? '') : $r['facebook'];
    $instagram = canDo($r, 'social_links') ? trim($_POST['instagram'] ?? '') : $r['instagram'];
    $primary_color = canDo($r, 'customize_colors') ? trim($_POST['primary_color'] ?? '#059669') : $r['primary_color'];

    $logo = $r['logo'];
    $cover = $r['cover'];

    if (canDo($r, 'upload_logo') && !empty($_FILES['logo']['name'])) {
        $newLogo = uploadImage('logo', __DIR__ . '/../assets/uploads/logos');
        if ($newLogo) {
            deleteUpload('logos', $logo);
            $logo = $newLogo;
        }
    }
    if (canDo($r, 'upload_cover') && !empty($_FILES['cover']['name'])) {
        $newCover = uploadImage('cover', __DIR__ . '/../assets/uploads/covers');
        if ($newCover) {
            deleteUpload('covers', $cover);
            $cover = $newCover;
        }
    }

    $slug = generateUniqueSlug($pdo, $name, $rid);

    $stmt = $pdo->prepare('UPDATE stores SET name=?, slug=?, phone=?, whatsapp=?, address=?, description=?, logo=?, cover=?, primary_color=?, currency=?, facebook=?, instagram=?, working_hours=? WHERE id=?');
    $stmt->execute([$name, $slug, $phone, $whatsapp, $address, $description, $logo, $cover, $primary_color, $currency, $facebook, $instagram, $working_hours, $rid]);

    // ---- Order Message Studio (MAX only) ----
    if (canDo($r, 'custom_message')) {
        $validEmoji = array_keys(orderMessageEmojiStyles());
        $validChannel = array_keys(orderMessageChannels());
        $emoji = in_array($_POST['msg_emoji_style'] ?? '', $validEmoji, true)
            ? $_POST['msg_emoji_style'] : 'standard';
        $channel = in_array($_POST['msg_channel_priority'] ?? '', $validChannel, true)
            ? $_POST['msg_channel_priority'] : 'whatsapp';

        // Trim + normalize CRLF→LF (Windows textareas submit \r\n which produces stray %0D
        // in the WhatsApp URL and breaks emoji across line breaks) + null-if-empty so
        // orderMessageResolve() falls back to defaults for blank fields.
        $nn = function ($v) {
            $v = str_replace(["\r\n", "\r"], "\n", (string) $v);
            $v = trim($v);
            return $v === '' ? null : $v;
        };

        $msgStmt = $pdo->prepare('UPDATE stores SET
            msg_template=?, msg_greeting=?, msg_signature=?, msg_button_label=?,
            msg_modal_title=?, msg_modal_subtitle=?,
            msg_ask_name=?, msg_ask_phone=?, msg_ask_table=?, msg_ask_quantity=?,
            msg_ask_notes=?, msg_ask_address=?,
            msg_include_price=?, msg_include_link=?, msg_include_time=?,
            msg_emoji_style=?, msg_channel_priority=?, msg_require_confirm=?
            WHERE id=?');
        $msgStmt->execute([
            $nn($_POST['msg_template'] ?? ''),
            $nn($_POST['msg_greeting'] ?? ''),
            $nn($_POST['msg_signature'] ?? ''),
            $nn($_POST['msg_button_label'] ?? ''),
            $nn($_POST['msg_modal_title'] ?? ''),
            $nn($_POST['msg_modal_subtitle'] ?? ''),
            !empty($_POST['msg_ask_name']) ? 1 : 0,
            !empty($_POST['msg_ask_phone']) ? 1 : 0,
            !empty($_POST['msg_ask_table']) ? 1 : 0,
            !empty($_POST['msg_ask_quantity']) ? 1 : 0,
            !empty($_POST['msg_ask_notes']) ? 1 : 0,
            !empty($_POST['msg_ask_address']) ? 1 : 0,
            !empty($_POST['msg_include_price']) ? 1 : 0,
            !empty($_POST['msg_include_link']) ? 1 : 0,
            !empty($_POST['msg_include_time']) ? 1 : 0,
            $emoji,
            $channel,
            !empty($_POST['msg_require_confirm']) ? 1 : 0,
            $rid,
        ]);
    }

    flash('success', 'تم حفظ الإعدادات بنجاح');
    redirect(BASE_URL . '/admin/settings.php');
}

require __DIR__ . '/../includes/header_admin.php';

$canLogo = canDo($r, 'upload_logo');
$canCover = canDo($r, 'upload_cover');
$canColors = canDo($r, 'customize_colors');
$canContact = canDo($r, 'edit_contact');
$canSocial = canDo($r, 'social_links');
$canCustomMsg = canDo($r, 'custom_message');
$hasAnyRestriction = !($canLogo && $canCover && $canColors && $canContact && $canSocial && $canCustomMsg);

// Order Message Studio — current values (merged with defaults) + presets + placeholders
$msgCfg = orderMessageResolve($r);
$msgPresets = orderMessagePresets();
$msgPlaceholders = orderMessagePlaceholders();
$msgEmojiStyles = orderMessageEmojiStyles();
$msgChannels = orderMessageChannels();
$msgDefaults = orderMessageDefaults($r);
?>

<?php if ($hasAnyRestriction): ?>
    <div class="mb-6 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-amber-50 via-orange-50 to-amber-50 border border-amber-200 flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4">
        <div class="flex items-start gap-3 flex-1 w-full">
            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg flex-shrink-0">
                <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="font-bold text-amber-900 text-sm sm:text-base">باقتك الحالية: <?= e($r['plan_name']) ?></h3>
                <p class="text-xs sm:text-sm text-amber-800 mt-1">بعض الخيارات مقفلة. ترقّ لفتح كل الإمكانيات.</p>
            </div>
        </div>
        <a href="upgrade.php" class="w-full sm:w-auto text-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 text-white font-bold whitespace-nowrap shadow-lg hover:shadow-xl transition active:scale-[0.98]">ترقية الآن</a>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-6">
    <?= csrfField() ?>

    <!-- Brand Identity Card -->
    <div class="bg-white rounded-2xl shadow-soft overflow-hidden">
        <!-- Cover Preview (logo removed from here to avoid overlap with heading) -->
        <div class="relative h-32 sm:h-44 md:h-56 bg-gradient-to-br from-emerald-500 to-teal-600">
            <?php if ($r['cover']): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/covers/<?= e($r['cover']) ?>" class="w-full h-full object-cover">
            <?php endif; ?>
            <?php if ($canCover): ?>
                <label class="absolute inset-0 cursor-pointer block">
                    <input type="file" name="cover" accept="image/*" class="hidden" onchange="previewImage(this, 'cover-preview')">
                    <!-- Always-visible chip (works on touch & desktop) -->
                    <div class="absolute bottom-3 left-3 bg-black/60 backdrop-blur-sm text-white px-3 py-1.5 rounded-full flex items-center gap-1.5 text-xs font-semibold shadow-lg hover:bg-black/80 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>تغيير الغلاف</span>
                    </div>
                </label>
            <?php else: ?>
                <div class="absolute inset-0 flex items-center justify-center bg-black/30 px-4">
                    <a href="upgrade.php" class="px-3 sm:px-4 py-2 rounded-xl bg-white/90 text-gray-900 font-bold text-xs sm:text-sm flex items-center gap-2 backdrop-blur">
                        🔒 متاح في الباقات المدفوعة
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Logo floats over cover (negative top margin). Heading moved to its own row below for breathing room. -->
        <div class="px-4 sm:px-6">
            <div class="-mt-10 sm:-mt-12">
                <?php if ($canLogo): ?>
                    <label class="inline-block cursor-pointer relative">
                        <input type="file" name="logo" accept="image/*" class="hidden" onchange="previewImage(this, 'logo-preview')">
                        <div class="relative w-20 h-20 sm:w-24 sm:h-24 rounded-2xl border-4 border-white bg-white shadow-xl overflow-hidden">
                            <?php if ($r['logo']): ?>
                                <img id="logo-preview" src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($r['logo']) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <img id="logo-preview" src="" class="w-full h-full object-cover hidden">
                                <div id="logo-placeholder" class="w-full h-full bg-gradient-to-br from-emerald-100 to-teal-100 flex items-center justify-center text-3xl font-bold text-emerald-700"><?= e(mb_substr($r['name'], 0, 1)) ?></div>
                            <?php endif; ?>
                        </div>
                        <!-- Camera badge (always visible) -->
                        <div class="absolute -bottom-1 -right-1 w-7 h-7 bg-emerald-500 rounded-full flex items-center justify-center text-white shadow-lg border-2 border-white">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                    </label>
                <?php else: ?>
                    <div class="relative w-20 h-20 sm:w-24 sm:h-24 rounded-2xl border-4 border-white bg-white shadow-xl overflow-hidden inline-block">
                        <div class="w-full h-full bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center text-3xl font-bold text-gray-500"><?= e(mb_substr($r['name'], 0, 1)) ?></div>
                        <div class="absolute inset-0 bg-black/60 flex items-center justify-center text-white">🔒</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="mt-4 mb-5">
                <h3 class="text-base sm:text-lg font-bold text-gray-900">هوية <?= e($bizName) ?></h3>
                <p class="text-xs sm:text-sm text-gray-500 mt-1">هذه أول ما يراه زبائنك عند فتح المتجر</p>
            </div>
        </div>

        <!-- Form section -->
        <div class="px-4 sm:px-6 pb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">اسم <?= e($bizName) ?></label>
                    <input type="text" name="name" value="<?= e($r['name']) ?>" required class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">العملة</label>
                    <select name="currency" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition">
                        <?php foreach (['ل.س', 'ر.س', 'د.إ', 'د.ك', 'د.ا', 'ج.م', 'USD', 'EUR', 'ل.ت'] as $c): ?>
                            <option value="<?= $c ?>" <?= $r['currency'] == $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">وصف مختصر</label>
                    <textarea name="description" rows="2" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" placeholder="ألذ الأكلات الشامية منذ 1990..."><?= e($r['description']) ?></textarea>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 mb-2">
                        اللون الأساسي
                        <?php if (!$canColors): ?><span class="text-amber-600 text-xs">🔒 Max فقط</span><?php endif; ?>
                    </label>
                    <div class="flex items-center gap-3">
                        <input type="color" name="primary_color" value="<?= e($r['primary_color']) ?>" <?= !$canColors ? 'disabled' : '' ?> class="w-14 h-14 rounded-xl border-2 border-gray-100 flex-shrink-0 <?= $canColors ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed' ?>">
                        <span class="text-xs sm:text-sm text-gray-500"><?= $canColors ? 'لون الهوية في القائمة العامة' : 'تخصيص الألوان متاح في باقة Max' ?></span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">ساعات العمل</label>
                    <input type="text" name="working_hours" value="<?= e($r['working_hours']) ?>" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition" placeholder="10 صباحاً - 1 بعد منتصف الليل">
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Card -->
    <div class="bg-white rounded-2xl shadow-soft p-4 sm:p-6 relative">
        <h3 class="text-base sm:text-lg font-bold mb-1">معلومات التواصل</h3>
        <p class="text-xs sm:text-sm text-gray-500 mb-5">تظهر في أسفل القائمة العامة</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">📞 رقم الهاتف</label>
                <input type="tel" name="phone" value="<?= e($r['phone']) ?>" dir="ltr" inputmode="tel" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition text-left" placeholder="+963">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">💬 واتساب</label>
                <input type="tel" name="whatsapp" value="<?= e($r['whatsapp']) ?>" dir="ltr" inputmode="tel" class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition text-left" placeholder="+963">
            </div>
            <div class="md:col-span-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 mb-2">
                    📍 العنوان
                    <?php if (!$canContact): ?><span class="text-amber-600 text-xs">🔒 ترقية</span><?php endif; ?>
                </label>
                <input type="text" name="address" value="<?= e($r['address']) ?>" <?= !$canContact ? 'disabled' : '' ?> class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition <?= !$canContact ? 'bg-gray-50' : '' ?>" placeholder="دمشق - شارع الثورة">
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 mb-2">
                    فيسبوك
                    <?php if (!$canSocial): ?><span class="text-amber-600 text-xs">🔒</span><?php endif; ?>
                </label>
                <input type="url" name="facebook" value="<?= e($r['facebook']) ?>" dir="ltr" inputmode="url" <?= !$canSocial ? 'disabled' : '' ?> class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition text-left <?= !$canSocial ? 'bg-gray-50' : '' ?>" placeholder="https://facebook.com/">
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 mb-2">
                    انستغرام
                    <?php if (!$canSocial): ?><span class="text-amber-600 text-xs">🔒</span><?php endif; ?>
                </label>
                <input type="url" name="instagram" value="<?= e($r['instagram']) ?>" dir="ltr" inputmode="url" <?= !$canSocial ? 'disabled' : '' ?> class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition text-left <?= !$canSocial ? 'bg-gray-50' : '' ?>" placeholder="https://instagram.com/">
            </div>
        </div>
    </div>

    <!-- ╔══════════════════════════════════════════════════════════════╗ -->
    <!-- ║  ORDER MESSAGE STUDIO  (MAX plan only)                         ║ -->
    <!-- ║  Lets the owner fully customize the message that visitors send ║ -->
    <!-- ║  via WhatsApp when they tap "Order" on a menu item.            ║ -->
    <!-- ╚══════════════════════════════════════════════════════════════╝ -->
    <div id="order-message-studio" class="bg-gradient-to-br from-emerald-900 via-emerald-800 to-teal-900 rounded-2xl shadow-xl overflow-hidden text-white relative">
        <!-- Decorative background -->
        <div class="absolute top-0 right-0 w-48 h-48 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-teal-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Header -->
        <div class="relative px-4 sm:px-6 pt-5 sm:pt-6 pb-4 border-b border-white/10">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-400 flex items-center justify-center text-emerald-950 shadow-lg flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-base sm:text-lg font-bold">استوديو رسالة الطلب</h3>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gradient-to-r from-amber-400 to-orange-400 text-amber-950 text-[10px] font-bold uppercase tracking-wider">
                                MAX
                            </span>
                        </div>
                        <p class="text-xs sm:text-sm text-emerald-100/80 mt-1">صمّم الرسالة التي يرسلها الزبون لمتجرك — ميزة حصرية تعطي كل طلب لمستك الخاصة.</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$canCustomMsg): ?>
            <!-- Locked state (free/pro or expired) -->
            <div class="relative px-4 sm:px-6 py-8 sm:py-10 text-center">
                <div class="max-w-md mx-auto">
                    <div class="w-16 h-16 mx-auto rounded-2xl bg-white/10 flex items-center justify-center backdrop-blur mb-4">
                        <svg class="w-8 h-8 text-emerald-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h4 class="text-lg sm:text-xl font-bold mb-2">متاحة حصرياً في باقة Max</h4>
                    <p class="text-sm text-emerald-100/80 mb-6">اختر قالب جاهز أو اكتب رسالتك بنفسك، فعّل أسئلة الاسم والهاتف والتوصيل، وأعطِ كل طلب هويّة متجرك الحقيقية.</p>
                    <a href="upgrade.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-amber-400 to-orange-400 text-amber-950 font-bold shadow-xl hover:shadow-2xl hover:scale-[1.02] transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                        ترقية إلى Max
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Unlocked: full studio UI -->
            <div class="relative px-4 sm:px-6 py-5 sm:py-6 space-y-6">

                <!-- ═══ 1) PRESETS ═══ -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-sm sm:text-base flex items-center gap-2">
                            <span class="w-6 h-6 rounded-lg bg-emerald-400/20 text-emerald-200 text-xs font-bold flex items-center justify-center">1</span>
                            قوالب جاهزة
                        </h4>
                        <span class="text-[11px] text-emerald-100/60">انقر لتطبيق أي قالب فوراً</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 sm:gap-3">
                        <?php foreach ($msgPresets as $key => $preset): ?>
                            <button type="button"
                                data-preset="<?= e($key) ?>"
                                data-template="<?= e($preset['template']) ?>"
                                data-greeting="<?= e($preset['greeting']) ?>"
                                data-signature="<?= e($preset['signature']) ?>"
                                class="msg-preset-btn group relative text-right p-3 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 hover:border-emerald-300/40 transition active:scale-[0.98]">
                                <div class="font-bold text-sm text-white">
                                    <?= e($preset['label']) ?>
                                </div>
                                <div class="text-[11px] text-emerald-100/70 mt-1 line-clamp-2">
                                    <?= e($preset['description']) ?>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ═══ 2) TEMPLATE EDITOR + LIVE PREVIEW ═══ -->
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 sm:gap-5">
                    <!-- Editor (3/5) -->
                    <div class="lg:col-span-3 space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-bold text-sm sm:text-base flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-400/20 text-emerald-200 text-xs font-bold flex items-center justify-center">2</span>
                                    محرر القالب
                                </h4>
                                <span id="msg-template-len" class="text-[11px] text-emerald-100/60">0 حرف</span>
                            </div>

                            <!-- Placeholder chips -->
                            <div class="mb-3">
                                <div class="text-[11px] text-emerald-100/70 mb-2">انقر أي متغير لإدراجه في مكان المؤشر:</div>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach ($msgPlaceholders as $ph): ?>
                                        <button type="button"
                                            data-token="<?= e($ph['token']) ?>"
                                            title="<?= e($ph['hint']) ?>"
                                            class="msg-token-chip inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-300/10 hover:bg-emerald-300/20 border border-emerald-300/20 text-emerald-100 text-[11px] font-medium transition active:scale-95">
                                            <span class="text-emerald-300">{</span>
                                            <?= e($ph['token']) ?>
                                            <span class="text-emerald-300">}</span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <textarea
                                id="msg_template"
                                name="msg_template"
                                rows="6"
                                class="w-full px-4 py-3 rounded-xl bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white placeholder-emerald-200/40 transition font-mono text-sm leading-relaxed"
                                placeholder="<?= e($msgDefaults['msg_template']) ?>"><?= e($msgCfg['msg_template']) ?></textarea>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">التحية {greeting}</label>
                                    <input type="text" name="msg_greeting" value="<?= e($msgCfg['msg_greeting']) ?>" maxlength="255" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white placeholder-emerald-200/40 transition text-sm" placeholder="<?= e($msgDefaults['msg_greeting']) ?>">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">التوقيع {signature}</label>
                                    <input type="text" name="msg_signature" value="<?= e($msgCfg['msg_signature']) ?>" maxlength="255" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white placeholder-emerald-200/40 transition text-sm" placeholder="— اسم متجرك">
                                </div>
                            </div>
                        </div>

                        <!-- Visitor input fields to collect -->
                        <div>
                            <h4 class="font-bold text-sm sm:text-base flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-lg bg-emerald-400/20 text-emerald-200 text-xs font-bold flex items-center justify-center">3</span>
                                ما الذي تريد سؤاله للزبون؟
                            </h4>
                            <p class="text-[11px] text-emerald-100/70 mb-3">كل خيار مُفعَّل يظهر للزبون في نافذة صغيرة قبل إرسال الرسالة.</p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                <?php
                                $asks = [
                                    ['msg_ask_name', 'الاسم', '👤'],
                                    ['msg_ask_phone', 'رقم الهاتف', '📞'],
                                    ['msg_ask_table', 'رقم الطاولة', '🪑'],
                                    ['msg_ask_quantity', 'الكمية', '🔢'],
                                    ['msg_ask_notes', 'ملاحظات', '📝'],
                                    ['msg_ask_address', 'عنوان التوصيل', '📍'],
                                ];
                                foreach ($asks as [$fld, $lbl, $ico]):
                                    $on = !empty($msgCfg[$fld]);
                                ?>
                                    <label class="msg-toggle relative flex items-center gap-2 p-2.5 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 cursor-pointer transition has-[:checked]:bg-emerald-400/20 has-[:checked]:border-emerald-300/50">
                                        <input type="checkbox" name="<?= $fld ?>" value="1" class="msg-ask-input peer sr-only" <?= $on ? 'checked' : '' ?> data-token="<?= str_replace('msg_ask_', '', $fld) ?>">
                                        <span class="w-5 h-5 rounded-md border-2 border-white/30 peer-checked:bg-emerald-300 peer-checked:border-emerald-300 flex items-center justify-center flex-shrink-0 transition">
                                            <svg class="w-3 h-3 text-emerald-950 opacity-0 peer-checked:opacity-100 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        </span>
                                        <span class="text-sm font-medium flex items-center gap-1.5"><?= $ico ?> <?= $lbl ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Appearance options -->
                        <div>
                            <h4 class="font-bold text-sm sm:text-base flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-lg bg-emerald-400/20 text-emerald-200 text-xs font-bold flex items-center justify-center">4</span>
                                خيارات المظهر والقناة
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">نمط الإيموجي</label>
                                    <select name="msg_emoji_style" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white transition text-sm">
                                        <?php foreach ($msgEmojiStyles as $v => $lbl): ?>
                                            <option value="<?= e($v) ?>" <?= $msgCfg['msg_emoji_style'] === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">قناة التواصل المفضّلة</label>
                                    <select name="msg_channel_priority" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white transition text-sm">
                                        <?php foreach ($msgChannels as $v => $lbl): ?>
                                            <option value="<?= e($v) ?>" <?= $msgCfg['msg_channel_priority'] === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php
                                $boolOpts = [
                                    ['msg_include_price', 'إظهار السعر في الرسالة', '💰'],
                                    ['msg_include_link', 'إرفاق رابط القائمة', '🔗'],
                                    ['msg_include_time', 'إضافة وقت وتاريخ الطلب', '🕒'],
                                    ['msg_require_confirm', 'طلب تأكيد قبل الإرسال', '✅'],
                                ];
                                foreach ($boolOpts as [$fld, $lbl, $ico]):
                                    $on = !empty($msgCfg[$fld]);
                                ?>
                                    <label class="msg-toggle flex items-center gap-2 p-2.5 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 cursor-pointer transition has-[:checked]:bg-emerald-400/20 has-[:checked]:border-emerald-300/50">
                                        <input type="checkbox" name="<?= $fld ?>" value="1" class="msg-opt-input peer sr-only" <?= $on ? 'checked' : '' ?> data-flag="<?= $fld ?>">
                                        <span class="w-5 h-5 rounded-md border-2 border-white/30 peer-checked:bg-emerald-300 peer-checked:border-emerald-300 flex items-center justify-center flex-shrink-0 transition">
                                            <svg class="w-3 h-3 text-emerald-950 opacity-0 peer-checked:opacity-100 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        </span>
                                        <span class="text-sm font-medium flex items-center gap-1.5"><?= $ico ?> <?= $lbl ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Button label + modal copy -->
                        <div>
                            <h4 class="font-bold text-sm sm:text-base flex items-center gap-2 mb-3">
                                <span class="w-6 h-6 rounded-lg bg-emerald-400/20 text-emerald-200 text-xs font-bold flex items-center justify-center">5</span>
                                النصوص الظاهرة للزبون
                            </h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">نص زر الطلب</label>
                                    <input type="text" name="msg_button_label" value="<?= e($msgCfg['msg_button_label']) ?>" maxlength="100" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white placeholder-emerald-200/40 transition text-sm" placeholder="<?= e($msgDefaults['msg_button_label']) ?>">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">عنوان نافذة الطلب</label>
                                    <input type="text" name="msg_modal_title" value="<?= e($msgCfg['msg_modal_title']) ?>" maxlength="255" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white placeholder-emerald-200/40 transition text-sm" placeholder="<?= e($msgDefaults['msg_modal_title']) ?>">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-emerald-100/90 mb-1.5">وصف نافذة الطلب</label>
                                    <textarea name="msg_modal_subtitle" rows="2" maxlength="500" class="w-full px-3 py-2.5 rounded-lg bg-emerald-950/50 border-2 border-emerald-300/20 focus:border-emerald-300 text-white placeholder-emerald-200/40 transition text-sm" placeholder="<?= e($msgDefaults['msg_modal_subtitle']) ?>"><?= e($msgCfg['msg_modal_subtitle']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live preview (2/5) — sticky on large screens -->
                    <div class="lg:col-span-2">
                        <div class="lg:sticky lg:top-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-bold text-sm sm:text-base flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-400/20 text-emerald-200 text-xs font-bold flex items-center justify-center">👁</span>
                                    معاينة مباشرة
                                </h4>
                                <span class="text-[11px] text-emerald-100/60">كما سيراها زبونك</span>
                            </div>

                            <!-- Simulated WhatsApp chat -->
                            <div class="rounded-2xl overflow-hidden shadow-2xl border-4 border-emerald-950/30" style="background: #0f1f1a;">
                                <!-- Header -->
                                <div class="flex items-center gap-3 px-3 py-2.5" style="background: #075e54;">
                                    <div class="w-8 h-8 rounded-full bg-emerald-300/30 flex items-center justify-center text-xs font-bold">
                                        <?= e(mb_substr($r['name'], 0, 1)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-white text-sm font-semibold truncate"><?= e($r['name']) ?></div>
                                        <div class="text-[10px] text-emerald-100/70">متصل الآن</div>
                                    </div>
                                    <svg class="w-5 h-5 text-white/80" fill="currentColor" viewBox="0 0 24 24"><path d="M20.5 3.4A12 12 0 0 0 12 0C5.4 0 0 5.4 0 12c0 2.1.6 4.2 1.6 6L0 24l6.2-1.6a12 12 0 0 0 17.6-10.4c0-3.2-1.3-6.2-3.3-8.6zm-8.5 18.4c-1.9 0-3.7-.5-5.3-1.4l-.4-.2-3.7 1 1-3.6-.2-.4c-1-1.6-1.5-3.5-1.5-5.4C1.9 6.4 6.4 1.9 12 1.9c2.7 0 5.2 1 7.1 2.9a10 10 0 0 1 2.9 7.1c0 5.6-4.5 10.1-10.1 10.1zm5.5-7.6c-.3-.2-1.8-.9-2-1s-.5-.2-.7.1c-.2.3-.8 1-1 1.2s-.4.2-.6 0c-.3-.2-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.7-1.6c-.2-.4-.4-.4-.6-.4h-.5c-.2 0-.5.1-.7.3-.3.3-1 1-1 2.4s1 2.8 1.1 3c.1.2 2 3.1 5 4.3.7.3 1.2.5 1.7.6.7.2 1.3.2 1.8.1.5-.1 1.8-.7 2-1.4.3-.7.3-1.3.2-1.4-.1-.2-.3-.2-.6-.4z" /></svg>
                                </div>
                                <!-- Chat body -->
                                <div class="p-3 min-h-[200px]" style="background: #0b141a; background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.03) 1px, transparent 0); background-size: 20px 20px;">
                                    <div class="flex justify-end">
                                        <div class="max-w-[85%] rounded-2xl rounded-tr-sm px-3 py-2 shadow" style="background: #005c4b;">
                                            <pre id="msg-preview-body" class="whitespace-pre-wrap break-words text-white text-[13px] leading-relaxed m-0" style="font-family: system-ui, sans-serif;">جاري التحميل...</pre>
                                            <div class="text-[10px] text-emerald-100/60 text-end mt-1 flex items-center justify-end gap-1">
                                                <span id="msg-preview-time">الآن</span>
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 16 15"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.88a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.366.366 0 0 0-.064-.512z" /><path d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.88a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z" /></svg>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Input bar (decorative) -->
                                <div class="flex items-center gap-2 px-3 py-2" style="background: #1f2c34;">
                                    <div class="flex-1 h-8 rounded-full" style="background: #2a3942;"></div>
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center" style="background: #00a884;">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14a3 3 0 003-3V5a3 3 0 10-6 0v6a3 3 0 003 3zm5-3c0 2.8-2.2 5-5 5s-5-2.2-5-5H5c0 3.5 2.6 6.4 6 6.9V21h2v-3.1c3.4-.5 6-3.4 6-6.9h-2z" /></svg>
                                    </div>
                                </div>
                            </div>

                            <!-- Button preview -->
                            <div class="mt-3 p-3 rounded-xl bg-white/5 border border-white/10">
                                <div class="text-[11px] text-emerald-100/60 mb-2">زر الطلب في القائمة العامة:</div>
                                <div id="msg-btn-preview" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-green-500 text-white text-sm font-semibold shadow-lg">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.5 3.4A12 12 0 0 0 12 0C5.4 0 0 5.4 0 12c0 2.1.6 4.2 1.6 6L0 24l6.2-1.6a12 12 0 0 0 17.6-10.4c0-3.2-1.3-6.2-3.3-8.6z" /></svg>
                                    <span id="msg-btn-label-preview"><?= e($msgCfg['msg_button_label']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer: Reset action -->
                <div class="pt-4 border-t border-white/10 flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:justify-between">
                    <div class="text-xs text-emerald-100/70 flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span>أي حقل تتركه فارغاً سيستخدم القيمة الافتراضية. التعديلات تُحفظ مع باقي الإعدادات بالضغط على "حفظ التغييرات".</span>
                    </div>
                    <!-- Reset button — submits a separate form to avoid interfering with the main save -->
                    <button type="button" onclick="if(confirm('هل تريد إرجاع رسالة الطلب إلى الإعدادات الافتراضية؟ سيتم حذف كل التخصيصات.'))document.getElementById('msg-reset-form').submit();"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-white/10 hover:bg-white/15 border border-white/20 text-white text-sm font-semibold transition active:scale-[0.98]">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M5 9a9 9 0 0114.65-3.36L23 9M19 15a9 9 0 01-14.65 3.36L1 15" /></svg>
                        إرجاع إلى الافتراضي
                    </button>
                </div>
            </div>

            <!-- Data-islands for the preview JS -->
            <script type="application/json" id="msg-cfg-defaults"><?= json_encode($msgDefaults, JSON_UNESCAPED_UNICODE) ?></script>
            <script type="application/json" id="msg-cfg-restaurant"><?= json_encode(['name' => $r['name'], 'slug' => $r['slug']], JSON_UNESCAPED_UNICODE) ?></script>
        <?php endif; ?>
    </div>

    <!-- Save -->
    <div class="flex justify-center sm:justify-end gap-3 sticky bottom-0 bg-gradient-to-t from-white via-white to-transparent pt-4 pb-2 -mx-4 sm:mx-0 px-4 sm:px-0">
        <button type="submit" class="w-full sm:w-auto px-8 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition active:scale-[0.98]">
            حفظ التغييرات
        </button>
    </div>
</form>

<?php if ($canCustomMsg): ?>
    <!-- Reset form (separate from main so the studio "Reset" button doesn't affect other fields) -->
    <form method="POST" id="msg-reset-form" class="hidden">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset_order_message">
    </form>
<?php endif; ?>

<script>
    function previewImage(input, targetId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.getElementById(targetId);
                if (img) {
                    img.src = e.target.result;
                    img.classList.remove('hidden');
                }
                const ph = document.getElementById(targetId.replace('-preview', '-placeholder'));
                if (ph) ph.classList.add('hidden');
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
       ORDER MESSAGE STUDIO — live preview + placeholder chips + presets
       This is a mirror of the rendering logic in public/menu.php so the
       owner sees an identical preview to what the visitor will receive.
       ═══════════════════════════════════════════════════════════════════════ */
    (function() {
        const defaultsEl = document.getElementById('msg-cfg-defaults');
        const restEl = document.getElementById('msg-cfg-restaurant');
        if (!defaultsEl || !restEl) return; // not MAX — studio not rendered

        const DEFAULTS = JSON.parse(defaultsEl.textContent);
        const REST = JSON.parse(restEl.textContent);
        const SAMPLE_ITEM = {
            name: 'شاورما دجاج خاصة',
            price: 45,
            currency: '<?= e($r['currency']) ?>'
        };

        const $tpl = document.getElementById('msg_template');
        const $body = document.getElementById('msg-preview-body');
        const $time = document.getElementById('msg-preview-time');
        const $len = document.getElementById('msg-template-len');
        const $btnLabel = document.getElementById('msg-btn-label-preview');
        const $btnPreview = document.getElementById('msg-btn-preview');

        function getVal(name, fallback) {
            const el = document.querySelector(`[name="${name}"]`);
            if (!el) return fallback;
            if (el.type === 'checkbox') return el.checked ? 1 : 0;
            const v = (el.value || '').trim();
            return v === '' ? fallback : v;
        }

        function render() {
            const tpl = $tpl.value.trim() || DEFAULTS.msg_template;
            const greeting = getVal('msg_greeting', DEFAULTS.msg_greeting);
            const signature = getVal('msg_signature', DEFAULTS.msg_signature);
            const includePrice = getVal('msg_include_price', 1);
            const includeLink = getVal('msg_include_link', 0);
            const includeTime = getVal('msg_include_time', 0);
            const emoji = getVal('msg_emoji_style', 'standard');

            // Build tokens — empty strings for disabled ask_* toggles
            const tokens = {
                item: SAMPLE_ITEM.name,
                price: includePrice ? ` — ${SAMPLE_ITEM.price} ${SAMPLE_ITEM.currency}` : '',
                restaurant: REST.name,
                greeting: greeting,
                signature: signature,
                qty: getVal('msg_ask_quantity', 0) ? '2' : '',
                name: getVal('msg_ask_name', 0) ? 'أحمد' : '',
                phone: getVal('msg_ask_phone', 0) ? '+963944123456' : '',
                table: getVal('msg_ask_table', 0) ? '5' : '',
                notes: getVal('msg_ask_notes', 0) ? 'بدون بصل، مع صوص حار' : '',
                address: getVal('msg_ask_address', 0) ? 'دمشق - المزة' : '',
                link: includeLink ? `${location.origin}/public/store.php?r=${REST.slug}` : '',
                time: includeTime ? new Date().toLocaleTimeString('ar-SY', { hour: '2-digit', minute: '2-digit' }) : '',
                date: includeTime ? new Date().toLocaleDateString('ar-SY') : '',
            };

            // Apply emoji style — 'minimal' strips emoji, 'rich' keeps as-is
            let out = tpl;
            Object.keys(tokens).forEach(k => {
                out = out.split('{' + k + '}').join(tokens[k]);
            });
            // Strip any leftover unknown placeholders
            out = out.replace(/\{[a-z_]+\}/g, '');
            // Collapse 3+ newlines
            out = out.replace(/\n{3,}/g, '\n\n').trim();

            // AUTO-APPEND — mirror public/menu.php so the preview matches
            // what visitors actually see. Any enabled ask_*/include_* whose
            // value didn't end up inside the template gets appended as a
            // trailing details block.
            const usedInTpl = (tok) => tpl.indexOf('{' + tok + '}') !== -1;
            const useIcons = emoji !== 'minimal';
            const blocks = [];
            // Icons as Unicode escapes so preview matches the visitor's
            // actual WhatsApp message byte-for-byte.
            const ICON = {
                name:    '\u{1F464}', phone: '\u{1F4DE}', address: '\u{1F4CD}',
                table:   '\u{1FA91}', qty:   '\u{1F522}', notes:   '\u{1F4DD}',
                link:    '\u{1F517}', time:  '\u{1F552}',
            };
            const askFields = [
                ['msg_ask_name',     'name',    'الاسم'],
                ['msg_ask_phone',    'phone',   'الهاتف'],
                ['msg_ask_address',  'address', 'العنوان'],
                ['msg_ask_table',    'table',   'رقم الطاولة'],
                ['msg_ask_quantity', 'qty',     'الكمية'],
                ['msg_ask_notes',    'notes',   'ملاحظات'],
            ];
            askFields.forEach(([flag, tok, label]) => {
                if (!getVal(flag, 0)) return;
                const val = tokens[tok] != null ? String(tokens[tok]).trim() : '';
                if (!val) return;
                if (usedInTpl(tok)) return;
                blocks.push((useIcons ? ICON[tok] + ' ' : '') + label + ': ' + val);
            });
            if (includeLink && tokens.link && !usedInTpl('link')) {
                blocks.push((useIcons ? ICON.link + ' ' : '') + 'القائمة: ' + tokens.link);
            }
            if (includeTime && !usedInTpl('time') && !usedInTpl('date')) {
                const stamp = ((tokens.date || '') + ' ' + (tokens.time || '')).trim();
                if (stamp) blocks.push((useIcons ? ICON.time + ' ' : '') + stamp);
            }
            if (blocks.length) out = out.replace(/\s+$/, '') + '\n\n' + blocks.join('\n');

            if (emoji === 'minimal') {
                // Remove common emoji (Unicode emoji + symbols)
                out = out.replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{1F000}-\u{1F2FF}]/gu, '').replace(/ {2,}/g, ' ');
            }

            $body.textContent = out || '(القالب فارغ)';
            $len.textContent = `${$tpl.value.length} حرف`;
            $time.textContent = new Date().toLocaleTimeString('ar-SY', { hour: '2-digit', minute: '2-digit' });

            // Button label + channel color hint
            const btnLbl = getVal('msg_button_label', DEFAULTS.msg_button_label);
            if ($btnLabel) $btnLabel.textContent = btnLbl;
            const channel = getVal('msg_channel_priority', 'whatsapp');
            if ($btnPreview) {
                $btnPreview.classList.remove('bg-green-500', 'bg-sky-500', 'bg-gradient-to-r', 'from-green-500', 'to-sky-500');
                if (channel === 'phone') $btnPreview.classList.add('bg-sky-500');
                else if (channel === 'both') $btnPreview.classList.add('bg-gradient-to-r', 'from-green-500', 'to-sky-500');
                else $btnPreview.classList.add('bg-green-500');
            }
        }

        // Placeholder chips — insert at cursor
        document.querySelectorAll('.msg-token-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                const token = '{' + btn.dataset.token + '}';
                const start = $tpl.selectionStart || 0;
                const end = $tpl.selectionEnd || 0;
                $tpl.value = $tpl.value.slice(0, start) + token + $tpl.value.slice(end);
                $tpl.focus();
                $tpl.setSelectionRange(start + token.length, start + token.length);
                render();
            });
        });

        // Preset buttons
        document.querySelectorAll('.msg-preset-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!confirm('تطبيق هذا القالب سيستبدل نص الرسالة الحالي. متابعة؟')) return;
                $tpl.value = btn.dataset.template;
                const greeting = document.querySelector('[name="msg_greeting"]');
                const sig = document.querySelector('[name="msg_signature"]');
                if (greeting) greeting.value = btn.dataset.greeting || '';
                if (sig) sig.value = btn.dataset.signature || '';
                render();
            });
        });

        // Re-render on every field change
        document.querySelectorAll(
            '#order-message-studio [name^="msg_"]'
        ).forEach(el => {
            el.addEventListener('input', render);
            el.addEventListener('change', render);
        });

        render();
    })();
</script>

<?php require __DIR__ . '/../includes/footer_admin.php'; ?>