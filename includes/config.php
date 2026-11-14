<?php
declare(strict_types=1);

/**
 * Loads configuration from environment variables, with sane defaults for local dev.
 * Copy .env.example to .env and fill in real values before going to production.
 */

if (!function_exists('env')) {
function env(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $envPath = dirname(__DIR__) . '/.env';
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $cache[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $val = getenv($key);
    return $val === false ? $default : $val;
}
}

return [
    'app' => [
        'name'  => env('APP_NAME', 'imeihub'),
        'url'   => rtrim((string) env('APP_URL', 'http://localhost:8080'), '/'),
        'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    ],
    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'imei_checker'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],
    'api' => [
        'provider'        => env('IMEI_API_PROVIDER', 'unlock-service'),
        'key'             => env('IMEI_API_KEY', ''),
        // Separate DHRU API key for async services (placeimeiorder /
        // getimeiorder). Falls back to the main key if not set.
        'dhru_key'        => env('IMEI_DHRU_API_KEY', '') ?: env('IMEI_API_KEY', ''),
        // Account username - needed by DHRU async services.
        'username'        => env('IMEI_API_USERNAME', ''),
        'url'             => env('IMEI_API_URL', 'https://api.unlock-service.net'),
        'default_service' => env('IMEI_API_DEFAULT_SERVICE', '1'),
    ],
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
    ],
    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
        'secret_key'      => env('STRIPE_SECRET_KEY', ''),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET', ''),
    ],
    'paypal' => [
        // 'live' or 'sandbox' - flips api.paypal.com vs api-m.sandbox.paypal.com
        'env'           => env('PAYPAL_ENV', 'sandbox'),
        'client_id'     => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
        // Webhook ID from https://developer.paypal.com/dashboard/applications/sandbox
        // - used by signature verification (PayPal verifies on their side).
        'webhook_id'    => env('PAYPAL_WEBHOOK_ID', ''),
    ],
    'binancepay' => [
        'api_key'        => env('BINANCE_PAY_KEY', ''),
        'api_secret'     => env('BINANCE_PAY_SECRET', ''),
        // Default base URL is fine for prod; only override for testing.
        'base_url'       => rtrim((string) env('BINANCE_PAY_BASE_URL', 'https://bpay.binanceapi.com'), '/'),
        // /openapi/certificates response is cached for this many seconds.
        // Lowering it forces fresher pubkey fetches during dev.
        'cert_ttl'       => (int) env('BINANCE_PAY_CERT_TTL', '300'),
    ],
    // Direct on-chain crypto top-ups: the user sends USDT to our address, then
    // pastes the TxID and we verify it via a public blockchain API and credit
    // the wallet for whatever amount actually landed.
    'crypto' => [
        'min_usd' => (float) env('CRYPTO_MIN_USD', '1'),
        'trc20' => [
            // The TRON address we want users to send USDT (TRC20) to.
            'address'           => env('CRYPTO_TRC20_ADDRESS', ''),
            // USDT TRC20 contract (well-known; do not change).
            'usdt_contract_hex' => 'a614f803b6fd780986a42c78ec9c7f77e6ded13c',
            'min_confirmations' => (int) env('CRYPTO_TRC20_MIN_CONFIRMATIONS', '3'),
            'trongrid_url'      => rtrim((string) env('TRONGRID_URL', 'https://api.trongrid.io'), '/'),
            // Optional. Empty falls back to the public free tier.
            'trongrid_key'      => env('TRONGRID_API_KEY', ''),
        ],
        'bep20' => [
            // The BSC address we want users to send USDT/USDC (BEP-20) to.
            'address'           => env('CRYPTO_BEP20_ADDRESS', ''),
            // USDT BEP-20 + USDC BEP-20 contracts (well-known).
            'usdt_contract'     => '0x55d398326f99059ff775485246999027b3197955',
            'usdc_contract'     => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d',
            'min_confirmations' => (int) env('CRYPTO_BEP20_MIN_CONFIRMATIONS', '5'),
            'bscscan_url'       => rtrim((string) env('BSCSCAN_URL', 'https://api.bscscan.com/api'), '?'),
            // Free key from https://bscscan.com/apis - required for prod.
            'bscscan_key'       => env('BSCSCAN_API_KEY', ''),
        ],
    ],
    'session' => [
        'lifetime_days' => (int) env('SESSION_LIFETIME_DAYS', '30'),
        'cookie_name'   => env('SESSION_COOKIE_NAME', 'imeihub_sid'),
    ],
    'telegram' => [
        'bot_token'      => env('TELEGRAM_BOT_TOKEN', ''),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    ],
    'line' => [
        'channel_secret'       => env('LINE_CHANNEL_SECRET', ''),
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN', ''),
    ],
    'resend' => [
        'api_key' => env('RESEND_API_KEY', ''),
        'from'    => env('RESEND_FROM', 'onboarding@resend.dev'),
    ],
    'lemonsqueezy' => [
        'api_key'        => env('LEMONSQUEEZY_API_KEY', ''),
        'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET', ''),
        'store_slug'     => env('LEMONSQUEEZY_STORE_SLUG', 'imeihub'),
    ],
];
