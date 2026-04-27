<?php
require_once __DIR__ . '/../includes/functions.php';

if (!empty($_SESSION['store_id'])) {
    redirect(BASE_URL . '/admin/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (honeypot_triggered()) {
        security_log($pdo, 'honeypot_triggered', 'warning', 'login', 'store');
        $error = 'طلب مرفوض';
    } elseif (!csrfCheck()) {
        security_log($pdo, 'csrf_failed', 'warning', 'login_admin');
        $error = 'انتهت صلاحية الجلسة، أعد المحاولة';
    } else {
        $email = clean_email($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (!$email) {
            $error = 'البريد غير صالح';
        } elseif (is_rate_limited($pdo, $email, 'store')) {
            security_log($pdo, 'rate_limited', 'warning', ['email' => $email], 'store');
            $error = 'محاولات دخول كثيرة. حاول بعد 15 دقيقة.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM stores WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $rUser = $stmt->fetch();

            if ($rUser && verify_password($password, $rUser['password'])) {
                record_login_attempt($pdo, $email, true, 'store');
                clear_login_attempts($pdo, $email);
                regenerate_session_id();
                $_SESSION['store_id'] = (int) $rUser['id'];
                security_log($pdo, 'login_success', 'info', null, 'store', (int) $rUser['id']);
                redirect(BASE_URL . '/admin/dashboard.php');
            } else {
                record_login_attempt($pdo, $email, false, 'store');
                security_log($pdo, 'login_failed', 'warning', ['email' => $email], 'store');
                usleep(random_int(300000, 700000));
                $error = 'البريد أو كلمة المرور غير صحيحة';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#059669">
    <title>تسجيل الدخول — <?= e(siteSetting($pdo, 'site_name', 'QR Stores')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
    <style>
        * { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
        html, body { overflow-x: hidden; margin: 0; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdfa 50%, #eff6ff 100%);
            min-height: 100dvh;
            min-height: 100vh;
        }
        .blob { position: absolute; border-radius: 50%; filter: blur(60px); opacity: 0.35; z-index: 0; pointer-events: none; }
        /* 16px font size on inputs prevents iOS Safari from zooming on focus */
        input { font-size: 16px !important; }
        input:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 4px rgba(5,150,105,0.1); }
        @media (max-width: 480px) {
            .blob { filter: blur(40px); opacity: 0.25; }
        }
    </style>
</head>
<body class="relative py-6 px-4 sm:py-10 flex items-center justify-center">

<div class="blob w-64 h-64 sm:w-96 sm:h-96 bg-emerald-300 -top-10 -right-10 sm:-top-20 sm:-right-20"></div>
<div class="blob w-64 h-64 sm:w-96 sm:h-96 bg-teal-300 -bottom-10 -left-10 sm:-bottom-20 sm:-left-20"></div>

<div class="relative z-10 w-full max-w-md mx-auto">
    <div class="bg-white rounded-3xl shadow-2xl p-6 sm:p-8 md:p-10">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 mb-4 shadow-lg shadow-emerald-500/30">
                <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">أهلاً بعودتك</h1>
            <p class="text-gray-500 text-sm">سجل دخولك لإدارة متجرك</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                <p class="text-red-700 text-sm"><?= e($error) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4" autocomplete="off">
            <?= csrfField() ?>
            <?= honeypot_field() ?>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">البريد الإلكتروني</label>
                <input type="email" name="email" required maxlength="150"
                    autocomplete="email" inputmode="email" enterkeyhint="next" dir="ltr"
                    class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition"
                    placeholder="your@email.com">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">كلمة المرور</label>
                <input type="password" name="password" required
                    autocomplete="current-password" enterkeyhint="done"
                    class="w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition"
                    placeholder="••••••••">
            </div>
            <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 active:scale-[0.98] hover:shadow-xl sm:hover:scale-[1.02] transition-all">
                دخول
            </button>
        </form>

        <p class="text-center mt-6 text-sm text-gray-500">
            ليس لديك حساب؟
            <a href="register.php" class="text-emerald-600 font-bold hover:underline">أنشئ حساباً الآن</a>
        </p>
    </div>

    <p class="text-center mt-6 text-xs text-gray-400">
        <a href="<?= BASE_URL ?>" class="hover:text-gray-600">← العودة للصفحة الرئيسية</a>
    </p>
</div>
</body>
</html>
