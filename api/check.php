<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/imei_demo.php';
require __DIR__ . '/../includes/blacklist.php';

/**
 * Public homepage IMEI check — the FREE tier ONLY.
 *
 * This endpoint is intentionally unauthenticated, so it must never call a
 * paid upstream provider or accept a caller-supplied `service`. It performs
 * the same local TAC lookup as the free IMEI_BASIC service (brand / model /
 * basic specs from the on-box TAC database) and nothing else.
 *
 * Paid lookups (carrier, iCloud, GSX, blacklist, ...) go exclusively through
 * the authenticated, credit-deducting /api/services/use.php.
 */

function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$imei = imei_normalize((string) ($_POST['imei'] ?? $_GET['imei'] ?? ''));

if ($imei === '') {
    respond(400, ['ok' => false, 'error' => 'Please enter an IMEI number.']);
}
if (!imei_is_valid($imei)) {
    respond(422, ['ok' => false, 'error' => 'Invalid IMEI. Make sure it has 15 digits and passes the Luhn check.']);
}
if (!rate_limit_allow(client_ip(), 10)) {
    respond(429, ['ok' => false, 'error' => 'Too many requests. Please try again in a minute.']);
}

$cached = lookup_get_cached($imei);
if ($cached) {
    $details = json_decode($cached['raw_response'] ?? 'null', true);
    respond(200, [
        'ok'        => true,
        'cached'    => true,
        'free'      => true,
        'imei'      => $imei,
        'tac'       => $cached['tac'],
        'brand'     => $cached['brand'],
        'model'     => $cached['model'],
        'details'   => is_array($details) ? ($details['details'] ?? []) : [],
    ]);
}

// Free local lookup only — no external/paid provider call.
$result = imei_demo_lookup($imei, '0');

lookup_save([
    'imei'         => $imei,
    'tac'          => imei_tac($imei),
    'provider'     => 'local',
    'service_id'   => null,
    'status'       => $result['status'],
    'brand'        => $result['brand'],
    'model'        => $result['model'],
    'raw_response' => json_encode(['details' => $result['details']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
]);

respond(200, [
    'ok'        => true,
    'cached'    => false,
    'free'      => true,
    'imei'      => $imei,
    'tac'       => imei_tac($imei),
    'brand'     => $result['brand'],
    'model'     => $result['model'],
    'details'   => $result['details'],
]);
