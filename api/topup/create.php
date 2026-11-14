<?php
declare(strict_types=1);

/**
 * Top-up order creation - dispatches to the right payment provider.
 *
 *   POST /api/topup/create.php
 *   Content-Type: application/json
 *   Idempotency-Key: <opaque-string>
 *
 *   { "amount": 25, "method": "card" | "paypal" | "binancepay" }
 *
 * Returns:
 *   { ok: true, url: "https://...", public_id: "01HX..." }
 *
 * "url" is where the client should redirect the user:
 *   - card        -> Stripe Checkout URL
 *   - paypal      -> PayPal approval URL
 *   - binancepay  -> /topup/binancepay.php?id=<public_id>  (local QR page)
 *
 * The dispatch keeps the provider concerns isolated in includes/
 * (stripe.php, paypal.php, binancepay.php) and lets the front end
 * stay provider-agnostic - it just follows the URL.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/credits_write.php';
require __DIR__ . '/../../includes/ulid.php';

function fail(int $code, string $error): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail(405, 'POST only.');
}

$user = auth_user();
if (!$user) {
    fail(401, 'Not signed in.');
}

// Parse body.
$body = [];
$raw  = file_get_contents('php://input');
$ct   = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
if (str_contains($ct, 'application/json') && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
} else {
    $body = $_POST;
}

$amount = isset($body['amount']) ? trim((string) $body['amount']) : '';
$method = isset($body['method']) ? strtolower(trim((string) $body['method'])) : 'card';

if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
    fail(400, 'Invalid amount.');
}
$amountFloat = (float) $amount;

// Look up the chosen method's config.
$methods = require __DIR__ . '/../../data/payment_methods.php';
$selected = null;
foreach ($methods as $m) {
    if (($m['id'] ?? '') === $method && ($m['enabled'] ?? true)) {
        $selected = $m;
        break;
    }
}
if (!$selected) {
    fail(400, 'Unknown or disabled payment method.');
}
if ($amountFloat < (float) $selected['min_usd'] || $amountFloat > (float) $selected['max_usd']) {
    fail(422, sprintf(
        '%s top-up must be between $%s and $%s.',
        $selected['label'],
        number_format((float) $selected['min_usd'], 2),
        number_format((float) $selected['max_usd'], 2)
    ));
}

$provider = (string) $selected['provider'];

// The fee (if any) is charged ON TOP of the requested amount: the user
// pays amount + fee% at the processor, but we credit the base amount to
// their wallet. So $amountFloat = wallet credit, $chargeAmount = what the
// card/PayPal actually bills.
$feePct       = (float) ($selected['fee_pct'] ?? 0);
$chargeAmount = round($amountFloat * (1 + $feePct / 100), 2);

// Idempotency key (client header preferred, otherwise generated).
$idempotencyKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $idempotencyKey)) {
    $idempotencyKey = ulid() . '-' . ((int) $user['id']);
}

$cfg = require __DIR__ . '/../../includes/config.php';

try {
    $order = credits_create_topup_order(
        (int) $user['id'],
        number_format($amountFloat, 2, '.', ''),
        'USD',
        $idempotencyKey,
        $provider
    );

    // If the same idempotent request was already served, return the
    // existing URL where possible (only Stripe gives us a session-level
    // retrieve; for paypal/binancepay we just re-issue the create call -
    // they're idempotent on their own merchant trade IDs).
    if (!empty($order['provider_charge_id']) && $provider === 'stripe') {
        require_once __DIR__ . '/../../includes/stripe.php';
        try {
            $session = stripe_retrieve_session((string) $order['provider_charge_id']);
            if (!empty($session['url']) && ($session['status'] ?? '') === 'open') {
                echo json_encode([
                    'ok'        => true,
                    'url'       => $session['url'],
                    'public_id' => $order['public_id'],
                ]);
                exit;
            }
        } catch (Throwable $e) {
            // fall through and create a new session
        }
    }

    $url = match ($provider) {
        'stripe'        => topup_create_stripe($order, $amountFloat, $chargeAmount, $feePct, $user, $cfg, $idempotencyKey),
        'paypal'        => topup_create_paypal($order, $chargeAmount, $cfg),
        'binancepay'    => topup_create_binancepay($order, $chargeAmount, $cfg),
        'lemonsqueezy'  => topup_create_lemonsqueezy($order, $amountFloat, $chargeAmount, $user, $cfg),
        default         => throw new RuntimeException('Unhandled provider: ' . $provider),
    };

    echo json_encode([
        'ok'        => true,
        'url'       => $url,
        'public_id' => $order['public_id'],
    ]);
} catch (Throwable $e) {
    fail(500, $e->getMessage());
}

// ---------- per-provider creators ---------------------------------------------

function topup_create_stripe(array $order, float $baseAmount, float $chargeAmount, float $feePct, array $user, array $cfg, string $idemKey): string
{
    require_once __DIR__ . '/../../includes/stripe.php';

    // Bill the credit as one line item and the processing fee as a second,
    // so the Stripe receipt shows the breakdown. Deriving feeCents from the
    // rounded charge keeps base + fee exactly equal to the total billed.
    $baseCents   = (int) round($baseAmount * 100);
    $chargeCents = (int) round($chargeAmount * 100);
    $feeCents    = $chargeCents - $baseCents;

    $lineItems = [[
        'price_data' => [
            'currency'     => 'usd',
            'unit_amount'  => $baseCents,
            'product_data' => [
                'name'        => 'imeihub credit top-up',
                'description' => 'Adds $' . number_format($baseAmount, 2) . ' to your imeihub wallet.',
            ],
        ],
        'quantity' => 1,
    ]];
    if ($feeCents > 0) {
        $feeLabel = rtrim(rtrim(number_format($feePct, 1), '0'), '.');
        $lineItems[] = [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => $feeCents,
                'product_data' => ['name' => 'Card processing fee (' . $feeLabel . '%)'],
            ],
            'quantity' => 1,
        ];
    }

    $session = stripe_create_checkout_session([
        'mode'                 => 'payment',
        'client_reference_id'  => $order['public_id'],
        'customer_email'       => $user['email'],
        'payment_method_types' => ['card'],
        'line_items'           => $lineItems,
        'metadata' => [
            'topup_public_id' => $order['public_id'],
            'user_id'         => (string) $user['id'],
        ],
        'payment_intent_data' => [
            'metadata' => [
                'topup_public_id' => $order['public_id'],
                'user_id'         => (string) $user['id'],
            ],
        ],
        'success_url' => $cfg['app']['url'] . '/topup/return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $cfg['app']['url'] . '/topup.php?cancelled=1',
    ], $idemKey);

    credits_attach_charge_id((int) $order['id'], (string) $session['id']);
    return (string) ($session['url'] ?? '');
}

function topup_create_paypal(array $order, float $amount, array $cfg): string
{
    require_once __DIR__ . '/../../includes/paypal.php';

    $returnUrl = $cfg['app']['url'] . '/topup/paypal-return.php?id=' . rawurlencode($order['public_id']);
    $cancelUrl = $cfg['app']['url'] . '/topup.php?cancelled=1';

    $pp = paypal_create_order(
        (string) $order['public_id'],
        number_format($amount, 2, '.', ''),
        $returnUrl,
        $cancelUrl
    );

    credits_attach_charge_id((int) $order['id'], (string) ($pp['id'] ?? ''));

    foreach ((array) ($pp['links'] ?? []) as $link) {
        if (($link['rel'] ?? '') === 'payer-action' || ($link['rel'] ?? '') === 'approve') {
            return (string) $link['href'];
        }
    }
    throw new RuntimeException('PayPal order returned no approval URL.');
}

function topup_create_binancepay(array $order, float $amount, array $cfg): string
{
    require_once __DIR__ . '/../../includes/binancepay.php';

    $webhookUrl = $cfg['app']['url'] . '/api/webhooks/binancepay.php';
    $returnUrl  = $cfg['app']['url'] . '/topup/binancepay.php?id=' . rawurlencode($order['public_id']);

    $data = binancepay_create_order(
        (string) $order['public_id'],
        number_format($amount, 2, '.', ''),
        $webhookUrl,
        $returnUrl
    );

    $prepayId = (string) ($data['prepayId'] ?? '');
    if ($prepayId !== '') {
        credits_attach_charge_id((int) $order['id'], $prepayId);
    }

    // Redirect to the local QR page rather than straight to Binance's
    // universalUrl. The local page polls status.php and we can show our
    // own branding + countdown + "open in Binance app" deeplink.
    return $returnUrl;
}

function topup_create_lemonsqueezy(array $order, float $baseAmount, float $chargeAmount, array $user, array $cfg): string
{
    require_once __DIR__ . '/../../includes/lemonsqueezy.php';

    $url = lemonsqueezy_get_checkout_url(
        (string) $order['public_id'],
        $chargeAmount,
        $user,
        $cfg
    );

    // Store a reference so we can look up the order from the return page
    credits_attach_charge_id((int) $order['id'], 'ls_pending_' . $order['public_id']);

    return $url;
}
