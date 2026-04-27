<?php
require_once __DIR__ . '/../includes/functions.php';

if (!empty($_SESSION['admin_id'])) {
    redirect(BASE_URL . '/super/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (honeypot_triggered()) {
        security_log($pdo, 'honeypot_triggered', 'warning', 'super_login', 'admin');
        $error = 'طلب مرفوض';
    } elseif (!csrfCheck()) {
        security_log($pdo, 'csrf_failed', 'warning', 'login_super');
        $error = 'انتهت صلاحية الجلسة';
    } else {
        $email = clean_email($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (!$email) {
            $error = 'البريد غير صالح';
        } elseif (is_rate_limited($pdo, $email, 'admin')) {
            security_log($pdo, 'rate_limited', 'critical', ['email' => $email], 'admin');
            $error = 'محاولات دخول كثيرة. حاول بعد 15 دقيقة.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $admin = $stmt->fetch();
            if ($admin && verify_password($password, $admin['password'])) {
                record_login_attempt($pdo, $email, true, 'admin');
                clear_login_attempts($pdo, $email);
                regenerate_session_id();
                $_SESSION['admin_id'] = (int) $admin['id'];
                $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$admin['id']]);
                security_log($pdo, 'super_login_success', 'info', null, 'admin', (int) $admin['id']);
                redirect(BASE_URL . '/super/dashboard.php');
            } else {
                record_login_attempt($pdo, $email, false, 'admin');
                security_log($pdo, 'super_login_failed', 'critical', ['email' => $email], 'admin');
                usleep(random_int(500000, 1200000));
                $error = 'بيانات الدخول غير صحيحة';
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
    <meta name="theme-color" content="#0f172a">
    <title>دخول المشرف العام — <?= e(siteSetting($pdo, 'site_name', 'QR Stores')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
    <style>
        * { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
        html, body { overflow-x: hidden; margin: 0; }
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #064e3b 100%);
            min-height: 100dvh;
            min-height: 100vh;
        }
        .blob { position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.4; z-index: 0; pointer-events: none; }
        input { font-size: 16px !important; }
        input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        @media (max-width: 480px) {
            .blob { filter: blur(50px); opacity: 0.3; }
        }
    </style>
</head>
<body class="relative py-6 px-4 sm:py-10 flex items-center justify-center">

<div class="blob w-64 h-64 sm:w-96 sm:h-96 bg-emerald-600 -top-10 -right-10 sm:-top-20 sm:-right-20"></div>
<div class="blob w-64 h-64 sm:w-96 sm:h-96 bg-teal-700 -bottom-10 -left-10 sm:-bottom-20 sm:-left-20"></div>

<div class="relative z-10 w-full max-w-md mx-auto">
    <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-3xl shadow-2xl p-6 sm:p-8 md:p-10">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 mb-4 shadow-lg shadow-emerald-500/50">
                <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="text-2xl md:text-3xl font-black text-white mb-2">لوحة الإدارة العامة</h1>
            <p class="text-gray-400 text-sm">Super Admin Panel</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-500/10 border border-red-500/30 rounded-xl">
                <p class="text-red-300 text-sm"><?= e($error) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4" autocomplete="off">
            <?= csrfField() ?>
            <?= honeypot_field() ?>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">البريد الإلكتروني</label>
                <input type="email" name="email" required maxlength="150"
                    autocomplete="email" inputmode="email" enterkeyhint="next" dir="ltr"
                    class="w-full px-4 py-3 rounded-xl bg-white/5 border-2 border-white/10 text-white placeholder-gray-500 focus:border-emerald-500 transition"
                    placeholder="admin@qrmenu.sy">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-300 mb-2">كلمة المرور</label>
                <input type="password" name="password" required
                    autocomplete="current-password" enterkeyhint="done"
                    class="w-full px-4 py-3 rounded-xl bg-white/5 border-2 border-white/10 text-white placeholder-gray-500 focus:border-emerald-500 transition"
                    placeholder="••••••••">
            </div>
            <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold shadow-lg shadow-emerald-500/40 active:scale-[0.98] hover:shadow-xl sm:hover:scale-[1.02] transition-all">
                دخول آمن
            </button>
        </form>
    </div>

    <p class="text-center mt-6 text-xs text-gray-500">
        <a href="<?= BASE_URL ?>/" class="hover:text-gray-300">← العودة للموقع</a>
    </p>
</div>
</body>
</html>
