<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/activity_tracker.php';

// Security hardening runs automatically on include (session + headers).
// errorLogInit replaces the legacy setup_error_handling() — it writes every
// uncaught exception/fatal to error_logs table and shows a user-friendly page
// with a short code (e.g. "E-A1B2C3"). Super admin sees all errors in super/errors.php.
errorLogInit($pdo);

// activityTrackerInit logs every PHP request to activity_logs (shutdown handler).
// Captures method, URL, HTTP status, user, IP, duration, etc. Super admin views
// this in super/activity.php. Fails silently if DB is unreachable.
activityTrackerInit($pdo);

function e($str)
{
    return safe_html($str ?? '');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function flash($key, $message = null)
{
    if ($message === null) {
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    $_SESSION['flash'][$key] = $message;
}

function old($key, $default = '')
{
    $val = $_SESSION['old'][$key] ?? $default;
    return $val;
}

function keepOld($data)
{
    $_SESSION['old'] = $data;
}

function clearOld()
{
    unset($_SESSION['old']);
}

function slugify($text)
{
    $text = trim($text);
    $text = preg_replace('/\s+/', '-', $text);
    $text = preg_replace('/[^\p{L}\p{N}\-]/u', '', $text);
    $text = strtolower($text);
    return $text ?: 'r-' . rand(1000, 9999);
}

function generateUniqueSlug($pdo, $name, $excludeId = null)
{
    $base = slugify($name);
    $slug = $base;
    $i = 1;
    while (true) {
        $sql = 'SELECT id FROM stores WHERE slug = ?';
        $params = [$slug];
        if ($excludeId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) return $slug;
        $i++;
        $slug = $base . '-' . $i;
    }
}

function uploadImage($fileKey, $targetDir)
{
    $result = secure_upload($fileKey, $targetDir, 5 * 1024 * 1024);
    if ($result === null) return null;
    if (is_array($result) && isset($result['error'])) return false;
    return $result;
}

function deleteUpload($folder, $filename)
{
    if (!$filename) return;
    $path = __DIR__ . '/../assets/uploads/' . $folder . '/' . $filename;
    if (file_exists($path)) @unlink($path);
}

function requireLogin()
{
    if (empty($_SESSION['store_id'])) {
        redirect(BASE_URL . '/admin/login.php');
    }
}

function currentStore($pdo)
{
    if (empty($_SESSION['store_id'])) return null;
    // Join plans (for capabilities) + business_types (for dynamic labels + specs schema).
    // `$r` is used so existing code keeps working; columns are qualified by prefix.
    $stmt = $pdo->prepare('SELECT r.*,
            p.code AS plan_code, p.name AS plan_name,
            p.max_categories, p.max_items, p.max_qr_styles,
            p.can_upload_logo, p.can_upload_cover, p.can_customize_colors,
            p.can_edit_contact, p.can_social_links, p.can_use_discount,
            p.can_feature_items, p.can_remove_watermark, p.can_custom_domain,
            p.can_multiple_media, p.can_custom_message, p.has_analytics,
            bt.code AS biz_code, bt.name_ar AS biz_name, bt.icon AS biz_icon,
            bt.label_singular, bt.label_plural, bt.label_category, bt.order_verb,
            bt.fields_schema AS biz_fields_schema
        FROM stores r
        LEFT JOIN plans p ON r.plan_id = p.id
        LEFT JOIN business_types bt ON r.business_type_id = bt.id
        WHERE r.id = ?');
    $stmt->execute([$_SESSION['store_id']]);
    $r = $stmt->fetch();
    if (!$r) return null;
    return apply_expired_downgrade($pdo, $r);
}

/**
 * If subscription expired, override plan capabilities with Free plan values.
 * Original plan_id stays intact so renewal restores access automatically.
 * Original plan info is preserved in `original_plan_code` / `original_plan_name`.
 */
function apply_expired_downgrade($pdo, $r)
{
    $r['is_expired'] = false;
    $r['original_plan_code'] = $r['plan_code'] ?? null;
    $r['original_plan_name'] = $r['plan_name'] ?? null;
    $r['expired_at_raw'] = $r['subscription_expires_at'] ?? null;

    $exp = $r['subscription_expires_at'] ?? null;
    if (!$exp) return $r; // permanent plan
    if (strtotime($exp) > time()) return $r; // still active

    // Expired — load Free plan to override
    static $free = null;
    if ($free === null) {
        $stmt = $pdo->query("SELECT * FROM plans WHERE code = 'free' LIMIT 1");
        $free = $stmt ? $stmt->fetch() : [];
    }
    if (!$free) return $r;

    $r['is_expired'] = true;
    $overrides = ['max_categories', 'max_items', 'max_qr_styles', 'can_upload_logo', 'can_upload_cover', 'can_customize_colors', 'can_edit_contact', 'can_social_links', 'can_use_discount', 'can_feature_items', 'can_remove_watermark', 'can_custom_domain', 'can_multiple_media', 'can_custom_message', 'has_analytics'];
    foreach ($overrides as $k) {
        if (array_key_exists($k, $free)) $r[$k] = $free[$k];
    }
    $r['plan_code'] = 'free';
    $r['plan_name'] = $free['name'] ?? 'مجاني';
    return $r;
}

// =========================================
// BUSINESS TYPE — dynamic labels per sector
// =========================================
/**
 * Get a dynamic label for the current store's business type.
 *
 * Examples:
 *   bizLabel($r, 'singular')  // "صنف" for restaurant, "منتج" for grocery, "موديل" for cars
 *   bizLabel($r, 'plural')    // "الأصناف" / "المنتجات" / "الموديلات"
 *   bizLabel($r, 'category')  // "القسم" / "الفئة"
 *   bizLabel($r, 'order_verb') // "أطلب" / "استفسر"
 *   bizLabel($r, 'name')      // "مطعم" / "محل ألبسة"
 *   bizLabel($r, 'icon')      // "🍽️"
 *
 * Safe fallback: returns restaurant-style labels when business_type is missing
 * (e.g. unit tests, malformed records).
 */
function bizLabel($r, $key)
{
    // Computed key: plural form of `label_category` (sidebar "الأقسام"/"الفئات").
    // Lightweight map avoids an extra DB column while staying grammatically correct.
    if ($key === 'categories') {
        $sing = $r['label_category'] ?? 'القسم';
        $pluralMap = [
            'القسم' => 'الأقسام',
            'الفئة' => 'الفئات',
            'الصنف' => 'الأصناف',
            'النوع' => 'الأنواع',
            'المجموعة' => 'المجموعات',
        ];
        return $pluralMap[$sing] ?? $sing;
    }

    $map = [
        'singular' => 'label_singular',
        'plural' => 'label_plural',
        'category' => 'label_category',
        'order_verb' => 'order_verb',
        'name' => 'biz_name',
        'icon' => 'biz_icon',
        'code' => 'biz_code',
    ];
    $col = $map[$key] ?? $key;
    if (!empty($r[$col])) return $r[$col];

    $defaults = [
        'singular' => 'صنف',
        'plural' => 'الأصناف',
        'category' => 'القسم',
        'order_verb' => 'أطلب',
        'name' => 'متجر',
        'icon' => '🏪',
        'code' => 'restaurant',
    ];
    return $defaults[$key] ?? '';
}

/**
 * Return the business-type name with the Arabic definite article "ال" applied
 * naturally. For compound names (محل ألبسة → محل الألبسة, أجهزة منزلية → الأجهزة المنزلية)
 * this handles each pattern so UI strings read grammatically.
 *
 * Examples:
 *   مطعم           → المطعم
 *   محل ألبسة      → محل الألبسة
 *   أجهزة منزلية    → الأجهزة المنزلية
 *   بقالية          → البقالية
 */
function bizNameDefinite($r)
{
    $name = bizLabel($r, 'name');
    if (!$name) return 'المتجر';
    // Already definite? leave alone.
    if (mb_strpos($name, 'ال') === 0) return $name;

    // "أجهزة منزلية" style — noun + adjective, both get ال.
    if (mb_strpos($name, 'أجهزة ') === 0) {
        $rest = mb_substr($name, mb_strlen('أجهزة '));
        return 'الأجهزة ال' . $rest;
    }
    // Compound name: "محل X" / "معرض X" / "متجر X" — ال on the second word.
    foreach (['محل ', 'معرض ', 'متجر '] as $prefix) {
        if (mb_strpos($name, $prefix) === 0) {
            $rest = mb_substr($name, mb_strlen($prefix));
            return $prefix . 'ال' . $rest;
        }
    }
    // Single word — prepend ال.
    return 'ال' . $name;
}

/**
 * Sector-aware emoji palette for category icon picker.
 * Admin can still type any emoji — this just seeds the picker with
 * sector-relevant suggestions instead of a food-only list.
 */
function categoryIconPalette($r)
{
    $code = $r['biz_code'] ?? 'restaurant';
    $palettes = [
        'restaurant'  => ['🍽️','🥗','🍔','🍕','🍝','🥙','🍗','🥘','🍲','🥪','🌮','🍰','🍦','🍩','🍪','☕','🥤','🧃','🍹','🍷','🥟','🍜','🍱','🥠','🍤','🦐','🥞','🧇','🍳','🥓'],
        'sweets'      => ['🧁','🍰','🎂','🍪','🍩','🍫','🍬','🍭','🍮','🍯','🍨','🍧','🍡','🥮','🥧','🍓','🍒','🫐','🥝','🥥','🍑','🍍','🥜','🍌','🍎','☕','🥤','🧃','🍵','🧋'],
        'grocery'     => ['🛒','🥖','🍞','🥩','🧀','🥚','🥛','🥫','🥦','🥕','🍅','🍎','🍌','🍇','🥔','🧅','🧄','🍉','🌽','🍚','🍝','🥜','🍯','🧈','🥓','🍗','🍖','🍤','🧴','🧻'],
        'clothing'    => ['👕','👔','👗','👖','🧥','🧣','🧤','🧦','👙','👘','🥻','🧢','👒','🎩','👞','👟','👠','👡','👢','🎽','💍','👜','👛','🧳','🕶️','🎒','👚','🥼','🦺','🧸'],
        'phones'      => ['📱','📟','☎️','📞','⌚','🎧','🎙️','🔌','🔋','💾','💿','📷','📸','📹','📺','🎮','🕹️','🖥️','💻','🖨️','🖱️','⌨️','📻','🎚️','🎛️','🔦','💡','🛜','📡','🔧'],
        'electronics' => ['💻','🖥️','📱','📷','📹','🎥','🎤','🎧','🎮','🕹️','⌚','📺','📻','🎙️','🔌','🔋','💡','💿','📀','💾','📟','🖨️','🖱️','⌨️','📡','🛜','🔦','🧯','⚡','🔧'],
        'appliances'  => ['🔌','🔋','💡','🚿','🛁','🛋️','🛏️','🪑','🪞','🪟','🪠','🧺','🧹','🧽','🧴','🪒','🧼','❄️','🔥','🌡️','⏰','🕰️','🔑','📦','🪣','🛒','🗑️','🧊','🧯','🪜'],
        'cars'        => ['🚗','🚙','🚕','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🏍️','🛵','🚲','🛴','🛺','🚌','🚎','⛽','🔧','🔩','🛞','🪫','🔋','🚨','🏁','🛠️','🔑','🗝️'],
    ];
    return $palettes[$code] ?? $palettes['restaurant'];
}

/**
 * Fallback icon for a new category — uses the store's business_type icon if available.
 */
function defaultCategoryIcon($r)
{
    return bizLabel($r, 'icon') ?: '🏪';
}

/**
 * Resolve a category icon (stored as emoji) to a local Fluent Emoji 3D PNG
 * if one exists for the current sector; otherwise return null so the caller
 * can fall back to rendering the raw emoji character.
 *
 * Returned path is a web path (relative to project root) so it works with
 * <?= site_url() ?> / BASE_URL patterns used across the UI.
 */
function resolveCategoryIconPath($emoji, $sectorCode)
{
    static $map = null;
    if ($map === null) {
        $map = require __DIR__ . '/icon_map.php';
    }
    $sector = $sectorCode ?: 'restaurant';
    $sectorMap = $map[$sector] ?? [];
    if (!isset($sectorMap[$emoji])) return null;
    $slug = $sectorMap[$emoji];
    $rel = "assets/icons/categories/$sector/$slug.png";
    $abs = __DIR__ . '/../' . $rel;
    if (!is_file($abs)) return null;
    return $rel;
}

/**
 * Render a category icon as <img> if a Fluent PNG exists for it,
 * otherwise emit the raw emoji wrapped in a span.
 *
 * $iconText    — stored icon (emoji character)
 * $sectorCode  — bt.code of the current store (e.g. 'restaurant')
 * $sizeClass   — Tailwind/utility classes for the icon element
 * $altFallback — text for the alt attribute on <img>
 */
function renderCategoryIcon($iconText, $sectorCode, $sizeClass = 'w-10 h-10', $altFallback = '')
{
    $iconText = (string) $iconText;
    // "No-icon" state: empty string or explicit 'none' marker.
    // Render a neutral tag SVG so the layout still looks balanced without a
    // chosen icon (the store owner explicitly opted for no icon).
    if ($iconText === '' || $iconText === 'none') {
        return '<svg class="' . e($sizeClass) . ' text-emerald-500/70" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">'
             . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/>'
             . '</svg>';
    }
    $path = resolveCategoryIconPath($iconText, $sectorCode);
    if ($path) {
        $alt = $altFallback !== '' ? $altFallback : $iconText;
        // Browsers may cache aggressively; filemtime keeps dev cycle snappy.
        $abs = __DIR__ . '/../' . $path;
        $v = @filemtime($abs) ?: 1;
        return '<img src="' . BASE_URL . '/' . e($path) . '?v=' . $v . '" alt="' . e($alt) . '" class="' . e($sizeClass) . ' object-contain" loading="lazy">';
    }
    // Fallback: the plain emoji character at a reasonable visual size.
    return '<span class="' . e($sizeClass) . ' inline-flex items-center justify-center text-3xl leading-none">' . e($iconText) . '</span>';
}

/**
 * Parse the store's business_type fields_schema into a fresh array.
 * Returns `['fields' => [...]]` — always safe to iterate.
 */
function bizFieldsSchema($r)
{
    $raw = $r['biz_fields_schema'] ?? null;
    if (!$raw) return ['fields' => []];
    $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($data) || empty($data['fields']) || !is_array($data['fields'])) {
        return ['fields' => []];
    }
    return $data;
}

/**
 * Parse stored specs JSON on an item row into an array.
 * Returns [] for missing/malformed data — callers never have to null-check.
 */
function itemSpecs($item)
{
    $raw = $item['specs'] ?? null;
    if (!$raw) return [];
    $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Validate and sanitize POSTed specs against the schema. Unknown keys dropped.
 * For select/multiselect, values are restricted to declared options.
 * Returns an associative array safe to JSON-encode.
 */
function sanitizeSpecsPost(array $schema, array $post): array
{
    $out = [];
    foreach (($schema['fields'] ?? []) as $f) {
        $key = $f['key'] ?? null;
        if (!$key) continue;
        $type = $f['type'] ?? 'text';
        $raw = $post[$key] ?? null;

        switch ($type) {
            case 'boolean':
                if ($raw !== null && $raw !== '' && $raw !== '0') {
                    $out[$key] = 1;
                }
                break;

            case 'number':
                if ($raw !== null && $raw !== '') {
                    // Keep decimals (e.g. 1.5L engine). Cast via float then back to string
                    // so the JSON column stays schema-agnostic.
                    $out[$key] = is_numeric($raw) ? 0 + $raw : null;
                    if ($out[$key] === null) unset($out[$key]);
                }
                break;

            case 'select':
                $options = $f['options'] ?? [];
                if ($raw !== null && $raw !== '' && in_array($raw, $options, true)) {
                    $out[$key] = $raw;
                }
                break;

            case 'multiselect':
                $options = $f['options'] ?? [];
                if (is_array($raw)) {
                    $filtered = array_values(array_filter($raw, fn($v) => in_array($v, $options, true)));
                    if ($filtered) $out[$key] = $filtered;
                }
                break;

            case 'textarea':
            case 'text':
            default:
                $val = is_string($raw) ? trim($raw) : '';
                if ($val !== '') {
                    $out[$key] = mb_substr($val, 0, 500);
                }
                break;
        }
    }
    return $out;
}

/**
 * Render one form control for a schema field. Meant to be called in a
 * loop from admin/items.php. `$current` is the current value (or null).
 * Keeps markup aligned with the rest of the form (tailwind + RTL).
 */
function renderSpecField(array $f, $current = null): string
{
    $key = $f['key'] ?? '';
    if ($key === '') return '';
    $type = $f['type'] ?? 'text';
    $label = $f['label'] ?? $key;
    $placeholder = $f['placeholder'] ?? '';
    $name = "specs[" . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . "]";
    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $lbl = $esc($label);
    $ph = $esc($placeholder);
    $inputCls = 'w-full px-4 py-3 rounded-xl border-2 border-gray-100 focus:border-emerald-500 transition';

    switch ($type) {
        case 'textarea':
            $val = $esc($current ?? '');
            return <<<HTML
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-2">{$lbl}</label>
    <textarea name="{$name}" rows="2" placeholder="{$ph}" class="{$inputCls}">{$val}</textarea>
</div>
HTML;

        case 'number':
            $val = ($current === null || $current === '') ? '' : $esc($current);
            return <<<HTML
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-2">{$lbl}</label>
    <input type="number" step="any" name="{$name}" value="{$val}" placeholder="{$ph}" class="{$inputCls}">
</div>
HTML;

        case 'select':
            $options = $f['options'] ?? [];
            $opts = '<option value="">— اختر —</option>';
            foreach ($options as $opt) {
                $sel = ((string) $current === (string) $opt) ? ' selected' : '';
                $opts .= '<option value="' . $esc($opt) . '"' . $sel . '>' . $esc($opt) . '</option>';
            }
            return <<<HTML
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-2">{$lbl}</label>
    <select name="{$name}" class="{$inputCls}">{$opts}</select>
</div>
HTML;

        case 'multiselect':
            $options = $f['options'] ?? [];
            $currentArr = is_array($current) ? $current : [];
            $checkboxes = '';
            foreach ($options as $opt) {
                $checked = in_array($opt, $currentArr, true) ? ' checked' : '';
                $ev = $esc($opt);
                $checkboxes .= '<label class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border-2 border-gray-100 cursor-pointer hover:border-emerald-300 transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50">'
                    . '<input type="checkbox" name="' . $name . '[]" value="' . $ev . '"' . $checked . ' class="w-4 h-4 rounded text-emerald-600">'
                    . '<span class="text-sm">' . $ev . '</span></label>';
            }
            return <<<HTML
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-2">{$lbl}</label>
    <div class="flex flex-wrap gap-2">{$checkboxes}</div>
</div>
HTML;

        case 'boolean':
            $checked = !empty($current) ? ' checked' : '';
            return <<<HTML
<div>
    <label class="inline-flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="{$name}" value="1"{$checked} class="w-5 h-5 rounded text-emerald-600 focus:ring-emerald-500">
        <span class="font-semibold text-gray-700">{$lbl}</span>
    </label>
</div>
HTML;

        case 'text':
        default:
            $val = $esc($current ?? '');
            return <<<HTML
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-2">{$lbl}</label>
    <input type="text" name="{$name}" value="{$val}" placeholder="{$ph}" class="{$inputCls}">
</div>
HTML;
    }
}

// =========================================
// PLAN PERMISSIONS
// =========================================
function canDo($r, $capability)
{
    $key = 'can_' . $capability;
    return isset($r[$key]) && (int) $r[$key] === 1;
}

function planLimit($r, $what)
{
    $key = 'max_' . $what;
    return $r[$key] ?? -1;
}

function isWithinLimit($pdo, $r, $resource)
{
    $max = planLimit($r, $resource);
    if ($max === -1 || $max === null) return true;
    $table = $resource === 'categories' ? 'categories' : 'items';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE store_id = ?");
    $stmt->execute([$r['id']]);
    return (int) $stmt->fetchColumn() < (int) $max;
}

function countResource($pdo, $rid, $resource)
{
    $table = $resource === 'categories' ? 'categories' : 'items';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE store_id = ?");
    $stmt->execute([$rid]);
    return (int) $stmt->fetchColumn();
}

function siteSetting($pdo, $key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT key_name, value FROM site_settings') as $row) {
            $cache[$row['key_name']] = $row['value'];
        }
    }
    return $cache[$key] ?? $default;
}

// =========================================
// SUPER ADMIN AUTH
// =========================================
function requireAdminLogin()
{
    if (empty($_SESSION['admin_id'])) {
        redirect(BASE_URL . '/super/login.php');
    }
}

function currentAdmin($pdo)
{
    if (empty($_SESSION['admin_id'])) return null;
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch();
}

function formatPrice($price, $currency = 'ل.س')
{
    $price = (float) $price;
    if ($price == floor($price)) {
        return number_format($price, 0) . ' ' . $currency;
    }
    return number_format($price, 2) . ' ' . $currency;
}

// =========================================
// ANALYTICS — Views + Favorites
// =========================================

/**
 * Track a store page view.
 * - Increments total views_count on every visit
 * - Increments unique_views once per session (dedup)
 * - Updates daily aggregation table for charts
 */
function trackStoreView($pdo, $storeId)
{
    $storeId = (int) $storeId;
    if ($storeId <= 0) return;

    $sessionKey = '_viewed_store_' . $storeId;
    $isUnique = empty($_SESSION[$sessionKey]);

    try {
        // Bump total views on every visit
        $pdo->prepare('UPDATE stores SET views_count = views_count + 1' . ($isUnique ? ', unique_views = unique_views + 1' : '') . ' WHERE id = ?')
            ->execute([$storeId]);

        // Update daily aggregation
        $today = date('Y-m-d');
        $sql = 'INSERT INTO store_views_daily (store_id, view_date, views_count, unique_views)
                VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE
                views_count = views_count + 1' . ($isUnique ? ', unique_views = unique_views + 1' : '');
        $pdo->prepare($sql)->execute([$storeId, $today, $isUnique ? 1 : 0]);

        if ($isUnique) $_SESSION[$sessionKey] = time();
    } catch (PDOException $e) {
        // Fail silently — tracking must never break the page
    }
}

/**
 * Return a hash that identifies this browser session (for favorites dedup).
 * We use session ID which is already a cryptographically random token.
 */
function visitorSessionHash()
{
    $sid = session_id();
    if (!$sid) {
        $sid = bin2hex(random_bytes(16));
        $_SESSION['_visitor_sid'] = $sid;
    }
    return hash('sha256', $sid);
}

/**
 * Toggle an item favorite. Returns ['favorited' => bool, 'count' => int] or null on failure.
 */
function toggleItemFavorite($pdo, $itemId, $storeId)
{
    $itemId = (int) $itemId;
    $storeId = (int) $storeId;
    if ($itemId <= 0 || $storeId <= 0) return null;

    // Verify item belongs to restaurant
    $stmt = $pdo->prepare('SELECT id FROM items WHERE id = ? AND store_id = ?');
    $stmt->execute([$itemId, $storeId]);
    if (!$stmt->fetch()) return null;

    $hash = visitorSessionHash();

    // Check if already favorited
    $stmt = $pdo->prepare('SELECT id FROM item_favorites WHERE item_id = ? AND session_hash = ?');
    $stmt->execute([$itemId, $hash]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('DELETE FROM item_favorites WHERE id = ?')->execute([$existing['id']]);
        $pdo->prepare('UPDATE items SET favorites_count = GREATEST(0, favorites_count - 1) WHERE id = ?')->execute([$itemId]);
        $favorited = false;
    } else {
        $pdo->prepare('INSERT INTO item_favorites (store_id, item_id, session_hash) VALUES (?, ?, ?)')
            ->execute([$storeId, $itemId, $hash]);
        $pdo->prepare('UPDATE items SET favorites_count = favorites_count + 1 WHERE id = ?')->execute([$itemId]);
        $favorited = true;
    }

    $stmt = $pdo->prepare('SELECT favorites_count FROM items WHERE id = ?');
    $stmt->execute([$itemId]);
    $count = (int) $stmt->fetchColumn();

    return ['favorited' => $favorited, 'count' => $count];
}

function csrfToken()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfCheck()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

function csrfField()
{
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}
