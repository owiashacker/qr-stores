<?php
/**
 * Expiry-Reminder Telegram Bot — separate from the signup/upgrade bot.
 *
 * Why separate:
 *   - Signup/upgrade events are HIGH-priority interrupts (super admin acts fast).
 *   - Expiry reminders are LOW-priority daily summaries (super admin reviews).
 *   - Different chat IDs / different mute settings.
 *
 * Use:
 *   expirySend($pdo, $message)          — raw send
 *   expiryNotifyStore($pdo, $store, 'expiring_7d')  — high-level (idempotent)
 *
 * Fail-silent — bot outage must never break the cron.
 */

/**
 * Send a raw message to the configured expiry-reminder chat.
 * Returns true on Telegram-200, false otherwise.
 */
function expirySend(PDO $pdo, string $message, ?array $inlineKeyboard = null): bool
{
    $token   = trim((string) siteSetting($pdo, 'expiry_bot_token', ''));
    $chatId  = trim((string) siteSetting($pdo, 'expiry_bot_chat_id', ''));
    $enabled = siteSetting($pdo, 'expiry_bot_enabled', '0') === '1';

    if (!$enabled || $token === '' || $chatId === '') {
        return false;
    }

    $payload = [
        'chat_id'                  => $chatId,
        'text'                     => $message,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($inlineKeyboard !== null) {
        $payload['reply_markup'] = json_encode([
            'inline_keyboard' => $inlineKeyboard,
        ], JSON_UNESCAPED_UNICODE);
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $body !== false && $http === 200;
}


/**
 * Catalog of notification types + their thresholds + Arabic templates.
 * Each entry's `days_check` is matched against (subscription_expires_at - today)
 * by the cron: positive = N days BEFORE expiry, 0 = today, negative = AFTER expiry.
 */
function expiryNotificationCatalog(): array
{
    return [
        'expiring_7d' => [
            'days_check' => 7,
            'title'      => '🔔 اشتراك يقترب من الانتهاء — أمامه أسبوع',
            'wa_template' => "أهلاً {name} 👋\n\nتحيّة من فريق QR Stores 🌿\n\nاشتراكك في باقة {plan} رح ينتهي بعد 7 أيام (بتاريخ {date}).\n\nمن خلال متجرك \"{store}\" قدّمت لزبائنك تجربة احترافية. لتستمر بنفس الزخم بدون انقطاع، تقدر تجدّد اشتراكك من نفس لوحة التحكم بضغطة واحدة.\n\nلو احتجت أي مساعدة، فريقنا جاهز يخدمك.\n\nدمت بخير،\nفريق QR Stores",
        ],

        'expiring_3d' => [
            'days_check' => 3,
            'title'      => '⚠️ اشتراك يقترب من الانتهاء — 3 أيام',
            'wa_template' => "مرحبا {name} 🌿\n\nتذكير ودّي: اشتراكك ينتهي بعد 3 أيام فقط (يوم {date}).\n\nبعد هالتاريخ، متجر \"{store}\" رح يبقى يعمل لزبائنك، بس أنت ما رح تقدر تعدّل أو تضيف منتجات جديدة لحدّ ما تجدّد.\n\nعشان تتجنّب أي توقّف، الأفضل تجدّد قبل الموعد.\n\nنشكر ثقتك فينا 💚\nفريق QR Stores",
        ],

        'expiring_1d' => [
            'days_check' => 1,
            'title'      => '🚨 اشتراك ينتهي غداً',
            'wa_template' => "{name} 👋\n\nاشتراكك ينتهي *بكرا*.\n\nما بدنا نزعجك، بس بدنا نتأكّد إنّك ما رح تخسر:\n• إمكانية تعديل منتجاتك أو إضافة جديد\n• الإحصائيات اليومية\n• ميزات باقتك الحالية\n\nمتجرك رح يبقى ظاهر للزبائن، بس بصلاحيات الباقة التجريبية فقط.\n\nشكراً لكونك جزء من عيلة QR Stores 💚",
        ],

        'expiring_today' => [
            'days_check' => 0,
            'title'      => '🛑 اشتراك ينتهي اليوم',
            'wa_template' => "صباح الخير {name} ☀️\n\nاليوم آخر يوم في اشتراكك.\n\nمن بعد منتصف الليل، متجر \"{store}\" رح يحوّل تلقائياً للوضع المحدود — يعمل لزبائنك، بس لوحة التحكم تتقفل بوجه التعديلات.\n\nخلّي شغلك مستمر بدون انقطاع — جدّد قبل الليلة.\n\nنشكرك،\nفريق QR Stores",
        ],

        'expired_grace' => [
            'days_check' => -1,
            'title'      => '😔 اشتراك انتهى — اليوم الأول',
            'wa_template' => "أهلاً {name} 🌿\n\nلاحظنا إنّ اشتراكك انتهى مبارح.\n\nمتجر \"{store}\" ما زال موجوداً ويعمل لزبائنك، لكنّك حالياً تستخدم الباقة التجريبية المحدودة.\n\nكل المنتجات والفئات والإعدادات اللي شغّلتها محفوظة بالكامل — مجرّد ما تجدّد رح ترجع لكامل ميزات باقتك.\n\nلو الموضوع له علاقة بالسعر أو في شي عم يمنعك، أخبرنا — كلّ ملاحظة تساعدنا نخدمك أحسن.\n\nدمت بخير 💚",
        ],

        'expired_recovery' => [
            'days_check' => -7,
            'title'      => '💔 اشتراك انتهى منذ أسبوع',
            'wa_template' => "{name}، اشتقنا لك 💚\n\nمرّ أسبوع على انتهاء اشتراكك، ومتجر \"{store}\" بانتظارك يرجع للحياة بكامل قوّته.\n\nبنعرف إنّ ظروف كل واحد بتختلف، عشان هيك حضّرنالك عرض خاص:\n\n🎁 خصم 20% على أول شهر تجديد\n\nأو لو حابب تجرّب باقة أصغر، تقدر تنزل للباقة الأقل سعراً وتشوف إذا تناسبك.\n\nبانتظارك،\nفريق QR Stores",
        ],
    ];
}


/**
 * Check if a notification of a given type was already sent for a store.
 * Used by the cron to ensure idempotency (won't double-notify).
 */
function expiryAlreadyNotified(PDO $pdo, int $storeId, string $type): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM subscription_notifications WHERE store_id = ? AND notification_type = ?'
    );
    $stmt->execute([$storeId, $type]);
    return (bool) $stmt->fetchColumn();
}


/**
 * Mark a notification as sent so we never send the same type twice for the same store.
 */
function expiryMarkNotified(PDO $pdo, int $storeId, string $type, bool $telegramOk): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO subscription_notifications (store_id, notification_type, telegram_ok)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE telegram_ok = VALUES(telegram_ok), sent_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$storeId, $type, $telegramOk ? 1 : 0]);
}


/**
 * Build a WhatsApp click-to-chat link with a pre-filled Arabic message.
 * Super admin clicks → WhatsApp opens → message ready to send.
 *
 *   https://wa.me/963944123456?text=URLENCODED
 */
function expiryWhatsAppLink(string $phoneE164, string $messageTemplate, array $vars): string
{
    $msg = $messageTemplate;
    foreach ($vars as $k => $v) {
        $msg = str_replace('{' . $k . '}', (string) $v, $msg);
    }
    $cleanPhone = preg_replace('/\D/', '', $phoneE164);
    return 'https://wa.me/' . $cleanPhone . '?text=' . rawurlencode($msg);
}


/**
 * Format a Telegram notification for a single store + send it.
 * Includes inline-keyboard buttons:
 *   📲 Send WhatsApp reminder  (deep link with pre-filled message)
 *   🔗 Open in admin panel
 *
 * Returns true if Telegram accepted the message.
 */
function expiryNotifyStore(PDO $pdo, array $store, string $type): bool
{
    $catalog = expiryNotificationCatalog();
    if (!isset($catalog[$type])) return false;

    $entry = $catalog[$type];

    // Guard against duplicates — caller should also check, but defense in depth.
    if (expiryAlreadyNotified($pdo, (int) $store['id'], $type)) {
        return false;
    }

    // Variables for both Telegram + WhatsApp templates
    $expDate = $store['subscription_expires_at'] ?? '';
    $arDate  = $expDate ? expiryArabicDate($expDate) : '—';
    $vars = [
        'name'  => $store['name'] ?? 'صديقنا',
        'store' => $store['name'] ?? '—',
        'plan'  => $store['plan_name'] ?? 'الحالية',
        'date'  => $arDate,
    ];

    // ── Build the Telegram message (super admin sees this) ──
    $msg  = "<b>{$entry['title']}</b>\n\n";
    $msg .= "🏪 المتجر: <b>" . htmlspecialchars($store['name'] ?? '—', ENT_QUOTES, 'UTF-8') . "</b>\n";
    $msg .= "📦 الباقة: " . htmlspecialchars($store['plan_name'] ?? '—', ENT_QUOTES, 'UTF-8') . "\n";
    $msg .= "📅 الانتهاء: {$arDate}\n";
    if (!empty($store['phone'])) {
        $msg .= "📞 الهاتف: " . htmlspecialchars($store['phone'], ENT_QUOTES, 'UTF-8') . "\n";
    }
    if (!empty($store['whatsapp'])) {
        $msg .= "💬 الواتساب: <code>" . htmlspecialchars($store['whatsapp'], ENT_QUOTES, 'UTF-8') . "</code>\n";
    }
    if (!empty($store['email'])) {
        $msg .= "📧 البريد: " . htmlspecialchars($store['email'], ENT_QUOTES, 'UTF-8') . "\n";
    }

    // Inline buttons: WhatsApp deep link + admin link
    $buttons = [];

    if (!empty($store['whatsapp'])) {
        $waLink = expiryWhatsAppLink($store['whatsapp'], $entry['wa_template'], $vars);
        $buttons[] = [['text' => '📲 إرسال التذكير على واتساب', 'url' => $waLink]];
    }

    $baseUrl = rtrim((string) siteSetting($pdo, 'site_url', ''), '/');
    if ($baseUrl !== '') {
        $buttons[] = [['text' => '🔗 عرض المتجر في لوحة الإدارة',
                        'url' => $baseUrl . '/super/stores.php?id=' . (int) $store['id']]];
    }

    $ok = expirySend($pdo, $msg, $buttons ?: null);
    expiryMarkNotified($pdo, (int) $store['id'], $type, $ok);

    return $ok;
}


/**
 * Convert a Y-m-d date to an Arabic-friendly "الأحد 11 أيار 2026" form.
 */
function expiryArabicDate(string $isoDate): string
{
    $months = [
        1 => 'كانون الثاني', 2 => 'شباط', 3 => 'آذار', 4 => 'نيسان',
        5 => 'أيار', 6 => 'حزيران', 7 => 'تموز', 8 => 'آب',
        9 => 'أيلول', 10 => 'تشرين الأول', 11 => 'تشرين الثاني', 12 => 'كانون الأول',
    ];
    $days = [
        'Sun' => 'الأحد', 'Mon' => 'الإثنين', 'Tue' => 'الثلاثاء',
        'Wed' => 'الأربعاء', 'Thu' => 'الخميس', 'Fri' => 'الجمعة', 'Sat' => 'السبت',
    ];

    $ts = strtotime($isoDate);
    if ($ts === false) return $isoDate;

    return sprintf(
        '%s %d %s %d',
        $days[date('D', $ts)] ?? '',
        (int) date('j', $ts),
        $months[(int) date('n', $ts)] ?? '',
        (int) date('Y', $ts)
    );
}
