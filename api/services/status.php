<?php
declare(strict_types=1);

/**
 * Polling endpoint for async (DHRU) service lookups.
 *
 *   GET /api/services/status.php?id=<usage public_id>
 *
 * Behavior:
 *   - Requires session; the usage must belong to the caller.
 *   - SUCCESS / FAILED / REFUNDED rows return immediately from the
 *     cached output / error fields. No provider call.
 *   - PROCESSING rows are debounced: if last_polled_at is within 5
 *     seconds, the response is "still processing" without hitting
 *     the upstream. Otherwise we call imei_provider_query_dhru(),
 *     update the row, and return the latest state.
 *   - PENDING rows (sync lookups that haven't returned yet) just
 *     report processing too - the caller of /api/services/use.php
 *     should have already received the result inline; if it's still
 *     PENDING something went wrong upstream and a future cron sweep
 *     will surface it.
 *
 * Response shape:
 *   { ok: true, status: 'processing', public_id, retry_after }
 *   { ok: true, status: 'success',    public_id, imei, tac, cost,
 *                                     brand, model, details }
 *   { ok: false, status: 'failed' | 'refunded', error, refunded? }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

// Keep warnings out of the JSON body (they'd surface as a client "Network
// error"); log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/credits_write.php';
require __DIR__ . '/../../includes/imei_provider.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/service_fields.php';

function reply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$user = auth_user();
if (!$user) {
    reply(401, ['ok' => false, 'error' => 'Not signed in.']);
}

$publicId = isset($_GET['id']) ? (string) $_GET['id'] : '';
if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)) {
    reply(400, ['ok' => false, 'error' => 'Invalid usage id.']);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    reply(503, ['ok' => false, 'error' => 'Database unavailable.']);
}

$stmt = $pdo->prepare(
    'SELECT u.*, UNIX_TIMESTAMP(u.last_polled_at) AS last_polled_ts
     FROM service_usages u
     WHERE u.public_id = ? AND u.user_id = ?
     LIMIT 1'
);
$stmt->execute([$publicId, (int) $user['id']]);
$usage = $stmt->fetch();
if (!$usage) {
    reply(404, ['ok' => false, 'error' => 'Lookup not found.']);
}

$status = (string) $usage['status'];
$imei   = (string) ($usage['input'] ? (json_decode((string) $usage['input'], true)['imei'] ?? '') : '');
$code   = (string) $usage['service_code'];
$curated = service_result_has_template($code);

// Terminal SUCCESS - serve the cached output.
if ($status === 'SUCCESS') {
    $out = json_decode((string) $usage['output'], true) ?: [];
    $details = (array) ($out['details'] ?? []);
    reply(200, [
        'ok'              => true,
        'status'          => 'success',
        'public_id'       => $publicId,
        'imei'            => $imei,
        'tac'             => $imei ? imei_tac($imei) : '',
        'cost'            => (string) $usage['cost'],
        'brand'           => $out['brand']   ?? null,
        'model'           => $out['model']   ?? null,
        'details'         => $curated ? service_filter_details($code, $details) : $details,
        'details_curated' => $curated,
    ]);
}

// Terminal failure / refunded.
if ($status === 'FAILED' || $status === 'REFUNDED') {
    reply(200, [
        'ok'       => false,
        'status'   => strtolower($status),
        'error'    => (string) ($usage['error_message'] ?? 'Lookup failed.'),
        'refunded' => $status === 'REFUNDED',
    ]);
}

// PENDING or PROCESSING -> still in flight. PROCESSING gets a poll if
// the debounce window has elapsed.
if ($status === 'PROCESSING' && !empty($usage['provider_order_id'])) {
    $lastPolledTs = (int) ($usage['last_polled_ts'] ?? 0);
    $debounceSec  = 5;
    if ((time() - $lastPolledTs) >= $debounceSec) {
        try {
            $result = imei_provider_query_dhru((string) $usage['provider_order_id']);
        } catch (Throwable $e) {
            // Network blip or bad provider state - keep PROCESSING,
            // tick last_polled_at so we back off.
            credits_touch_usage_polled($publicId);
            reply(200, [
                'ok'          => true,
                'status'      => 'processing',
                'public_id'   => $publicId,
                'retry_after' => 10,
            ]);
        }

        $pStatus = (string) ($result['status'] ?? '');
        if ($pStatus === 'success') {
            credits_mark_usage_success($publicId, [
                'brand'   => $result['brand'],
                'model'   => $result['model'],
                'details' => $result['details'],
            ]);
            reply(200, [
                'ok'              => true,
                'status'          => 'success',
                'public_id'       => $publicId,
                'imei'            => $imei,
                'tac'             => $imei ? imei_tac($imei) : '',
                'cost'            => (string) $usage['cost'],
                'brand'           => $result['brand'],
                'model'           => $result['model'],
                'details'         => $curated ? service_filter_details($code, (array) $result['details']) : $result['details'],
                'details_curated' => $curated,
            ]);
        }
        if ($pStatus === 'failed') {
            credits_refund_usage($publicId, (string) ($result['error'] ?? 'DHRU returned failure'));
            reply(200, [
                'ok'       => false,
                'status'   => 'refunded',
                'error'    => (string) ($result['error'] ?? 'Lookup failed.'),
                'refunded' => true,
            ]);
        }

        // Still processing - tick last_polled_at.
        credits_touch_usage_polled($publicId);
    }
}

// Default: still processing.
reply(200, [
    'ok'          => true,
    'status'      => 'processing',
    'public_id'   => $publicId,
    'retry_after' => 8,
]);
