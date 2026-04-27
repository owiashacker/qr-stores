<?php
/**
 * Order Message Studio — helper library
 *
 * Centralizes defaults, presets, and placeholder rendering for the
 * "Custom Visitor Order Message" feature (MAX plan only).
 *
 * Consumers:
 *   - admin/settings.php ........ Order Message Studio UI
 *   - public/menu.php ........... visitor-side WhatsApp link + pre-send modal
 *   - includes/order_message.php .. (this file, the single source of truth)
 *
 * When a restaurant is NOT on a plan with can_custom_message, or when the MAX
 * subscription has expired, the defaults here are used verbatim so the public
 * menu keeps working exactly like it did before the feature existed.
 */

// =========================================================================
// DEFAULTS — used when a column is NULL OR when the plan doesn't allow
// customization. Sector-aware when `$store` is passed: cars use "استفسر" etc.
// Supported placeholders:
//   {greeting}, {restaurant}, {store}, {item}, {price}, {qty}, {name},
//   {phone}, {table}, {notes}, {address}, {link}, {time}, {date}, {signature}
// =========================================================================
function orderMessageDefaults($store = null)
{
    // Sector-aware verbs (fall back to restaurant phrasing).
    // `order_verb` comes from business_types via the JOIN in currentStore().
    $orderVerb = trim((string) ($store['order_verb'] ?? '')) ?: 'أطلب';

    // Verbal noun (المصدر) lookup — "أطلب" → "طلب", "استفسر" → "الاستفسار عن"
    // produces grammatically clean Arabic that reads naturally after "أريد".
    $verbalNoun = [
        'أطلب'    => 'طلب',
        'استفسر' => 'الاستفسار عن',
        'اشتر'    => 'شراء',
        'احجز'    => 'حجز',
    ][$orderVerb] ?? 'طلب';

    $defaultTemplate = "{greeting}\nأريد {$verbalNoun}: *{item}*{price}\nمن: {restaurant}{signature}";

    return [
        'msg_template' => $defaultTemplate,
        'msg_greeting' => "مرحباً \u{1F44B}", // 👋
        'msg_signature' => '',
        'msg_button_label' => $orderVerb . ' عبر واتساب',
        'msg_modal_title' => 'أكمل رسالتك',
        'msg_modal_subtitle' => 'عبّئ بياناتك وسيتم فتح محادثة واتساب مع المتجر.',
        'msg_ask_name' => 0,
        'msg_ask_phone' => 0,
        'msg_ask_table' => 0,
        'msg_ask_quantity' => 0,
        'msg_ask_notes' => 0,
        'msg_ask_address' => 0,
        'msg_include_price' => 1,
        'msg_include_link' => 0,
        'msg_include_time' => 0,
        'msg_emoji_style' => 'standard',
        'msg_channel_priority' => 'whatsapp',
        'msg_require_confirm' => 0,
    ];
}

// =========================================================================
// PRESETS — ready-made templates the restaurant owner can apply with one click
// Each preset overwrites msg_template (and optionally greeting/signature) only.
// Toggles (ask_name, include_price, …) stay as-is.
// =========================================================================
function orderMessagePresets()
{
    return [
        'classic' => [
            'label' => 'الكلاسيكي',
            'description' => 'رسالة مختصرة ومباشرة — الأكثر استخداماً',
            'template' => "مرحباً، أريد طلب:\n• *{item}*{price}\n\nمن: {restaurant}",
            'greeting' => 'مرحباً',
            'signature' => '',
        ],
        'warm' => [
            'label' => 'ودود ودافئ',
            'description' => 'يستخدم تحية دافئة وإيموجي يدعو للود',
            'template' => "{greeting}\n\nأودّ طلب هذا المنتج من متجركم:\n\u{1F4E6} *{item}*{price}\n\nشكراً لكم — {restaurant}{signature}", // 📦
            'greeting' => "السلام عليكم \u{1F33F}", // 🌿
            'signature' => "\n\nمع تحيات زبونكم \u{2728}", // ✨
        ],
        'professional' => [
            'label' => 'احترافي',
            'description' => 'بصيغة رسمية مناسبة للمطاعم الفاخرة',
            'template' => "{greeting}\n\nأتواصل معكم بخصوص طلب من قائمة {restaurant}:\n\nالصنف: *{item}*{price}\n{qty}\n{notes}\n\nأرجو تأكيد الطلب. شكراً لكم.{signature}",
            'greeting' => 'تحية طيبة وبعد،',
            'signature' => "\n\nمع خالص التقدير.",
        ],
        'delivery' => [
            'label' => 'توصيل',
            'description' => 'مناسب لمطاعم التوصيل — يطلب العنوان والهاتف',
            'template' => "{greeting}\nأريد طلب توصيل من {restaurant}:\n\n\u{1F4E6} *{item}*{price}\n{qty}\n\u{1F464} الاسم: {name}\n\u{1F4DE} الهاتف: {phone}\n\u{1F4CD} العنوان: {address}\n\u{1F4DD} ملاحظات: {notes}{signature}", // 📦 👤 📞 📍 📝
            'greeting' => "مرحباً \u{1F6F5}", // 🛵
            'signature' => "\n\nشكراً جزيلاً \u{1F64F}", // 🙏
        ],
        'dine_in' => [
            'label' => 'طاولة (Dine-in)',
            'description' => 'للزبون داخل المطعم — يطلب رقم الطاولة',
            'template' => "{greeting}\nنحن في الطاولة رقم *{table}* في {restaurant}\nونريد طلب:\n\n\u{1F374} *{item}*{price}\n{qty}\n\u{1F4DD} {notes}{signature}", // 🍴 📝
            'greeting' => "مرحباً \u{1F37D}\u{FE0F}", // 🍽️
            'signature' => '',
        ],
        'quick' => [
            'label' => 'سريع ومختصر',
            'description' => 'أقصر صيغة ممكنة — بدون مقدمات',
            'template' => "طلب: *{item}*{price} — {restaurant}",
            'greeting' => '',
            'signature' => '',
        ],
        'english' => [
            'label' => 'English',
            'description' => 'Template in English for non-Arabic customers',
            'template' => "Hi! I'd like to order:\n• *{item}*{price}\n\nFrom: {restaurant}",
            'greeting' => 'Hi!',
            'signature' => '',
        ],
    ];
}

// =========================================================================
// EMOJI STYLES — lets owner control how much emoji appears in messages
// =========================================================================
function orderMessageEmojiStyles()
{
    return [
        'standard' => 'قياسي (إيموجي معتدل)',
        'rich' => 'غني (إيموجي أكثر)',
        'minimal' => 'خفيف (بدون إيموجي)',
    ];
}

function orderMessageChannels()
{
    return [
        'whatsapp' => 'واتساب',
        'phone' => 'اتصال هاتفي',
        'both' => 'الاثنان (مع أولوية للواتساب)',
    ];
}

// =========================================================================
// SETTINGS RESOLVER — merges a $store row with defaults.
// Returns the full config dict regardless of plan (callers enforce gating).
// =========================================================================
function orderMessageResolve($store)
{
    // Pass the store so defaults pick sector-aware phrasing (verb, noun, …).
    $defaults = orderMessageDefaults($store);
    $out = [];
    foreach ($defaults as $key => $fallback) {
        $val = $store[$key] ?? null;
        // NULL or empty-string → default
        if ($val === null || $val === '') {
            $out[$key] = $fallback;
        } else {
            $out[$key] = $val;
        }
    }
    return $out;
}

// =========================================================================
// PLACEHOLDER RENDERER — server-side template rendering (PHP).
// The JS side in public/menu.php uses the exact same placeholder set so
// preview and actual message stay identical.
//
// $tokens should be an associative array. Missing tokens render as empty.
// =========================================================================
function orderMessageRender($template, $tokens)
{
    if ($template === null || $template === '') return '';
    // Sector-neutral alias: `{store}` resolves to the same value as `{restaurant}`.
    // Old templates stay valid; new ones can use either name.
    if (!isset($tokens['store']) && isset($tokens['restaurant'])) {
        $tokens['store'] = $tokens['restaurant'];
    }
    $out = $template;
    $replace = [];
    foreach ($tokens as $k => $v) {
        $replace['{' . $k . '}'] = (string) $v;
    }
    $out = strtr($out, $replace);
    // Strip any placeholder that wasn't provided
    $out = preg_replace('/\{[a-z_]+\}/', '', $out);
    // Collapse 3+ consecutive newlines to 2 (keeps message tidy when toggles off)
    $out = preg_replace("/\n{3,}/", "\n\n", $out);
    // Trim leading/trailing whitespace
    return trim($out);
}

// =========================================================================
// AUTO-APPEND — takes the rendered message and sticks enabled fields that
// the template didn't consume onto the end as a "details" block.
//
// Why: the default/preset templates don't include {name}, {phone}, {link}
// etc. So without this, toggling ask_name ON but leaving the classic
// template produces a message where the visitor's name is silently dropped.
// This ensures "every toggle does something visible".
//
// $config is the resolved cfg (orderMessageResolve). $tokens is what we fed
// the renderer. $rendered is the output of orderMessageRender.
// =========================================================================
function orderMessageAutoAppend($config, $tokens, $rendered)
{
    $template = (string) ($config['msg_template'] ?? '');
    $emoji = $config['msg_emoji_style'] ?? 'standard';
    $useIcons = $emoji !== 'minimal';

    // Helper: was this token referenced in the raw template?
    $usedInTpl = function ($token) use ($template) {
        return strpos($template, '{' . $token . '}') !== false;
    };

    $blocks = [];

    // Icons as Unicode escapes — immune to any file-encoding hiccups.
    // 👤 \u{1F464}   📞 \u{1F4DE}   📍 \u{1F4CD}   🪑 \u{1FA91}
    // 🔢 \u{1F522}   📝 \u{1F4DD}   🔗 \u{1F517}   🕒 \u{1F552}
    $ICON = [
        'name'    => "\u{1F464}",
        'phone'   => "\u{1F4DE}",
        'address' => "\u{1F4CD}",
        'table'   => "\u{1FA91}",
        'qty'     => "\u{1F522}",
        'notes'   => "\u{1F4DD}",
        'link'    => "\u{1F517}",
        'time'    => "\u{1F552}",
    ];

    // Visitor-input fields: append "<icon> <label>: <value>" for enabled
    // toggles whose value is non-empty AND not already in the template.
    $fields = [
        ['flag' => 'msg_ask_name',     'token' => 'name',    'label' => 'الاسم'],
        ['flag' => 'msg_ask_phone',    'token' => 'phone',   'label' => 'الهاتف'],
        ['flag' => 'msg_ask_address',  'token' => 'address', 'label' => 'العنوان'],
        ['flag' => 'msg_ask_table',    'token' => 'table',   'label' => 'رقم الطاولة'],
        ['flag' => 'msg_ask_quantity', 'token' => 'qty',     'label' => 'الكمية'],
        ['flag' => 'msg_ask_notes',    'token' => 'notes',   'label' => 'ملاحظات'],
    ];
    foreach ($fields as $f) {
        if (empty($config[$f['flag']])) continue;
        $val = (string) ($tokens[$f['token']] ?? '');
        if ($val === '') continue;
        if ($usedInTpl($f['token'])) continue;
        $prefix = $useIcons ? ($ICON[$f['token']] . ' ') : '';
        $blocks[] = $prefix . $f['label'] . ': ' . $val;
    }

    // Appearance toggles: link + timestamp
    if (!empty($config['msg_include_link']) && !empty($tokens['link']) && !$usedInTpl('link')) {
        $prefix = $useIcons ? ($ICON['link'] . ' ') : '';
        $blocks[] = $prefix . 'القائمة: ' . $tokens['link'];
    }
    if (!empty($config['msg_include_time']) && !$usedInTpl('time') && !$usedInTpl('date')) {
        $stamp = trim(($tokens['date'] ?? '') . ' ' . ($tokens['time'] ?? ''));
        if ($stamp !== '') {
            $prefix = $useIcons ? ($ICON['time'] . ' ') : '';
            $blocks[] = $prefix . $stamp;
        }
    }

    if (empty($blocks)) return $rendered;
    return rtrim($rendered) . "\n\n" . implode("\n", $blocks);
}

// =========================================================================
// FORMAT — render + auto-append in one call. Applies emoji-minimal filter
// last. Use this instead of orderMessageRender() whenever you have the full
// config and want the "final" visitor-facing message.
// =========================================================================
function orderMessageFormat($config, $tokens)
{
    $rendered = orderMessageRender($config['msg_template'] ?? '', $tokens);
    $final = orderMessageAutoAppend($config, $tokens, $rendered);
    if (($config['msg_emoji_style'] ?? '') === 'minimal') {
        // Strip emoji: pictographic Unicode blocks + symbols. Keep Arabic intact.
        $final = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F000}-\x{1F2FF}]/u', '', $final);
        $final = preg_replace('/ {2,}/', ' ', $final);
    }
    return trim($final);
}

// =========================================================================
// Build the JS-safe config payload used by public/menu.php.
// Returns a normalized array that can be json_encode'd directly.
// =========================================================================
function orderMessageJsConfig($store)
{
    $cfg = orderMessageResolve($store);
    // Cast bool-ish columns to actual bools for clean JS
    $boolKeys = [
        'msg_ask_name', 'msg_ask_phone', 'msg_ask_table', 'msg_ask_quantity',
        'msg_ask_notes', 'msg_ask_address', 'msg_include_price',
        'msg_include_link', 'msg_include_time', 'msg_require_confirm',
    ];
    foreach ($boolKeys as $k) $cfg[$k] = (bool) (int) $cfg[$k];
    return $cfg;
}

// =========================================================================
// Placeholder chips shown in the admin Studio UI — label + token + hint.
// =========================================================================
function orderMessagePlaceholders()
{
    return [
        ['token' => 'item', 'label' => 'اسم المنتج', 'hint' => 'مثل: شاورما دجاج / قميص كلاسيكي / iPhone 15'],
        ['token' => 'price', 'label' => 'السعر', 'hint' => 'يضاف إذا كان خيار عرض السعر مفعل'],
        ['token' => 'store', 'label' => 'اسم المتجر', 'hint' => 'اسم متجرك الحالي (يعمل مع {restaurant} أيضاً)'],
        ['token' => 'restaurant', 'label' => 'اسم المتجر (قديم)', 'hint' => 'مرادف لـ {store} — يبقى للتوافقية'],
        ['token' => 'greeting', 'label' => 'التحية', 'hint' => 'تحية البداية المخصصة'],
        ['token' => 'signature', 'label' => 'التوقيع', 'hint' => 'نص إغلاق الرسالة'],
        ['token' => 'qty', 'label' => 'الكمية', 'hint' => 'يُعرض إذا فُعّل السؤال عن الكمية'],
        ['token' => 'name', 'label' => 'اسم الزبون', 'hint' => 'يُعرض إذا فُعّل السؤال عن الاسم'],
        ['token' => 'phone', 'label' => 'هاتف الزبون', 'hint' => 'يُعرض إذا فُعّل السؤال عن الهاتف'],
        ['token' => 'table', 'label' => 'رقم الطاولة', 'hint' => 'للمطاعم — يُعرض إذا فُعّل'],
        ['token' => 'address', 'label' => 'عنوان التوصيل', 'hint' => 'للتوصيل — يُعرض إذا فُعّل'],
        ['token' => 'notes', 'label' => 'ملاحظات', 'hint' => 'ملاحظات إضافية من الزبون'],
        ['token' => 'link', 'label' => 'رابط المتجر', 'hint' => 'رابط متجرك الإلكتروني'],
        ['token' => 'time', 'label' => 'الوقت', 'hint' => 'وقت إرسال الطلب'],
        ['token' => 'date', 'label' => 'التاريخ', 'hint' => 'تاريخ إرسال الطلب'],
    ];
}
