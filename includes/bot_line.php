<?php
declare(strict_types=1);

/**
 * Minimal LINE Messaging API client.
 * Docs: https://developers.line.biz/en/reference/messaging-api/
 *
 * Two send methods:
 *   - reply API   (reply token from the inbound webhook event, single use, ~30s window)
 *   - push API    (anytime, but costs 1 message quota each)
 *
 * We prefer reply when a token is supplied; we fall back to push if the
 * reply attempt fails (e.g. token already used).
 */

const LINE_REPLY_URL = 'https://api.line.me/v2/bot/message/reply';
const LINE_PUSH_URL  = 'https://api.line.me/v2/bot/message/push';

if (!function_exists('line_send_text')) {
function line_send_text(string $chatId, string $text, ?string $replyToken = null): bool
{
    $cfg = require __DIR__ . '/config.php';
    $token = (string) ($cfg['line']['channel_access_token'] ?? '');
    if ($token === '') {
        error_log('[line] LINE_CHANNEL_ACCESS_TOKEN not configured');
        return false;
    }

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];

    // Try reply API first if we have a token.
    if ($replyToken !== null && $replyToken !== '') {
        if (line_post(LINE_REPLY_URL, [
            'replyToken' => $replyToken,
            'messages'   => [['type' => 'text', 'text' => mb_substr($text, 0, 5000)]],
        ], $headers)) {
            return true;
        }
    }

    // Push API fallback.
    return line_post(LINE_PUSH_URL, [
        'to'       => $chatId,
        'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 5000)]],
    ], $headers);
}
}

if (!function_exists('line_post')) {
function line_post(string $url, array $body, array $headers): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
}

if (!function_exists('line_verify_signature')) {
/**
 * LINE signs the request with HMAC-SHA256 over the raw body, base64-encoded.
 * Header: X-Line-Signature
 */
function line_verify_signature(string $rawBody, string $signature, string $secret): bool
{
    if ($signature === '' || $secret === '') return false;
    $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
    return hash_equals($expected, $signature);
}
}
