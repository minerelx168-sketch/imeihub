<?php
declare(strict_types=1);

/**
 * Paid service usage endpoint.
 *
 *   POST /api/services/use.php
 *   Content-Type: application/json
 *   { "code": "BLACKLIST", "input": { "imei": "359..." } }
 *
 * Flow:
 *   1. Require session.
 *   2. credits_deduct() in a SERIALIZABLE tx: creates PENDING usage,
 *      deducts the cost atomically.
 *   3. Call the IMEI provider with the input.
 *   4. provider success -> credits_mark_usage_success(), return result.
 *      provider failure -> credits_refund_usage(), return error.
 *
 * The deduct is atomic and the refund is idempotent, so a slow client
 * retrying the same request CAN cost a user one extra deduct if they
 * retry before our refund commits. We accept that for now - the brief
 * says client-side idempotency is optional, and the dashboard's full
 * history makes any discrepancy auditable.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

// A large GSX/heavy report can trip a PHP notice/warning while parsing; with
// display_errors on (dev php.ini) that HTML prints into the body and corrupts
// the JSON, which the browser surfaces as an opaque "Network error". Force
// errors to the log only and guarantee a JSON body even on a fatal.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Give the request enough headroom for a slow upstream provider (curl is
// capped at 30s; plus a few seconds for JSON encode / DB writes). Apache's
// default 60s request timeout still bounds us above this.
@set_time_limit(60);
ob_start();
// Track the active deduct so that if the script is killed mid-flight (memory
// fatal, set_time_limit hit, peer abort) the shutdown handler can refund the
// user instead of leaving the wallet charged with no result.
$pendingDeduct = ['public_id' => null];
register_shutdown_function(static function () use (&$pendingDeduct): void {
    $e = error_get_last();
    $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    if (!$fatal) return;
    // Best-effort refund of any usage that was deducted but never resolved.
    $refunded = false;
    if ($pendingDeduct['public_id'] !== null && function_exists('credits_refund_usage')) {
        try {
            credits_refund_usage(
                (string) $pendingDeduct['public_id'],
                'Lookup interrupted before provider responded: ' . ($e['message'] ?? '')
            );
            $refunded = true;
        } catch (Throwable $_) { /* logged below */ }
    }
    if (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'       => false,
        'error'    => $refunded
            ? 'Lookup took too long and was cancelled. Your credit has been refunded automatically.'
            : 'Server error during lookup.',
        'refunded' => $refunded,
    ]);
});

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/credits_write.php';
require __DIR__ . '/../../includes/imei_provider.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/blacklist.php';
require __DIR__ . '/../../includes/service_fields.php';

function fail(int $code, string $error, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $error], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail(405, 'POST only.');
}

$user = auth_user();
if (!$user) {
    fail(401, 'Not signed in.');
}

// Parse body (JSON or form-encoded).
$body = [];
$raw  = (string) file_get_contents('php://input');
$ct   = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
if (str_contains($ct, 'application/json') && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
} else {
    $body = $_POST;
}

$code  = isset($body['code'])  ? strtoupper(trim((string) $body['code'])) : '';
$input = isset($body['input']) ? $body['input'] : [];
if (!is_array($input)) $input = [];

if (!preg_match('/^[A-Z0-9_]{2,32}$/', $code)) {
    fail(400, 'Invalid service code.');
}

// All current services need an IMEI input. Validate it up-front so we don't
// charge the user for a guaranteed failure.
$imei = isset($input['imei']) ? imei_normalize((string) $input['imei']) : '';
if (!imei_is_valid($imei)) {
    fail(422, 'Invalid IMEI. Make sure it has 15 digits and passes the Luhn check.');
}
$input['imei'] = $imei; // canonicalized

// Rate limit on user id, not IP, since these are authenticated calls.
if (!rate_limit_allow('user:' . $user['id'], 30)) {
    fail(429, 'Too many requests. Please try again in a minute.');
}

// Phase 1: ATOMIC deduct (or InsufficientCreditError).
try {
    $usage = credits_deduct((int) $user['id'], $code, $input);
} catch (InsufficientCreditError $e) {
    fail(402, $e->getMessage(), ['error_code' => 'INSUFFICIENT_CREDIT']);
} catch (Throwable $e) {
    fail(500, $e->getMessage());
}

$publicId = (string) $usage['public_id'];
$cost     = (string) $usage['cost'];
// Wallet is now debited; arm the shutdown-refund net until we either commit a
// SUCCESS/PROCESSING result or explicitly refund below.
$pendingDeduct['public_id'] = $publicId;

// Phase 2: call the provider. Map our service_code -> upstream service.
// Entry shapes (data/service_provider_map.php):
//   null                          -> free local TAC lookup, no API call
//   123                           -> PHP API (sync)
//   ['id'=>123,'type'=>'dhru']    -> DHRU API (async), place order +
//                                    return status=processing; the
//                                    client then polls api/services/status.php
$serviceMap = require __DIR__ . '/../../data/service_provider_map.php';
$entry      = $serviceMap[$code] ?? null;

if ($entry === null) {
    $providerServiceId = null;
    $apiType           = 'php';
} elseif (is_array($entry)) {
    $providerServiceId = (string) ($entry['id']   ?? '');
    $apiType           = strtolower((string) ($entry['type'] ?? 'php'));
} else {
    $providerServiceId = (string) $entry;
    $apiType           = 'php';
}

try {
    if ($providerServiceId === null) {
        // Free tier: local TAC lookup, no external call.
        require_once __DIR__ . '/../../includes/imei_demo.php';
        $result = imei_demo_lookup($imei, '0');
    } else {
        $result = imei_provider_lookup($imei, $providerServiceId, $apiType);
    }
} catch (Throwable $e) {
    credits_refund_usage($publicId, 'provider exception: ' . $e->getMessage());
    $pendingDeduct['public_id'] = null;
    fail(502, 'Lookup failed; your credit has been refunded.', [
        'public_id' => $publicId,
        'refunded'  => true,
    ]);
}

$status = (string) ($result['status'] ?? '');

// DHRU async: the order is placed, the wallet stays deducted, the row
// flips to PROCESSING with the provider reference id stamped. The
// client now polls /api/services/status.php?id=<public_id> for the
// final result (or a refund if the provider rejects it).
if ($status === 'processing') {
    credits_mark_usage_processing($publicId, (string) ($result['provider_order_id'] ?? ''));
    // Order is live in the provider's queue now; never refund via shutdown.
    $pendingDeduct['public_id'] = null;
    echo json_encode([
        'ok'                => true,
        'status'            => 'processing',
        'public_id'         => $publicId,
        'imei'              => $imei,
        'tac'               => imei_tac($imei),
        'cost'              => $cost,
        'provider_order_id' => $result['provider_order_id'] ?? null,
        // Community blacklist alerts are a paid feature; suppress for free services.
        'blacklist'         => $cost > 0 ? blacklist_status($imei) : null,
        // Suggested next-poll delay in seconds. The provider documents
        // 1-5 min turnaround so we use a wide initial gap; main.js
        // backs off further if /status.php returns processing again.
        'retry_after'       => 8,
    ]);
    exit;
}

if ($status !== 'success') {
    credits_refund_usage($publicId, (string) ($result['error'] ?? 'provider returned failure'));
    $pendingDeduct['public_id'] = null;
    fail(502, (string) ($result['error'] ?? 'Lookup failed.'), [
        'public_id' => $publicId,
        'refunded'  => true,
    ]);
}

// Phase 3: persist + return.
credits_mark_usage_success($publicId, [
    'brand'   => $result['brand'],
    'model'   => $result['model'],
    'details' => $result['details'],
    // Persist the provider latency breakdown (dns/connect/tls/ttfb/total ms)
    // so scripts/provider-latency.php can report p50/p95 per service and tell
    // us, with data, which services are slow enough to move to async. Stored
    // under a "_"-prefixed key that the result/orders views never read, so it
    // never surfaces in the UI.
    '_timing' => $result['_timing'] ?? null,
]);
// Result is locked in; the shutdown handler must not refund this usage even
// if the JSON encode below trips a fatal.
$pendingDeduct['public_id'] = null;

$curated = service_result_has_template($code);

echo json_encode([
    'ok'              => true,
    'status'          => 'success',
    'public_id'       => $publicId,
    'imei'            => $imei,
    'tac'             => imei_tac($imei),
    'cost'            => $cost,
    'free'            => $cost <= 0,
    'brand'           => $result['brand'],
    'model'           => $result['model'],
    'details'         => $curated ? service_filter_details($code, (array) $result['details']) : $result['details'],
    'details_curated' => $curated,
    // Community blacklist alerts are a paid feature; suppress for free services.
    'blacklist'       => $cost > 0 ? blacklist_status($imei) : null,
]);
