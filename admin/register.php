<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/telegram.php';

if (!empty($_SESSION['store_id'])) {
    redirect(BASE_URL . '/admin/dashboard.php');
}

$errors = [];

// Platform-level gate — super admin can freeze new signups
if (siteSetting($pdo, 'allow_registration', '1') !== '1' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('التسجيل موقوف مؤقتاً');
}

// Load all active business types for the type-selector step
$businessTypes = $pdo->query(
    'SELECT id, code, name_ar, icon FROM business_types WHERE is_active = 1 ORDER BY sort_order'
)->fetchAll();

// ─── Affiliate referral capture ────────────────────────────────────────────
// 1. ?ref=CODE in URL → save to a 30-day cookie + look up the affiliate
// 2. On POST, link the new store to the affiliate via affiliate_id + referred_at
$referringAffiliate = null;
$incomingRefCode = null;
if (!empty($_GET['ref'])) {
    $incomingRefCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_GET['ref']));
}
if (!$incomingRefCode && !empty($_COOKIE['qrs_ref'])) {
    $incomingRefCode = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $_COOKIE['qrs_ref']));
}

if ($incomingRefCode) {
    $stmt = $pdo->prepare('SELECT id, name, referral_code, commission_rate FROM affiliates WHERE referral_code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$incomingRefCode]);
    $referringAffiliate = $stmt->fetch();

    // Persist the cookie if we just got it from URL (or refresh expiration)
    if ($referringAffiliate && empty($_COOKIE['qrs_ref'])) {
        setcookie('qrs_ref', $referringAffiliate['referral_code'], [
            'expires'  => time() + (30 * 86400),
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (honeypot_triggered()) {
        security_log($pdo, 'honeypot_triggered', 'warning', 'register', 'store');
        $errors[] = 'طلب مرفوض';
    } elseif (!csrfCheck()) {
        security_log($pdo, 'csrf_failed', 'warning', 'register');
        $errors[] = 'انتهت صلاحية الجلسة. حاول مجدداً.';
    } elseif (!check_rate_limit($pdo, 'register', 5, 300)) {
        security_log($pdo, 'rate_limited', 'warning', 'register too fast');
        $errors[] = 'محاولات تسجيل كثيرة. انتظر دقائق وأعد المحاولة.';
    } else {
        record_rate_event($pdo, 'register');
        $businessTypeId = (int) ($_POST['business_type_id'] ?? 0);
        $name = clean_string($_POST['name'] ?? '', 150);
        $email = clean_email($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $phone = clean_phone($_POST['phone'] ?? '');
        $whatsapp = clean_phone($_POST['whatsapp'] ?? '');
        $country = strtoupper(trim($_POST['country'] ?? ''));

        // Verify the selected business_type is real + active (trust nothing from client)
        $validTypeIds = array_map(fn($t) => (int) $t['id'], $businessTypes);
        if (!in_array($businessTypeId, $validTypeIds, true)) {
            $errors[] = 'يجب اختيار نوع النشاط';
        }

        if (mb_strlen($name) < 2) $errors[] = 'اسم المتجر قصير جداً';
        if (!$email) $errors[] = 'البريد الإلكتروني غير صالح';
        // WhatsApp is now mandatory — used by super-admin to contact the owner during approval
        if (mb_strlen($whatsapp) < 7) $errors[] = 'رقم الواتساب مطلوب للتواصل (7 أرقام على الأقل)';
        // Country is mandatory and must be a valid ISO code from the dropdown
        if (!isValidCountryCode($country)) $errors[] = 'يجب اختيار البلد من القائمة';
        $pwErrors = validate_password($password);
        if ($pwErrors) $errors = array_merge($errors, $pwErrors);

        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM stores WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'هذا البريد مسجل بالفعل';
            }
        }

        if (!$errors) {
            $freePlan = (int) $pdo->query("SELECT id FROM plans WHERE code = 'free' LIMIT 1")->fetchColumn();
            $slug = generateUniqueSlug($pdo, $name);
            $hash = hash_password($password);

            // Affiliate link (if user came through ?ref=CODE)
            $affId = $referringAffiliate['id'] ?? null;
            $referredAt = $affId ? date('Y-m-d H:i:s') : null;

            // New stores start as is_active=0 + approval_status='pending' until super approves
            $stmt = $pdo->prepare(
                'INSERT INTO stores (business_type_id, name, slug, email, password, phone, whatsapp, country, plan_id, affiliate_id, referred_at, is_active, approval_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
            );
            $stmt->execute([
                $businessTypeId, $name, $slug, $email, $hash, $phone, $whatsapp, $country,
                $freePlan ?: 1, $affId, $referredAt, 'pending',
            ]);
            $newId = (int) $pdo->lastInsertId();
            // DO NOT log the user in — wait for approval. Use a one-time session
            // hint so admin/pending.php can show the right message.
            $_SESSION['pending_store_email'] = $email;
            $_SESSION['pending_store_whatsapp'] = $whatsapp;
            security_log($pdo, 'store_registered_pending', 'info',
                ['email' => $email, 'business_type_id' => $businessTypeId, 'affiliate_id' => $affId],
                'store', $newId);

            // Telegram notification (silently best-effort — never blocks the response)
            $bizName = '';
            foreach ($businessTypes as $bt) {
                if ((int) $bt['id'] === $businessTypeId) { $bizName = $bt['name_ar']; break; }
            }
            tgNotifyEvent($pdo, 'store_signup', [
                'id'       => $newId,
                'name'     => $name,
                'email'    => $email,
                'whatsapp' => $whatsapp,
                'country'  => countryName($country),
                'biz_name' => $bizName,
            ]);

            // Clear the ref cookie since it's now consumed
            if ($affId) {
                setcookie('qrs_ref', '', ['expires' => time() - 3600, 'path' => '/']);
            }
            redirect(BASE_URL . '/admin/pending.php');
        } else {
            keepOld($_POST);
        }
    }
}

// Keep the user on step 2 if they already chose a type and there's an error
$preselectedTypeId = (int) (old('business_type_id') ?: 0);
$preselectedType = null;
foreach ($businessTypes as $t) {
    if ((int) $t['id'] === $preselectedTypeId) { $preselectedType = $t; break; }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#059669">
    <title>إنشاء حساب — QR Stores</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
    <style>
        * { -webkit-tap-highlight-color: transparent; }
        html, body { overflow-x: hidden; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdfa 50%, #eff6ff 100%);
            min-height: 100dvh;
            min-height: 100vh;
        }
        .blob { position: absolute; border-radius: 50%; filter: blur(60px); opacity: 0.35; z-index: 0; pointer-events: none; }
        input { font-size: 16px !important; }
        input:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 4px rgba(5,150,105,0.1); }
        @media (max-width: 480px) { .blob { filter: blur(40px); opacity: 0.25; } }

        .type-card {
            transition: transform .2s, box-shadow .2s, border-color .2s;
        }
        .type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -8px rgba(16,185,129,.25);
            border-color: #10b981;
        }
        .type-card:active { transform: translateY(0); }
        .type-card .icon { font-size: 2.25rem; line-height: 1; }
        @media (max-width: 480px) {
            .type-card .icon { font-size: 1.75rem; }
        }
    </style>
</head>
<body class="relative py-6 px-4 sm:py-10 flex items-center justify-center">

<div class="blob w-64 h-64 sm:w-96 sm:h-96 bg-emerald-300 -top-10 -right-10 sm:-top-20 sm:-right-20"></div>
<div class="blob w-64 h-64 sm:w-96 sm:h-96 bg-teal-300 -bottom-10 -left-10 sm:-bottom-20 sm:-left-20"></div>

<div class="relative z-10 w-full max-w-2xl mx-auto">

    <?php if ($referringAffiliate): ?>
        <!-- Affiliate referral badge — shown when user came via ?ref=CODE -->
        <div class="mb-4 p-4 rounded-2xl bg-gradient-to-l from-orange-100 to-amber-100 border-2 border-orange-300 flex items-center gap-3 shadow">
            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center flex-shrink-0 shadow">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-bold text-orange-700 uppercase tracking-wider">دعوة من وسيط</p>
                <p class="text-sm font-bold text-gray-900 truncate">أنت مدعوّ من قِبل: <span class="text-orange-700"><?= e($referringAffiliate['name']) ?></span></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-3xl shadow-2xl p-6 sm:p-8 md:p-10">

        <?php if ($errors): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                <?php foreach ($errors as $err): ?>
                    <p class="text-red-700 text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <?= e($err) ?>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ============ STEP 1: Choose business type ============ -->
        <div id="step-type" <?= $preselectedType ? 'class="hidden"' : '' ?>>
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 mb-4 shadow-lg shadow-emerald-500/30">
                    <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2v2h-2v-2zm4 0h2v6h-2v-6z"/>
                    </svg>
                </div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">ابدأ متجرك الرقمي</h1>
                <p class="text-gray-500 text-sm">اختر نوع نشاطك لنُخصّص التجربة لك</p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php foreach ($businessTypes as $t): ?>
                    <button type="button"
                            data-type-id="<?= (int) $t['id'] ?>"
                            data-type-name="<?= e($t['name_ar']) ?>"
                            data-type-icon="<?= e($t['icon']) ?>"
                            class="type-card p-4 rounded-2xl border-2 border-gray-100 bg-white text-center flex flex-col items-center gap-2 cursor-pointer focus:outline-none focus:border-emerald-500">
                        <span class="icon"><?= e($t['icon']) ?></span>
                        <span class="text-sm font-bold text-gray-800"><?= e($t['name_ar']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <p class="text-center mt-8 text-sm text-gray-500">
                لديك حساب بالفعل؟
                <a href="login.php" class="text-emerald-600 font-bold hover:underline">تسجيل الدخول</a>
            </p>
        </div>

        <!-- ============ STEP 2: Account form ============ -->
        <div id="step-form" <?= $preselectedType ? '' : 'class="hidden"' ?>>
            <button type="button" id="back-to-types" class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-800 mb-4">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
                تغيير نوع النشاط
            </button>

            <div class="text-center mb-6">
                <div id="chosen-badge" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-50 border border-emerald-200 mb-3">
                    <span id="chosen-icon" class="text-xl"><?= e($preselectedType['icon'] ?? '') ?></span>
                    <span id="chosen-name" class="text-sm font-bold text-emerald-700"><?= e($preselectedType['name_ar'] ?? '') ?></span>
                </div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-1">أنشئ حسابك</h1>
                <p class="text-gray-500 text-sm">دقائق فقط وسيكون متجرك جاهزاً</p>
            </div>

            <form method="POST" class="space-y-4 max-w-md mx-auto">
                <?= csrfField() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="business_type_id" id="business_type_id" value="<?= e(old('business_type_id') ?: '') ?>">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">اسم المتجر</label>
                    <input type="text" name="name" value="<?= e(old('name')) ?>" required maxlength="150"
                        autocomplete="organization" enterkeyhint="next"
                        class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition text-gray-900 placeholder-gray-400"
                        placeholder="مثال: متجر النور">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">البريد الإلكتروني</label>
                    <input type="email" name="email" value="<?= e(old('email')) ?>" required
                        autocomplete="email" inputmode="email" enterkeyhint="next" dir="ltr"
                        class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition"
                        placeholder="your@email.com">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        البلد <span class="text-red-500 font-bold">*</span>
                    </label>
                    <select name="country" required
                        class="w-full px-4 py-3 rounded-xl border-2 border-emerald-200 focus:border-emerald-500 transition bg-white text-gray-900">
                        <option value="">— اختر البلد —</option>
                        <?php
                        $oldCountry = old('country');
                        // Merge priority + rest into one flat list (Arab countries shown first naturally)
                        foreach (array_merge($COUNTRIES_PRIORITY, $COUNTRIES_REST) as $code => $name): ?>
                            <option value="<?= e($code) ?>" <?= $oldCountry === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        رقم الواتساب <span class="text-red-500 font-bold">*</span>
                        <span class="block text-xs text-gray-400 font-normal mt-1">
                            ضروري للتواصل معك من قبل إدارة المنصة
                        </span>
                    </label>
                    <input type="tel" name="whatsapp" value="<?= e(old('whatsapp')) ?>" required
                        autocomplete="tel" inputmode="tel" enterkeyhint="next" dir="ltr"
                        class="w-full px-4 py-3 rounded-xl border-2 border-emerald-200 focus:border-emerald-500 transition"
                        placeholder="+963 9XX XXX XXX">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">رقم الهاتف <span class="text-gray-400 font-normal">(اختياري)</span></label>
                    <input type="tel" name="phone" value="<?= e(old('phone')) ?>"
                        autocomplete="tel" inputmode="tel" enterkeyhint="next" dir="ltr"
                        class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition"
                        placeholder="+963">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">كلمة المرور</label>
                    <input type="password" name="password" required minlength="8"
                        autocomplete="new-password" enterkeyhint="done"
                        class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition"
                        placeholder="8 أحرف على الأقل (حرف + رقم)">
                </div>
                <!-- Notice about manual approval -->
                <div class="rounded-xl bg-amber-50 border border-amber-200 p-3 flex items-start gap-2">
                    <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-xs text-amber-800 leading-relaxed">
                        <span class="font-bold">ملاحظة:</span>
                        بعد إرسال الطلب، سيقوم المشرف بمراجعته ومن ثم تفعيل متجرك.
                        سنتواصل معك عبر الواتساب لتأكيد البيانات.
                    </p>
                </div>
                <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 active:scale-[0.98] hover:shadow-xl sm:hover:scale-[1.02] transition-all">
                    إنشاء الحساب
                </button>
            </form>

            <p class="text-center mt-6 text-sm text-gray-500">
                لديك حساب بالفعل؟
                <a href="login.php" class="text-emerald-600 font-bold hover:underline">تسجيل الدخول</a>
            </p>
        </div>

    </div>

    <p class="text-center mt-6 text-xs text-gray-400">
        <a href="<?= BASE_URL ?>/" class="hover:text-gray-600">← العودة للصفحة الرئيسية</a>
    </p>
</div>

<script>
(function () {
    const stepType = document.getElementById('step-type');
    const stepForm = document.getElementById('step-form');
    const hiddenInput = document.getElementById('business_type_id');
    const chosenIcon = document.getElementById('chosen-icon');
    const chosenName = document.getElementById('chosen-name');

    // Click a type card → show form
    document.querySelectorAll('.type-card').forEach(card => {
        card.addEventListener('click', () => {
            hiddenInput.value = card.dataset.typeId;
            chosenIcon.textContent = card.dataset.typeIcon;
            chosenName.textContent = card.dataset.typeName;
            stepType.classList.add('hidden');
            stepForm.classList.remove('hidden');
            // Focus the name input for quick typing
            const nameInput = stepForm.querySelector('input[name="name"]');
            if (nameInput) setTimeout(() => nameInput.focus(), 100);
        });
    });

    // Back button → back to type selector
    document.getElementById('back-to-types').addEventListener('click', () => {
        stepForm.classList.add('hidden');
        stepType.classList.remove('hidden');
        hiddenInput.value = '';
    });
})();
</script>

<?php clearOld(); ?>
</body>
</html>
