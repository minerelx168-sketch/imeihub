<?php
declare(strict_types=1);

/**
 * Tiny Stripe API client (cURL + form-encoded body).
 *
 * Why no SDK? Keep the dependency footprint at zero and the call surface
 * tight (we only need: create checkout session, retrieve session, verify
 * webhook signature).
 *
 * Docs we lean on:
 *   https://docs.stripe.com/api/checkout/sessions/create
 *   https://docs.stripe.com/payments/promptpay
 *   https://docs.stripe.com/webhooks/signatures
 */

if (!function_exists('stripe_request')) {
function stripe_request(string $method, string $path, array $params = [], array $headers = []): array
{
    $cfg = require __DIR__ . '/config.php';
    $key = (string) $cfg['stripe']['secret_key'];
    if ($key === '') {
        throw new RuntimeException('Stripe is not configured. Set STRIPE_SECRET_KEY in .env.');
    }

    $url = 'https://api.stripe.com/v1' . $path;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub/1.0',
        CURLOPT_HTTPHEADER     => array_merge([
            'Authorization: Bearer ' . $key,
            'Stripe-Version: 2024-12-18.acacia',
        ], $headers),
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = stripe_encode($params);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }

    if ($method === 'GET' && $params) {
        $opts[CURLOPT_URL] .= '?' . http_build_query($params);
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Stripe request failed: ' . $err);
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Stripe returned non-JSON (HTTP ' . $code . ').');
    }
    if ($code >= 400) {
        $msg = $decoded['error']['message'] ?? ('Stripe error HTTP ' . $code);
        throw new RuntimeException('Stripe: ' . $msg);
    }
    return $decoded;
}
}

if (!function_exists('stripe_encode')) {
/**
 * Stripe expects nested params encoded as form-data with bracket notation:
 *   payment_intent_data[metadata][topup_id]=abc
 */
function stripe_encode(array $params, string $prefix = ''): string
{
    $pairs = [];
    foreach ($params as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
        if (is_array($v)) {
            $pairs[] = stripe_encode($v, $key);
        } elseif (is_bool($v)) {
            $pairs[] = $key . '=' . ($v ? 'true' : 'false');
        } elseif ($v === null) {
            continue;
        } else {
            $pairs[] = $key . '=' . rawurlencode((string) $v);
        }
    }
    return implode('&', array_filter($pairs, 'strlen'));
}
}

if (!function_exists('stripe_create_checkout_session')) {
function stripe_create_checkout_session(array $params, ?string $idempotencyKey = null): array
{
    $headers = [];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    return stripe_request('POST', '/checkout/sessions', $params, $headers);
}
}

if (!function_exists('stripe_retrieve_session')) {
function stripe_retrieve_session(string $sessionId): array
{
    return stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
}
}

if (!function_exists('stripe_verify_webhook')) {
/**
 * Verifies a Stripe-Signature header against the raw POST body.
 * Returns the decoded event or throws.
 *
 * Tolerance defaults to 5 minutes to allow for clock skew.
 */
function stripe_verify_webhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): array
{
    if ($secret === '') {
        throw new RuntimeException('Stripe webhook secret not configured.');
    }

    $parts = [];
    foreach (explode(',', $sigHeader) as $piece) {
        [$k, $v] = array_pad(explode('=', $piece, 2), 2, '');
        $parts[$k][] = $v;
    }

    $t = $parts['t'][0] ?? null;
    $signatures = $parts['v1'] ?? [];
    if (!$t || !$signatures) {
        throw new RuntimeException('Malformed Stripe-Signature header.');
    }

    if (abs(time() - (int) $t) > $tolerance) {
        throw new RuntimeException('Stripe webhook timestamp outside tolerance.');
    }

    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $match = false;
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            $match = true;
            break;
        }
    }
    if (!$match) {
        throw new RuntimeException('Stripe webhook signature mismatch.');
    }

    $event = json_decode($payload, true);
    if (!is_array($event)) {
        throw new RuntimeException('Webhook payload is not valid JSON.');
    }
    return $event;
}
}
