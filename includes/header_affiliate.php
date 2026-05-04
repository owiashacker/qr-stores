<?php
require_once __DIR__ . '/functions.php';
requireAffiliateLogin();
$aff = currentAffiliate($pdo);
if (!$aff) {
    unset($_SESSION['affiliate_id']);
    redirect(BASE_URL . '/affiliate/login.php');
}
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#ea580c">
    <title><?= $pageTitle ?? 'لوحة الوسيط' ?> — <?= e($aff['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: { sans: ['Cairo', 'system-ui', 'sans-serif'] },
                colors: {
                    brand: {
                        50: '#fff7ed', 100: '#ffedd5', 200: '#fed7aa',
                        300: '#fdba74', 400: '#fb923c', 500: '#f97316',
                        600: '#ea580c', 700: '#c2410c', 800: '#9a3412', 900: '#7c2d12'
                    }
                },
                boxShadow: {
                    'soft': '0 2px 20px rgba(0,0,0,0.04)',
                    'card': '0 4px 30px rgba(0,0,0,0.06)',
                }
            }
        }
    }
    </script>
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f8fafc; }
        .sidebar-link.active { background: linear-gradient(135deg, #ea580c, #c2410c); color: white; box-shadow: 0 4px 15px rgba(234,88,12,0.3); }
        .sidebar-link.active svg { color: white; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        @media (max-width: 768px) { .sidebar-mobile-hidden { transform: translateX(100%); } }
        .sidebar-mobile-hidden { transition: transform 0.3s ease; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #ea580c; box-shadow: 0 0 0 3px rgba(234,88,12,0.15); }
    </style>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
</head>
<body class="min-h-screen">

<div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar-mobile-hidden md:translate-x-0 fixed md:sticky top-0 right-0 z-40 w-72 h-screen bg-white border-l border-gray-100 flex flex-col">

        <!-- Header / Brand -->
        <div class="p-6 border-b border-gray-100">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 flex items-center justify-center text-white font-black shadow-lg shadow-orange-500/30">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-black text-gray-900 truncate"><?= e($aff['name']) ?></p>
                    <p class="text-xs text-orange-600 font-bold">وسيط معتمد</p>
                </div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex-1 p-4 space-y-1 overflow-y-auto scrollbar-hide">
            <?php
            $navItems = [
                ['dashboard',  'لوحة الإحصائيات',  'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['stores',     'المتاجر المُحالة', 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'],
                ['earnings',   'الأرباح والمدفوعات', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['account',    'إعدادات الحساب',   'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
            ];
            foreach ($navItems as [$slug, $label, $iconPath]):
                $isActive = $currentPage === $slug;
            ?>
            <a href="<?= BASE_URL ?>/affiliate/<?= $slug ?>.php" class="sidebar-link <?= $isActive ? 'active' : '' ?> flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 hover:bg-orange-50 transition-all">
                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $iconPath ?>"/>
                </svg>
                <span class="font-medium"><?= $label ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <!-- Logout -->
        <div class="p-4 border-t border-gray-100">
            <a href="<?= BASE_URL ?>/affiliate/logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 hover:bg-red-50 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span class="font-bold">تسجيل الخروج</span>
            </a>
        </div>
    </aside>

    <!-- Mobile sidebar backdrop -->
    <div id="sidebarBackdrop" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- Main content area -->
    <main class="flex-1 min-w-0">
        <!-- Mobile top bar -->
        <header class="md:hidden sticky top-0 z-20 bg-white border-b border-gray-100 px-4 py-3 flex items-center justify-between">
            <button onclick="toggleSidebar()" class="p-2 -mr-2" aria-label="القائمة">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <p class="font-bold text-gray-900"><?= $pageTitle ?? 'لوحة الوسيط' ?></p>
            <div class="w-10"></div>
        </header>

        <div class="p-4 md:p-8">
            <?php if ($success = flash('success')): ?>
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl text-green-700 text-sm">
                    ✓ <?= e($success) ?>
                </div>
            <?php endif; ?>
            <?php if ($error = flash('error')): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700 text-sm">
                    ✕ <?= e($error) ?>
                </div>
            <?php endif; ?>
