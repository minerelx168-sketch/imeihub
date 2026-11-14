<?php
declare(strict_types=1);

/**
 * Tiny PayPal Orders v2 client (cURL + JSON).
 *
 * No SDK on purpose - the surface we use is small:
 *   - POST /v2/checkout/orders         (create order)
 *   - GET  /v2/checkout/orders/{id}    (lookup)
 *   - POST /v2/checkout/orders/{id}/capture
 *   - POST /v1/notifications/verify-webhook-signature
 *
 * Auth is OAuth2 client_credentials; the bearer token is cached in
 * process memory for the rest of the request.
 *
 * Docs:
 *   https://developer.paypal.com/docs/api/orders/v2/
 *   https://developer.paypal.com/docs/api/webhooks/v1/
 */

if (!function_exists('paypal_base_url')) {
function paypal_base_url(): string
{
    $cfg = require __DIR__ . '/config.php';
    $env = strtolower((string) $cfg['paypal']['env']);
    return $env === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}
}

if (!function_exists('paypal_token')) {
function paypal_token(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cfg = require __DIR__ . '/config.php';
    $clientId = (string) $cfg['paypal']['client_id'];
    $secret   = (string) $cfg['paypal']['client_secret'];
    if ($clientId === '' || $secret === '') {
        throw new RuntimeException('PayPal is not configured. Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET in .env.');
    }

    $ch = curl_init(paypal_base_url() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERPWD        => $clientId . ':' . $secret,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('PayPal oauth request failed: ' . $err);
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded) || empty($decoded['access_token']) || $code >= 400) {
        $msg = $decoded['error_description'] ?? ('PayPal oauth HTTP ' . $code);
        throw new RuntimeException('PayPal: ' . $msg);
    }

    $cache = (string) $decoded['access_token'];
    return $cache;
}
}

if (!function_exists('paypal_request')) {
function paypal_request(string $method, string $path, array $body = [], array $extraHeaders = []): array
{
    $token = paypal_token();

    $url = paypal_base_url() . $path;
    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub/1.0',
        CURLOPT_HTTPHEADER     => array_merge([
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders),
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $body ? json_encode($body) : '';
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('PayPal request failed: ' . $err);
    }

    $decoded = json_decode((string) $resp, true);
    if (!is_array($decoded)) {
        // Some endpoints (notably webhook verify) return 200 with JSON; others
        // return empty body on success. Treat empty 2xx as ok.
        if ($code < 300) return [];
        throw new RuntimeException('PayPal returned non-JSON (HTTP ' . $code . ').');
    }
    if ($code >= 400) {
        $msg = $decoded['message']
            ?? $decoded['error_description']
            ?? ($decoded['name'] ?? 'PayPal error HTTP ' . $code);
        throw new RuntimeException('PayPal: ' . $msg);
    }
    return $decoded;
}
}

if (!function_exists('paypal_create_order')) {
/**
 * Create a one-shot CAPTURE order for `$amount` USD.
 *
 * `$publicId` is our topup_orders.public_id - we pass it as
 * `custom_id` so the webhook can map back without a DB lookup, and
 * also as `invoice_id` so PayPal enforces idempotency (you can't
 * pay the same invoice twice).
 *
 * Returns the PayPal order object. Caller uses links[].href where
 * rel='approve' to redirect the buyer.
 */
function paypal_create_order(string $publicId, string $amountUsd, string $returnUrl, string $cancelUrl): array
{
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $publicId,
            'custom_id'    => $publicId,
            'invoice_id'   => $publicId,
            'description'  => 'imeihub credit top-up',
            'amount'       => [
                'currency_code' => 'USD',
                'value'         => $amountUsd,
            ],
        ]],
        'payment_source' => [
            'paypal' => [
                'experience_context' => [
                    'brand_name'          => 'imeihub',
                    'landing_page'        => 'LOGIN',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action'         => 'PAY_NOW',
                    'return_url'          => $returnUrl,
                    'cancel_url'          => $cancelUrl,
                ],
            ],
        ],
    ];

    // PayPal-Request-Id makes the create call idempotent at the API layer:
    // retrying with the same id returns the original order instead of
    // creating a duplicate.
    return paypal_request('POST', '/v2/checkout/orders', $body, [
        'PayPal-Request-Id: ' . $publicId,
        'Prefer: return=representation',
    ]);
}
}

if (!function_exists('paypal_capture_order')) {
function paypal_capture_order(string $orderId): array
{
    // Pass an empty body; PayPal still requires Content-Type: application/json.
    return paypal_request('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [], [
        // Capture is also idempotent on PayPal-Request-Id.
        'PayPal-Request-Id: capture-' . $orderId,
    ]);
}
}

if (!function_exists('paypal_get_order')) {
function paypal_get_order(string $orderId): array
{
    return paypal_request('GET', '/v2/checkout/orders/' . rawurlencode($orderId));
}
}

if (!function_exists('paypal_verify_webhook')) {
/**
 * Verifies a webhook event using PayPal's verify-webhook-signature
 * endpoint. PayPal does the crypto for us; we just hand them the
 * headers + raw body and check they say "SUCCESS".
 *
 * NEVER trust the event body until verify returns SUCCESS.
 */
function paypal_verify_webhook(string $payload, array $headers, string $webhookId): bool
{
    if ($webhookId === '') {
        throw new RuntimeException('PayPal webhook ID not configured.');
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('PayPal webhook payload is not valid JSON.');
    }

    // Header names PayPal sends - case-insensitive lookup.
    $h = [];
    foreach ($headers as $k => $v) $h[strtolower($k)] = is_array($v) ? $v[0] : $v;

    $body = [
        'transmission_id'    => $h['paypal-transmission-id']   ?? '',
        'transmission_time'  => $h['paypal-transmission-time'] ?? '',
        'cert_url'           => $h['paypal-cert-url']          ?? '',
        'auth_algo'          => $h['paypal-auth-algo']         ?? '',
        'transmission_sig'   => $h['paypal-transmission-sig']  ?? '',
        'webhook_id'         => $webhookId,
        'webhook_event'      => $decoded,
    ];
    $result = paypal_request('POST', '/v1/notifications/verify-webhook-signature', $body);
    return (string) ($result['verification_status'] ?? '') === 'SUCCESS';
}
}
