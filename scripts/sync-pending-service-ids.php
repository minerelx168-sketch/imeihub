<?php
declare(strict_types=1);

/**
 * Fetches the upstream service catalog and tells you exactly which
 * lines to flip on to activate the 17 pending IMEI Check services.
 *
 * Usage:
 *   php scripts/sync-pending-service-ids.php
 *
 *   php scripts/sync-pending-service-ids.php --fixture /tmp/catalog.json
 *       (Skip the live fetch; load the response from a file. Useful
 *        for testing the matcher against a saved sample.)
 *
 * Workflow:
 *   1. Hit  {IMEI_API_URL}/?key={IMEI_API_KEY}&accountinfo=servicelist
 *   2. Build a name -> {id, price, time} map from `object`.
 *   3. For each of the 17 pending codes hardcoded below, look up the
 *      provider entry by EXACT (case-insensitive, trimmed) name match.
 *   4. Print:
 *        - PHP edits for data/service_provider_map.php
 *        - SQL UPDATEs for sql/seed.sql (retail = max($0.05, wholesale*2))
 *        - Anything still missing (these are usually DHRU-only services
 *          that the PHP API endpoint does not list).
 *
 * Names below are verbatim from the catalog dump the operator received
 * from the provider; do NOT edit unless the upstream renames a service.
 */

require __DIR__ . '/../includes/config.php';
$cfg = require __DIR__ . '/../includes/config.php';

const PENDING = [
    'APPLE_OWNER_ID_INFO'        => 'Apple Owner ID Info - (Read Description)',
    'APPLE_IMEI_SN_CONVERT'      => 'Apple IMEI ↔ SN ↔ IMEI2 CONVERT',
    'APPLE_GSX_CASE_REPLACE'     => 'Apple Case History, Replacement',
    'APPLE_GSX_SOLD_BY'          => 'Apple Sold By Info',
    'APPLE_GSX_CASE_S2'          => 'Apple Case History',
    'APPLE_GSX_REPAIRS_S2'       => 'Apple Repairs, Replacement Details',
    'APPLE_GSX_CASE_DIAG_S2'     => 'Apple Case History, Repairs, Replacements, Diagnostics',
    'APPLE_GSX_SOLD_POLICY_S2'   => 'Apple Sold By, Activation Policy',
    'APPLE_GSX_FULL_LITE_S2'     => 'Apple Sold By, Case History, Replacement, Activation Policy',
    'APPLE_FULL_GSX_S2'          => 'Apple Sold By, Case History, Replacement, Activation Policy [ICCID & MAC] (FULL GSX) S2',
    'APPLE_GSX_CASE_PIC'         => 'Apple Case History (Picture)',
    'APPLE_GSX_CASE_REPAIR_PIC'  => 'Apple Case History, Repairs, Replacements (Picture)',
    'APPLE_GSX_SOLD_REPLACE_PIC' => 'Apple Sold By, Case History, Replacement (Picture)',
    'APPLE_GSX_SOLD_DIAG_PIC'    => 'Apple Sold By, Case History, Repairs, Replacement, Diagnostics (Picture)',
    'APPLE_FULL_GSX_PIC'         => 'Apple Full GSX (Picture)',
    'ZTE_INFO'                   => 'ZTE INFO',
    'SAMSUNG_INFO_S2'            => 'SAMSUNG INFO (Server 2)',
];

// --fixture <path>  -> read from file instead of hitting the API.
$fixturePath = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--fixture' && isset($argv[$i + 1])) {
        $fixturePath = $argv[$i + 1];
        $i++;
    }
}

$body = null;
if ($fixturePath !== null) {
    if (!is_file($fixturePath)) {
        fwrite(STDERR, "Fixture not found: $fixturePath\n");
        exit(2);
    }
    fwrite(STDERR, "Reading fixture: $fixturePath\n");
    $body = (string) file_get_contents($fixturePath);
} else {
    $apiKey = (string) ($cfg['api']['key'] ?? '');
    $apiUrl = rtrim((string) ($cfg['api']['url'] ?? ''), '/');
    if ($apiKey === '' || str_starts_with($apiKey, 'replace-with')) {
        fwrite(STDERR, "IMEI_API_KEY not configured in .env\n");
        exit(1);
    }
    $url = $apiUrl . '/?' . http_build_query([
        'key'         => $apiKey,
        'accountinfo' => 'servicelist',
    ]);
    fwrite(STDERR, "Fetching " . preg_replace('/(key)=[^&]+/', '$1=***', $url) . "\n");

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub-id-sync/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        fwrite(STDERR, "Network error: $err\n");
        exit(3);
    }
    if ($code >= 400) {
        fwrite(STDERR, "HTTP $code\n");
        exit(3);
    }
}

$json = json_decode((string) $body, true);
if (!is_array($json) || !is_array($json['object'] ?? null)) {
    fwrite(STDERR, "Unexpected response shape. Expected {status, object: {...}}.\n");
    fwrite(STDERR, substr((string) $body, 0, 400) . "\n");
    exit(4);
}

// Build name -> svc map. Provider names are kept verbatim.
$catalog = [];
foreach ($json['object'] as $key => $svc) {
    if (!is_array($svc)) continue;
    $name = trim((string) ($svc['name'] ?? ''));
    if ($name === '') continue;
    $catalog[normalize_name($name)] = [
        'id'    => (string) ($svc['service']     ?? $key),
        'name'  => $name,
        'price' => (string) ($svc['price']       ?? ''),
        'time'  => (string) ($svc['time']        ?? ''),
        'desc'  => (string) ($svc['description'] ?? ''),
    ];
}

fwrite(STDERR, sprintf("Catalog has %d services. Matching %d pending codes...\n\n",
    count($catalog), count(PENDING)));

$matched = [];
$missing = [];
foreach (PENDING as $code => $name) {
    $key = normalize_name($name);
    if (isset($catalog[$key])) {
        $matched[$code] = $catalog[$key];
    } else {
        $missing[$code] = $name;
    }
}

// -----------------------------------------------------------------------------
// Output: PHP map edits, then SQL UPDATEs.
// -----------------------------------------------------------------------------

echo "// =============================================================\n";
echo "// data/service_provider_map.php\n";
echo "// Replace each commented placeholder with the matched ID below.\n";
echo "// =============================================================\n\n";
foreach ($matched as $code => $svc) {
    printf("    '%-30s => %-6s  // %s | %s | wholesale \$%s\n",
        "$code'",
        $svc['id'] . ',',
        $svc['name'],
        $svc['time'] !== '' ? $svc['time'] : '?',
        $svc['price']
    );
}

echo "\n";
echo "-- =============================================================\n";
echo "-- sql/seed.sql -- apply to activate the matched rows.\n";
echo "-- Retail = max(\$0.05, wholesale * 2) rounded to \$0.01.\n";
echo "-- Adjust if your selling sheet has a specific price.\n";
echo "-- =============================================================\n\n";
foreach ($matched as $code => $svc) {
    $wholesale = (float) $svc['price'];
    $retail = round(max(0.05, $wholesale * 2), 2);
    printf("UPDATE service_prices SET active = 1, cost = %.2f WHERE code = '%s';  -- wholesale %s, time %s\n",
        $retail, $code, $svc['price'] !== '' ? '$' . $svc['price'] : '?',
        $svc['time'] !== '' ? $svc['time'] : '?');
}

if ($missing) {
    echo "\n-- =============================================================\n";
    echo "-- Still missing from PHP API catalog (likely DHRU-only).\n";
    echo "-- Request these IDs from provider support, then either:\n";
    echo "--   1. Wire the DHRU async flow in includes/imei_provider.php\n";
    echo "--      (place order -> poll), or\n";
    echo "--   2. Set them up as manual-fulfillment services.\n";
    echo "-- =============================================================\n";
    foreach ($missing as $code => $name) {
        printf("--  %-30s -- %s\n", $code, $name);
    }
}

fwrite(STDERR, sprintf("\nDone: matched %d, missing %d.\n", count($matched), count($missing)));

// -----------------------------------------------------------------------------

function normalize_name(string $s): string
{
    // Lowercase + collapse whitespace + strip non-essential punctuation
    // variance (different unicode arrow forms, NBSPs, etc.) so a tiny
    // formatting difference between our seed.sql and the provider's
    // catalog doesn't break the match.
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(
        ["\xC2\xA0", '↔', '⇔', '<->', '<=>'],
        [' ',         '<>', '<>',  '<>', '<>'],
        $s
    );
    $s = (string) preg_replace('/\s+/u', ' ', $s);
    return $s;
}
