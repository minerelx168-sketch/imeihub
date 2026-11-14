<?php
declare(strict_types=1);

/**
 * PayPal IPN webhook handler.
 *
 * Same shape as the Stripe webhook:
 *   1. Read RAW body.
 *   2. Verify signature via PayPal's verify-webhook-signature endpoint.
 *      We rely on PayPal's own crypto check rather than re-implementing
 *      DSA verification - the call back to PayPal is the price of
 *      not shipping a public key cache.
 *   3. INSERT IGNORE into webhook_events for audit + dedupe.
 *   4. Dispatch on event_type. Only PAYMENT.CAPTURE.COMPLETED issues
 *      credit; the other events are logged so the admin can see them.
 *   5. Mark processed=1 on success. Return 5xx on failure so PayPal
 *      retries (their backoff is up to 25 hours).
 *
 * NEVER return 200 for an event we failed to process.
 */

require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/paypal.php';
require __DIR__ . '/../../includes/credits_write.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function ppwhlog(string $level, string $msg, array $ctx = []): void
{
    error_log('[paypal-webhook][' . $level . '] ' . $msg . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$cfg       = require __DIR__ . '/../../includes/config.php';
$webhookId = (string) $cfg['paypal']['webhook_id'];
$payload   = (string) file_get_contents('php://input');
$headers   = getallheaders() ?: [];

if ($webhookId === '') {
    ppwhlog('error', 'PAYPAL_WEBHOOK_ID not configured');
    http_response_code(500);
    echo json_encode(['error' => 'webhook id not configured']);
    exit;
}

try {
    $ok = paypal_verify_webhook($payload, $headers, $webhookId);
    if (!$ok) {
        ppwhlog('warn', 'signature rejected');
        http_response_code(400);
        echo json_encode(['error' => 'signature failed']);
        exit;
    }
} catch (Throwable $e) {
    ppwhlog('warn', 'verify failed', ['err' => $e->getMessage()]);
    http_response_code(400);
    echo json_encode(['error' => 'verify failed']);
    exit;
}

$event     = json_decode($payload, true) ?: [];
$eventId   = (string) ($event['id']         ?? '');
$eventType = (string) ($event['event_type'] ?? '');

// Audit + dedupe.
$logRowId = null;
$already  = false;
try {
    $pdo = db();
    $pdo->prepare(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_ok, raw_body)
         VALUES ("paypal", ?, ?, 1, ?)'
    )->execute([$eventId, $eventType, $payload]);

    $stmt = $pdo->prepare(
        'SELECT id, processed FROM webhook_events
         WHERE provider = "paypal" AND event_id = ? LIMIT 1'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row) {
        $logRowId = (int) $row['id'];
        $already  = (int) $row['processed'] === 1;
    }
} catch (Throwable $e) {
    ppwhlog('error', 'DB log failed', ['err' => $e->getMessage(), 'event' => $eventId]);
    http_response_code(503);
    echo json_encode(['error' => 'db unavailable']);
    exit;
}

if ($already) {
    echo json_encode(['received' => true, 'duplicate' => true]);
    exit;
}

try {
    $resource = $event['resource'] ?? [];
    // For PAYMENT.CAPTURE.COMPLETED the custom_id is on the capture
    // resource. For CHECKOUT.ORDER.APPROVED it lives one level deeper
    // under purchase_units[0].
    $publicId = $resource['custom_id']
              ?? ($resource['purchase_units'][0]['custom_id'] ?? null);
    if ($publicId === null || $publicId === '') {
        $publicId = $resource['purchase_units'][0]['reference_id'] ?? null;
    }

    switch ($eventType) {
        case 'PAYMENT.CAPTURE.COMPLETED':
            if ($publicId) {
                $result = credits_issue_topup((string) $publicId);
                ppwhlog('info', 'topup credited', [
                    'public_id'    => $publicId,
                    'credited_now' => $result['credited_now'],
                    'bonus'        => $result['bonus'] ?? 0,
                ]);
            }
            break;

        case 'PAYMENT.CAPTURE.DENIED':
        case 'PAYMENT.CAPTURE.DECLINED':
        case 'PAYMENT.CAPTURE.REVERSED':
            if ($publicId) {
                credits_mark_order_failed((string) $publicId, $eventType);
                ppwhlog('info', 'topup marked failed', ['public_id' => $publicId, 'event' => $eventType]);
            }
            break;

        case 'CHECKOUT.ORDER.APPROVED':
            // The buyer pressed Pay Now but capture hasn't fired yet.
            // We mark the order PAID (still not CREDITED) so the
            // dashboard shows progress; CAPTURE.COMPLETED will flip
            // it to CREDITED.
            if ($publicId) {
                db()->prepare(
                    'UPDATE topup_orders SET status = "PAID", paid_at = COALESCE(paid_at, NOW())
                     WHERE public_id = ? AND status = "PENDING"'
                )->execute([(string) $publicId]);
            }
            break;

        default:
            ppwhlog('debug', 'unhandled event', ['type' => $eventType, 'id' => $eventId]);
    }

    if ($logRowId) {
        db()->prepare(
            'UPDATE webhook_events SET processed = 1, processed_at = NOW(3) WHERE id = ?'
        )->execute([$logRowId]);
    }
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    ppwhlog('error', 'processing failed', [
        'event' => $eventId,
        'type'  => $eventType,
        'err'   => $e->getMessage(),
    ]);
    if ($logRowId) {
        try {
            db()->prepare('UPDATE webhook_events SET processing_error = ? WHERE id = ?')
                ->execute([substr($e->getMessage(), 0, 500), $logRowId]);
        } catch (Throwable $_) {}
    }
    http_response_code(500);
    echo json_encode(['error' => 'processing failed']);
}
