<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_message.php';

$slug = $_GET['r'] ?? '';
if (!$slug) { http_response_code(404); die('الصفحة غير موجودة'); }

$stmt = $pdo->prepare('SELECT r.*, p.code AS plan_code, p.name AS plan_name, p.max_categories, p.max_items, p.can_upload_logo, p.can_upload_cover, p.can_customize_colors, p.can_edit_contact, p.can_social_links, p.can_use_discount, p.can_feature_items, p.can_remove_watermark, p.can_multiple_media, p.can_custom_message, bt.code AS biz_code, bt.name_ar AS biz_name, bt.icon AS biz_icon, bt.label_singular, bt.label_plural, bt.label_category, bt.order_verb, bt.fields_schema AS biz_fields_schema FROM stores r LEFT JOIN plans p ON r.plan_id = p.id LEFT JOIN business_types bt ON r.business_type_id = bt.id WHERE r.slug = ? AND r.is_active = 1');
$stmt->execute([$slug]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); die('الصفحة غير موجودة'); }

// Track view — bumps views_count + unique_views (session-based dedup)
trackStoreView($pdo, $r['id']);

// Apply expired downgrade — if subscription ended, act as Free plan
$r = apply_expired_downgrade($pdo, $r);

$showWatermark = !(int) ($r['can_remove_watermark'] ?? 0);
$watermarkText = siteSetting($pdo, 'watermark_text', 'QR Stores');

// Strip paid-plan content when expired / on Free plan
if (!canDo($r, 'upload_logo'))   $r['logo'] = null;
if (!canDo($r, 'upload_cover'))  $r['cover'] = null;
if (!canDo($r, 'customize_colors')) $r['primary_color'] = '#059669';
// Phone & WhatsApp are available on ALL plans — only address/working_hours are gated
if (!canDo($r, 'edit_contact')) { $r['address'] = null; $r['working_hours'] = null; }
if (!canDo($r, 'social_links')) { $r['facebook'] = null; $r['instagram'] = null; }

$maxCats = (int) ($r['max_categories'] ?? -1);
$maxItems = (int) ($r['max_items'] ?? -1);

// Fetch categories — cap to plan limit
$catSql = 'SELECT * FROM categories WHERE store_id = ? AND is_active = 1 ORDER BY sort_order, id';
if ($maxCats !== -1) $catSql .= ' LIMIT ' . $maxCats;
$cats = $pdo->prepare($catSql);
$cats->execute([$r['id']]);
$cats = $cats->fetchAll();
$allowedCatIds = array_column($cats, 'id');

// Fetch items — only those in allowed categories + cap to plan limit
if (!$allowedCatIds) {
    $allItems = [];
} else {
    $placeholders = implode(',', array_fill(0, count($allowedCatIds), '?'));
    $itemSql = "SELECT * FROM items WHERE store_id = ? AND is_available = 1 AND category_id IN ($placeholders) ORDER BY is_featured DESC, sort_order, id";
    if ($maxItems !== -1) $itemSql .= ' LIMIT ' . $maxItems;
    $stmt = $pdo->prepare($itemSql);
    $stmt->execute(array_merge([$r['id']], $allowedCatIds));
    $allItems = $stmt->fetchAll();
}

// Strip discount / featured if plan forbids
if (!canDo($r, 'use_discount'))   foreach ($allItems as &$it) { $it['old_price'] = null; } unset($it);
if (!canDo($r, 'feature_items')) foreach ($allItems as &$it) { $it['is_featured'] = 0; } unset($it);

// Attach multi-media gallery (MAX plan only)
// Each item gets a `media` array of { type, path } ordered by sort_order.
if (canDo($r, 'multiple_media') && $allItems) {
    $itemIds = array_column($allItems, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    // is_cover DESC ensures the user-chosen cover image appears first in each item's media array —
    // this becomes the menu card thumbnail and the default slide in any carousel.
    $mediaStmt = $pdo->prepare("SELECT item_id, media_type, file_path FROM item_media WHERE store_id = ? AND item_id IN ($placeholders) ORDER BY item_id, is_cover DESC, sort_order, id");
    $mediaStmt->execute(array_merge([$r['id']], $itemIds));
    $mediaByItem = [];
    foreach ($mediaStmt->fetchAll() as $m) {
        $mediaByItem[(int) $m['item_id']][] = [
            'type' => $m['media_type'],
            'path' => $m['file_path'],
        ];
    }
    foreach ($allItems as &$it) {
        $it['media'] = $mediaByItem[(int) $it['id']] ?? [];
    }
    unset($it);
} else {
    foreach ($allItems as &$it) { $it['media'] = []; } unset($it);
}

// Resolve a thumbnail URL for each item:
// Priority on MAX plan: the user-chosen cover from item_media (first in array due to is_cover DESC ordering)
// — this lets MAX users control which gallery image is the "menu face". Falls back to legacy `image` field.
// Non-MAX plans have empty `media`, so they just use the legacy `image`.
// If only videos are present, thumb_url stays null and the card shows the empty-state icon.
foreach ($allItems as &$it) {
    $it['thumb_url'] = null;
    // First pass: try media (only populated on MAX plan; first entry is the cover)
    if (!empty($it['media'])) {
        foreach ($it['media'] as $m) {
            if (($m['type'] ?? '') === 'image') {
                $it['thumb_url'] = BASE_URL . '/assets/uploads/media/' . $m['path'];
                break;
            }
        }
    }
    // Fallback: legacy single-image field (non-MAX plans, or MAX items with only videos / no media yet)
    if (!$it['thumb_url'] && !empty($it['image'])) {
        $it['thumb_url'] = BASE_URL . '/assets/uploads/items/' . $it['image'];
    }
}
unset($it);

// Group items by category
$itemsByCategory = [];
foreach ($allItems as $it) {
    $itemsByCategory[$it['category_id']][] = $it;
}

$featured = array_filter($allItems, fn($i) => $i['is_featured']);
$primary = $r['primary_color'] ?: '#059669';

// Sector-aware labels for the public page (defaults to restaurant if biz type missing).
$publicLabelItem = bizLabel($r, 'singular');    // e.g. "صنف" / "منتج" / "موديل"
$publicLabelItems = bizLabel($r, 'plural');     // e.g. "الأصناف" / "المنتجات"
$publicLabelCat = bizLabel($r, 'category');     // e.g. "القسم" / "الفئة"
$publicLabelCats = bizLabel($r, 'categories');  // plural
$publicOrderVerb = bizLabel($r, 'order_verb');  // "أطلب" (food) / "استفسر" (cars/electronics)
$publicSchema = bizFieldsSchema($r);            // for rendering per-item specs
// Fast lookup: schema field key → {label, type, options}
$publicSchemaByKey = [];
foreach (($publicSchema['fields'] ?? []) as $__sf) {
    if (!empty($__sf['key'])) $publicSchemaByKey[$__sf['key']] = $__sf;
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="<?= e($primary) ?>">
    <title><?= e($r['name']) ?></title>
    <meta name="description" content="<?= e($r['description'] ?: $publicLabelItems . ' ' . $r['name']) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= e($r['name']) ?>">
    <meta property="og:description" content="<?= e($r['description']) ?>">
    <?php if ($r['logo']): ?>
        <meta property="og:image" content="<?= BASE_URL ?>/assets/uploads/logos/<?= e($r['logo']) ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mobile.css?v=<?= @filemtime(__DIR__ . '/../assets/css/mobile.css') ?: 1 ?>">
    <style>
        :root {
            --primary: <?= e($primary) ?>;
            --primary-soft: color-mix(in srgb, var(--primary) 8%, white);
            --primary-tint: color-mix(in srgb, var(--primary) 15%, white);
            --primary-glow: color-mix(in srgb, var(--primary) 35%, transparent);
            --primary-dark: color-mix(in srgb, var(--primary) 80%, black);
        }
        * { -webkit-tap-highlight-color: transparent; }
        *:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 4px; }
        html { scroll-behavior: smooth; scroll-padding-top: 140px; }

        /* ===== Custom Scrollbar — branded with store color ===== */
        /* Firefox */
        html {
            scrollbar-color: var(--primary) rgba(0,0,0,0.04);
            scrollbar-width: thin;
        }
        /* Webkit (Chrome, Edge, Safari) */
        ::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.03);
            border-radius: 999px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 999px;
            border: 2px solid rgba(255,255,255,0.6);
            background-clip: padding-box;
            box-shadow: 0 0 12px var(--primary-glow), inset 0 0 4px rgba(255,255,255,0.3);
            transition: all .2s;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 100%);
            box-shadow: 0 0 18px var(--primary), inset 0 0 6px rgba(255,255,255,0.5);
            border-width: 1px;
        }
        ::-webkit-scrollbar-thumb:active {
            background: var(--primary);
            box-shadow: 0 0 24px var(--primary), 0 0 4px var(--primary-glow);
        }
        ::-webkit-scrollbar-corner { background: transparent; }

        /* Sparkles that fly off the scrollbar on scroll — soft & subtle */
        .scroll-sparkle {
            position: fixed;
            pointer-events: none;
            z-index: 99999;
            font-size: 11px;
            color: var(--primary);
            opacity: 0.55;
            text-shadow: 0 0 4px var(--primary-glow);
            animation-name: sparkleFly;
            animation-duration: 1400ms;
            animation-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
            animation-fill-mode: forwards;
            will-change: transform, opacity;
            user-select: none;
            line-height: 1;
        }
        @keyframes sparkleFly {
            0%   { transform: translate(0, 0) scale(0)    rotate(0deg);   opacity: 0; }
            20%  { transform: translate(0, 0) scale(0.9)  rotate(40deg);  opacity: 0.55; }
            100% { transform: translate(-45px, -18px) scale(0.15) rotate(360deg); opacity: 0; }
        }
        [dir="rtl"] .scroll-sparkle {
            animation-name: sparkleFlyRTL;
        }
        @keyframes sparkleFlyRTL {
            0%   { transform: translate(0, 0) scale(0)    rotate(0deg);   opacity: 0; }
            20%  { transform: translate(0, 0) scale(0.9)  rotate(40deg);  opacity: 0.55; }
            100% { transform: translate(45px, -18px) scale(0.15) rotate(360deg); opacity: 0; }
        }
        body {
            font-family: 'Cairo', system-ui, sans-serif;
            background:
                radial-gradient(1200px 600px at 0% -200px, var(--primary-tint), transparent 60%),
                radial-gradient(800px 400px at 100% -100px, rgba(8,145,178,0.06), transparent 60%),
                #fafafa;
            color: #0f172a;
            overscroll-behavior-y: contain;
            font-feature-settings: "kern", "liga";
        }

        /* Shadow system */
        .shadow-soft { box-shadow: 0 2px 20px -4px rgba(15,23,42,0.06); }
        .shadow-elev { box-shadow: 0 4px 12px -2px rgba(15,23,42,0.06), 0 20px 40px -10px rgba(15,23,42,0.08); }
        .shadow-glow { box-shadow: 0 10px 30px -6px var(--primary-glow); }

        /* Primary utilities */
        .glass { backdrop-filter: blur(24px) saturate(180%); -webkit-backdrop-filter: blur(24px) saturate(180%); background: rgba(255,255,255,0.7); }
        .gradient-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
        .text-primary { color: var(--primary); }
        .bg-primary { background: var(--primary); }
        .bg-primary-soft { background: var(--primary-soft); }
        .border-primary { border-color: var(--primary); }

        /* Cover wrap — natural aspect ratio image */
        .cover-wrap {
            min-height: 180px; /* مساحة كافية للـ stats حتى لو الصورة قصيرة جداً */
        }
        .cover-wrap img {
            display: block;
            width: 100%;
            height: auto;
        }

        /* Cover gradient */
        .cover-gradient {
            background: linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,0.2) 40%, rgba(0,0,0,0.75) 100%);
        }

        /* Category tab */
        .cat-tab {
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            min-height: 40px;
            line-height: 1;
            flex-shrink: 0 !important;
            width: max-content !important;
            box-sizing: border-box !important;
        }
        .cat-tab:hover { transform: translateY(-1px); border-color: var(--primary); }
        .cat-tab.active {
            background: var(--primary);
            color: white !important;
            box-shadow: 0 6px 20px var(--primary-glow);
            transform: translateY(-1px);
            border-color: var(--primary);
        }
        .cat-tab.active::after {
            content: ''; position: absolute; bottom: -10px; left: 50%; transform: translateX(-50%);
            width: 6px; height: 6px; border-radius: 50%; background: var(--primary);
        }
        /* Count badge in category tab */
        .cat-tab .cat-count {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            font-size: 10px;
            font-weight: 800;
            border-radius: 999px;
            background: rgba(0,0,0,0.08);
            color: #475569;
            line-height: 1;
            position: static !important;
            top: auto !important;
            left: auto !important;
            right: auto !important;
            transform: none !important;
            flex-shrink: 0;
            align-self: center;
            vertical-align: middle;
        }
        .cat-tab.active .cat-count {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        /* Item card — premium polished */
        .item-card {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s, border-color 0.4s;
            will-change: transform;
            border: 1px solid rgba(15, 23, 42, 0.05);
            background: linear-gradient(180deg, #ffffff 0%, #fdfdfd 100%);
            position: relative;
        }
        .item-card::before {
            content: '';
            position: absolute; inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, color-mix(in srgb, var(--primary) 35%, transparent), transparent 40%);
            /* -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); */
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            opacity: 0; transition: opacity .4s;
            pointer-events: none;
        }
        .item-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 30px 60px -15px color-mix(in srgb, var(--primary) 18%, transparent),
                        0 20px 40px -20px rgba(15,23,42,0.15);
            border-color: transparent;
        }
        .item-card:hover::before { opacity: 1; }
        .item-card:active { transform: scale(0.98); }
        .item-card .img-wrap {
            position: relative; overflow: hidden;
            background: linear-gradient(135deg, var(--primary-soft), #fafafa);
        }
        .item-card .img-wrap::after {
            content: '';
            position: absolute; inset: 0;
            box-shadow: inset 0 -40px 60px -30px rgba(0,0,0,0.08);
            pointer-events: none;
        }
        .item-card .img-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(180deg, transparent 55%, rgba(0,0,0,0.45));
            opacity: 0; transition: opacity .35s;
            pointer-events: none;
            z-index: 1;
        }
        .item-card:hover .img-overlay { opacity: 1; }
        .item-card .peek-hint {
            position: absolute; bottom: 10px; left: 10px;
            background: rgba(255,255,255,0.95);
            color: var(--primary-dark);
            font-size: 11px; font-weight: 800;
            padding: 5px 10px; border-radius: 999px;
            display: flex; align-items: center; gap: 4px;
            opacity: 0; transform: translateY(8px);
            transition: all .35s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            backdrop-filter: blur(8px);
            z-index: 2;
        }
        .item-card:hover .peek-hint { opacity: 1; transform: translateY(0); }
        .item-card .item-title-wrap { position: relative; }
        .item-card .item-title-wrap::after {
            content: ''; position: absolute; bottom: -4px; right: 0;
            width: 24px; height: 2px; border-radius: 999px;
            background: linear-gradient(90deg, var(--primary), transparent);
            opacity: 0; transition: all .4s;
        }
        .item-card:hover .item-title-wrap::after { opacity: 1; width: 40px; }
        .empty-img-wrap {
            width: 100%; height: 100%;
            background: linear-gradient(135deg, var(--primary-soft), var(--primary-tint));
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .empty-img-wrap::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                radial-gradient(circle at 25% 30%, rgba(255,255,255,0.5), transparent 50%),
                radial-gradient(circle at 75% 70%, rgba(255,255,255,0.3), transparent 50%);
        }

        /* Scrollbar hiding */
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

        /* Price badge — premium visual with subtle shine */
        .price-badge {
            background: linear-gradient(135deg, var(--primary-soft), var(--primary-tint));
            color: var(--primary-dark);
            font-weight: 900;
            letter-spacing: -0.01em;
            border: 1px solid color-mix(in srgb, var(--primary) 22%, transparent);
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px color-mix(in srgb, var(--primary) 12%, transparent);
        }
        .price-badge::before {
            content: '';
            position: absolute; top: 0; right: -100%;
            width: 60%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5), transparent);
            transition: right .6s ease;
        }
        .item-card:hover .price-badge::before { right: 140%; }
        .price-old {
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            text-decoration: line-through;
            text-decoration-color: color-mix(in srgb, #ef4444 70%, transparent);
            text-decoration-thickness: 1.5px;
        }

        /* ===== Item Modal — premium redesign ===== */
        .item-modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px) saturate(120%);
            -webkit-backdrop-filter: blur(8px) saturate(120%);
            z-index: 50;
            opacity: 0; transition: opacity .3s ease;
        }
        .item-modal-backdrop.visible { opacity: 1; }
        .modal-enter {
            animation: modalIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.94) translateY(40px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* Modal hero (image area) */
        .modal-hero {
            position: relative;
            background: linear-gradient(135deg, var(--primary-soft), var(--primary-tint));
            overflow: hidden;
        }
        .modal-hero-img {
            width: 100%;
            aspect-ratio: 4/3;
            object-fit: cover;
            display: block;
        }
        @media (max-width: 640px) {
            .modal-hero-img { aspect-ratio: 16/10; }
        }
        .modal-hero-gradient {
            position: absolute; inset: 0;
            background: linear-gradient(180deg,
                rgba(0,0,0,0.35) 0%,
                transparent 30%,
                transparent 55%,
                rgba(0,0,0,0.5) 100%);
            pointer-events: none;
        }
        .modal-empty-hero {
            width: 100%;
            aspect-ratio: 16/10;
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .modal-empty-hero::before {
            content: '';
            position: absolute; inset: 0;
            background-image:
                radial-gradient(circle at 25% 30%, rgba(255,255,255,0.5), transparent 50%),
                radial-gradient(circle at 75% 70%, rgba(255,255,255,0.3), transparent 50%);
        }

        /* Gallery carousel (MAX plan multi-media) */
        .modal-gallery {
            position: relative;
            width: 100%;
            aspect-ratio: 4/3;
            overflow: hidden;
        }
        @media (max-width: 640px) {
            .modal-gallery { aspect-ratio: 16/10; }
        }
        .modal-gallery-track {
            display: flex;
            width: 100%;
            height: 100%;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .modal-gallery-track::-webkit-scrollbar { display: none; }
        .modal-gallery-slide {
            flex: 0 0 100%;
            width: 100%;
            height: 100%;
            scroll-snap-align: start;
            scroll-snap-stop: always;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
        }
        .modal-gallery-slide > img,
        .modal-gallery-slide > video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .modal-gallery-slide > video { background: #000; }
        .modal-gallery-dots {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(0,0,0,0.35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 4;
            pointer-events: none;
        }
        .modal-gallery-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: rgba(255,255,255,0.45);
            transition: all 0.3s;
        }
        .modal-gallery-dot.active {
            background: #fff;
            width: 22px;
            border-radius: 5px;
        }
        .modal-gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px; height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,0.9);
            color: #0f172a;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 4;
            box-shadow: 0 4px 16px rgba(0,0,0,0.25);
            border: 0;
        }
        .modal-gallery-nav:hover { background: #fff; }
        .modal-gallery-nav:disabled { opacity: 0.35; cursor: default; }
        .modal-gallery-nav.prev { right: 12px; }   /* RTL: "prev" sits on right */
        .modal-gallery-nav.next { left: 12px; }
        @media (hover: hover) and (pointer: fine) {
            .modal-gallery-nav { display: flex; }
        }
        .modal-gallery-badge {
            position: absolute;
            top: 12px; left: 12px;
            background: rgba(0,0,0,0.7);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            z-index: 3;
            backdrop-filter: blur(8px);
        }

        /* Modal action buttons (top) */
        .modal-top-actions {
            position: absolute; top: 16px; right: 16px; left: 16px;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 5;
        }
        .modal-icon-btn {
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: flex; align-items: center; justify-content: center;
            color: #0f172a;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            transition: transform .2s, background .2s, color .2s;
            cursor: pointer;
        }
        .modal-icon-btn:hover { transform: scale(1.08); background: white; }
        .modal-icon-btn:active { transform: scale(0.92); }
        .modal-icon-btn.fav-active {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        .modal-icon-btn.fav-active svg { fill: white; stroke: white; }
        .modal-right-actions { display: flex; gap: 8px; }

        /* Featured badge inside modal */
        .modal-featured-badge {
            position: absolute; bottom: 16px; right: 16px;
            background: linear-gradient(135deg, #fbbf24, #f97316);
            color: white;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 12px;
            display: inline-flex; align-items: center; gap: 6px;
            box-shadow: 0 8px 20px rgba(245,158,11,0.4);
            z-index: 3;
        }

        /* Discount savings banner */
        .modal-savings-banner {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px;
            background: linear-gradient(90deg, #fef2f2, #fff1f2);
            border: 1px solid #fecaca;
            border-radius: 14px;
            color: #b91c1c;
            font-weight: 800;
            font-size: 13px;
        }
        .modal-savings-banner .savings-icon {
            width: 32px; height: 32px; border-radius: 10px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(239,68,68,0.3);
        }

        /* Modal body */
        .modal-body {
            padding: 24px;
        }
        @media (min-width: 768px) {
            .modal-body { padding: 28px; }
        }
        .modal-title {
            font-size: 24px;
            font-weight: 900;
            line-height: 1.2;
            color: #0f172a;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }
        @media (min-width: 768px) {
            .modal-title { font-size: 28px; }
        }
        .modal-subtitle-row {
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 14px;
            font-size: 12px;
            color: #64748b;
        }
        .modal-subtitle-row .dot {
            width: 4px; height: 4px; border-radius: 50%;
            background: #cbd5e1;
        }
        .modal-desc {
            color: #475569;
            line-height: 1.75;
            font-size: 14px;
            margin-bottom: 20px;
            padding: 14px 16px;
            background: #f8fafc;
            border-radius: 14px;
            border-right: 3px solid var(--primary);
        }

        /* Sector-specific specs (cars year, phone storage, clothing sizes, etc.) */
        .modal-specs {
            margin-bottom: 20px;
            padding: 14px 16px;
            background: #f8fafc;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
        }
        .specs-title {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .specs-title::before {
            content: '';
            width: 3px;
            height: 14px;
            background: var(--primary);
            border-radius: 2px;
        }
        .specs-list { display: flex; flex-direction: column; gap: 8px; }
        .spec-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .spec-row:last-child { border-bottom: 0; }
        .spec-label {
            font-size: 13px;
            color: #64748b;
            flex-shrink: 0;
        }
        .spec-value {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            text-align: left;
        }
        .spec-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            justify-content: flex-end;
        }
        .spec-chip {
            display: inline-block;
            padding: 2px 8px;
            background: white;
            border: 1px solid var(--primary);
            color: var(--primary);
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Price display */
        .modal-price-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            background: linear-gradient(135deg, var(--primary-soft), var(--primary-tint));
            border: 1px solid color-mix(in srgb, var(--primary) 18%, transparent);
            border-radius: 18px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        .modal-price-row::before {
            content: '';
            position: absolute;
            width: 120px; height: 120px;
            border-radius: 50%;
            background: color-mix(in srgb, var(--primary) 12%, transparent);
            top: -40px; left: -40px;
        }
        .modal-price-label {
            font-size: 11px;
            font-weight: 800;
            color: var(--primary-dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.7;
            margin-bottom: 2px;
        }
        .modal-price-main {
            font-size: 28px;
            font-weight: 900;
            color: var(--primary-dark);
            line-height: 1;
            letter-spacing: -0.02em;
        }
        .modal-price-old {
            font-size: 13px;
            color: #94a3b8;
            text-decoration: line-through;
            text-decoration-color: #ef4444;
            font-weight: 700;
            margin-top: 4px;
        }
        .modal-price-discount-pill {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            font-weight: 900;
            font-size: 14px;
            padding: 8px 14px;
            border-radius: 14px;
            box-shadow: 0 6px 16px rgba(239,68,68,0.35);
            position: relative;
            z-index: 1;
        }

        /* Qty selector */
        .modal-qty-section {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            padding: 14px 16px;
            background: #f8fafc;
            border-radius: 16px;
        }
        .modal-qty-section .qty-label {
            font-size: 13px;
            font-weight: 800;
            color: #334155;
        }
        .modal-qty-controls {
            display: flex; align-items: center; gap: 4px;
            background: white;
            padding: 4px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(15,23,42,0.06);
        }
        .modal-qty-btn {
            width: 34px; height: 34px; border-radius: 10px;
            background: #f1f5f9;
            display: flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: 18px;
            color: #334155;
            transition: all .15s;
            cursor: pointer;
        }
        .modal-qty-btn:hover:not(:disabled) {
            background: var(--primary);
            color: white;
        }
        .modal-qty-btn:disabled {
            opacity: 0.4; cursor: not-allowed;
        }
        .modal-qty-value {
            min-width: 40px; text-align: center;
            font-weight: 900; font-size: 16px;
            color: #0f172a;
        }

        /* CTA row */
        .modal-cta-row {
            display: flex; gap: 10px;
            flex-wrap: wrap;
        }
        .modal-cta-add {
            flex: 1;
            min-width: 140px;
            display: flex; align-items: center; justify-content: center;
            gap: 8px;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            font-weight: 900; font-size: 15px;
            border-radius: 14px;
            box-shadow: 0 8px 20px color-mix(in srgb, var(--primary) 40%, transparent);
            transition: all .2s;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        .modal-cta-add::before {
            content: '';
            position: absolute; top: 0; right: -100%;
            width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: right .6s;
        }
        .modal-cta-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px color-mix(in srgb, var(--primary) 55%, transparent);
        }
        .modal-cta-add:hover::before { right: 140%; }
        .modal-cta-add:active { transform: scale(0.98); }
        .modal-cta-whatsapp {
            flex: 1;
            min-width: 140px;
            display: flex; align-items: center; justify-content: center;
            gap: 8px;
            padding: 14px 20px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            font-weight: 900; font-size: 15px;
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(34,197,94,0.35);
            transition: all .2s;
            text-decoration: none;
        }
        .modal-cta-whatsapp:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(34,197,94,0.5);
        }
        .modal-cta-whatsapp:active { transform: scale(0.98); }

        /* Total preview */
        .modal-total-preview {
            display: flex; align-items: baseline; gap: 6px;
            font-size: 13px;
            color: #64748b;
        }
        .modal-total-preview .total-val {
            font-size: 18px;
            font-weight: 900;
            color: var(--primary-dark);
        }

        .animate-fade-up { animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Section heading decorative line */
        .section-title {
            display: flex; align-items: center; gap: 12px;
            position: relative;
        }
        .section-title::after {
            content: ''; flex: 1; height: 2px;
            background: linear-gradient(90deg, color-mix(in srgb, var(--primary) 30%, transparent), transparent);
            border-radius: 999px;
            margin-right: 8px;
        }

        /* Category icon badge */
        .cat-icon-wrap {
            width: 48px; height: 48px; border-radius: 14px;
            background: linear-gradient(135deg, var(--primary-soft), white);
            border: 1px solid color-mix(in srgb, var(--primary) 15%, transparent);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 26px;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--primary) 15%, transparent);
        }

        /* Stat badge */
        .stat-pill {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        @media (max-width: 480px) {
            .stat-pill {
                font-size: 10.5px;
                padding: 3px 9px;
            }
        }

        @supports not (background: color-mix(in srgb, red, blue)) {
            .price-badge { background: #f0fdf4; color: #059669; }
            .cat-tab.active { box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
            body { background: #fafafa; }
        }

        /* ===== Interactive enhancements ===== */

        /* Item card extras */
        .fav-btn {
            width: 34px; height: 34px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            transition: transform .2s, background .2s;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            color: #64748b;
        }
        .fav-btn:hover { transform: scale(1.1); color: #ef4444; }
        .fav-btn:active { transform: scale(0.9); }
        .fav-btn.active { background: linear-gradient(135deg, #ef4444, #dc2626); color: white; box-shadow: 0 4px 12px rgba(239,68,68,0.4); }
        .fav-btn.active svg { fill: white; stroke: white; }

        .add-btn {
            width: 38px; height: 38px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            transition: transform .2s, box-shadow .25s, filter .2s;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--primary) 35%, transparent);
            position: relative;
            overflow: hidden;
        }
        .add-btn::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.25), transparent 50%);
            opacity: 0; transition: opacity .2s;
        }
        .add-btn:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 20px color-mix(in srgb, var(--primary) 50%, transparent);
        }
        .add-btn:hover::after { opacity: 1; }
        .add-btn:active { transform: scale(0.92); }
        .add-btn.added { animation: pulsePop .45s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes pulsePop {
            0%{transform:scale(1)}
            40%{transform:scale(1.3) rotate(8deg)}
            70%{transform:scale(0.95) rotate(-4deg)}
            100%{transform:scale(1) rotate(0)}
        }

        /* Cart FAB */
        .cart-fab {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
            z-index: 40;
            background: var(--primary);
            color: white;
            border-radius: 999px;
            padding: 12px 20px;
            font-weight: 800;
            box-shadow: 0 10px 30px color-mix(in srgb, var(--primary) 40%, transparent);
            display: flex; align-items: center; gap: 10px;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0; pointer-events: none;
            transform: translate(-50%, 20px);
        }
        .cart-fab.visible { opacity: 1; pointer-events: auto; transform: translate(-50%, 0); }
        .cart-fab:hover { filter: brightness(1.08); }
        .cart-fab-badge {
            background: white; color: var(--primary);
            border-radius: 999px; padding: 2px 10px;
            font-size: 13px; font-weight: 900;
        }

        /* Cart drawer */
        .cart-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 50; opacity: 0; pointer-events: none;
            transition: opacity .25s;
        }
        .cart-backdrop.open { opacity: 1; pointer-events: auto; }

        /* ───── PRE-SEND ORDER MODAL (MAX plan) ───── */
        .pre-send-backdrop {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 100; opacity: 0; pointer-events: none;
            display: flex; align-items: flex-end; justify-content: center;
            transition: opacity .25s;
        }
        .pre-send-backdrop.open { opacity: 1; pointer-events: auto; }
        @media (min-width: 640px) {
            .pre-send-backdrop { align-items: center; padding: 20px; }
        }
        .pre-send-dialog {
            background: #fff;
            width: 100%; max-width: 520px;
            border-radius: 24px 24px 0 0;
            max-height: 90vh; overflow-y: auto;
            transform: translateY(100%);
            transition: transform .3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 -20px 60px rgba(0,0,0,0.3);
        }
        @media (min-width: 640px) {
            .pre-send-dialog { border-radius: 24px; transform: translateY(30px) scale(.96); }
        }
        .pre-send-backdrop.open .pre-send-dialog { transform: translateY(0) scale(1); }
        .pre-send-head {
            padding: 24px 20px 16px;
            background: linear-gradient(135deg, #059669, #0d9488);
            color: #fff; border-radius: 24px 24px 0 0;
        }
        @media (min-width: 640px) {
            .pre-send-head { border-radius: 24px 24px 0 0; }
        }
        .pre-send-title { font-size: 18px; font-weight: 800; margin-bottom: 4px; }
        .pre-send-subtitle { font-size: 13px; opacity: .85; line-height: 1.6; }
        .pre-send-item {
            margin-top: 14px; padding: 10px 14px;
            background: rgba(255,255,255,0.15); backdrop-filter: blur(6px);
            border-radius: 12px; display: flex; flex-wrap: wrap;
            align-items: center; gap: 8px; font-size: 13px;
        }
        .pre-send-item-label { opacity: .75; }
        .pre-send-item-name { font-weight: 700; flex: 1; min-width: 0; }
        .pre-send-item-price {
            background: rgba(255,255,255,0.2); padding: 2px 10px;
            border-radius: 99px; font-weight: 700; font-size: 12px;
        }
        .pre-send-fields {
            padding: 20px;
            display: flex; flex-direction: column; gap: 12px;
        }
        .pre-send-field { display: flex; flex-direction: column; gap: 6px; }
        .pre-send-field-label {
            font-size: 13px; font-weight: 600; color: #374151;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .pre-send-field input, .pre-send-field textarea {
            padding: 10px 14px; border: 2px solid #e5e7eb; border-radius: 12px;
            font-size: 14px; font-family: inherit; transition: border-color .2s;
            background: #f9fafb;
        }
        .pre-send-field input:focus, .pre-send-field textarea:focus {
            outline: none; border-color: #059669; background: #fff;
        }
        .pre-send-field textarea { resize: vertical; min-height: 60px; }
        .pre-send-actions {
            padding: 0 20px 20px; display: flex; gap: 10px;
        }
        .pre-send-cancel, .pre-send-submit {
            flex: 1; padding: 12px; border-radius: 14px; font-weight: 700;
            font-size: 14px; cursor: pointer; border: none;
            transition: transform .1s, box-shadow .2s;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        }
        .pre-send-cancel { background: #f3f4f6; color: #374151; }
        .pre-send-cancel:hover { background: #e5e7eb; }
        .pre-send-submit {
            background: linear-gradient(135deg, #22c55e, #059669);
            color: #fff; box-shadow: 0 6px 20px rgba(5,150,105,.35);
        }
        .pre-send-submit:hover { box-shadow: 0 10px 28px rgba(5,150,105,.5); }
        .pre-send-submit:active, .pre-send-cancel:active { transform: scale(.97); }
        .cart-drawer {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: white; border-radius: 24px 24px 0 0;
            z-index: 51; max-height: 85vh; overflow: hidden;
            display: flex; flex-direction: column;
            transform: translateY(100%);
            transition: transform .3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 -20px 60px rgba(0,0,0,0.2);
        }
        .cart-drawer.open { transform: translateY(0); }
        @media (min-width: 768px) {
            .cart-drawer { left: auto; right: 20px; bottom: 20px; width: 400px; max-height: 80vh; border-radius: 24px; }
        }
        .cart-item { animation: fadeUp .25s ease both; }

        /* Search bar */
        .search-wrap {
            position: sticky; top: 0; z-index: 25;
            background: rgba(250, 250, 250, 0.95);
            backdrop-filter: blur(16px);
            padding: 10px 0;
            transition: transform .2s;
        }

        /* Scroll reveal */
        .reveal { opacity: 0; transform: translateY(24px); transition: opacity .55s ease, transform .55s ease; }
        .reveal.in { opacity: 1; transform: translateY(0); }

        /* Toast — must sit above EVERY modal/backdrop (cart, item, pre-send, etc.)
           so validation messages like "يرجى تعبئة رقم الهاتف" stay visible when
           the pre-send modal (z:100) is open. */
        .toast {
            position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%) translateY(20px);
            background: #0f172a; color: white; padding: 12px 22px;
            border-radius: 999px; font-weight: 700; font-size: 14px;
            z-index: 9999; opacity: 0; pointer-events: none;
            transition: all .3s;
            box-shadow: 0 12px 34px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.08);
            max-width: calc(100vw - 32px);
            text-align: center;
            line-height: 1.5;
        }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        /* When a modal is active the toast floats to the top so it's clearly
           above the blurred backdrop (which sits at align-items:flex-end on
           mobile, covering the default bottom:90px toast position). */
        .pre-send-backdrop.open ~ .toast,
        body:has(.pre-send-backdrop.open) .toast {
            bottom: auto; top: 24px;
            transform: translateX(-50%) translateY(-20px);
        }
        .pre-send-backdrop.open ~ .toast.show,
        body:has(.pre-send-backdrop.open) .toast.show {
            transform: translateX(-50%) translateY(0);
        }

        /* Discount badge pulse */
        .badge-discount { animation: tilt 2s ease infinite; }
        @keyframes tilt { 0%,100% {transform: rotate(-6deg);} 50% {transform: rotate(6deg);} }

        /* Qty controls */
        .qty-btn { width: 28px; height: 28px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-weight: 800; transition: background .15s; }
        .qty-btn:hover { background: #e2e8f0; }
        .qty-btn:active { background: #cbd5e1; }

        /* Empty cart state */
        .empty-cart { text-align: center; padding: 40px 20px; color: #94a3b8; }
    </style>
</head>
<body class="min-h-screen pb-24">

<!-- Hero / Cover -->
<header class="relative">
    <div class="cover-wrap relative">
        <?php if ($r['cover']): ?>
            <!-- Cover image: full natural aspect ratio, never cropped -->
            <img src="<?= BASE_URL ?>/assets/uploads/covers/<?= e($r['cover']) ?>" class="block w-full h-auto" alt="<?= e($r['name']) ?>">
        <?php else: ?>
            <!-- No cover: gradient placeholder with fixed responsive height -->
            <div class="h-56 sm:h-72 md:h-96 relative gradient-primary overflow-hidden">
                <div class="absolute inset-0 opacity-30" style="background-image: radial-gradient(circle at 20% 50%, white 0%, transparent 50%), radial-gradient(circle at 80% 80%, white 0%, transparent 50%), radial-gradient(circle at 50% 0%, white 0%, transparent 60%);"></div>
                <div class="absolute top-8 right-8 w-24 h-24 rounded-full bg-white/10 blur-2xl"></div>
                <div class="absolute bottom-12 left-12 w-32 h-32 rounded-full bg-white/10 blur-3xl"></div>
            </div>
        <?php endif; ?>
        <div class="absolute inset-0 cover-gradient pointer-events-none"></div>

        <!-- Stats badges in hero -->
        <div class="absolute bottom-4 right-0 left-0 container max-w-5xl mx-auto px-4">
            <div class="flex gap-2 flex-wrap justify-center md:justify-start">
                <?php $totalCats = count(array_filter($cats, fn($c) => !empty($itemsByCategory[$c['id']]))); ?>
                <?php if ($totalCats > 0): ?>
                    <span class="stat-pill">📋 <?= $totalCats ?> <?= e($totalCats === 1 ? $publicLabelCat : $publicLabelCats) ?></span>
                <?php endif; ?>
                <?php if (count($allItems) > 0): ?>
                    <span class="stat-pill"><?= e(bizLabel($r, 'icon')) ?> <?= count($allItems) ?> <?= e(count($allItems) === 1 ? $publicLabelItem : $publicLabelItems) ?></span>
                <?php endif; ?>
                <?php if (count($featured) > 0): ?>
                    <span class="stat-pill">⭐ <?= count($featured) ?> مميّز</span>
                <?php endif; ?>
                <?php if ($r['working_hours']): ?>
                    <span class="stat-pill hidden sm:inline-flex">🕒 <?= e($r['working_hours']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Store Info Overlap -->
    <div class="container max-w-5xl mx-auto px-4 relative -mt-16 sm:-mt-20 md:-mt-24">
        <div class="glass rounded-3xl shadow-elev p-4 sm:p-5 md:p-7 flex items-center gap-3 sm:gap-4 md:gap-6 border border-white/50">
            <?php if ($r['logo']): ?>
                <div class="relative flex-shrink-0">
                    <div class="absolute -inset-1 gradient-primary rounded-2xl blur opacity-30"></div>
                    <img src="<?= BASE_URL ?>/assets/uploads/logos/<?= e($r['logo']) ?>" class="relative w-16 h-16 sm:w-20 sm:h-20 md:w-28 md:h-28 rounded-2xl object-cover shadow-xl border-2 border-white">
                </div>
            <?php else: ?>
                <div class="relative flex-shrink-0">
                    <div class="absolute -inset-1 gradient-primary rounded-2xl blur opacity-30"></div>
                    <div class="relative w-16 h-16 sm:w-20 sm:h-20 md:w-28 md:h-28 rounded-2xl gradient-primary flex items-center justify-center text-white font-black text-2xl sm:text-3xl md:text-5xl shadow-xl border-2 border-white">
                        <?= e(mb_substr($r['name'], 0, 1)) ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <h1 class="text-lg sm:text-xl md:text-3xl font-black text-gray-900 leading-tight mb-1.5 break-words"><?= e($r['name']) ?></h1>
                <?php if ($r['description']): ?>
                    <p class="text-[13px] sm:text-sm md:text-base text-gray-600 line-clamp-2 leading-relaxed"><?= e($r['description']) ?></p>
                <?php endif; ?>
                <?php if ($r['address']): ?>
                    <p class="text-[12px] sm:text-xs text-gray-500 mt-2 flex items-start gap-1.5 leading-snug">
                        <svg class="w-4 h-4 sm:w-3.5 sm:h-3.5 text-primary flex-shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span class="line-clamp-2"><?= e($r['address']) ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<!-- Quick Actions -->
<div class="container max-w-5xl mx-auto px-4 mt-5">
    <div class="flex items-center gap-2 overflow-x-auto scrollbar-hide py-1">
        <?php if ($r['whatsapp'] || $r['phone']): ?>
            <a href="https://wa.me/<?= e(preg_replace('/\D/','', $r['whatsapp'] ?: $r['phone'])) ?>" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gradient-to-br from-green-500 to-emerald-600 text-white font-bold text-sm whitespace-nowrap hover:shadow-lg hover:shadow-green-500/30 hover:-translate-y-0.5 transition">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                واتساب
            </a>
        <?php endif; ?>
        <?php if ($r['phone']): ?>
            <a href="tel:<?= e($r['phone']) ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-gray-100 text-gray-700 font-bold text-sm whitespace-nowrap hover:border-primary hover:text-primary hover:-translate-y-0.5 transition shadow-soft">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                اتصل
            </a>
        <?php endif; ?>
        <?php if ($r['address']): ?>
            <a href="https://maps.google.com/?q=<?= urlencode($r['address']) ?>" target="_blank" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white border border-gray-100 text-gray-700 font-bold text-sm whitespace-nowrap hover:border-primary hover:text-primary hover:-translate-y-0.5 transition shadow-soft">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                الموقع
            </a>
        <?php endif; ?>
        <?php if ($r['instagram']): ?>
            <a href="<?= e($r['instagram']) ?>" target="_blank" class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-gradient-to-br from-purple-500 via-pink-500 to-orange-500 text-white flex-shrink-0 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-pink-500/30 transition shadow-soft" aria-label="انستغرام">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
            </a>
        <?php endif; ?>
        <?php if ($r['facebook']): ?>
            <a href="<?= e($r['facebook']) ?>" target="_blank" class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-700 text-white flex-shrink-0 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-blue-500/30 transition shadow-soft" aria-label="فيسبوك">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </a>
        <?php endif; ?>

        <!-- Share -->
        <button type="button" onclick="shareMenu()" class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-white border border-gray-100 text-gray-700 hover:border-primary hover:text-primary hover:-translate-y-0.5 transition flex-shrink-0 shadow-soft" aria-label="مشاركة الصفحة">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
        </button>
    </div>
</div>

<!-- Search Bar -->
<div class="container max-w-5xl mx-auto px-4 mt-5">
    <div class="relative group">
        <div class="absolute inset-0 gradient-primary rounded-2xl blur opacity-0 group-focus-within:opacity-20 transition"></div>
        <div class="relative">
            <svg class="absolute right-5 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 group-focus-within:text-primary transition pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input id="searchInput" type="search" inputmode="search" placeholder="ابحث عن <?= e($publicLabelItem) ?>..."
                class="w-full pr-14 pl-14 py-4 rounded-2xl bg-white border border-gray-100 focus:border-primary transition shadow-soft outline-none text-base font-semibold placeholder:font-normal placeholder:text-gray-400">
            <button id="clearSearch" type="button" class="absolute left-5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-gray-100 hover:bg-red-50 hover:text-red-500 flex items-center justify-center hidden transition" aria-label="مسح">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <p id="searchCount" class="text-xs text-gray-500 mt-2 hidden px-2"></p>
</div>

<!-- Category Tabs (Sticky) -->
<?php if ($cats): ?>
<nav id="catNav" class="sticky top-0 z-30 glass border-b border-white/40 mt-6">
    <div class="container max-w-5xl mx-auto px-4 py-3">
        <div class="flex gap-2 overflow-x-auto scrollbar-hide">
            <?php foreach ($cats as $i => $cat): ?>
                <?php if (!empty($itemsByCategory[$cat['id']])):
                    $count = count($itemsByCategory[$cat['id']]);
                ?>
                    <a href="#cat-<?= $cat['id'] ?>" data-cat-id="<?= $cat['id'] ?>" class="cat-tab <?= $i === 0 ? 'active' : '' ?> inline-flex items-center gap-2 px-3 sm:px-4 py-2.5 rounded-xl bg-white border border-gray-100 text-gray-700 font-bold text-sm whitespace-nowrap">
                        <?= renderCategoryIcon($cat['icon'], $r['biz_code'] ?? 'restaurant', 'w-5 h-5', $cat['name']) ?>
                        <span><?= e($cat['name']) ?></span>
                        <span class="cat-count"><?= $count ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
<?php endif; ?>

<!-- Featured Section -->
<?php if ($featured): ?>
<section class="container max-w-5xl mx-auto px-4 mt-8 relative">
    <!-- Decorative background accent -->
    <div class="absolute -top-4 right-1/2 translate-x-1/2 w-64 h-32 gradient-primary rounded-full blur-3xl opacity-10 pointer-events-none"></div>

    <h2 class="section-title text-lg md:text-xl font-black text-gray-900 mb-5 relative">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 text-white text-lg shadow-lg shadow-amber-500/30">⭐</span>
        <span><?= e($publicLabelItems) ?> المميزة</span>
    </h2>
    <div class="flex gap-4 overflow-x-auto scrollbar-hide snap-x snap-mandatory pb-2">
        <?php foreach ($featured as $item): ?>
        <article data-item-id="<?= (int) $item['id'] ?>" data-item-name="<?= e(mb_strtolower($item['name'])) ?>" data-item-desc="<?= e(mb_strtolower($item['description'] ?? '')) ?>" class="item-card searchable snap-start flex-shrink-0 w-52 md:w-64 rounded-3xl shadow-soft overflow-hidden group relative">
            <div onclick='showItem(<?= json_encode($item, JSON_UNESCAPED_UNICODE) ?>)' class="img-wrap aspect-square cursor-pointer">
                <?php if ($item['thumb_url']): ?>
                    <img src="<?= e($item['thumb_url']) ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-700" loading="lazy" alt="<?= e($item['name']) ?>">
                    <div class="img-overlay"></div>
                <?php else: ?>
                    <div class="empty-img-wrap">
                        <svg class="w-16 h-16 relative" fill="none" viewBox="0 0 24 24" style="color: var(--primary); opacity: 0.55;">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4" d="M3 11h18M5 11V9a7 7 0 0114 0v2M4 11h16l-1.5 8a2 2 0 01-2 1.7H7.5a2 2 0 01-2-1.7L4 11zM9 14v3M12 14v3M15 14v3"/>
                            <circle cx="12" cy="5.5" r="0.8" fill="currentColor"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <span class="absolute top-2 right-2 bg-gradient-to-br from-amber-400 to-orange-500 text-white text-[11px] px-2 py-1 rounded-lg font-black shadow-lg shadow-amber-500/40 flex items-center gap-0.5 z-10">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.5 7h7l-5.5 4.5L18 21l-6-4.5L6 21l2-7.5L2.5 9h7z"/></svg>
                    مميز
                </span>
                <button type="button" data-fav="<?= (int) $item['id'] ?>" class="fav-btn absolute top-2 left-2 z-10" aria-label="إضافة للمفضلة">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                </button>
            </div>
            <div class="p-3">
                <h3 class="font-black text-sm line-clamp-1 text-gray-900 group-hover:text-primary transition leading-tight"><?= e($item['name']) ?></h3>
                <div class="flex items-center justify-between mt-2.5 gap-2 pt-2 border-t border-gray-100">
                    <span class="price-badge px-2.5 py-1 rounded-lg text-sm"><?= formatPrice($item['price'], $r['currency']) ?></span>
                    <button type="button" class="add-btn" data-add='<?= safe_html(json_encode(["id"=>(int)$item["id"],"name"=>$item["name"],"price"=>(float)$item["price"]], JSON_UNESCAPED_UNICODE)) ?>' aria-label="إضافة للسلة">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    </button>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Categories + Items -->
<main class="container max-w-5xl mx-auto px-4 mt-8 space-y-10">
    <?php foreach ($cats as $cat): ?>
        <?php if (empty($itemsByCategory[$cat['id']])) continue; ?>
        <section id="cat-<?= $cat['id'] ?>" class="scroll-mt-32">
            <div class="section-title mb-6">
                <span class="cat-icon-wrap"><?= renderCategoryIcon($cat['icon'], $r['biz_code'] ?? 'restaurant', 'w-9 h-9', $cat['name']) ?></span>
                <div>
                    <h2 class="text-xl md:text-2xl font-black text-gray-900 leading-tight"><?= e($cat['name']) ?></h2>
                    <p class="text-xs text-gray-500 font-semibold mt-0.5"><?= count($itemsByCategory[$cat['id']]) ?> <?= e($publicLabelItem) ?></p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5">
                <?php foreach ($itemsByCategory[$cat['id']] as $item):
                    $hasDiscount = $item['old_price'] && $item['old_price'] > $item['price'];
                    $discountPct = $hasDiscount ? round((($item['old_price'] - $item['price']) / $item['old_price']) * 100) : 0;
                ?>
                <article data-item-id="<?= (int) $item['id'] ?>" data-item-name="<?= e(mb_strtolower($item['name'])) ?>" data-item-desc="<?= e(mb_strtolower($item['description'] ?? '')) ?>" class="item-card searchable reveal rounded-3xl shadow-soft overflow-hidden group relative">
                    <div class="flex sm:flex-col">
                        <div onclick='showItem(<?= json_encode($item, JSON_UNESCAPED_UNICODE) ?>)' class="img-wrap w-28 sm:w-full sm:aspect-[4/3] flex-shrink-0 cursor-pointer">
                            <?php if ($item['thumb_url']): ?>
                                <img src="<?= e($item['thumb_url']) ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-700" loading="lazy" alt="<?= e($item['name']) ?>">
                                <div class="img-overlay"></div>
                                <span class="peek-hint hidden sm:flex">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    عرض التفاصيل
                                </span>
                            <?php else: ?>
                                <div class="empty-img-wrap">
                                    <svg class="w-16 h-16 relative" fill="none" viewBox="0 0 24 24" style="color: var(--primary); opacity: 0.55;">
                                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4" d="M3 11h18M5 11V9a7 7 0 0114 0v2M4 11h16l-1.5 8a2 2 0 01-2 1.7H7.5a2 2 0 01-2-1.7L4 11zM9 14v3M12 14v3M15 14v3"/>
                                        <circle cx="12" cy="5.5" r="0.8" fill="currentColor"/>
                                    </svg>
                                </div>
                            <?php endif; ?>
                            <!-- Top-right badges (featured + discount stack) -->
                            <div class="absolute top-2 right-2 flex flex-col gap-1 items-end z-10">
                                <?php if ($item['is_featured']): ?>
                                    <span class="bg-gradient-to-br from-amber-400 to-orange-500 text-white text-[11px] px-2 py-1 rounded-lg font-black shadow-lg shadow-amber-500/40 flex items-center gap-0.5">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.5 7h7l-5.5 4.5L18 21l-6-4.5L6 21l2-7.5L2.5 9h7z"/></svg>
                                        مميز
                                    </span>
                                <?php endif; ?>
                                <?php if ($hasDiscount): ?>
                                    <span class="badge-discount bg-gradient-to-br from-rose-500 to-red-600 text-white text-[11px] px-2 py-1 rounded-lg font-black shadow-lg shadow-rose-500/40">−<?= $discountPct ?>%</span>
                                <?php endif; ?>
                            </div>
                            <!-- Fav button -->
                            <button type="button" data-fav="<?= (int) $item['id'] ?>" class="fav-btn absolute top-2 left-2 z-10" aria-label="إضافة للمفضلة">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                            </button>
                        </div>
                        <div class="p-3.5 sm:p-4 flex-1 min-w-0 flex flex-col">
                            <div class="item-title-wrap mb-1.5">
                                <h3 onclick='showItem(<?= json_encode($item, JSON_UNESCAPED_UNICODE) ?>)' class="font-black text-base md:text-lg text-gray-900 line-clamp-1 cursor-pointer group-hover:text-primary transition leading-tight tracking-tight"><?= e($item['name']) ?></h3>
                            </div>
                            <?php if ($item['description']): ?>
                                <p class="text-xs md:text-sm text-gray-500 line-clamp-2 mb-3 flex-1 leading-relaxed"><?= e($item['description']) ?></p>
                            <?php else: ?>
                                <div class="flex-1 min-h-[8px]"></div>
                            <?php endif; ?>
                            <div class="flex items-center gap-2 mt-auto justify-between pt-2 border-t border-gray-100">
                                <div class="flex flex-col gap-0.5 min-w-0">
                                    <span class="price-badge px-3 py-1.5 rounded-xl whitespace-nowrap text-sm md:text-base inline-block w-fit"><?= formatPrice($item['price'], $r['currency']) ?></span>
                                    <?php if ($hasDiscount): ?>
                                        <span class="price-old whitespace-nowrap px-3"><?= formatPrice($item['old_price'], $r['currency']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="add-btn flex-shrink-0" data-add='<?= safe_html(json_encode(["id"=>(int)$item["id"],"name"=>$item["name"],"price"=>(float)$item["price"]], JSON_UNESCAPED_UNICODE)) ?>' aria-label="إضافة للسلة">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if (!$allItems): ?>
        <div class="py-20 text-center">
            <div class="text-6xl mb-4"><?= e(bizLabel($r, 'icon')) ?></div>
            <h2 class="text-xl font-bold text-gray-700">المحتوى قيد التحضير</h2>
            <p class="text-gray-500 mt-2">تابعنا للاطلاع على <?= e($publicLabelItems) ?> قريباً</p>
        </div>
    <?php endif; ?>
</main>

<!-- Footer -->
<footer class="mt-16 py-8 text-center text-sm text-gray-400 container max-w-5xl mx-auto px-4">
    <?php if ($showWatermark): ?>
        <a href="<?= BASE_URL ?>/" target="_blank" class="inline-flex items-center gap-2 bg-white px-4 py-2 rounded-full shadow-soft hover:shadow-md transition">
            <span>متجر إلكتروني بواسطة</span>
            <span class="font-bold text-primary"><?= e($watermarkText) ?></span>
        </a>
    <?php endif; ?>
</footer>

<?php if ($showWatermark): ?>
<!-- Floating Watermark Badge -->
<a href="<?= BASE_URL ?>/" target="_blank" class="fixed bottom-4 left-4 z-30 flex items-center gap-2 px-3 py-2 rounded-full bg-white/95 backdrop-blur shadow-lg text-xs text-gray-600 hover:text-gray-900 transition">
    <div class="w-5 h-5 rounded-md gradient-primary flex items-center justify-center text-white font-black text-[10px]">Q</div>
    <span class="font-bold"><?= e($watermarkText) ?></span>
</a>
<?php endif; ?>

<!-- Cart FAB -->
<button id="cartFab" type="button" class="cart-fab" aria-label="عرض السلة">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
    <span>السلة</span>
    <span id="cartFabBadge" class="cart-fab-badge">0</span>
</button>

<!-- Pre-send Order Modal (MAX plan custom message + ask_* toggles) -->
<div id="preSendBackdrop" class="pre-send-backdrop" onclick="if(event.target===this)closePreSendModal()">
    <div id="preSendDialog" class="pre-send-dialog" role="dialog" aria-modal="true">
        <div id="preSendBody"></div>
    </div>
</div>

<!-- Cart Drawer -->
<div id="cartBackdrop" class="cart-backdrop" onclick="closeCart()"></div>
<div id="cartDrawer" class="cart-drawer">
    <!-- Gradient header -->
    <div class="gradient-primary px-5 py-5 flex items-center justify-between text-white relative overflow-hidden">
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
        <div class="absolute -bottom-12 -left-12 w-40 h-40 bg-white/10 rounded-full blur-3xl"></div>
        <div class="relative flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <div>
                <h3 class="font-black text-lg">سلة الطلب</h3>
                <p class="text-xs text-white/80 font-semibold" id="cartSubtitle">جهّز طلبك وأرسله بنقرة</p>
            </div>
        </div>
        <button type="button" onclick="closeCart()" class="relative w-9 h-9 rounded-full bg-white/20 hover:bg-white/30 backdrop-blur flex items-center justify-center transition" aria-label="إغلاق">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div id="cartBody" class="flex-1 overflow-y-auto p-5">
        <div class="empty-cart">
            <div class="text-6xl mb-4">🛒</div>
            <p class="font-black text-lg text-gray-700">السلة فارغة</p>
            <p class="text-sm mt-1 text-gray-500">اضغط "+" على أي <?= e($publicLabelItem) ?> لإضافته للسلة</p>
        </div>
    </div>
    <div id="cartFooter" class="border-t border-gray-100 p-5 hidden bg-gradient-to-b from-white to-gray-50">
        <div class="flex items-baseline justify-between mb-4">
            <div>
                <span class="text-xs text-gray-500 font-semibold uppercase tracking-wider">الإجمالي</span>
                <div id="cartTotal" class="text-3xl font-black text-primary leading-none">0</div>
            </div>
            <div class="text-right">
                <span id="cartItemCount" class="text-xs text-gray-500 font-semibold">0 <?= e($publicLabelItem) ?></span>
            </div>
        </div>
        <textarea id="cartNotes" placeholder="ملاحظات إضافية (اختياري)..." rows="2" class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm mb-3 focus:outline-none focus:border-primary resize-none"></textarea>
        <?php if ($r['whatsapp'] || $r['phone']): ?>
        <button type="button" onclick="sendOrder()" id="cartSendBtn" class="flex items-center justify-center gap-2 w-full py-3.5 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 hover:shadow-xl hover:shadow-green-500/30 text-white font-black shadow-lg transition active:scale-95">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
            <span id="cartSendLabel">إرسال الطلب عبر واتساب</span>
        </button>
        <?php else: ?>
        <p class="text-center text-sm text-gray-500">لا توجد طريقة تواصل مباشرة — احفظ السلة لتجهيز طلبك.</p>
        <?php endif; ?>
        <button type="button" onclick="clearCart()" class="text-xs text-gray-400 hover:text-red-500 font-semibold mt-3 w-full py-1 transition">إفراغ السلة</button>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="toast"></div>

<!-- No results -->
<div id="noResults" class="hidden container max-w-5xl mx-auto px-4 py-16 text-center">
    <div class="text-5xl mb-3">🔍</div>
    <h3 class="text-lg font-bold text-gray-700">لا توجد نتائج</h3>
    <p class="text-sm text-gray-500 mt-1">جرّب كلمة بحث مختلفة</p>
</div>

<!-- Item Modal -->
<div id="itemModal" class="item-modal-backdrop hidden items-end md:items-center justify-center" onclick="if(event.target===this)closeItem()">
    <div id="itemModalContent" class="bg-white w-full md:max-w-xl md:rounded-3xl rounded-t-3xl max-h-[92vh] overflow-y-auto modal-enter shadow-2xl">
        <div id="itemModalBody"></div>
    </div>
</div>

<script>
const CURRENCY = <?= json_encode($r['currency']) ?>;
const UPLOADS = <?= json_encode(BASE_URL . '/assets/uploads/items/') ?>;
const MEDIA_URL = <?= json_encode(BASE_URL . '/assets/uploads/media/') ?>;
const WHATSAPP = <?= json_encode(preg_replace('/\D/','', $r['whatsapp'] ?: $r['phone'] ?: '')) ?>;
const PHONE_RAW = <?= json_encode(preg_replace('/\D/','', $r['phone'] ?: $r['whatsapp'] ?: '')) ?>;
const RESTAURANT_NAME = <?= json_encode($r['name']) ?>;
const MENU_URL = <?= json_encode(BASE_URL . '/public/store.php?r=' . $r['slug']) ?>;

/* Sector-aware labels (e.g. "أطلب" for restaurants, "استفسر" for cars). */
const ORDER_VERB = <?= json_encode($publicOrderVerb, JSON_UNESCAPED_UNICODE) ?>;
const LABEL_ITEM = <?= json_encode($publicLabelItem, JSON_UNESCAPED_UNICODE) ?>;
const LABEL_ITEMS = <?= json_encode($publicLabelItems, JSON_UNESCAPED_UNICODE) ?>;
const BIZ_ICON = <?= json_encode(bizLabel($r, 'icon'), JSON_UNESCAPED_UNICODE) ?>;

/* Schema map: key -> {label,type,options} — lets the item modal render specs with proper labels. */
const PUBLIC_SCHEMA = <?= json_encode($publicSchemaByKey, JSON_UNESCAPED_UNICODE) ?>;

/* Order Message Studio config (MAX plan). Falls back to defaults automatically. */
const ORDER_MSG_ENABLED = <?= canDo($r, 'custom_message') ? 'true' : 'false' ?>;
const ORDER_MSG_CFG = <?= json_encode(orderMessageJsConfig($r), JSON_UNESCAPED_UNICODE) ?>;

/**
 * Render an order message from the template + tokens, then auto-append any
 * enabled ask_* / include_* toggle whose value wasn't consumed by the
 * template. This guarantees every toggle the owner flips has a visible
 * effect on the final WhatsApp message, even when the chosen template is
 * minimal.
 * Mirrors includes/order_message.php::orderMessageFormat exactly.
 */
function renderOrderMessage(tokens) {
    const c = ORDER_MSG_CFG;
    // Normalize CRLF → LF; owners pasting templates from Windows textareas
    // often introduce \r\n which renders as stray %0D in WhatsApp.
    const rawTpl = (c.msg_template || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!rawTpl) return '';

    // Sector-neutral alias: {store} mirrors {restaurant} for new templates.
    // Callers only set `restaurant`; we fill in `store` automatically.
    if (tokens.store == null && tokens.restaurant != null) {
        tokens = Object.assign({}, tokens, { store: tokens.restaurant });
    }

    // 1) Substitute placeholders in the template
    let out = rawTpl;
    Object.keys(tokens).forEach(k => {
        out = out.split('{' + k + '}').join(tokens[k] == null ? '' : tokens[k]);
    });
    out = out.replace(/\{[a-z_]+\}/g, '');      // strip unknown placeholders
    out = out.replace(/\n{3,}/g, '\n\n').trim();

    // 2) Auto-append toggled fields the template didn't use
    const usedInTpl = (tok) => rawTpl.indexOf('{' + tok + '}') !== -1;
    const useIcons  = c.msg_emoji_style !== 'minimal';
    const blocks = [];

    // Icons as Unicode escapes — avoids any file-encoding corruption
    // turning them into � when they hit WhatsApp.
    const ICON = {
        name:    '\u{1F464}', // 👤
        phone:   '\u{1F4DE}', // 📞
        address: '\u{1F4CD}', // 📍
        table:   '\u{1FA91}', // 🪑
        qty:     '\u{1F522}', // 🔢
        notes:   '\u{1F4DD}', // 📝
        link:    '\u{1F517}', // 🔗
        time:    '\u{1F552}', // 🕒
    };
    const fields = [
        ['msg_ask_name',     'name',    'الاسم'],
        ['msg_ask_phone',    'phone',   'الهاتف'],
        ['msg_ask_address',  'address', 'العنوان'],
        ['msg_ask_table',    'table',   'رقم الطاولة'],
        ['msg_ask_quantity', 'qty',     'الكمية'],
        ['msg_ask_notes',    'notes',   'ملاحظات'],
    ];
    fields.forEach(([flag, tok, label]) => {
        if (!c[flag]) return;
        const val = tokens[tok] != null ? String(tokens[tok]).trim() : '';
        if (!val) return;
        if (usedInTpl(tok)) return;
        blocks.push((useIcons ? ICON[tok] + ' ' : '') + label + ': ' + val);
    });

    if (c.msg_include_link && tokens.link && !usedInTpl('link')) {
        blocks.push((useIcons ? ICON.link + ' ' : '') + 'الرابط: ' + tokens.link);
    }
    if (c.msg_include_time && !usedInTpl('time') && !usedInTpl('date')) {
        const stamp = ((tokens.date || '') + ' ' + (tokens.time || '')).trim();
        if (stamp) blocks.push((useIcons ? ICON.time + ' ' : '') + stamp);
    }

    if (blocks.length) out = out.replace(/\s+$/, '') + '\n\n' + blocks.join('\n');

    // 3) Minimal emoji style: strip pictographic unicode AFTER appending
    if (c.msg_emoji_style === 'minimal') {
        out = out.replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{1F000}-\u{1F2FF}]/gu, '').replace(/ {2,}/g, ' ');
    }
    return out.trim();
}

/**
 * Does the restaurant owner want a pre-send modal (any ask_* toggle + require_confirm)?
 */
function orderMsgNeedsModal() {
    const c = ORDER_MSG_CFG;
    return ORDER_MSG_ENABLED && (
        c.msg_ask_name || c.msg_ask_phone || c.msg_ask_table ||
        c.msg_ask_quantity || c.msg_ask_notes || c.msg_ask_address ||
        c.msg_require_confirm
    );
}

/**
 * Build the default tokens object for a single-item order.
 * Additional fields (name, phone, table, qty, notes, address) come from the pre-send modal.
 */
function buildOrderTokens(item, extras) {
    extras = extras || {};
    const c = ORDER_MSG_CFG;
    const qty = extras.qty || (c.msg_ask_quantity ? 1 : '');
    const priceSrc = item.price ? formatPrice(item.price) : '';
    const priceBlock = c.msg_include_price && priceSrc ? ` — ${priceSrc}` : '';
    const now = new Date();
    return {
        item: item.name,
        price: priceBlock,
        restaurant: RESTAURANT_NAME,
        greeting: c.msg_greeting || '',
        signature: c.msg_signature || '',
        qty: qty ? String(qty) : '',
        name: extras.name || '',
        phone: extras.phone || '',
        table: extras.table || '',
        notes: extras.notes || '',
        address: extras.address || '',
        link: c.msg_include_link ? MENU_URL : '',
        time: c.msg_include_time ? now.toLocaleTimeString('ar-SY', { hour: '2-digit', minute: '2-digit' }) : '',
        date: c.msg_include_time ? now.toLocaleDateString('ar-SY') : '',
    };
}

/**
 * Compose a WhatsApp URL from an order message. Falls back to tel: when channel = phone.
 *
 * We target api.whatsapp.com/send directly instead of wa.me because wa.me
 * does a client-side redirect that on some browsers (Chrome/Edge on Windows
 * with a non-installed WhatsApp app) mangles 4-byte UTF-8 emoji into U+FFFD
 * (replacement char). api.whatsapp.com/send is the final redirect target
 * anyway, so going direct preserves the message byte-for-byte.
 */
function buildOrderChannelUrl(message) {
    const c = ORDER_MSG_CFG;
    const wa = WHATSAPP;
    if (!wa && !PHONE_RAW) return null;
    if (c.msg_channel_priority === 'phone' && PHONE_RAW) return 'tel:+' + PHONE_RAW;
    if (!wa) return 'tel:+' + PHONE_RAW;
    // Normalize CRLF → LF so the URL encodes %0A instead of %0D%0A — some
    // WhatsApp clients render %0D as a stray character.
    const clean = String(message).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    return 'https://api.whatsapp.com/send?phone=' + wa + '&text=' + encodeURIComponent(clean);
}

function formatPrice(p) {
    const n = parseFloat(p);
    if (Number.isInteger(n)) return n.toLocaleString('en-US') + ' ' + CURRENCY;
    return n.toFixed(2) + ' ' + CURRENCY;
}

let currentModalItem = null;
let currentModalQty = 1;

function showItem(item) {
    currentModalItem = item;
    currentModalQty = 1;
    const hasImg = item.image && item.image.length;
    const hasDiscount = item.old_price && parseFloat(item.old_price) > parseFloat(item.price);
    const discountPct = hasDiscount ? Math.round(((item.old_price - item.price) / item.old_price) * 100) : 0;
    const savings = hasDiscount ? (item.old_price - item.price) : 0;
    const isFavActive = isFav(item.id) ? 'fav-active' : '';
    const body = document.getElementById('itemModalBody');

    // Build slides: when the MAX plan gallery has media, the user-chosen cover (first entry)
    // becomes slide #1 — the legacy `image` field is appended at the end as a fallback.
    // When there's no gallery (non-MAX plans), the legacy image is the only slide.
    // item.media is an array of { type: 'image'|'video', path: 'filename.ext' } ordered by is_cover DESC.
    const slides = [];
    const hasMedia = Array.isArray(item.media) && item.media.length > 0;
    if (hasMedia) {
        item.media.forEach(m => {
            if (m && m.path) slides.push({ type: m.type, src: MEDIA_URL + m.path });
        });
        // Append legacy image at the end (not replacing the cover) so it's still accessible if present
        if (hasImg) slides.push({ type: 'image', src: UPLOADS + item.image });
    } else if (hasImg) {
        slides.push({ type: 'image', src: UPLOADS + item.image });
    }
    const hasGallery = slides.length > 1;

    const heroHtml = slides.length === 0 ?
        `<div class="modal-empty-hero">
            <svg class="w-24 h-24 relative" fill="none" viewBox="0 0 24 24" style="color: var(--primary); opacity: 0.55;">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.4" d="M3 11h18M5 11V9a7 7 0 0114 0v2M4 11h16l-1.5 8a2 2 0 01-2 1.7H7.5a2 2 0 01-2-1.7L4 11zM9 14v3M12 14v3M15 14v3"/>
                <circle cx="12" cy="5.5" r="0.8" fill="currentColor"/>
            </svg>
        </div>`
        : (hasGallery ?
            `<div class="modal-gallery" id="modalGallery">
                <div class="modal-gallery-track" id="modalGalleryTrack">
                    ${slides.map((s, i) => s.type === 'video'
                        ? `<div class="modal-gallery-slide" data-idx="${i}">
                               <video src="${escapeHtml(s.src)}" controls playsinline preload="metadata"></video>
                           </div>`
                        : `<div class="modal-gallery-slide" data-idx="${i}">
                               <img src="${escapeHtml(s.src)}" alt="${escapeHtml(item.name)}">
                           </div>`
                    ).join('')}
                </div>
                <button type="button" class="modal-gallery-nav prev" aria-label="السابق" onclick="galleryNav(1)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                <button type="button" class="modal-gallery-nav next" aria-label="التالي" onclick="galleryNav(-1)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="modal-gallery-badge" id="modalGalleryBadge">1 / ${slides.length}</span>
                <div class="modal-gallery-dots" id="modalGalleryDots">
                    ${slides.map((_, i) => `<span class="modal-gallery-dot ${i === 0 ? 'active' : ''}"></span>`).join('')}
                </div>
            </div>`
            : (slides[0].type === 'video'
                ? `<div class="modal-gallery"><div class="modal-gallery-slide"><video src="${escapeHtml(slides[0].src)}" controls playsinline preload="metadata"></video></div></div>`
                : `<img src="${escapeHtml(slides[0].src)}" class="modal-hero-img" alt="${escapeHtml(item.name)}">`)
        );

    body.innerHTML = `
        <!-- Hero -->
        <div class="modal-hero">
            ${heroHtml}
            <div class="modal-hero-gradient"></div>

            <!-- Top actions -->
            <div class="modal-top-actions">
                <button onclick="closeItem()" class="modal-icon-btn" aria-label="إغلاق">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div class="modal-right-actions">
                    <button onclick="toggleModalFav(${item.id}, this)" class="modal-icon-btn ${isFavActive}" aria-label="إضافة للمفضلة">
                        <svg class="w-5 h-5" fill="${isFav(item.id) ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                    </button>
                    <button onclick="shareItem()" class="modal-icon-btn" aria-label="مشاركة">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    </button>
                </div>
            </div>

            ${item.is_featured == 1 ? `
                <span class="modal-featured-badge">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.5 7h7l-5.5 4.5L18 21l-6-4.5L6 21l2-7.5L2.5 9h7z"/></svg>
                    ${LABEL_ITEM} مميز
                </span>
            ` : ''}
        </div>

        <!-- Body -->
        <div class="modal-body">
            <h2 class="modal-title">${escapeHtml(item.name)}</h2>

            <div class="modal-subtitle-row">
                <span class="inline-flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-green-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    متوفر الآن
                </span>
                <span class="dot"></span>
                <span>${escapeHtml(RESTAURANT_NAME)}</span>
            </div>

            ${item.description ? `
                <div class="modal-desc">${escapeHtml(item.description)}</div>
            ` : ''}

            ${renderItemSpecs(item)}

            ${hasDiscount ? `
                <div class="modal-savings-banner mb-4">
                    <div class="savings-icon">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                    </div>
                    <div class="flex-1">
                        وفّر <strong>${formatPrice(savings)}</strong> من هذا الطلب
                    </div>
                </div>
            ` : ''}

            <!-- Price row -->
            <div class="modal-price-row">
                <div class="relative z-10">
                    <div class="modal-price-label">السعر</div>
                    <div class="modal-price-main">${formatPrice(item.price)}</div>
                    ${hasDiscount ? `<div class="modal-price-old">${formatPrice(item.old_price)}</div>` : ''}
                </div>
                ${hasDiscount ? `<div class="modal-price-discount-pill">−${discountPct}%</div>` : ''}
            </div>

            <!-- Qty selector -->
            <div class="modal-qty-section">
                <span class="qty-label">الكمية</span>
                <div class="flex items-center gap-4">
                    <div class="modal-total-preview">
                        <span>الإجمالي</span>
                        <span class="total-val" id="modalTotalVal">${formatPrice(item.price)}</span>
                    </div>
                    <div class="modal-qty-controls">
                        <button type="button" class="modal-qty-btn" id="modalQtyMinus" onclick="changeModalQty(-1)">−</button>
                        <span class="modal-qty-value" id="modalQtyValue">1</span>
                        <button type="button" class="modal-qty-btn" onclick="changeModalQty(1)">+</button>
                    </div>
                </div>
            </div>

            <!-- CTA row -->
            <div class="modal-cta-row">
                <button type="button" class="modal-cta-add" onclick="addFromModal()">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    أضف للسلة
                </button>
                ${WHATSAPP ? `
                    <button type="button" onclick="orderItemViaChannel()" class="modal-cta-whatsapp" style="border:none;cursor:pointer;">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                        ${escapeHtml(ORDER_MSG_CFG.msg_button_label || 'واتساب')}
                    </button>
                ` : ''}
            </div>
        </div>
    `;
    const modal = document.getElementById('itemModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    // Force reflow then add visible for smooth backdrop fade
    requestAnimationFrame(() => modal.classList.add('visible'));
    document.body.style.overflow = 'hidden';
    updateModalQtyButtons();
    setupGallery();
}

// Track which gallery slide is active so we can update dots/badge and pause videos
function setupGallery() {
    const track = document.getElementById('modalGalleryTrack');
    if (!track) return;
    let ticking = false;
    track.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            const idx = Math.round(track.scrollLeft / track.clientWidth);
            const absIdx = Math.abs(idx);
            const total = track.children.length;
            const dots = document.querySelectorAll('#modalGalleryDots .modal-gallery-dot');
            dots.forEach((d, i) => d.classList.toggle('active', i === absIdx));
            const badge = document.getElementById('modalGalleryBadge');
            if (badge) badge.textContent = (absIdx + 1) + ' / ' + total;
            // Pause every video in every other slide than the active one
            track.querySelectorAll('video').forEach((v, i) => { if (i !== absIdx) v.pause(); });
            ticking = false;
        });
    }, { passive: true });
}

// dir: +1 moves to the next slide. In RTL the gallery scrolls negatively toward "next",
// so flip the sign — dir argument is the visual direction the user perceives.
function galleryNav(dir) {
    const track = document.getElementById('modalGalleryTrack');
    if (!track) return;
    track.scrollBy({ left: dir * track.clientWidth, behavior: 'smooth' });
}

function closeItem() {
    const modal = document.getElementById('itemModal');
    // Pause any playing videos before closing
    modal.querySelectorAll('video').forEach(v => { try { v.pause(); } catch (e) {} });
    modal.classList.remove('visible');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
        currentModalItem = null;
    }, 200);
}

function changeModalQty(delta) {
    if (!currentModalItem) return;
    const newQty = currentModalQty + delta;
    if (newQty < 1 || newQty > 99) return;
    currentModalQty = newQty;
    const valEl = document.getElementById('modalQtyValue');
    const totalEl = document.getElementById('modalTotalVal');
    if (valEl) valEl.textContent = currentModalQty;
    if (totalEl) totalEl.textContent = formatPrice(currentModalItem.price * currentModalQty);
    updateModalQtyButtons();
}

function updateModalQtyButtons() {
    const minus = document.getElementById('modalQtyMinus');
    if (minus) minus.disabled = (currentModalQty <= 1);
}

function addFromModal() {
    if (!currentModalItem) return;
    const id = parseInt(currentModalItem.id);
    const name = currentModalItem.name;
    const price = parseFloat(currentModalItem.price);
    // Add directly without triggering per-item toast
    if (cart[id]) cart[id].qty += currentModalQty;
    else cart[id] = { id, name, price, qty: currentModalQty };
    saveCart();
    renderCart();
    showToast('✓ تم إضافة ' + currentModalQty + ' × ' + name);
    closeItem();
}

function toggleModalFav(id, btn) {
    toggleFav(id);
    const active = isFav(id);
    btn.classList.toggle('fav-active', active);
    const svg = btn.querySelector('svg');
    if (svg) svg.setAttribute('fill', active ? 'currentColor' : 'none');
    // Also sync with card's fav button on page
    document.querySelectorAll('[data-fav="' + id + '"]').forEach(b => b.classList.toggle('active', active));
}

function shareItem() {
    if (!currentModalItem) return;
    const text = currentModalItem.name + ' — ' + RESTAURANT_NAME;
    const url = window.location.href;
    if (navigator.share) {
        navigator.share({ title: currentModalItem.name, text: text, url: url }).catch(() => {});
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text + '\n' + url);
        showToast('✓ تم نسخ الرابط');
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

/**
 * Render sector-specific specs as a compact list inside the item modal.
 * `item.specs` is a JSON string (or already-parsed object). Keys are looked up
 * in PUBLIC_SCHEMA for the human label. Missing/empty fields are skipped.
 * Returns '' when there is nothing to show — caller should check.
 */
function renderItemSpecs(item) {
    if (!item.specs) return '';
    let specs = item.specs;
    if (typeof specs === 'string') {
        try { specs = JSON.parse(specs); } catch (e) { return ''; }
    }
    if (!specs || typeof specs !== 'object') return '';

    const rows = [];
    for (const key of Object.keys(specs)) {
        const val = specs[key];
        if (val === null || val === undefined || val === '') continue;
        const field = PUBLIC_SCHEMA[key] || { label: key, type: 'text' };
        const label = field.label || key;
        let display = '';

        if (field.type === 'boolean') {
            if (!val) continue;
            display = '✓';
        } else if (field.type === 'multiselect' && Array.isArray(val)) {
            if (!val.length) continue;
            display = val.map(v => `<span class="spec-chip">${escapeHtml(v)}</span>`).join('');
            rows.push(`<div class="spec-row"><span class="spec-label">${escapeHtml(label)}</span><div class="spec-chips">${display}</div></div>`);
            continue;
        } else {
            display = escapeHtml(String(val));
        }

        rows.push(`<div class="spec-row"><span class="spec-label">${escapeHtml(label)}</span><span class="spec-value">${display}</span></div>`);
    }
    if (!rows.length) return '';
    return `<div class="modal-specs"><h3 class="specs-title">المواصفات</h3><div class="specs-list">${rows.join('')}</div></div>`;
}

// Category tabs active state on scroll
const sections = document.querySelectorAll('section[id^="cat-"]');
const tabs = document.querySelectorAll('.cat-tab');

function onScroll() {
    let current = null;
    const scrollY = window.scrollY + 200;
    sections.forEach(s => {
        if (s.offsetTop <= scrollY) current = s.id.replace('cat-', '');
    });
    tabs.forEach(t => {
        t.classList.toggle('active', t.dataset.catId === current);
    });
    if (current) {
        const activeTab = document.querySelector(`.cat-tab[data-cat-id="${current}"]`);
        if (activeTab) {
            const container = activeTab.parentElement;
            const targetLeft = activeTab.offsetLeft - container.offsetWidth / 2 + activeTab.offsetWidth / 2;
            container.scrollTo({ left: targetLeft, behavior: 'smooth' });
        }
    }
}
window.addEventListener('scroll', onScroll, { passive: true });

// Close modal with Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeItem(); closeCart(); }
});

/* ============================================================
   Sparkles flying off the scrollbar on scroll — soft & subtle
   Exposed to window.spawnSparkle for manual testing.
   ============================================================ */
(function () {
    const SPARKLE_CHARS = ['✦', '✧', '✨'];
    const isRTL = document.documentElement.dir === 'rtl';
    let lastY = window.scrollY;
    let cooldownUntil = 0;

    function spawnSparkle() {
        const s = document.createElement('div');
        s.className = 'scroll-sparkle';
        s.textContent = SPARKLE_CHARS[(Math.random() * SPARKLE_CHARS.length) | 0];

        const edge = isRTL
            ? (2 + Math.random() * 14)
            : (window.innerWidth - 16 - Math.random() * 14);
        s.style.left = edge + 'px';
        s.style.top  = (40 + Math.random() * (window.innerHeight - 80)) + 'px';
        s.style.fontSize = (9 + Math.random() * 4).toFixed(1) + 'px';

        document.body.appendChild(s);
        setTimeout(() => s.remove(), 1500);
    }

    window.spawnSparkle = spawnSparkle;

    function onScroll() {
        const now = performance.now();
        if (now < cooldownUntil) return;
        const delta = Math.abs(window.scrollY - lastY);
        lastY = window.scrollY;
        // Higher threshold = fewer triggers; emit only one sparkle per burst
        if (delta > 40) {
            spawnSparkle();
            cooldownUntil = now + 350; // ~3 sparkles/sec maximum
        }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
})();

/* ============================================================
   INTERACTIVE LAYER: Cart • Favorites • Search • Share • Reveal
   ============================================================ */
const STORAGE_KEY = 'qrmenu_cart_' + <?= json_encode($r['slug']) ?>;
const FAV_KEY = 'qrmenu_fav_' + <?= json_encode($r['slug']) ?>;
let cart = {}; // { id: { id, name, price, qty } }
let favs = new Set();

try { cart = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {}; } catch (e) { cart = {}; }
try { favs = new Set(JSON.parse(localStorage.getItem(FAV_KEY) || '[]')); } catch (e) { favs = new Set(); }

function saveCart() { localStorage.setItem(STORAGE_KEY, JSON.stringify(cart)); }
function saveFavs() { localStorage.setItem(FAV_KEY, JSON.stringify([...favs])); }
function isFav(id) { return favs.has(parseInt(id)); }

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => t.classList.remove('show'), 1800);
}

/* --- CART --- */
function addToCart(item) {
    if (cart[item.id]) cart[item.id].qty++;
    else cart[item.id] = { ...item, qty: 1 };
    saveCart(); renderCart();
    showToast('✓ أُضيف للسلة: ' + item.name);
}
function removeFromCart(id) { delete cart[id]; saveCart(); renderCart(); }
function updateQty(id, delta) {
    if (!cart[id]) return;
    cart[id].qty += delta;
    if (cart[id].qty <= 0) delete cart[id];
    saveCart(); renderCart();
}
function clearCart() {
    if (!Object.keys(cart).length) return;
    if (!confirm('إفراغ السلة بالكامل؟')) return;
    cart = {}; saveCart(); renderCart();
}
/**
 * Silent cart reset — used after a successful order send so the visitor
 * returns to a clean menu. Also closes the drawer and wipes any typed notes.
 */
function resetCartAfterSend() {
    cart = {};
    saveCart();
    renderCart();
    const notes = document.getElementById('cartNotes');
    if (notes) notes.value = '';
    closeCart();
}
function totalCart() {
    return Object.values(cart).reduce((s, it) => s + it.price * it.qty, 0);
}
function countCart() {
    return Object.values(cart).reduce((s, it) => s + it.qty, 0);
}
function renderCart() {
    const n = countCart();
    const fab = document.getElementById('cartFab');
    document.getElementById('cartFabBadge').textContent = n;
    fab.classList.toggle('visible', n > 0);

    const body = document.getElementById('cartBody');
    const footer = document.getElementById('cartFooter');
    if (!n) {
        body.innerHTML = `<div class="empty-cart"><div class="text-5xl mb-3">🛒</div><p class="font-semibold text-gray-700">السلة فارغة</p><p class="text-xs mt-1">أضف ${LABEL_ITEMS} لبدء الطلب</p></div>`;
        footer.classList.add('hidden');
        return;
    }
    footer.classList.remove('hidden');
    body.innerHTML = Object.values(cart).map(it => `
        <div class="cart-item flex items-center gap-3 p-3 rounded-2xl bg-white border border-gray-100 mb-2 hover:border-primary/30 transition">
            <div class="flex-1 min-w-0">
                <p class="font-black text-sm line-clamp-1 text-gray-900">${escapeHtml(it.name)}</p>
                <p class="text-xs text-gray-500 mt-0.5 font-semibold">${formatPrice(it.price)} × ${it.qty} = <span class="text-primary font-black">${formatPrice(it.price * it.qty)}</span></p>
            </div>
            <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                <button class="qty-btn" onclick="updateQty(${it.id}, -1)">−</button>
                <span class="font-black text-sm min-w-[22px] text-center">${it.qty}</span>
                <button class="qty-btn" onclick="updateQty(${it.id}, 1)">+</button>
            </div>
            <button onclick="removeFromCart(${it.id})" class="w-8 h-8 rounded-lg text-gray-300 hover:bg-red-50 hover:text-red-500 flex items-center justify-center transition" aria-label="حذف">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </button>
        </div>
    `).join('');
    document.getElementById('cartTotal').textContent = formatPrice(totalCart());
    const cartCountEl = document.getElementById('cartItemCount');
    if (cartCountEl) cartCountEl.textContent = n + ' ' + LABEL_ITEM;
    const subtitle = document.getElementById('cartSubtitle');
    if (subtitle) subtitle.textContent = n + ' ' + (n === 1 ? LABEL_ITEM : LABEL_ITEMS) + ' في السلة';
}
function openCart() {
    document.getElementById('cartBackdrop').classList.add('open');
    document.getElementById('cartDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCart() {
    document.getElementById('cartBackdrop').classList.remove('open');
    document.getElementById('cartDrawer').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('cartFab').addEventListener('click', openCart);

/* ═══════════════════════════════════════════════════════════════════════
   UNIFIED ORDER FLOW  (MAX plan custom template aware)
   Both the cart-drawer "Send Order" button and the item-modal WhatsApp
   button funnel into the same pre-send flow, so every visitor-facing
   toggle (ask_*, include_*, channel, button label, modal texts)
   applies consistently whether ordering one item directly or a cart.
   ═══════════════════════════════════════════════════════════════════════ */

// Holds the in-flight order context between opening the pre-send modal
// and submitting it. Shape: { mode: 'item'|'cart', item?, qty?, lines?, total?, notes? }
let pendingOrder = null;

/* ─── Cart flow ─── */
function sendOrder() {
    if (!WHATSAPP && !PHONE_RAW) { showToast('لا يوجد رقم للطلب'); return; }
    if (!Object.keys(cart).length) { showToast('السلة فارغة'); return; }

    const notes = (document.getElementById('cartNotes').value || '').trim();
    const lines = Object.values(cart).map(it =>
        `• ${it.name} × ${it.qty} = ${formatPrice(it.price * it.qty)}`
    );
    const totalStr = formatPrice(totalCart());

    if (ORDER_MSG_ENABLED) {
        const ctx = { mode: 'cart', lines: lines, total: totalStr, notes: notes };
        if (orderMsgNeedsModal()) {
            openPreSendModal(ctx);
            return;
        }
        // No extra inputs needed — fire immediately using the custom template
        finalizeOrderSend(ctx, {});
        return;
    }

    // Legacy path (no custom template) — unchanged format.
    // Icons as Unicode escapes so WhatsApp renders them correctly on every
    // platform regardless of this file's on-disk encoding.
    const msg = [
        BIZ_ICON + ' طلب جديد من ' + RESTAURANT_NAME + ':',
        '',
        ...lines,
        '',
        "\u{1F4CA} الإجمالي: " + totalStr, // 📊
        notes ? ("\u{1F4DD} ملاحظات: " + notes) : '', // 📝
    ].filter(Boolean).join('\n');
    // api.whatsapp.com/send skips the wa.me redirect that was mangling emoji.
    window.open('https://api.whatsapp.com/send?phone=' + WHATSAPP + '&text=' + encodeURIComponent(msg), '_blank');
    resetCartAfterSend();
    showToast("\u{2705} تم إرسال طلبك — شكراً لك!"); // ✅
}

/* ─── Item-modal flow (single-item direct order) ─── */
function orderItemViaChannel() {
    if (!currentModalItem) return;
    if (!WHATSAPP && !PHONE_RAW) { showToast('لا يوجد رقم للطلب'); return; }

    if (!ORDER_MSG_ENABLED) {
        // Legacy: quick fire with the old default template
        // Sector-aware verbal noun: طلب/الاستفسار عن/شراء/حجز. Mirrors the PHP orderMessageDefaults map.
        const verbalNoun = ({'أطلب':'طلب','استفسر':'الاستفسار عن','اشتر':'شراء','احجز':'حجز'})[ORDER_VERB] || 'طلب';
        const msg = 'مرحباً، أريد ' + verbalNoun + ': ' + currentModalItem.name + ' من ' + RESTAURANT_NAME;
        window.open('https://api.whatsapp.com/send?phone=' + WHATSAPP + '&text=' + encodeURIComponent(msg), '_blank');
        return;
    }

    const ctx = { mode: 'item', item: currentModalItem, qty: currentModalQty };
    if (orderMsgNeedsModal()) {
        openPreSendModal(ctx);
        return;
    }
    finalizeOrderSend(ctx, {});
}

/* ─── Pre-send modal (builds inputs from msg_ask_* flags) ─── */
function openPreSendModal(ctx) {
    pendingOrder = ctx || null;
    const c = ORDER_MSG_CFG;
    const body = document.getElementById('preSendBody');
    if (!body || !pendingOrder) return;

    // Quantity is already known when ordering a single item from the modal,
    // and meaningless for a multi-item cart, so we only ask when the flow is
    // 'item' AND the owner enabled ask_quantity.
    const isItemMode = pendingOrder.mode === 'item';
    const fields = [];
    if (c.msg_ask_name)                fields.push(['name',    'الاسم',         '\u{1F464}', 'text',     'مثل: أحمد']);
    if (c.msg_ask_phone)               fields.push(['phone',   'رقم الهاتف',     '\u{1F4DE}', 'tel',      '+963...']);
    if (c.msg_ask_table)               fields.push(['table',   'رقم الطاولة',    '\u{1FA91}', 'text',     '1-20']);
    if (c.msg_ask_quantity && isItemMode) fields.push(['qty',  'الكمية',         '\u{1F522}', 'number',   '1']);
    if (c.msg_ask_address)             fields.push(['address', 'عنوان التوصيل',  '\u{1F4CD}', 'text',     'المنطقة / الحي / بناء...']);
    if (c.msg_ask_notes)               fields.push(['notes',   'ملاحظات إضافية', '\u{1F4DD}', 'textarea', 'أي تفاصيل إضافية تود إخبارنا بها']);

    // Seed values: use what we already know from the in-flight context
    const seed = {};
    if (isItemMode) {
        seed.qty = pendingOrder.qty || 1;
    } else if (pendingOrder.mode === 'cart') {
        seed.notes = pendingOrder.notes || '';
    }

    // Build a context-aware "summary" strip (single item vs cart lines)
    let summaryHtml;
    if (isItemMode) {
        const it = pendingOrder.item;
        summaryHtml = `
            <div class="pre-send-item">
                <span class="pre-send-item-label">${LABEL_ITEM}:</span>
                <span class="pre-send-item-name">${escapeHtml(it.name)}</span>
                ${c.msg_include_price && it.price ? `<span class="pre-send-item-price">${formatPrice(it.price)}</span>` : ''}
            </div>`;
    } else {
        const previewLines = pendingOrder.lines.slice(0, 3).map(l => `<div>${escapeHtml(l)}</div>`).join('');
        const more = pendingOrder.lines.length > 3 ? `<div style="opacity:.7;font-size:12px;">+ ${pendingOrder.lines.length - 3} ${LABEL_ITEMS} أخرى</div>` : '';
        summaryHtml = `
            <div class="pre-send-item" style="flex-direction:column;align-items:flex-start;gap:4px;">
                <span class="pre-send-item-label">طلبك:</span>
                <div style="font-size:13px;line-height:1.7;width:100%;">${previewLines}${more}</div>
                ${c.msg_include_price ? `<span class="pre-send-item-price" style="align-self:flex-end;">الإجمالي: ${escapeHtml(pendingOrder.total)}</span>` : ''}
            </div>`;
    }

    body.innerHTML = `
        <div class="pre-send-head">
            <div class="pre-send-title">${escapeHtml(c.msg_modal_title || 'أكمل طلبك')}</div>
            ${c.msg_modal_subtitle ? `<div class="pre-send-subtitle">${escapeHtml(c.msg_modal_subtitle)}</div>` : ''}
            ${summaryHtml}
        </div>
        ${fields.length ? '<div class="pre-send-fields">' + fields.map(([k, lbl, ico, type, ph]) => {
            const val = seed[k] != null ? seed[k] : '';
            if (type === 'textarea') {
                return `<label class="pre-send-field">
                    <span class="pre-send-field-label">${ico} ${escapeHtml(lbl)}</span>
                    <textarea data-field="${k}" rows="2" placeholder="${escapeHtml(ph)}">${escapeHtml(String(val))}</textarea>
                </label>`;
            }
            return `<label class="pre-send-field">
                <span class="pre-send-field-label">${ico} ${escapeHtml(lbl)}</span>
                <input type="${type}" data-field="${k}" value="${escapeHtml(String(val))}" placeholder="${escapeHtml(ph)}" ${type === 'number' ? 'min="1"' : ''}>
            </label>`;
        }).join('') + '</div>' : '<div style="padding:16px 20px;color:#4b5563;font-size:13px;line-height:1.7;">سيتم إرسال طلبك مباشرةً — راجع التفاصيل أعلاه ثم اضغط "إرسال".</div>'}
        <div class="pre-send-actions">
            <button type="button" class="pre-send-cancel" onclick="closePreSendModal()">رجوع</button>
            <button type="button" class="pre-send-submit" onclick="submitPreSend()">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.5 3.4A12 12 0 0 0 12 0C5.4 0 0 5.4 0 12c0 2.1.6 4.2 1.6 6L0 24l6.2-1.6a12 12 0 0 0 17.6-10.4c0-3.2-1.3-6.2-3.3-8.6z"/></svg>
                ${escapeHtml(c.msg_button_label || 'إرسال الطلب')}
            </button>
        </div>
    `;
    document.getElementById('preSendBackdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closePreSendModal() {
    const bd = document.getElementById('preSendBackdrop');
    if (bd) bd.classList.remove('open');
    document.body.style.overflow = '';
    // Intentionally leave pendingOrder populated so the cart drawer state
    // is still correct if the user cancels — it'll be overwritten on the
    // next openPreSendModal() call.
}

function submitPreSend() {
    if (!pendingOrder) { closePreSendModal(); return; }

    const extras = {};
    document.querySelectorAll('#preSendBody [data-field]').forEach(el => {
        extras[el.dataset.field] = (el.value || '').trim();
    });

    // Client validation — required fields shouldn't be empty if toggled on
    const c = ORDER_MSG_CFG;
    const required = [];
    if (c.msg_ask_name    && !extras.name)    required.push('الاسم');
    if (c.msg_ask_phone   && !extras.phone)   required.push('رقم الهاتف');
    if (c.msg_ask_address && !extras.address) required.push('عنوان التوصيل');
    if (required.length) { showToast('يرجى تعبئة: ' + required.join('، ')); return; }

    if (finalizeOrderSend(pendingOrder, extras)) {
        closePreSendModal();
    }
}

/**
 * Final step: merge tokens from context (item or cart) with any extras from
 * the pre-send form, render the message, and open the appropriate channel.
 * Returns true on success so the modal can close.
 */
function finalizeOrderSend(ctx, extras) {
    if (!ctx) return false;
    const c = ORDER_MSG_CFG;
    let tokens;

    if (ctx.mode === 'item') {
        // Use the pre-send qty if provided, otherwise the modal qty
        const mergedExtras = Object.assign({}, extras, {
            qty: extras.qty || ctx.qty || (c.msg_ask_quantity ? 1 : '')
        });
        tokens = buildOrderTokens(ctx.item, mergedExtras);
    } else {
        // Cart mode: pack lines into {item} and total into {price}
        const mergedExtras = Object.assign({}, extras);
        if (!mergedExtras.notes && ctx.notes) mergedExtras.notes = ctx.notes;

        tokens = buildOrderTokens(
            { name: ctx.lines.join('\n'), price: 0 },
            mergedExtras
        );
        tokens.price = c.msg_include_price ? ` — الإجمالي: ${ctx.total}` : '';
        // Cart has no meaningful single "qty" — blank it so the template doesn't show "× 1"
        if (!extras.qty) tokens.qty = '';
    }

    const msg = renderOrderMessage(tokens);
    const url = buildOrderChannelUrl(msg);
    if (!url) { showToast('لا يوجد رقم للطلب'); return false; }
    window.open(url, '_blank');

    // Post-send housekeeping: clear the cart (cart mode only — a single-item
    // direct order shouldn't wipe whatever the visitor has queued up).
    if (ctx.mode === 'cart') {
        resetCartAfterSend();
        showToast("\u{2705} تم إرسال طلبك — شكراً لك!"); // ✅
    }
    return true;
}

/* Apply the owner's custom button label to the cart send button on startup. */
(function applyCustomOrderLabels() {
    if (!ORDER_MSG_ENABLED) return;
    const lbl = document.getElementById('cartSendLabel');
    if (lbl && ORDER_MSG_CFG.msg_button_label) {
        lbl.textContent = ORDER_MSG_CFG.msg_button_label;
    }
})();

/* --- QUICK-ADD from cards --- */
document.querySelectorAll('[data-add]').forEach(btn => {
    btn.addEventListener('click', e => {
        e.stopPropagation();
        const item = JSON.parse(btn.dataset.add);
        addToCart(item);
        btn.classList.add('added');
        setTimeout(() => btn.classList.remove('added'), 400);
    });
});

/* --- FAVORITES --- */
const RESTAURANT_SLUG = <?= json_encode($r['slug']) ?>;
const FAV_API_URL = <?= json_encode(BASE_URL . '/public/api/toggle-favorite.php') ?>;

function toggleFav(id) {
    const wasActive = favs.has(id);
    if (wasActive) favs.delete(id);
    else favs.add(id);
    saveFavs();
    document.querySelectorAll(`[data-fav="${id}"]`).forEach(el => el.classList.toggle('active', favs.has(id)));

    // Sync to server (fire & forget — failure just means count won't update server-side)
    fetch(FAV_API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'slug=' + encodeURIComponent(RESTAURANT_SLUG) + '&item_id=' + id,
        credentials: 'same-origin'
    }).catch(() => { /* fail silently */ });
}
document.querySelectorAll('[data-fav]').forEach(btn => {
    const id = parseInt(btn.dataset.fav);
    if (favs.has(id)) btn.classList.add('active');
    btn.addEventListener('click', e => {
        e.stopPropagation();
        toggleFav(id);
    });
});

/* --- SEARCH --- */
const searchInput = document.getElementById('searchInput');
const clearBtn = document.getElementById('clearSearch');
const searchCount = document.getElementById('searchCount');
const noResults = document.getElementById('noResults');
const allSearchable = document.querySelectorAll('.searchable');

function doSearch(q) {
    q = (q || '').trim().toLowerCase();
    clearBtn.classList.toggle('hidden', !q);
    if (!q) {
        allSearchable.forEach(el => el.style.display = '');
        document.querySelectorAll('section[id^="cat-"], section:has(.item-card)').forEach(s => s.style.display = '');
        searchCount.classList.add('hidden');
        noResults.classList.add('hidden');
        return;
    }
    let matches = 0;
    allSearchable.forEach(el => {
        const name = el.dataset.itemName || '';
        const desc = el.dataset.itemDesc || '';
        const hit = name.includes(q) || desc.includes(q);
        el.style.display = hit ? '' : 'none';
        if (hit) matches++;
    });
    // hide sections with no visible items
    document.querySelectorAll('section[id^="cat-"]').forEach(section => {
        const hasVisible = section.querySelector('.item-card:not([style*="display: none"])');
        section.style.display = hasVisible ? '' : 'none';
    });
    searchCount.textContent = matches + ' نتيجة للبحث عن "' + q + '"';
    searchCount.classList.remove('hidden');
    noResults.classList.toggle('hidden', matches > 0);
}
searchInput.addEventListener('input', e => doSearch(e.target.value));
clearBtn.addEventListener('click', () => { searchInput.value = ''; doSearch(''); searchInput.focus(); });

/* --- SHARE --- */
async function shareMenu() {
    const data = { title: RESTAURANT_NAME, text: 'اطّلع على صفحة ' + RESTAURANT_NAME, url: location.href };
    if (navigator.share) {
        try { await navigator.share(data); } catch (e) {}
    } else {
        try { await navigator.clipboard.writeText(location.href); showToast('✓ تم نسخ الرابط'); } catch (e) { prompt('انسخ الرابط:', location.href); }
    }
}

/* --- SCROLL REVEAL --- */
const io = new IntersectionObserver(entries => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
}, { threshold: 0.1 });
document.querySelectorAll('.reveal').forEach(el => io.observe(el));

// Initial cart render
renderCart();
</script>

</body>
</html>
