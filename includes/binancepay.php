<?php
declare(strict_types=1);

/**
 * Tiny Binance Pay Merchant API client (cURL + JSON).
 *
 * Auth scheme (per spec):
 *   - Each request carries 4 headers: BinancePay-Timestamp,
 *     BinancePay-Nonce, BinancePay-Certificate-SN (API key),
 *     BinancePay-Signature (uppercase hex HMAC-SHA512 of
 *     `${timestamp}\n${nonce}\n${body}\n` keyed by API secret).
 *   - Content-Type is application/json.
 *
 * Webhook verification:
 *   - Binance signs the IPN body with their RSA private key. We fetch
 *     their current public certificate from /openapi/certificates
 *     (signed request) and verify the signature in the IPN header.
 *
 * Docs:
 *   https://developers.binance.com/docs/binance-pay/api-order-create-v3
 *   https://developers.binance.com/docs/binance-pay/webhook-common
 */

if (!function_exists('binancepay_request')) {
function binancepay_request(string $method, string $path, array $body = []): array
{
    $cfg    = require __DIR__ . '/config.php';
    $key    = (string) $cfg['binancepay']['api_key'];
    $secret = (string) $cfg['binancepay']['api_secret'];
    $base   = (string) $cfg['binancepay']['base_url'];

    if ($key === '' || $secret === '') {
        throw new RuntimeException('Binance Pay is not configured. Set BINANCE_PAY_KEY and BINANCE_PAY_SECRET in .env.');
    }

    $payload   = $body ? json_encode($body, JSON_UNESCAPED_SLASHES) : '{}';
    $timestamp = (string) (int) (microtime(true) * 1000);
    $nonce     = bin2hex(random_bytes(16));

    // Signature payload: timestamp\nnonce\nbody\n -> HMAC-SHA512 hex uppercase
    $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
    $signature  = strtoupper(hash_hmac('sha512', $sigPayload, $secret));

    $url = $base . $path;
    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub/1.0',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $key,
            'BinancePay-Signature: ' . $signature,
        ],
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body) $opts[CURLOPT_POSTFIELDS] = $payload;
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Binance Pay request failed: ' . $err);
    }

    $decoded = json_decode((string) $resp, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Binance Pay returned non-JSON (HTTP ' . $code . ').');
    }

    // Binance returns 200 even on logical errors; status field is the truth.
    $status = (string) ($decoded['status'] ?? '');
    if ($code >= 400 || $status === 'FAIL') {
        $msg = $decoded['errorMessage'] ?? $decoded['errorCode'] ?? ('HTTP ' . $code);
        throw new RuntimeException('Binance Pay: ' . $msg);
    }
    return $decoded;
}
}

if (!function_exists('binancepay_create_order')) {
/**
 * Create a Binance Pay order. `$publicId` is our topup_orders.public_id
 * and doubles as the merchantTradeNo (Binance enforces uniqueness on
 * this field, giving us free idempotency).
 *
 * Returns the data block with `prepayId`, `qrcodeLink`, `universalUrl`,
 * and `checkoutUrl`. Pick whichever fits the UX (we show qrcodeLink
 * inline and provide universalUrl as the "open Binance app" deeplink).
 */
function binancepay_create_order(string $publicId, string $amountUsd, string $webhookUrl, string $returnUrl): array
{
    $cfg = require __DIR__ . '/config.php';
    $appName = (string) ($cfg['app']['name'] ?? 'imeihub');

    // Binance wants amount as a string with 2-8 decimals. We use USDT
    // 1:1 to USD as the settlement currency.
    $body = [
        'env' => [
            'terminalType' => 'WEB',
        ],
        'merchantTradeNo' => preg_replace('/[^A-Za-z0-9]/', '', $publicId),
        'orderAmount'     => number_format((float) $amountUsd, 2, '.', ''),
        'currency'        => 'USDT',
        'goods' => [
            'goodsType'      => '02', // virtual goods
            'goodsCategory'  => 'Z000', // others
            'referenceGoodsId' => 'imeihub-topup',
            'goodsName'      => $appName . ' credit top-up',
            'goodsDetail'    => 'Adds $' . number_format((float) $amountUsd, 2) . ' to your ' . $appName . ' wallet.',
        ],
        // returnUrl shown after the user pays in the Binance app/web;
        // webhookUrl is where Binance POSTs the IPN.
        'returnUrl'  => $returnUrl,
        'webhookUrl' => $webhookUrl,
    ];

    $resp = binancepay_request('POST', '/binancepay/openapi/v3/order', $body);
    return $resp['data'] ?? [];
}
}

if (!function_exists('binancepay_query_order')) {
function binancepay_query_order(string $publicId): array
{
    $body = [
        'merchantTradeNo' => preg_replace('/[^A-Za-z0-9]/', '', $publicId),
    ];
    $resp = binancepay_request('POST', '/binancepay/openapi/v2/order/query', $body);
    return $resp['data'] ?? [];
}
}

if (!function_exists('binancepay_fetch_cert')) {
/**
 * Fetches Binance's current webhook signing public certificate. We
 * cache it in a temp file because the cert rarely rotates (Binance
 * rolls it on a multi-day cadence, never per-request).
 */
function binancepay_fetch_cert(string $serial): string
{
    $cfg     = require __DIR__ . '/config.php';
    $ttl     = (int) $cfg['binancepay']['cert_ttl'];
    $tmp     = sys_get_temp_dir() . '/binancepay_cert_' . preg_replace('/[^A-Za-z0-9]/', '_', $serial) . '.pem';

    if (is_file($tmp) && (time() - filemtime($tmp)) < $ttl) {
        $cached = (string) file_get_contents($tmp);
        if ($cached !== '') return $cached;
    }

    $resp = binancepay_request('POST', '/binancepay/openapi/certificates', []);
    $certs = $resp['data'] ?? [];
    foreach ($certs as $c) {
        if (($c['certSerial'] ?? '') === $serial) {
            $pem = (string) $c['certPublic'];
            @file_put_contents($tmp, $pem);
            return $pem;
        }
    }
    throw new RuntimeException('Binance Pay: webhook cert with serial ' . $serial . ' not found.');
}
}

if (!function_exists('binancepay_verify_webhook')) {
/**
 * Verifies a Binance Pay IPN payload against the RSA signature in the
 * IPN headers. Returns the decoded JSON event body on success, throws
 * on any failure (signature, cert lookup, JSON parse, expired ts).
 */
function binancepay_verify_webhook(string $payload, array $headers, int $tolerance = 300): array
{
    // Headers as lowercase map.
    $h = [];
    foreach ($headers as $k => $v) $h[strtolower($k)] = is_array($v) ? $v[0] : $v;

    $timestamp = $h['binancepay-timestamp']      ?? '';
    $nonce     = $h['binancepay-nonce']          ?? '';
    $signature = $h['binancepay-signature']      ?? '';
    $serial    = $h['binancepay-certificate-sn'] ?? '';

    if (!$timestamp || !$nonce || !$signature || !$serial) {
        throw new RuntimeException('Binance Pay webhook missing required headers.');
    }
    if (abs(time() - (int) ((int) $timestamp / 1000)) > $tolerance) {
        throw new RuntimeException('Binance Pay webhook timestamp outside tolerance.');
    }

    $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
    $cert       = binancepay_fetch_cert((string) $serial);
    $pubKey     = openssl_pkey_get_public($cert);
    if (!$pubKey) {
        throw new RuntimeException('Binance Pay webhook cert is not a valid public key.');
    }
    $ok = openssl_verify($sigPayload, base64_decode((string) $signature), $pubKey, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        throw new RuntimeException('Binance Pay webhook signature mismatch.');
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Binance Pay webhook body is not valid JSON.');
    }
    return $decoded;
}
}
