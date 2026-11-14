<?php
declare(strict_types=1);

/**
 * Stripe webhook handler.
 *
 * Order of operations (DO NOT reshuffle without thinking through it):
 *   1. Read the RAW request body BEFORE any json_decode/json_encode mutates it
 *      (Stripe's signature is computed over the raw bytes).
 *   2. Verify the Stripe-Signature header. If it fails, return 400 immediately.
 *      Never touch the database for an unverified payload.
 *   3. INSERT IGNORE the raw event into webhook_events. This gives us an audit
 *      log even for events we don't currently handle, and the unique key on
 *      (provider, event_id) gives us instant deduplication.
 *   4. If the row was already processed (processed=1), return 200 - Stripe is
 *      retrying because it didn't get our previous 2xx in time.
 *   5. Dispatch on event type. The actual credit issuance is wrapped in
 *      credits_issue_topup() which is itself idempotent, so even if Stripe
 *      delivers the same event twice in flight we never double-credit.
 *   6. Mark processed=1 + processed_at on success. On failure, leave processed=0
 *      AND return 5xx so Stripe will retry (its default policy is exponential
 *      backoff up to 3 days).
 *
 * NEVER return 200 for an event we failed to process - that tells Stripe
 * "all good, stop retrying" and we'd lose money.
 */

require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/stripe.php';
require __DIR__ . '/../../includes/credits_write.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function whlog(string $level, string $msg, array $ctx = []): void
{
    error_log('[stripe-webhook][' . $level . '] ' . $msg . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$cfg     = require __DIR__ . '/../../includes/config.php';
$secret  = (string) $cfg['stripe']['webhook_secret'];
$payload = (string) file_get_contents('php://input');
$sigHdr  = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($secret === '') {
    whlog('error', 'STRIPE_WEBHOOK_SECRET not configured');
    http_response_code(500);
    echo json_encode(['error' => 'webhook secret not configured']);
    exit;
}

try {
    $event = stripe_verify_webhook($payload, $sigHdr, $secret);
} catch (Throwable $e) {
    whlog('warn', 'signature rejected', ['err' => $e->getMessage()]);
    http_response_code(400);
    echo json_encode(['error' => 'signature failed']);
    exit;
}

$eventId   = isset($event['id'])   ? (string) $event['id']   : null;
$eventType = isset($event['type']) ? (string) $event['type'] : null;

// ---- Step 3: audit log (best effort) + step 4: dedupe check -------------
$logRowId = null;
$alreadyProcessed = false;
try {
    $pdo = db();
    $pdo->prepare(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_ok, raw_body)
         VALUES ("stripe", ?, ?, 1, ?)'
    )->execute([$eventId, $eventType, $payload]);

    $stmt = $pdo->prepare(
        'SELECT id, processed FROM webhook_events
         WHERE provider = "stripe" AND event_id = ? LIMIT 1'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row) {
        $logRowId = (int) $row['id'];
        $alreadyProcessed = (int) $row['processed'] === 1;
    }
} catch (Throwable $e) {
    whlog('error', 'DB log failed', ['err' => $e->getMessage(), 'event' => $eventId]);
    http_response_code(503);
    echo json_encode(['error' => 'db unavailable - please retry']);
    exit;
}

if ($alreadyProcessed) {
    echo json_encode(['received' => true, 'duplicate' => true]);
    exit;
}

// ---- Step 5: dispatch ----------------------------------------------------
try {
    $object   = $event['data']['object'] ?? [];
    $publicId = $object['client_reference_id']
              ?? ($object['metadata']['topup_public_id'] ?? null);

    switch ($eventType) {
        // Synchronous card payment OR the front half of an async PromptPay flow.
        // We only credit when payment_status === 'paid'. If unpaid, this is a
        // PromptPay session that hasn't completed yet - the credit will land
        // on async_payment_succeeded.
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            $paymentStatus = (string) ($object['payment_status'] ?? '');
            if ($publicId && $paymentStatus === 'paid') {
                $result = credits_issue_topup($publicId);
                // Record the PaymentIntent so a later charge.refunded can be
                // matched back to this order (provider_charge_id holds the
                // Checkout session id, which refund events never carry).
                $pi = (string) ($object['payment_intent'] ?? '');
                if ($pi !== '') {
                    db()->prepare(
                        'UPDATE topup_orders SET provider_payment_intent = ?
                         WHERE public_id = ? AND (provider_payment_intent IS NULL OR provider_payment_intent = "")'
                    )->execute([$pi, $publicId]);
                }
                whlog('info', 'topup credited', [
                    'public_id'    => $publicId,
                    'credited_now' => $result['credited_now'],
                ]);
            } elseif ($publicId) {
                whlog('info', 'session completed but unpaid (async)', [
                    'public_id'      => $publicId,
                    'payment_status' => $paymentStatus,
                ]);
            }
            break;

        case 'checkout.session.async_payment_failed':
            if ($publicId) {
                credits_mark_order_failed($publicId, 'async_payment_failed');
                whlog('info', 'topup marked failed (async)', ['public_id' => $publicId]);
            }
            break;

        case 'checkout.session.expired':
            if ($publicId) {
                db()->prepare(
                    'UPDATE topup_orders SET status = "EXPIRED" WHERE public_id = ? AND status = "PENDING"'
                )->execute([$publicId]);
                whlog('info', 'topup expired', ['public_id' => $publicId]);
            }
            break;

        case 'charge.refunded':
            // The Charge object carries its PaymentIntent (pi_...); match the
            // order we recorded it against at payment time. We only reflect the
            // refund on the order here - the ledger-level refund (a REFUND
            // CreditTransaction) stays a manual admin action because clawing
            // back already-spent wallet balance must be deliberate.
            $pi = (string) ($object['payment_intent'] ?? '');
            if ($pi !== '') {
                $upd = db()->prepare(
                    'UPDATE topup_orders SET status = "REFUNDED"
                     WHERE provider_payment_intent = ? AND status <> "REFUNDED"'
                );
                $upd->execute([$pi]);
                whlog('info', 'topup marked refunded', ['payment_intent' => $pi, 'rows' => $upd->rowCount()]);
            }
            break;

        default:
            // Logged for audit, no action.
            whlog('debug', 'unhandled event', ['type' => $eventType, 'id' => $eventId]);
    }

    if ($logRowId) {
        db()->prepare(
            'UPDATE webhook_events SET processed = 1, processed_at = NOW(3) WHERE id = ?'
        )->execute([$logRowId]);
    }

    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    whlog('error', 'processing failed', [
        'event' => $eventId,
        'type'  => $eventType,
        'err'   => $e->getMessage(),
    ]);
    if ($logRowId) {
        try {
            db()->prepare(
                'UPDATE webhook_events SET processing_error = ? WHERE id = ?'
            )->execute([substr($e->getMessage(), 0, 500), $logRowId]);
        } catch (Throwable $_) { /* ignore */ }
    }
    http_response_code(500);
    echo json_encode(['error' => 'processing failed']);
}
