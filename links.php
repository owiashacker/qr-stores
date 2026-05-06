<?php
/**
 * Public landing page for the Instagram bio link.
 * Loads fast, mobile-first, and tracks the click source via ?from=ig
 */
require_once __DIR__ . '/includes/functions.php';
$contactWa = preg_replace('/\D/', '', siteSetting($pdo, 'contact_whatsapp', '963933000000'));
?><!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#059669">
<title>QR Stores — كل الروابط</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body {
    font-family: 'Cairo', sans-serif;
    background: linear-gradient(160deg, #ecfdf5 0%, #f0fdfa 50%, #eff6ff 100%);
    min-height: 100dvh;
    min-height: 100vh;
    padding: 32px 20px 60px;
    color: #0f172a;
}
.wrap {
    max-width: 460px;
    margin: 0 auto;
}
.brand {
    text-align: center;
    margin-bottom: 28px;
}
.logo {
    width: 96px; height: 96px;
    margin: 0 auto 16px;
    border-radius: 24px;
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 36px;
    box-shadow: 0 12px 30px -6px rgba(5,150,105,0.4);
    letter-spacing: -1px;
}
.brand h1 {
    font-size: 26px;
    font-weight: 900;
    margin-bottom: 6px;
}
.brand p {
    color: #475569;
    font-size: 14px;
    line-height: 1.6;
    padding: 0 16px;
}
.links {
    display: flex; flex-direction: column;
    gap: 14px;
    margin-top: 8px;
}
.link {
    display: flex; align-items: center; gap: 14px;
    background: white;
    padding: 16px 18px;
    border-radius: 18px;
    text-decoration: none;
    color: #0f172a;
    font-weight: 700;
    font-size: 15px;
    box-shadow: 0 6px 16px -4px rgba(15,23,42,0.06), 0 2px 6px rgba(15,23,42,0.04);
    border: 1px solid rgba(15,23,42,0.04);
    transition: transform .15s, box-shadow .15s, border-color .15s;
}
.link:active { transform: scale(0.98); }
.link:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px -6px rgba(15,23,42,0.12);
    border-color: #059669;
}
.link.primary {
    background: linear-gradient(135deg, #059669, #047857);
    color: white;
    border: none;
    box-shadow: 0 10px 28px -6px rgba(5,150,105,0.5);
}
.link.primary:hover { box-shadow: 0 14px 36px -6px rgba(5,150,105,0.7); }
.link.whatsapp {
    background: linear-gradient(135deg, #25D366, #1ebb59);
    color: white;
    border: none;
}
.link .icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    background: rgba(15,23,42,0.05);
    font-size: 22px;
}
.link.primary .icon, .link.whatsapp .icon {
    background: rgba(255,255,255,0.18);
}
.link .body { flex: 1; min-width: 0; }
.link .title { font-size: 15px; font-weight: 800; line-height: 1.3; }
.link .sub { font-size: 12px; opacity: 0.7; font-weight: 500; margin-top: 2px; }
.arrow { opacity: 0.4; font-size: 18px; }
.link:hover .arrow { opacity: 1; transform: translateX(-4px); }
.footer {
    text-align: center;
    margin-top: 32px;
    color: #64748b;
    font-size: 12px;
}
.footer a { color: #059669; font-weight: 700; text-decoration: none; }
</style>
</head>
<body>

<div class="wrap">
    <div class="brand">
        <div class="logo">QR</div>
        <h1>QR Stores</h1>
        <p>منصة الكتالوج الرقمي بكود QR<br>لكل أنشطتك التجارية 🚀</p>
    </div>

    <div class="links">
        <!-- 1. Primary CTA -->
        <a href="/admin/register.php?from=ig" class="link primary">
            <div class="icon">🎁</div>
            <div class="body">
                <div class="title">جرّب 7 أيام مجاناً</div>
                <div class="sub">بدون بطاقة ائتمان • أنشئ متجرك الآن</div>
            </div>
            <span class="arrow">←</span>
        </a>

        <!-- 2. Live Demo -->
        <a href="/public/store.php?r=sham-restaurant" target="_blank" class="link">
            <div class="icon">👀</div>
            <div class="body">
                <div class="title">شاهد متجراً تجريبياً</div>
                <div class="sub">جرّب التجربة من عين الزبون</div>
            </div>
            <span class="arrow">←</span>
        </a>

        <!-- 3. Pricing -->
        <a href="/#pricing" class="link">
            <div class="icon">💎</div>
            <div class="body">
                <div class="title">الأسعار والباقات</div>
                <div class="sub">من 0$ إلى 25$ شهرياً</div>
            </div>
            <span class="arrow">←</span>
        </a>

        <!-- 4. WhatsApp Contact -->
        <a href="https://wa.me/<?= e($contactWa) ?>?text=السلام%20عليكم،%20أريد%20معلومات%20عن%20QR%20Stores" target="_blank" class="link whatsapp">
            <div class="icon">💬</div>
            <div class="body">
                <div class="title">تواصل واتساب فوري</div>
                <div class="sub">إجابة خلال دقائق</div>
            </div>
            <span class="arrow">←</span>
        </a>

        <!-- 5. Become an affiliate -->
        <a href="/affiliate/login.php" class="link">
            <div class="icon">🤝</div>
            <div class="body">
                <div class="title">كن وسيطاً معنا</div>
                <div class="sub">اربح من كل متجر تجلبه</div>
            </div>
            <span class="arrow">←</span>
        </a>
    </div>

    <div class="footer">
        <p>صُنع بـ ❤ في سوريا</p>
        <p style="margin-top: 4px;"><a href="/">qr-stores.com</a></p>
    </div>
</div>

</body>
</html>
