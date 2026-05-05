<?php
require_once __DIR__ . '/functions.php';
requireLogin();
$r = currentStore($pdo);
if (!$r) {
    // Restaurant row was deleted — clear only the stale ID (keep CSRF token,
    // flash messages, etc.) and bounce to login. Don't destroy the whole
    // session, since other legitimate session data (remember-me, preferences)
    // may be attached to it.
    unset($_SESSION['store_id']);
    redirect(BASE_URL . '/admin/login.php');
}
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#059669">
    <title><?= $pageTitle ?? 'لوحة التحكم' ?> — <?= e($r['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: { sans: ['Cairo', 'Tajawal', 'system-ui', 'sans-serif'] },
                colors: {
                    brand: {
                        50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0',
                        300: '#6ee7b7', 400: '#34d399', 500: '#10b981',
                        600: '#059669', 700: '#047857', 800: '#065f46', 900: '#064e3b'
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
        .sidebar-link.active { background: linear-gradient(135deg, #059669, #047857); color: white; box-shadow: 0 4px 15px rgba(5,150,105,0.3); }
        .sidebar-link.active svg { color: white; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        @media (max-width: 768px) { .sidebar-mobile-hidden { transform: translateX(100%); } }
        .sidebar-mobile-hidden { transition: transform 0.3s ease; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.15); }
    </style>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
</head>
<body class="min-h-screen">

<div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside id="sidebar" class="fixed md:sticky top-0 right-0 h-screen w-72 bg-white border-l border-gray-100 z-40 sidebar-mobile-hidden md:transform-none overflow-y-auto scrollbar-hide">
        <div class="p-6 border-b border-gray-100">
            <a href="<?= BASE_URL ?>/admin/account.php" class="flex items-center gap-3 mb-3 -m-1 p-1 rounded-xl hover:bg-gray-50 transition" title="حسابي">
                <?php if (!empty($r['logo'])): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($r['logo']) ?>" class="w-12 h-12 rounded-xl object-cover">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-bold text-xl">
                        <?= e(mb_substr($r['name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <h2 class="font-bold text-gray-900 truncate"><?= e($r['name']) ?></h2>
                    <p class="text-xs text-gray-500 truncate">/<?= e($r['slug']) ?></p>
                </div>
            </a>
            <?php
            $planColors = [
                'free' => 'bg-gray-100 text-gray-700',
                'pro' => 'bg-gradient-to-r from-emerald-500 to-teal-500 text-white',
                'max' => 'bg-gradient-to-r from-amber-500 to-orange-500 text-white'
            ];
            $isExpired = !empty($r['is_expired']);
            $planClass = $isExpired ? 'bg-gradient-to-r from-red-500 to-rose-600 text-white' : ($planColors[$r['plan_code'] ?? 'free'] ?? 'bg-gray-100 text-gray-700');
            ?>
            <a href="<?= BASE_URL ?>/admin/upgrade.php" class="flex items-center justify-between gap-2 p-2 rounded-xl <?= $planClass ?> hover:opacity-90 transition">
                <div class="flex items-center gap-2">
                    <?php if ($isExpired): ?>
                        <span class="text-xs font-bold">⚠️ <?= e($r['original_plan_name']) ?> منتهية</span>
                    <?php else: ?>
                        <span class="text-xs font-bold">باقة <?= e($r['plan_name'] ?? 'مجاني') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($isExpired): ?>
                    <span class="text-xs font-bold opacity-90">جدّد ←</span>
                <?php elseif (($r['plan_code'] ?? 'free') !== 'max'): ?>
                    <span class="text-xs font-bold opacity-90">ترقية ←</span>
                <?php else: ?>
                    <span class="text-xs">⭐</span>
                <?php endif; ?>
            </a>
        </div>

        <nav class="p-4 space-y-1">
            <?php
            $links = [
                ['dashboard', 'لوحة التحكم', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['categories', bizLabel($r, 'categories'), 'M4 6h16M4 10h16M4 14h16M4 18h16'],
                ['items', bizLabel($r, 'plural'), 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
                ['qr', 'QR Code', 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h2v2h-2v-2zm4 0h2v6h-2v-6zm-4 4h2v2h-2v-2z'],
                ['settings', 'الإعدادات', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                ['upgrade', 'الباقات والترقية', 'M13 10V3L4 14h7v7l9-11h-7z'],
                ['payments', 'سجل المدفوعات', 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
            ];
            foreach ($links as [$slug, $label, $path]):
                $active = $currentPage === $slug ? 'active' : '';
            ?>
                <a href="<?= BASE_URL ?>/admin/<?= $slug ?>.php" class="sidebar-link <?= $active ?> flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 hover:bg-gray-50 transition-all">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $path ?>"/>
                    </svg>
                    <span class="font-medium"><?= $label ?></span>
                </a>
            <?php endforeach; ?>

            <div class="pt-4 mt-4 border-t border-gray-100">
                <a href="<?= BASE_URL ?>/admin/account.php" class="sidebar-link <?= $currentPage === 'account' ? 'active' : '' ?> flex items-center gap-3 px-4 py-3 rounded-xl text-gray-700 hover:bg-gray-50 transition-all">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span class="font-medium">حسابي</span>
                </a>
                <a href="<?= BASE_URL ?>/public/store.php?r=<?= urlencode($r['slug']) ?>" target="_blank" class="flex items-center gap-3 px-4 py-3 rounded-xl text-brand-700 hover:bg-brand-50 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                    <span class="font-medium">عرض المتجر</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/logout.php" class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 hover:bg-red-50 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="font-medium">تسجيل الخروج</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Sidebar Overlay (mobile) -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <main class="flex-1 min-w-0">
        <!-- Top Bar -->
        <header class="bg-white border-b border-gray-100 sticky top-0 z-20">
            <div class="flex items-center justify-between px-4 md:px-8 py-4">
                <div class="flex items-center gap-3">
                    <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-lg hover:bg-gray-100">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h1 class="text-lg md:text-xl font-bold text-gray-900"><?= $pageTitle ?? 'لوحة التحكم' ?></h1>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?= BASE_URL ?>/public/store.php?r=<?= urlencode($r['slug']) ?>" target="_blank" class="hidden md:inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-50 text-brand-700 hover:bg-brand-100 transition text-sm font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        معاينة المتجر
                    </a>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="p-4 md:p-8">
            <?php if (!empty($r['is_expired'])):
                $expAgo = $r['expired_at_raw'] ? (int) floor((time() - strtotime($r['expired_at_raw'])) / 86400) : 0;
                $isFreeTrialEnded = ($r['original_plan_code'] ?? '') === 'free';
            ?>
                <div class="mb-6 p-4 md:p-5 rounded-2xl bg-gradient-to-r from-red-50 via-rose-50 to-red-50 border-2 border-red-300 shadow-md">
                    <div class="flex flex-col md:flex-row md:items-start gap-3 md:gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white flex-shrink-0 shadow-lg">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <div class="flex-1">
                            <?php if ($isFreeTrialEnded): ?>
                                <h3 class="font-black text-red-900 text-base md:text-lg mb-2">⏱ انتهت الفترة التجريبية المجانية<?= $expAgo > 0 ? ' منذ ' . $expAgo . ' يوم' : '' ?></h3>
                            <?php else: ?>
                                <h3 class="font-black text-red-900 text-base md:text-lg mb-2">⏱ انتهت باقة <?= e($r['original_plan_name']) ?><?= $expAgo > 0 ? ' منذ ' . $expAgo . ' يوم' : '' ?></h3>
                            <?php endif; ?>

                            <!-- Two-card layout: what works / what's blocked -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-3">
                                <div class="rounded-lg bg-white/70 p-3 border border-emerald-200">
                                    <p class="text-xs font-black text-emerald-700 mb-1">✓ متجرك يعمل للزبائن</p>
                                    <p class="text-xs text-emerald-900/80 leading-relaxed">رابط متجرك ومنتجاتك ما زالت ظاهرة للعملاء كالمعتاد.</p>
                                </div>
                                <div class="rounded-lg bg-white/70 p-3 border border-red-200">
                                    <p class="text-xs font-black text-red-700 mb-1">✕ التعديل موقوف مؤقّتاً</p>
                                    <p class="text-xs text-red-900/80 leading-relaxed">لا يمكنك إضافة أو تعديل أي شيء حتى تجدّد الباقة.</p>
                                </div>
                            </div>
                            <p class="text-xs text-red-800 font-semibold">جدّد الباقة الآن لاستعادة التحكم الكامل بمتجرك.</p>
                        </div>
                        <a href="<?= BASE_URL ?>/admin/upgrade.php" class="px-5 py-3 rounded-xl bg-gradient-to-r from-red-600 to-rose-600 text-white font-bold shadow-lg hover:shadow-xl transition whitespace-nowrap text-center md:self-center">
                            ترقية الباقة الآن ←
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('success')): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= e($msg) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('error')): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <span><?= e($msg) ?></span>
                </div>
            <?php endif; ?>
