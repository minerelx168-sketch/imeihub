<?php
declare(strict_types=1);

/**
 * Minimal Resend HTTP client (no SDK dependency).
 *
 * Docs: https://resend.com/docs/api-reference/emails/send-email
 *
 * Returns true on success. Logs failures to error_log so the caller can
 * still proceed (e.g. caller may want to render "OTP sent" even when
 * the upstream send had a transient hiccup; the OTP is in the DB and
 * a re-send link is one click away).
 */

if (!function_exists('resend_send_email')) {
function resend_send_email(string $to, string $subject, string $html, ?string $text = null): bool
{
    $cfg = require __DIR__ . '/config.php';
    $key  = (string) ($cfg['resend']['api_key'] ?? '');
    $from = (string) ($cfg['resend']['from']    ?? 'onboarding@resend.dev');
    if ($key === '') {
        error_log('[resend] RESEND_API_KEY not configured');
        return false;
    }

    $body = [
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text !== null) $body['text'] = $text;

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) return true;

    error_log('[resend] send to ' . $to . ' failed: HTTP ' . $code . ($err ? ' (' . $err . ')' : '')
        . ' body=' . substr((string) $resp, 0, 300));
    return false;
}
}
