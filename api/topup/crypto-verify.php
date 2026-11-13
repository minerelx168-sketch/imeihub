<?php
declare(strict_types=1);

/**
 * Verify a user-submitted on-chain TxID and credit the wallet.
 *
 *   POST /api/topup/crypto-verify.php   { "txid": "<hex>" }
 *
 * The chain is auto-detected from the TxID format (0x… → BEP-20, plain 64-hex
 * → TRC20). The credit amount is whatever the chain says actually arrived at
 * our address; the request body never names an amount. Idempotent on TxID:
 * verifying the same hash twice for the same user is a no-op and a different
 * user claiming someone else's TxID is rejected.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

// API endpoints must never leak warnings into the JSON body - it shows up in
// the browser as an opaque "Network error" with no diagnostics.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/credits.php';
require __DIR__ . '/../../includes/credits_write.php';
require __DIR__ . '/../../includes/db.php';

function cv_fail(int $code, string $msg, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    cv_fail(405, 'POST only.');
}

$user = auth_user();
if (!$user) cv_fail(401, 'Please sign in to top up.');

if (!rate_limit_allow('crypto-verify:' . client_ip(), 20)) {
    cv_fail(429, 'Too many verification attempts. Please wait a minute.');
}

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;

$txid = strtolower(trim((string) ($body['txid'] ?? '')));
if ($txid === '') cv_fail(400, 'Paste the transaction ID from your wallet.');

// Auto-detect chain from the txid shape. BEP-20 prefixes with 0x and is
// 64 hex chars after that; TRC20 is a bare 64-hex string.
if (preg_match('/^0x[0-9a-f]{64}$/', $txid)) {
    $chain    = 'bep20';
    $provider = 'crypto-bep20';
} elseif (preg_match('/^[0-9a-f]{64}$/', $txid)) {
    $chain    = 'trc20';
    $provider = 'crypto-trc20';
} else {
    cv_fail(400, 'That does not look like a valid TRON or BSC transaction ID.');
}

$userId = (int) $user['id'];
$pdo    = db();

// 1. Idempotency / ownership pre-check on the unique provider_charge_id column.
// Same user re-submitting the same TxID is silently re-played; a different
// user trying to claim someone else's TxID is rejected.
$stmt = $pdo->prepare('SELECT id, public_id, user_id, status, amount FROM topup_orders WHERE provider_charge_id = ? LIMIT 1');
$stmt->execute([$txid]);
$existing = $stmt->fetch();
if ($existing) {
    if ((int) $existing['user_id'] !== $userId) {
        cv_fail(409, 'This TxID has already been claimed by another account. If you believe this is a mistake, contact support.');
    }
    if ($existing['status'] === 'CREDITED') {
        $balance = credits_get_balance($userId, true);
        echo json_encode([
            'ok'           => true,
            'credited_now' => false,
            'amount_usd'   => number_format((float) $existing['amount'], 2, '.', ''),
            'balance'      => $balance,
            'note'         => 'This TxID was already credited.',
        ]);
        exit;
    }
    // PENDING / PAID: fall through and re-attempt issue.
}

// 2. Run the on-chain verifier.
if ($chain === 'trc20') {
    require __DIR__ . '/../../includes/crypto_trc20.php';
    $res = crypto_trc20_verify($txid);
} else {
    require __DIR__ . '/../../includes/crypto_bep20.php';
    $res = crypto_bep20_verify($txid);
}

if (empty($res['ok'])) {
    // Pending confirmations -> 202 so the UI can distinguish "retry" from "fail".
    if (!empty($res['pending'])) {
        http_response_code(202);
        echo json_encode([
            'ok'            => false,
            'pending'       => true,
            'confirmations' => (int) ($res['confirmations'] ?? 0),
            'min_required'  => (int) ($res['min_required'] ?? 0),
            'amount_usd'    => $res['amount_usd'] ?? null,
            'error'         => (string) ($res['error'] ?? 'Waiting for confirmations.'),
        ]);
        exit;
    }
    cv_fail(400, (string) ($res['error'] ?? 'Verification failed.'));
}

$amountUsd = (string) $res['amount_usd'];
$cfg       = require __DIR__ . '/../../includes/config.php';
$minUsd    = (float) ($cfg['crypto']['min_usd'] ?? 1);
if ((float) $amountUsd < $minUsd) {
    cv_fail(400, sprintf('Amount %s USD is below the %s USD minimum top-up.', $amountUsd, number_format($minUsd, 2)));
}

// 3. Create (or fetch existing) order and credit the wallet.
try {
    $idem = 'crypto-' . $chain . '-' . $txid;
    if ($existing) {
        // Already exists from the pre-check; pick it up.
        $order = $existing;
    } else {
        $order = credits_create_topup_order($userId, $amountUsd, 'USDT', $idem, $provider);
    }
    // Stamp the TxID (UNIQUE in DB -> can never double-claim).
    credits_attach_charge_id((int) $order['id'], $txid);
    $result = credits_issue_topup((string) $order['public_id']);
} catch (Throwable $e) {
    error_log('[crypto-verify] credit error: ' . $e->getMessage());
    cv_fail(500, 'Could not credit your wallet. The transfer is verified - please retry in a moment.');
}

$balance = credits_get_balance($userId, true);
echo json_encode([
    'ok'            => true,
    'credited_now'  => (bool) ($result['credited_now'] ?? false),
    'amount_usd'    => $amountUsd,
    'token'         => (string) ($res['token'] ?? 'USDT'),
    'chain'         => $chain,
    'confirmations' => (int) ($res['confirmations'] ?? 0),
    'balance'       => $balance,
]);
