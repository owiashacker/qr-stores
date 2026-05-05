<?php
/**
 * Telegram notification helper.
 *
 * Setup (one-time):
 *   1. Create a bot via @BotFather on Telegram → grab the token.
 *   2. Send any message to your bot from your account.
 *   3. Visit https://api.telegram.org/bot<TOKEN>/getUpdates → grab your chat_id.
 *   4. Save token + chat_id from super/settings.php.
 *
 * Use:
 *   tgNotify('متجر جديد سُجّل: مكتبة المعرفة');
 *   tgNotifyEvent('store_signup', ['name' => 'متجري']);
 *
 * Designed to FAIL SILENTLY — Telegram outage must never block a signup or payment.
 */

/**
 * Send a raw message to the configured Telegram chat.
 * Returns true on success, false otherwise.
 *
 * Reads token + chat_id + per-event toggles from site_settings.
 * The $eventKey is used to check `telegram_notify_<eventKey>` flag (1 = on, 0 = off).
 * Pass empty $eventKey to bypass the toggle check (e.g. test message).
 */
function tgSend(PDO $pdo, string $message, string $eventKey = ''): bool
{
    $token   = trim((string) siteSetting($pdo, 'telegram_bot_token', ''));
    $chatId  = trim((string) siteSetting($pdo, 'telegram_chat_id', ''));
    $enabled = siteSetting($pdo, 'telegram_enabled', '1') === '1';

    if (!$enabled || $token === '' || $chatId === '') {
        return false;
    }

    // Per-event toggle (default ON if not set)
    if ($eventKey !== '') {
        $flag = siteSetting($pdo, 'telegram_notify_' . $eventKey, '1');
        if ($flag !== '1') return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'                  => $chatId,
            'text'                     => $message,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]),
        CURLOPT_TIMEOUT        => 5,        // never block more than 5s
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,    // some shared hosts have stale CA bundles
    ]);
    $body  = curl_exec($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return $body !== false && $http === 200;
}

/**
 * Convenience: send a high-level event with structured data.
 * Each event has a pre-built message template.
 *
 * Supported events:
 *   - 'store_signup'    payload: ['id', 'name', 'email', 'whatsapp', 'country', 'biz_name']
 *   - 'plan_upgrade'    payload: ['store_id', 'store_name', 'current_plan', 'requested_plan', 'note']
 *   - 'test'            payload: []
 */
function tgNotifyEvent(PDO $pdo, string $eventKey, array $data = []): bool
{
    $msg = '';
    switch ($eventKey) {
        case 'store_signup':
            $name     = $data['name']     ?? '—';
            $email    = $data['email']    ?? '—';
            $whatsapp = $data['whatsapp'] ?? '—';
            $country  = $data['country']  ?? '—';
            $biz      = $data['biz_name'] ?? '—';
            $waLink   = $whatsapp !== '—' ? 'https://wa.me/' . preg_replace('/\D/', '', $whatsapp) : '';

            $msg  = "🆕 <b>تسجيل متجر جديد — بانتظار الموافقة</b>\n\n";
            $msg .= "🏪 <b>{$name}</b>\n";
            $msg .= "📋 النشاط: {$biz}\n";
            $msg .= "🌍 البلد: {$country}\n";
            $msg .= "📧 البريد: {$email}\n";
            if ($waLink !== '') {
                $msg .= "📱 واتساب: <a href=\"{$waLink}\">{$whatsapp}</a>\n";
            } else {
                $msg .= "📱 واتساب: {$whatsapp}\n";
            }
            $msg .= "\n⚠ يحتاج موافقة من السوبر أدمن.";
            break;

        case 'plan_upgrade':
            $storeName = $data['store_name']      ?? '—';
            $current   = $data['current_plan']    ?? '—';
            $requested = $data['requested_plan']  ?? '—';
            $note      = $data['note']            ?? '';

            $msg  = "💎 <b>طلب ترقية باقة</b>\n\n";
            $msg .= "🏪 المتجر: <b>{$storeName}</b>\n";
            $msg .= "📦 الباقة الحالية: {$current}\n";
            $msg .= "🚀 الباقة المطلوبة: <b>{$requested}</b>\n";
            if ($note !== '') {
                $msg .= "\n📝 ملاحظة: " . mb_substr($note, 0, 300);
            }
            break;

        case 'test':
            $msg  = "✅ <b>اختبار البوت</b>\n\n";
            $msg .= "إذا وصلتك هذه الرسالة، فالبوت مرتبط بشكل صحيح بـ QR Stores.\n";
            $msg .= "الوقت: " . date('Y-m-d H:i:s');
            break;

        default:
            return false;
    }

    return tgSend($pdo, $msg, $eventKey);
}
