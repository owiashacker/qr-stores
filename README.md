# QR Stores — منصّة كتالوج رقمي

منصّة PHP/MySQL متعدّدة القطاعات تتيح لأصحاب المتاجر إنشاء كتالوج رقمي
بكود QR للمطاعم، الحلويات، البقاليات، الملابس، الموبايلات، الإلكترونيات،
الأجهزة المنزلية، والسيارات.

## المتطلّبات

- PHP 8.2+
- MySQL 8.0+
- Apache mod_rewrite

## التثبيت

```bash
# 1. استنسخ المشروع
git clone https://github.com/USERNAME/qr-stores.git

# 2. أنشئ ملف الإعدادات
cp config/db.example.php config/db.php

# 3. عدّل config/db.php بمعلومات قاعدة البيانات

# 4. استورد الجداول
mysql -u USER -p DB_NAME < schema.sql
```

## بنية المشروع

```
qr_stores/
├── admin/              لوحة تحكّم صاحب المتجر
├── super/              لوحة تحكّم المنصّة (super admin)
├── public/             صفحات عرض المتجر للزوار
├── assets/             css, js, icons, uploads
├── config/             إعدادات (db.php مستثنى من Git)
├── includes/           دوالّ مشتركة
└── index.php           الصفحة الرئيسية
