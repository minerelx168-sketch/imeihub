<?php
/**
 * Fire a fake but properly-signed Stripe webhook at the local server.
 *
 * Use this to validate the full webhook -> credit flow end-to-end when you
 * don't want to (or can't) wait for a real Stripe delivery.
 *
 * Usage:
 *   php scripts/simulate-webhook.php <event_type> <topup_public_id> [payment_status]
 *
 * Example:
 *   # User has just created order ULID01JABCDEF1GHJKMNPQRSTVWXYZ via /topup
 *   php scripts/simulate-webhook.php checkout.session.completed 01JABCDEF1GHJKMNPQRSTVWXYZ paid
 *
 * Requires STRIPE_WEBHOOK_SECRET in .env and a running webserver on APP_URL.
 */

declare(strict_types=1);

$cfg = require __DIR__ . '/../includes/config.php';
$secret = $cfg['stripe']['webhook_secret'];
$base   = $cfg['app']['url'];

if ($secret === '') {
    fwrite(STDERR, "STRIPE_WEBHOOK_SECRET not set in .env\n");
    exit(1);
}

$eventType    = $argv[1] ?? 'checkout.session.completed';
$publicId     = $argv[2] ?? '';
$paymentStat  = $argv[3] ?? 'paid';

if ($publicId === '') {
    fwrite(STDERR, "Usage: php scripts/simulate-webhook.php <event_type> <public_id> [payment_status]\n");
    exit(1);
}

$event = [
    'id'      => 'evt_sim_' . bin2hex(random_bytes(8)),
    'object'  => 'event',
    'type'    => $eventType,
    'created' => time(),
    'data' => [
        'object' => [
            'id'                   => 'cs_sim_' . bin2hex(random_bytes(12)),
            'object'               => 'checkout.session',
            'client_reference_id'  => $publicId,
            'payment_status'       => $paymentStat,
            'metadata'             => ['topup_public_id' => $publicId],
        ],
    ],
];

$payload = json_encode($event, JSON_UNESCAPED_SLASHES);
$t       = time();
$sig     = hash_hmac('sha256', $t . '.' . $payload, $secret);
$header  = "t={$t},v1={$sig}";

$ch = curl_init($base . '/api/webhooks/stripe.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Stripe-Signature: ' . $header,
    ],
]);
$body  = curl_exec($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err   = curl_error($ch);
curl_close($ch);

if ($body === false) {
    fwrite(STDERR, "Request failed: $err\n");
    exit(1);
}

echo "HTTP $code\n";
echo $body . "\n";
exit($code >= 200 && $code < 300 ? 0 : 1);
