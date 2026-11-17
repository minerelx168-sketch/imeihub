<?php
declare(strict_types=1);

/**
 * Lemon Squeezy helper functions.
 *
 * Provides:
 *   - lemonsqueezy_get_checkout_url()  Build a checkout URL with custom data
 *   - lemonsqueezy_verify_webhook()    HMAC-SHA256 signature verification
 *
 * Docs:
 *   https://docs.lemonsqueezy.com/api
 *   https://docs.lemonsqueezy.com/guides/developer-guide/webhooks
 */

if (!function_exists('lemonsqueezy_get_checkout_url')) {
/**
 * Build a Lemon Squeezy checkout URL for a given amount.
 *
 * Uses the pre-created products on the imeihub store. Each fixed amount
 * has its own product; amounts that don't match a preset use the
 * "Pay What You Want" product with a custom price.
 *
 * @param string $publicId  The topup_orders.public_id (passed as custom_data)
 * @param float  $amount    The credit amount in USD
 * @param array  $user      The authenticated user row
 * @param array  $cfg       The config array
 * @return string           The checkout URL to redirect the user to
 */
function lemonsqueezy_get_checkout_url(string $publicId, float $chargeAmount, array $user, array $cfg): string
{
    $store = (string) ($cfg['lemonsqueezy']['store_slug'] ?? 'imeihub');

    // Fee percentage (must match data/payment_methods.php)
    $feePct = 5;

    // Reverse-calculate the base (credit) amount from the charge amount
    // chargeAmount = baseAmount * (1 + feePct/100)
    $baseAmount = round($chargeAmount / (1 + $feePct / 100), 2);

    // Product UUIDs (checkout/buy/{uuid}) - fixed-price products
    $variantMap = [
        '5'   => '4b80693a-3ff1-4b01-ae94-7e910e1de5b6',
        '10'  => 'edbdc4a2-ec6a-40a0-8b2e-f3001e67a8f0',
        '25'  => '99ad7079-7a9a-4915-8f3e-bdcde373ec68',
        '50'  => 'd248ef88-6aa5-449e-9733-3e3eea70efd9',
        '100' => 'a31d218e-c618-4345-be48-c2dde54e5711',
        '250' => '76c09225-6ded-4d3b-8326-61bf2c09a34f',
    ];

    // Pay What You Want product for custom amounts
    $pwywUuid = '2f5a4380-0651-462d-88ad-959f81ba0d77';

    // Pick the right product UUID using the BASE amount (before fee)
    $amountKey = rtrim(rtrim(number_format($baseAmount, 2, '.', ''), '0'), '.');
    $uuid = $variantMap[$amountKey] ?? $pwywUuid;

    // Build checkout URL with query params for custom data
    $baseUrl = "https://{$store}.lemonsqueezy.com/checkout/buy/{$uuid}";

    $params = [];

    // Pass custom data so the webhook can identify the order
    $params['checkout[custom][topup_public_id]'] = $publicId;
    $params['checkout[custom][user_id]'] = (string) ($user['id'] ?? '');
    $params['checkout[custom][amount]'] = number_format($baseAmount, 2, '.', '');

    // Pre-fill email if available
    if (!empty($user['email'])) {
        $params['checkout[email]'] = $user['email'];
    }

    // For PWYW product, set the custom price (in cents) using chargeAmount
    if ($uuid === $pwywUuid) {
        $params['price'] = (string) ((int) round($chargeAmount * 100));
    }

    return $baseUrl . '?' . http_build_query($params);
}
}

if (!function_exists('lemonsqueezy_verify_webhook')) {
/**
 * Verify a Lemon Squeezy webhook signature (HMAC-SHA256).
 *
 * Lemon Squeezy signs webhooks with:
 *   X-Signature: hex(HMAC-SHA256(raw_body, signing_secret))
 *
 * @param string $payload  Raw POST body
 * @param string $signature  Value of X-Signature header
 * @param string $secret   Webhook signing secret
 * @return bool
 */
function lemonsqueezy_verify_webhook(string $payload, string $signature, string $secret): bool
{
    if ($secret === '' || $signature === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}
}
