<?php
http_response_code(404);
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الصفحة غير موجودة — 404</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: linear-gradient(135deg, #ecfdf5, #f0fdfa); min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #fff; padding: 48px 40px; border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.08); text-align: center; max-width: 500px; }
        h1 { font-size: 96px; margin: 0; background: linear-gradient(135deg, #059669, #0d9488); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; font-weight: 900; }
        h2 { color: #111827; margin: 0 0 12px; }
        p { color: #6b7280; margin-bottom: 28px; }
        a { display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #059669, #0d9488); color: #fff; text-decoration: none; border-radius: 12px; font-weight: bold; box-shadow: 0 8px 20px rgba(5,150,105,.3); transition: .2s; }
        a:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(5,150,105,.4); }
    </style>
</head>
<body>
    <div class="card">
        <h1>404</h1>
        <h2>الصفحة غير موجودة</h2>
        <p>الصفحة التي تبحث عنها قد تكون حُذفت أو نُقلت إلى رابط آخر.</p>
        <a href="<?= BASE_URL ?>">← العودة إلى الصفحة الرئيسية</a>
    </div>
</body>
</html>
