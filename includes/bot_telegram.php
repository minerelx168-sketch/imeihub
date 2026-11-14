<?php
declare(strict_types=1);

/**
 * Minimal Telegram Bot API client.
 * Docs: https://core.telegram.org/bots/api
 *
 * We only use sendMessage. No SDK dependency.
 */

if (!function_exists('telegram_send_text')) {
function telegram_send_text(string $chatId, string $text): bool
{
    $cfg = require __DIR__ . '/config.php';
    $token = (string) ($cfg['telegram']['bot_token'] ?? '');
    if ($token === '') {
        error_log('[telegram] TELEGRAM_BOT_TOKEN not configured');
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        // Markdown can choke on stray asterisks/underscores - retry plain.
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'chat_id' => $chatId,
                'text'    => $text,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    return $code === 200;
}
}
