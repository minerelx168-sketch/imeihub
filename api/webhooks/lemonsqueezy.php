<?php
declare(strict_types=1);

/**
 * Lemon Squeezy webhook handler.
 *
 * Same lifecycle as the Stripe/PayPal handlers:
 *   1. Read RAW body.
 *   2. Verify HMAC-SHA256 signature (X-Signature header).
 *   3. INSERT IGNORE into webhook_events for audit + dedupe.
 *   4. Dispatch on meta.event_name. Only "order_created" with
 *      status "paid" issues credit.
 *   5. Mark processed=1 on success. Return 5xx on failure so
 *      Lemon Squeezy retries.
 *
 * NEVER return 200 for an event we failed to process.
 */

require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/lemonsqueezy.php';
require __DIR__ . '/../../includes/credits_write.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function lswhlog(string $level, string $msg, array $ctx = []): void
{
    error_log('[lemonsqueezy-webhook][' . $level . '] ' . $msg . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$cfg     = require __DIR__ . '/../../includes/config.php';
$secret  = (string) ($cfg['lemonsqueezy']['webhook_secret'] ?? '');
$payload = (string) file_get_contents('php://input');
$sigHdr  = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');

if ($secret === '') {
    lswhlog('error', 'LEMONSQUEEZY_WEBHOOK_SECRET not configured');
    http_response_code(500);
    echo json_encode(['error' => 'webhook secret not configured']);
    exit;
}

// Step 2: Verify signature
if (!lemonsqueezy_verify_webhook($payload, $sigHdr, $secret)) {
    lswhlog('warn', 'signature rejected', ['sig' => substr($sigHdr, 0, 16) . '...']);
    http_response_code(400);
    echo json_encode(['error' => 'signature failed']);
    exit;
}

$event     = json_decode($payload, true) ?: [];
$meta      = $event['meta'] ?? [];
$eventName = (string) ($meta['event_name'] ?? '');
$data      = $event['data'] ?? [];
$attrs     = $data['attributes'] ?? [];

// Use the Lemon Squeezy order ID as the unique event identifier
$eventId = 'ls_order_' . (string) ($data['id'] ?? uniqid());

// Step 3: Audit + dedupe
$logRowId = null;
$already  = false;
try {
    $pdo = db();
    $pdo->prepare(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_ok, raw_body)
         VALUES ("lemonsqueezy", ?, ?, 1, ?)'
    )->execute([$eventId, $eventName, $payload]);

    $stmt = $pdo->prepare(
        'SELECT id, processed FROM webhook_events
         WHERE provider = "lemonsqueezy" AND event_id = ? LIMIT 1'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row) {
        $logRowId = (int) $row['id'];
        $already  = (int) $row['processed'] === 1;
    }
} catch (Throwable $e) {
    lswhlog('error', 'DB log failed', ['err' => $e->getMessage(), 'event' => $eventId]);
    http_response_code(503);
    echo json_encode(['error' => 'db unavailable - please retry']);
    exit;
}

if ($already) {
    echo json_encode(['received' => true, 'duplicate' => true]);
    exit;
}

// Step 4: Dispatch
try {
    $customData = $meta['custom_data'] ?? [];
    $publicId   = (string) ($customData['topup_public_id'] ?? '');

    switch ($eventName) {
        case 'order_created':
            $status = (string) ($attrs['status'] ?? '');

            if ($publicId !== '' && $status === 'paid') {
                // Store the Lemon Squeezy order ID as the charge reference
                $lsOrderId = (string) ($data['id'] ?? '');
                if ($lsOrderId !== '') {
                    try {
                        db()->prepare(
                            'UPDATE topup_orders SET provider_charge_id = ?
                             WHERE public_id = ? AND (provider_charge_id IS NULL OR provider_charge_id = "" OR provider_charge_id LIKE "ls_pending_%")'
                        )->execute(['ls_' . $lsOrderId, $publicId]);
                    } catch (Throwable $_) {
                        // Non-critical: charge_id is for reference only
                    }
                }

                $result = credits_issue_topup($publicId);
                lswhlog('info', 'topup credited', [
                    'public_id'    => $publicId,
                    'credited_now' => $result['credited_now'],
                    'bonus'        => $result['bonus'] ?? 0,
                    'ls_order_id'  => $lsOrderId,
                ]);
            } elseif ($publicId !== '' && $status === 'pending') {
                // Mark as PAID (waiting for final confirmation)
                db()->prepare(
                    'UPDATE topup_orders SET status = "PAID", paid_at = COALESCE(paid_at, NOW())
                     WHERE public_id = ? AND status = "PENDING"'
                )->execute([$publicId]);
                lswhlog('info', 'order pending', ['public_id' => $publicId, 'status' => $status]);
            } elseif ($publicId !== '' && in_array($status, ['failed', 'refunded'], true)) {
                credits_mark_order_failed($publicId, 'lemonsqueezy_' . $status);
                lswhlog('info', 'topup marked ' . $status, ['public_id' => $publicId]);
            } else {
                lswhlog('debug', 'order_created without public_id or unhandled status', [
                    'public_id' => $publicId,
                    'status'    => $status,
                ]);
            }
            break;

        case 'order_refunded':
            if ($publicId !== '') {
                db()->prepare(
                    'UPDATE topup_orders SET status = "REFUNDED"
                     WHERE public_id = ? AND status <> "REFUNDED"'
                )->execute([$publicId]);
                lswhlog('info', 'topup refunded', ['public_id' => $publicId]);
            }
            break;

        default:
            lswhlog('debug', 'unhandled event', ['type' => $eventName, 'id' => $eventId]);
    }

    if ($logRowId) {
        db()->prepare(
            'UPDATE webhook_events SET processed = 1, processed_at = NOW(3) WHERE id = ?'
        )->execute([$logRowId]);
    }
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    lswhlog('error', 'processing failed', [
        'event' => $eventId,
        'type'  => $eventName,
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
