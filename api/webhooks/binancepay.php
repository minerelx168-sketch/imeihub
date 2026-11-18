<?php
declare(strict_types=1);

/**
 * Binance Pay IPN webhook handler.
 *
 * Same shape as the Stripe / PayPal webhooks:
 *   1. Read RAW body.
 *   2. Verify the RSA signature against Binance's signing cert.
 *      The cert is fetched (signed) once and then cached on disk for
 *      BINANCE_PAY_CERT_TTL seconds.
 *   3. INSERT IGNORE into webhook_events for audit + dedupe.
 *   4. Dispatch on bizStatus. PAY_SUCCESS issues credit (idempotent),
 *      PAY_CLOSED / PAY_FAILURE marks the order failed.
 *   5. Always reply with the JSON envelope Binance expects:
 *        {"returnCode":"SUCCESS","returnMessage":null}
 *      Anything else makes Binance retry (up to 8 times over a day).
 */

require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/binancepay.php';
require __DIR__ . '/../../includes/credits_write.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function bpwhlog(string $level, string $msg, array $ctx = []): void
{
    error_log('[binancepay-webhook][' . $level . '] ' . $msg . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : ''));
}

function bp_reply_ok(): never {
    echo json_encode(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
    exit;
}
function bp_reply_fail(string $msg): never {
    http_response_code(500);
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'POST only']);
    exit;
}

$payload = (string) file_get_contents('php://input');
$headers = getallheaders() ?: [];

try {
    $event = binancepay_verify_webhook($payload, $headers);
} catch (Throwable $e) {
    bpwhlog('warn', 'verify failed', ['err' => $e->getMessage()]);
    http_response_code(400);
    echo json_encode(['returnCode' => 'FAIL', 'returnMessage' => 'verify failed']);
    exit;
}

// Binance wraps the actual order in `data` as a JSON-encoded string.
$bizType   = (string) ($event['bizType']   ?? '');
$bizStatus = (string) ($event['bizStatus'] ?? '');
$dataJson  = (string) ($event['data']      ?? '');
$data      = json_decode($dataJson, true) ?: [];

$merchantTradeNo = (string) ($data['merchantTradeNo'] ?? '');
$publicId        = $merchantTradeNo; // we used the public_id verbatim

// Audit + dedupe (Binance's bizIdStr / transactionId is the unique key).
$eventId = (string) ($event['bizIdStr'] ?? $event['bizId'] ?? '');
if ($eventId === '') {
    $eventId = $bizType . ':' . $merchantTradeNo . ':' . $bizStatus;
}

$logRowId = null;
$already  = false;
try {
    $pdo = db();
    $pdo->prepare(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_ok, raw_body)
         VALUES ("binancepay", ?, ?, 1, ?)'
    )->execute([$eventId, $bizType . ':' . $bizStatus, $payload]);

    $stmt = $pdo->prepare(
        'SELECT id, processed FROM webhook_events
         WHERE provider = "binancepay" AND event_id = ? LIMIT 1'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row) {
        $logRowId = (int) $row['id'];
        $already  = (int) $row['processed'] === 1;
    }
} catch (Throwable $e) {
    bpwhlog('error', 'DB log failed', ['err' => $e->getMessage(), 'event' => $eventId]);
    bp_reply_fail('db unavailable');
}

if ($already) {
    bp_reply_ok();
}

try {
    if ($bizType === 'PAY' && $publicId !== '') {
        switch ($bizStatus) {
            case 'PAY_SUCCESS':
                $result = credits_issue_topup($publicId);
                bpwhlog('info', 'topup credited', [
                    'public_id'    => $publicId,
                    'credited_now' => $result['credited_now'],
                    'bonus'        => $result['bonus'] ?? 0,
                ]);
                break;
            case 'PAY_CLOSED':
            case 'PAY_FAILURE':
                credits_mark_order_failed($publicId, $bizStatus);
                bpwhlog('info', 'topup marked failed', ['public_id' => $publicId, 'status' => $bizStatus]);
                break;
            default:
                bpwhlog('debug', 'unhandled bizStatus', ['status' => $bizStatus, 'id' => $publicId]);
        }
    } else {
        bpwhlog('debug', 'unhandled event', ['bizType' => $bizType, 'id' => $eventId]);
    }

    if ($logRowId) {
        db()->prepare(
            'UPDATE webhook_events SET processed = 1, processed_at = NOW(3) WHERE id = ?'
        )->execute([$logRowId]);
    }
    bp_reply_ok();
} catch (Throwable $e) {
    bpwhlog('error', 'processing failed', [
        'event' => $eventId,
        'err'   => $e->getMessage(),
    ]);
    if ($logRowId) {
        try {
            db()->prepare('UPDATE webhook_events SET processing_error = ? WHERE id = ?')
                ->execute([substr($e->getMessage(), 0, 500), $logRowId]);
        } catch (Throwable $_) {}
    }
    bp_reply_fail('processing failed');
}
