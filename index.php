<?php
require_once __DIR__ . '/includes/functions.php';
$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price')->fetchAll();
$bizTypes = $pdo->query('SELECT code, name_ar, icon FROM business_types WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
$siteName = siteSetting($pdo, 'site_name', 'QR Stores');
$siteTagline = siteSetting($pdo, 'site_tagline', 'متجرك الإلكتروني في 5 دقائق');
$contactWhatsapp = preg_replace('/\D/', '', siteSetting($pdo, 'contact_whatsapp', ''));
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#059669">
    <title>QR Stores — متجرك الإلكتروني في 5 دقائق</title>
    <meta name="description" content="أنشئ متجر إلكتروني احترافي لأي نشاط — مطعم، ألبسة، سيارات، إلكترونيات — مع QR Code لكل منتج. حدّث المنتجات والأسعار بلا طباعة جديدة.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Cairo', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/css/mobile.css') ?: 1 ?>">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }

        .gradient-text {
            background: linear-gradient(135deg, #059669 0%, #0d9488 50%, #0891b2 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-bg {
            background:
                radial-gradient(circle at 20% 50%, rgba(16, 185, 129, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(13, 148, 136, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(8, 145, 178, 0.1) 0%, transparent 50%),
                linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.5;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0)
            }

            50% {
                transform: translateY(-20px)
            }
        }

        .float {
            animation: float 6s ease-in-out infinite;
        }

        .card-glow {
            box-shadow: 0 30px 80px -20px rgba(5, 150, 105, 0.4);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .slide-up {
            animation: slideUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) both;
        }

        .slide-up-1 {
            animation-delay: 0.1s;
        }

        .slide-up-2 {
            animation-delay: 0.2s;
        }

        .slide-up-3 {
            animation-delay: 0.3s;
        }
    </style>
</head>

<body class="bg-white">

    <!-- Nav -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100">
        <div class="container max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white font-black shadow-lg shadow-emerald-500/30">Q</div>
                <span class="font-black text-lg">QR Stores</span>
            </div>
            <div class="flex items-center gap-2">
                <a href="admin/login.php" class="hidden sm:inline-block px-4 py-2 text-gray-700 hover:text-emerald-600 font-semibold">دخول</a>
                <a href="admin/register.php" class="px-4 py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-500/30 hover:shadow-xl transition">ابدأ مجاناً</a>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero-bg pt-32 pb-20 md:pt-40 md:pb-32 relative overflow-hidden">
        <div class="blob w-96 h-96 bg-emerald-300 -top-20 -right-40 float"></div>
        <div class="blob w-96 h-96 bg-teal-300 bottom-0 -left-40 float" style="animation-delay: 2s"></div>

        <div class="container max-w-6xl mx-auto px-4 relative z-10">
            <div class="text-center">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-emerald-100 text-emerald-700 text-sm font-bold mb-6 slide-up">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    متجر إلكتروني مع QR Code — لأي نشاط
                </div>
                <h1 class="text-4xl md:text-6xl lg:text-7xl font-black leading-tight mb-6 slide-up slide-up-1">
                    <span class="gradient-text">متجرك الإلكتروني</span><br>
                    بكود QR واحد يغيّر كل شيء
                </h1>
                <p class="text-lg md:text-xl text-gray-600 max-w-2xl mx-auto mb-10 slide-up slide-up-2">
                    مطعم، محل ألبسة، سيارات، هواتف، بقالية... أنشئ كتالوج احترافي بالصور والأسعار والمواصفات، واطبع QR Code لزبائنك. حدّث منتجاتك في ثوانٍ.
                </p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center slide-up slide-up-3">
                    <a href="admin/register.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold text-lg shadow-xl shadow-emerald-500/40 hover:shadow-2xl hover:scale-105 transition">
                        جرّب مجاناً الآن
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </a>
                    <a href="#features" class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-2xl bg-white text-gray-900 font-bold text-lg border-2 border-gray-100 hover:border-emerald-300 transition">
                        شاهد كيف يعمل
                    </a>
                </div>

                <!-- Supported sectors strip — visually proves multi-sector support -->
                <?php if (!empty($bizTypes)): ?>
                    <div class="mt-12 slide-up" style="animation-delay: 0.45s">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4">يناسب كل الأنشطة</p>
                        <div class="flex flex-wrap justify-center gap-2 sm:gap-3 max-w-3xl mx-auto">
                            <?php foreach ($bizTypes as $bt): ?>
                                <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white border border-gray-100 shadow-soft text-sm font-semibold text-gray-700">
                                    <span class="text-lg"><?= e($bt['icon']) ?></span>
                                    <?= e($bt['name_ar']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Phone Mockup -->
            <div class="mt-16 relative slide-up" style="animation-delay: 0.4s">
                <div class="max-w-sm mx-auto relative">
                    <div class="card-glow rounded-[3rem] border-[12px] border-gray-900 bg-gray-900 overflow-hidden">
                        <div class="bg-gradient-to-br from-emerald-500 via-teal-500 to-cyan-600 h-32 relative">
                            <div class="absolute bottom-4 right-4 flex items-center gap-2">
                                <div class="w-12 h-12 rounded-xl bg-white/90 flex items-center justify-center font-black text-emerald-700 text-xl">ش</div>
                                <div>
                                    <p class="text-white font-black">مطعم الشام</p>
                                    <p class="text-white/80 text-xs">ألذ الأكلات الشامية</p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white p-4 space-y-3">
                            <div class="flex gap-2 overflow-hidden">
                                <span class="px-3 py-1 rounded-lg bg-emerald-600 text-white text-xs font-bold whitespace-nowrap">🥗 مقبلات</span>
                                <span class="px-3 py-1 rounded-lg bg-gray-100 text-xs font-bold whitespace-nowrap">🍗 رئيسية</span>
                                <span class="px-3 py-1 rounded-lg bg-gray-100 text-xs font-bold whitespace-nowrap">🍰 حلويات</span>
                            </div>
                            <div class="flex gap-3 bg-gray-50 rounded-xl p-2">
                                <div class="w-16 h-16 rounded-lg bg-gradient-to-br from-amber-200 to-orange-300"></div>
                                <div class="flex-1">
                                    <p class="font-bold text-sm">حمص بيروتي</p>
                                    <p class="text-xs text-gray-500">حمص كريمي مع زيت الزيتون</p>
                                    <p class="text-emerald-600 font-bold text-sm mt-1">15,000 ل.س</p>
                                </div>
                            </div>
                            <div class="flex gap-3 bg-gray-50 rounded-xl p-2">
                                <div class="w-16 h-16 rounded-lg bg-gradient-to-br from-red-200 to-rose-300"></div>
                                <div class="flex-1">
                                    <p class="font-bold text-sm">شاورما لحم</p>
                                    <p class="text-xs text-gray-500">لحم عجل، خضار، صلصة</p>
                                    <p class="text-emerald-600 font-bold text-sm mt-1">28,000 ل.س</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating QR -->
                    <div class="absolute -bottom-8 -left-8 md:-left-16 bg-white rounded-2xl shadow-2xl p-4 rotate-[-6deg] float" style="animation-delay: 1s">
                        <div class="w-24 h-24 bg-gradient-to-br from-gray-900 to-gray-700 rounded-lg grid grid-cols-8 grid-rows-8 p-2 gap-px">
                            <?php for ($i = 0; $i < 64; $i++): ?>
                                <div class="<?= rand(0, 1) ? 'bg-white' : '' ?> rounded-sm"></div>
                            <?php endfor; ?>
                        </div>
                        <p class="text-center text-xs font-bold mt-2">امسح للطلب</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="py-20 md:py-32">
        <div class="container max-w-6xl mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-5xl font-black mb-4">كل ما يحتاجه <span class="gradient-text">متجرك</span></h2>
                <p class="text-lg text-gray-600">أدوات بسيطة، تأثير قوي على تجربة زبائنك</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php
                $features = [
                    ['⚡', 'إعداد في 5 دقائق', 'سجّل، أضف منتجاتك، اطبع QR. بدون تنصيب أو تعقيدات.'],
                    ['📱', 'يعمل على كل جهاز', 'الزبون يمسح بكاميرا الهاتف مباشرة — بدون تحميل تطبيق.'],
                    ['🎨', 'تصميم أنيق يناسبك', 'لون خاص، شعار، صور عالية الجودة — يعكس هوية متجرك.'],
                    ['💰', 'تحديثات فورية', 'غيّرت السعر؟ نفذت منتج؟ حدّث في ثانية — بدون طباعة من جديد.'],
                    ['💬', 'تواصل مباشر عبر واتساب', 'الزبون يضغط على المنتج → رسالة جاهزة تصل على واتساب متجرك.'],
                    ['✨', 'استوديو تخصيص رسالة الزبون', 'خصّص الرسالة كاملةً بأسلوب متجرك — حصرياً في Max.'],
                    ['📊', 'إدارة كاملة من الهاتف', 'لوحة تحكم عربية سهلة، أضف وعدّل من أي مكان.'],
                    ['🔐', 'أمان وموثوقية', 'حماية جلسات، تسجيل دخول آمن، وحدود اشتراك واضحة.'],
                ];
                foreach ($features as [$icon, $title, $desc]):
                ?>
                    <div class="bg-white p-8 rounded-3xl border border-gray-100 hover:shadow-xl hover:border-emerald-200 transition group">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 flex items-center justify-center text-3xl mb-4 group-hover:scale-110 transition"><?= $icon ?></div>
                        <h3 class="text-xl font-black mb-2"><?= $title ?></h3>
                        <p class="text-gray-600"><?= $desc ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- MAX SPOTLIGHT: Order Message Studio  (exclusive MAX-plan showcase)      -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <section id="max-studio" class="py-20 md:py-28 bg-gradient-to-br from-gray-900 via-emerald-950 to-gray-900 text-white relative overflow-hidden">
        <div class="absolute top-0 right-0 w-[40rem] h-[40rem] bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-[40rem] h-[40rem] bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="container max-w-6xl mx-auto px-4 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- Copy -->
                <div>
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-gradient-to-r from-amber-400 to-orange-400 text-amber-950 text-xs font-black mb-5 shadow-lg">
                        <span>⭐ حصرياً في باقة Max</span>
                    </div>
                    <h2 class="text-3xl md:text-5xl font-black leading-tight mb-5">
                        <span class="gradient-text">استوديو رسالة الزبون</span><br>
                        كل تواصل يحمل بصمة متجرك
                    </h2>
                    <p class="text-lg text-emerald-100/80 mb-8 leading-relaxed">
                        لا توجد منصة متاجر أخرى تعطيك هذا التحكم. صمّم الرسالة التي يرسلها زبائنك عبر واتساب
                        من الألف إلى الياء — القالب، التحية، التوقيع، الأسئلة المطلوبة، الإيموجي، كل شيء.
                    </p>

                    <ul class="space-y-4 mb-8">
                        <?php
                        $studioPoints = [
                            ['🎨', 'قوالب احترافية جاهزة', '7 قوالب مصممة بعناية — كلاسيكي، ودود، احترافي، توصيل، سريع، أو بالإنجليزي.'],
                            ['🧩', 'متغيّرات ذكية', '{item}، {price}، {store}، {name} — انقر لإدراج أي متغير في مكانه.'],
                            ['✅', 'أسئلة قبل الإرسال', 'فعّل الاسم / الهاتف / الكمية / العنوان / الملاحظات — يظهر نموذج أنيق للزبون.'],
                            ['👁', 'معاينة مباشرة بتصميم واتساب', 'شاهد الرسالة كما سيراها زبونك تماماً، قبل النشر.'],
                            ['🔄', 'زر إرجاع للافتراضي', 'جرّب بحرية — زر واحد يُرجع كل شيء.'],
                        ];
                        foreach ($studioPoints as [$ico, $title, $desc]):
                        ?>
                            <li class="flex gap-3">
                                <div class="w-10 h-10 rounded-xl bg-emerald-400/10 border border-emerald-300/20 flex items-center justify-center text-xl flex-shrink-0"><?= $ico ?></div>
                                <div>
                                    <div class="font-bold text-white mb-0.5"><?= $title ?></div>
                                    <div class="text-sm text-emerald-100/70"><?= $desc ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <a href="admin/register.php" class="inline-flex items-center gap-2 px-8 py-4 rounded-2xl bg-gradient-to-r from-amber-400 to-orange-400 text-amber-950 font-black text-lg shadow-xl hover:shadow-2xl hover:scale-[1.02] transition">
                        احصل على باقة Max
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </a>
                </div>

                <!-- Visual: simulated WhatsApp chat showing a customized message -->
                <div class="relative">
                    <div class="rounded-[2rem] shadow-2xl overflow-hidden border-[10px] border-gray-900 max-w-md mx-auto" style="background: #0f1f1a;">
                        <!-- WhatsApp header -->
                        <div class="flex items-center gap-3 px-4 py-3" style="background: #075e54;">
                            <div class="w-10 h-10 rounded-full bg-emerald-300/30 flex items-center justify-center font-black text-white">ش</div>
                            <div class="flex-1 min-w-0">
                                <div class="text-white font-bold text-sm">مطعم الشام</div>
                                <div class="text-[11px] text-emerald-100/70">متصل الآن</div>
                            </div>
                            <svg class="w-5 h-5 text-white/80" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.5 3.4A12 12 0 0 0 12 0C5.4 0 0 5.4 0 12c0 2.1.6 4.2 1.6 6L0 24l6.2-1.6a12 12 0 0 0 17.6-10.4c0-3.2-1.3-6.2-3.3-8.6z" />
                            </svg>
                        </div>
                        <!-- chat body -->
                        <div class="p-4 min-h-[280px]" style="background: #0b141a; background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.03) 1px, transparent 0); background-size: 20px 20px;">
                            <div class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-tr-sm px-4 py-3 shadow text-white text-sm leading-relaxed whitespace-pre-wrap" style="background: #005c4b;">السلام عليكم 🌿

                                    أودّ طلب هذا الصنف من قائمتكم:
                                    🍽️ <strong>شاورما دجاج خاصة</strong> — 45,000 ل.س
                                    🔢 الكمية: 2
                                    🪑 الطاولة: 5
                                    📝 ملاحظات: بدون بصل

                                    شكراً لكم — مطعم الشام

                                    مع تحيات زبونكم ✨<span class="block text-[10px] text-emerald-100/60 text-end mt-2">10:24 ✓✓</span></div>
                            </div>
                        </div>
                    </div>
                    <!-- Floating badge -->
                    <div class="absolute -top-4 -left-4 bg-white text-gray-900 px-4 py-2 rounded-2xl shadow-2xl rotate-[-8deg] font-bold text-sm">
                        <span class="text-amber-500">✨</span> مُخصَّصة بالكامل
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How it works -->
    <section class="py-20 md:py-32 bg-gradient-to-br from-emerald-50 via-white to-teal-50">
        <div class="container max-w-6xl mx-auto px-4">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-5xl font-black mb-4">كيف يعمل؟</h2>
                <p class="text-lg text-gray-600">ثلاث خطوات ومتجرك جاهز</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php
                $steps = [
                    ['1', 'سجّل واختر نوع متجرك', 'أدخل اسم متجرك وبريدك الإلكتروني — مطعم أو ألبسة أو سيارات... مجاناً بالكامل.'],
                    ['2', 'أضف منتجاتك', 'قسّم كتالوجك إلى فئات وأضف المنتجات بالصور والأسعار والمواصفات.'],
                    ['3', 'اطبع QR', 'احصل على QR Code جاهز للطباعة. ضعه على الواجهة أو الكرت أو الطاولات وانطلق.'],
                ];
                foreach ($steps as [$n, $title, $desc]):
                ?>
                    <div class="relative">
                        <div class="bg-white rounded-3xl p-8 shadow-soft hover:shadow-xl transition">
                            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-600 to-teal-600 text-white font-black text-2xl flex items-center justify-center mb-4 shadow-lg shadow-emerald-500/30"><?= $n ?></div>
                            <h3 class="text-xl font-black mb-2"><?= $title ?></h3>
                            <p class="text-gray-600"><?= $desc ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="py-20 md:py-32">
        <div class="container max-w-6xl mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl md:text-5xl font-black mb-4">بسيط. شفاف. <span class="gradient-text">بدون مفاجآت.</span></h2>
                <p class="text-lg text-gray-600">اختر الباقة المناسبة لحجم متجرك — طوّر لاحقاً بلا قيود</p>
            </div>

            <?php
            // الصفحة الرئيسية عامة — لا يوجد متجر محدد. نستخدم مصطلحات محايدة عامة.
            $genericPlanTokens = [
                '{items_def}'      => 'المنتجات',
                '{item_def}'       => 'المنتج',
                '{categories_def}' => 'الأقسام',
                '{category_def}'   => 'القسم',
                '{items}'          => 'منتجات',
                '{item}'           => 'منتج',
                '{categories}'     => 'أقسام',
                '{category}'       => 'قسم',
                '{one_category}'   => 'قسم واحد',
                '{biz}'            => 'متجر',
                '{store}'          => 'المتجر',
                '{for_store}'      => 'للمتجر',
            ];
            $renderPlanText = fn(string $t): string => strtr($t, $genericPlanTokens);
            ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-stretch">
                <?php foreach ($plans as $plan):
                    $features = array_filter(array_map('trim', explode("\n", $renderPlanText($plan['features_list'] ?? ''))));
                    $isPopular = (int) $plan['is_popular'] === 1;
                    $isPremium = $plan['code'] === 'max';
                    $isFree = $plan['code'] === 'free';
                    $periodLabels = ['7days' => 'لمدة 7 أيام', 'monthly' => 'شهرياً', 'yearly' => 'سنوياً'];
                    $periodLabel  = $periodLabels[$plan['period']] ?? 'دائماً';

                    if ($isPremium) {
                        $cardClass = 'relative bg-gradient-to-br from-gray-900 via-gray-900 to-amber-900 text-white';
                        $checkColor = 'text-amber-400';
                        $tagColor = 'text-amber-200';
                    } elseif ($isPopular) {
                        $cardClass = 'relative bg-gradient-to-br from-emerald-600 via-teal-700 to-emerald-800 text-white md:-mt-4';
                        $checkColor = 'text-amber-300';
                        $tagColor = 'text-emerald-100';
                    } else {
                        $cardClass = 'relative bg-white border-2 border-gray-100';
                        $checkColor = 'text-emerald-500';
                        $tagColor = 'text-gray-500';
                    }

                    $waMsg = $isFree
                        ? ''
                        : ($contactWhatsapp
                            ? "https://wa.me/$contactWhatsapp?text=" . urlencode('مرحباً، أريد الاشتراك في باقة ' . $plan['name'])
                            : 'admin/register.php');
                ?>
                    <div class="<?= $cardClass ?> p-8 rounded-3xl flex flex-col">
                        <?php if ($isPopular): ?>
                            <span class="absolute -top-3 right-1/2 translate-x-1/2 bg-amber-400 text-gray-900 text-xs font-black px-4 py-1 rounded-full shadow-lg">الأكثر مبيعاً 🔥</span>
                        <?php elseif ($isPremium): ?>
                            <span class="absolute top-4 left-4 bg-amber-400 text-gray-900 text-xs font-black px-3 py-1 rounded-full">⭐ الأشمل</span>
                        <?php endif; ?>

                        <div class="mb-6">
                            <h3 class="text-2xl font-black mb-1"><?= e($plan['name']) ?></h3>
                            <p class="<?= $tagColor ?> text-sm"><?= e($renderPlanText($plan['tagline'] ?? '')) ?></p>
                        </div>

                        <div class="mb-6 pb-6 border-b <?= $isPremium || $isPopular ? 'border-white/20' : 'border-gray-100' ?>">
                            <?php if ($plan['price'] == 0): ?>
                                <div class="flex items-baseline gap-1">
                                    <span class="text-5xl font-black">مجاني</span>
                                </div>
                            <?php else:
                                // Prices are intentionally hidden on the public landing page.
                                // Visitors contact us on WhatsApp for a quote tailored to their store.
                                $waPriceHref = $contactWhatsapp
                                    ? 'https://wa.me/' . $contactWhatsapp . '?text=' . rawurlencode('مرحباً، أريد الاستفسار عن سعر باقة ' . $plan['name'])
                                    : null;
                                $waPriceColor = $isPremium
                                    ? 'text-amber-300 hover:text-amber-200'
                                    : ($isPopular ? 'text-amber-200 hover:text-white' : 'text-emerald-600 hover:text-emerald-700');
                            ?>
                                <div class="space-y-2">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-3xl md:text-4xl font-black">اتصل للسعر</span>
                                        <span class="<?= $tagColor ?> text-sm">/ <?= $periodLabel ?></span>
                                    </div>
                                    <?php if ($waPriceHref): ?>
                                        <a href="<?= e($waPriceHref) ?>" target="_blank" rel="noopener"
                                            class="inline-flex items-center gap-2 text-sm font-bold <?= $waPriceColor ?> transition">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347" />
                                            </svg>
                                            للاستفسار على واتساب
                                        </a>
                                    <?php else: ?>
                                        <p class="<?= $tagColor ?> text-xs">تواصل معنا لمعرفة السعر</p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <ul class="space-y-3 mb-8 flex-1">
                            <?php foreach ($features as $feature): ?>
                                <li class="flex gap-2 text-sm">
                                    <span class="<?= $checkColor ?> flex-shrink-0">✓</span>
                                    <span><?= e($feature) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($isFree): ?>
                            <a href="admin/register.php" class="block text-center py-3 rounded-xl border-2 border-gray-900 text-gray-900 font-bold hover:bg-gray-900 hover:text-white transition">
                                ابدأ مجاناً
                            </a>
                        <?php elseif ($isPremium): ?>
                            <a href="<?= e($waMsg) ?>" class="block text-center py-3 rounded-xl bg-gradient-to-r from-amber-400 to-orange-400 text-gray-900 font-black hover:shadow-xl transition">
                                اطلب <?= e($plan['name']) ?>
                            </a>
                        <?php else: ?>
                            <a href="<?= e($waMsg) ?>" class="block text-center py-3 rounded-xl bg-white text-gray-900 font-bold hover:bg-gray-100 transition">
                                اطلب <?= e($plan['name']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Installation Service -->
            <div class="mt-10 p-6 rounded-3xl bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-100">
                <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
                    <div class="text-4xl">🎯</div>
                    <div class="flex-1">
                        <h3 class="font-black text-lg mb-1">خدمة التركيب الكامل</h3>
                        <p class="text-gray-600 text-sm">نصمم كتالوجك، نصور منتجاتك، ونركّب كل شيء لك. جاهز خلال 48 ساعة.</p>
                    </div>
                    <a href="<?= $contactWhatsapp ? 'https://wa.me/' . $contactWhatsapp . '?text=' . urlencode('أريد خدمة التركيب الكامل لمتجري الإلكتروني') : 'admin/register.php' ?>" class="px-6 py-3 rounded-xl bg-gray-900 text-white font-bold whitespace-nowrap hover:bg-gray-800 transition">اطلب الخدمة</a>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-20 md:py-32 bg-gradient-to-br from-emerald-600 via-teal-600 to-emerald-700 text-white relative overflow-hidden">
        <div class="absolute top-0 right-0 w-96 h-96 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/2 blur-3xl"></div>
        <div class="absolute bottom-0 left-0 w-96 h-96 bg-white/10 rounded-full translate-y-1/2 -translate-x-1/2 blur-3xl"></div>

        <div class="container max-w-4xl mx-auto px-4 text-center relative z-10">
            <h2 class="text-3xl md:text-5xl font-black mb-6">جاهز لإطلاق متجرك الإلكتروني؟</h2>
            <p class="text-xl text-emerald-50 mb-10">ابدأ في أقل من 5 دقائق. بدون بطاقة ائتمانية.</p>
            <a href="admin/register.php" class="inline-flex items-center gap-2 px-8 py-4 rounded-2xl bg-white text-emerald-700 font-black text-lg shadow-2xl hover:scale-105 transition">
                أنشئ متجري الآن
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-12 border-t border-gray-100">
        <div class="container max-w-6xl mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white font-black text-sm">Q</div>
                <span class="font-black">QR Stores</span>
            </div>
            <p class="text-sm text-gray-500">© <?= date('Y') ?> QR Stores. جميع الحقوق محفوظة.</p>
        </div>
    </footer>

</body>

</html>