<?php
require_once __DIR__ . '/../includes/functions.php';

// Show this page right after a fresh registration, OR if a pending user lands here.
$email    = $_SESSION['pending_store_email'] ?? '';
$whatsapp = $_SESSION['pending_store_whatsapp'] ?? '';

// Strip the session hint so a refresh doesn't keep showing personal data
unset($_SESSION['pending_store_email'], $_SESSION['pending_store_whatsapp']);
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f59e0b">
    <title>طلبك قيد المراجعة — <?= e(siteSetting($pdo, 'site_name', 'QR Stores')) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 50%, #fef9c3 100%); min-height: 100vh; }
        .blob { position: absolute; border-radius: 50%; filter: blur(70px); opacity: 0.45; z-index: 0; pointer-events: none; }
        @keyframes pulse-ring {
            0%   { transform: scale(0.8); opacity: 0.6; }
            70%  { transform: scale(1.4); opacity: 0; }
            100% { transform: scale(1.6); opacity: 0; }
        }
        .pulse-ring::before {
            content: '';
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 4px solid #f59e0b;
            animation: pulse-ring 2s ease-out infinite;
        }
    </style>
</head>
<body class="relative py-10 px-4 flex items-center justify-center">

<div class="blob w-72 h-72 sm:w-96 sm:h-96 bg-amber-300 -top-10 -right-10"></div>
<div class="blob w-72 h-72 sm:w-96 sm:h-96 bg-orange-200 -bottom-10 -left-10"></div>

<div class="relative z-10 w-full max-w-xl mx-auto">
    <div class="bg-white rounded-3xl shadow-2xl p-6 sm:p-10">

        <!-- Pulsing clock icon -->
        <div class="text-center mb-6">
            <div class="relative inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 shadow-xl shadow-orange-500/30 pulse-ring">
                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>

        <h1 class="text-2xl md:text-3xl font-black text-gray-900 text-center mb-3">
            تم استلام طلبك بنجاح ✓
        </h1>
        <p class="text-center text-gray-600 mb-6 leading-relaxed">
            شكراً لتسجيلك في <span class="font-bold text-amber-600">QR Stores</span>
            — حسابك الآن قيد المراجعة من قبل إدارة المنصة.
        </p>

        <!-- What happens next -->
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-5">
            <h2 class="font-black text-amber-900 mb-4 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-amber-600 text-white text-xs flex items-center justify-center font-bold">!</span>
                ما هي الخطوات التالية؟
            </h2>
            <ol class="space-y-3 text-sm text-gray-700">
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 text-xs flex items-center justify-center font-bold flex-shrink-0">1</span>
                    <span>سيقوم المشرف بمراجعة طلبك خلال <span class="font-bold">24 ساعة</span>.</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 text-xs flex items-center justify-center font-bold flex-shrink-0">2</span>
                    <span>قد نتواصل معك عبر <span class="font-bold text-green-700">الواتساب</span> لتأكيد بياناتك<?= $whatsapp ? ' (' . e($whatsapp) . ')' : '' ?>.</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 text-xs flex items-center justify-center font-bold flex-shrink-0">3</span>
                    <span>بعد الموافقة، ستتمكن من تسجيل الدخول وإدارة متجرك.</span>
                </li>
            </ol>
        </div>

        <?php if ($email): ?>
            <div class="text-center text-sm text-gray-500 mb-6">
                البريد المسجّل:
                <span class="font-mono font-bold text-gray-700 ltr:text-left" dir="ltr"><?= e($email) ?></span>
            </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="flex flex-col sm:flex-row gap-3">
            <a href="<?= BASE_URL ?>/" class="flex-1 px-5 py-3 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold text-center transition">
                ← العودة للصفحة الرئيسية
            </a>
            <a href="<?= BASE_URL ?>/admin/login.php" class="flex-1 px-5 py-3 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white font-bold text-center shadow-lg shadow-orange-500/30 hover:shadow-xl transition">
                صفحة الدخول
            </a>
        </div>
    </div>

    <p class="text-center mt-6 text-xs text-gray-500">
        تواجه مشكلة؟ تواصل معنا عبر الواتساب
    </p>
</div>

</body>
</html>
