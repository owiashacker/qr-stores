<?php
require_once __DIR__ . '/functions.php';
requireAdminLogin();
$admin = currentAdmin($pdo);
if (!$admin) {
    // Admin row deleted or deactivated — clear only the stale ID.
    unset($_SESSION['admin_id']);
    redirect(BASE_URL . '/super/login.php');
}
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM subscription_requests WHERE status = 'pending'")->fetchColumn();
// Unresolved error count for sidebar badge (may not exist on fresh install — guard it)
$errorsCount = 0;
try {
    $errorsCount = (int) $pdo->query("SELECT COUNT(*) FROM error_logs WHERE status = 'new'")->fetchColumn();
} catch (Throwable $e) { /* table not yet created — silently ignore */
}
// Recent error-status activity (last hour) — small badge next to the Activity link
$activityErrorsCount = 0;
try {
    $activityErrorsCount = (int) $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE http_status >= 400 AND created_at > (NOW() - INTERVAL 1 HOUR)")->fetchColumn();
} catch (Throwable $e) { /* table not yet created — silently ignore */
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'لوحة الإدارة' ?> — Super Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: #0f172a;
            color: #e2e8f0;
        }

        .sidebar-link.active {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .sidebar-link.active svg {
            color: white;
        }

        .card {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        input,
        select,
        textarea {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #10b981 !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
        }

        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }

        /* Scrollable table with sticky header — wrap any table in .table-scroll */
        .table-scroll {
            max-height: 65vh;
            overflow: auto;
            position: relative;
        }

        .table-scroll table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-scroll thead {
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .table-scroll thead tr {
            background: #192133;
            box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.06);
        }

        .table-scroll thead th {
            position: sticky;
            top: 0;
            background: #192133;
        }

        .table-scroll::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .table-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 6px;
        }

        .table-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .table-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        @media (max-width: 768px) {
            .sidebar-mobile-hidden {
                transform: translateX(100%);
            }

            .table-scroll {
                max-height: 70vh;
            }
        }

        .sidebar-mobile-hidden {
            transition: transform 0.3s ease;
        }
    </style>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
</head>

<body class="min-h-screen">

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="fixed md:sticky top-0 right-0 h-screen w-72 bg-[#0b1220] border-l border-white/5 z-40 sidebar-mobile-hidden md:transform-none overflow-y-auto scrollbar-hide">
            <div class="p-6 border-b border-white/5">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white shadow-lg">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="font-black text-white text-sm"><?= e(siteSetting($pdo, 'site_name', 'QR Stores')) ?></h2>
                        <p class="text-xs text-gray-500">Super Admin</p>
                    </div>
                </div>
            </div>

            <nav class="p-4 space-y-1">
                <?php
                $links = [
                    ['dashboard', 'نظرة عامة', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                    ['stores', 'المتاجر', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['business_types', 'أنواع المتاجر', 'M4 6h16M4 10h16M4 14h16M4 18h16'],
                    ['subscriptions', 'طلبات الاشتراك', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', $pendingCount],
                    ['payments', 'سجل المدفوعات', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['plans', 'الباقات والأسعار', 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                    ['security', 'الأمن والسجلات', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['errors', 'سجل الأخطاء', 'M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16a2 2 0 001.73 3z', $errorsCount],
                    ['activity', 'سجل الأحداث', 'M13 10V3L4 14h7v7l9-11h-7z', $activityErrorsCount],
                    ['settings', 'إعدادات المنصة', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                ];
                foreach ($links as $link):
                    [$slug, $label, $path] = $link;
                    $badge = $link[3] ?? null;
                    $active = $currentPage === $slug ? 'active' : '';
                ?>
                    <a href="<?= BASE_URL ?>/super/<?= $slug ?>.php" class="sidebar-link <?= $active ?> flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-white/5 transition-all">
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $path ?>" />
                        </svg>
                        <span class="font-medium flex-1"><?= $label ?></span>
                        <?php if ($badge): ?>
                            <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full font-bold"><?= $badge ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>

                <div class="pt-4 mt-4 border-t border-white/5">
                    <a href="<?= BASE_URL ?>/super/account.php" class="sidebar-link <?= $currentPage === 'account' ? 'active' : '' ?> flex items-center gap-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-white/5 transition-all">
                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span class="font-medium">حسابي</span>
                    </a>
                    <a href="<?= BASE_URL ?>/" target="_blank" class="flex items-center gap-3 px-4 py-3 rounded-xl text-gray-400 hover:bg-white/5 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        <span class="font-medium">زيارة الموقع</span>
                    </a>
                    <a href="<?= BASE_URL ?>/super/logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-400 hover:bg-red-500/10 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        <span class="font-medium">خروج</span>
                    </a>
                </div>
            </nav>
        </aside>

        <div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden" onclick="toggleSidebar()"></div>

        <!-- Main Content -->
        <main class="flex-1 min-w-0">
            <header class="bg-[#0b1220]/80 backdrop-blur-lg border-b border-white/5 sticky top-0 z-20">
                <div class="flex items-center justify-between px-4 md:px-8 py-4">
                    <div class="flex items-center gap-3">
                        <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-lg hover:bg-white/5">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <h1 class="text-lg md:text-xl font-black text-white"><?= $pageTitle ?? 'لوحة التحكم' ?></h1>
                    </div>
                    <a href="<?= BASE_URL ?>/super/account.php" class="flex items-center gap-3 px-2 py-1 rounded-xl hover:bg-white/5 transition" title="حسابي">
                        <div class="hidden md:block text-right">
                            <p class="text-xs text-gray-500">مرحباً</p>
                            <p class="text-sm font-bold text-white"><?= e($admin['name']) ?></p>
                        </div>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center font-bold text-white shadow-lg ring-2 ring-white/10 hover:ring-emerald-500/40 transition"><?= e(mb_substr($admin['name'], 0, 1)) ?></div>
                    </a>
                </div>
            </header>

            <div class="p-4 md:p-8">
                <?php if ($msg = flash('success')): ?>
                    <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-xl"><?= e($msg) ?></div>
                <?php endif; ?>
                <?php if ($msg = flash('error')): ?>
                    <div class="mb-6 p-4 bg-red-500/10 border border-red-500/30 text-red-300 rounded-xl"><?= e($msg) ?></div>
                <?php endif; ?>