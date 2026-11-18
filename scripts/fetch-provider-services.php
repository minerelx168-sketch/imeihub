<?php
/**
 * Fetch unlock-service.net's full IMEI service catalog and write it
 * to disk as both .csv (always works) and .xlsx (no dependencies -
 * uses PHP's built-in ZipArchive to build a minimal Open XML file).
 *
 * Usage:
 *   php scripts/fetch-provider-services.php [output-prefix]
 *
 * Examples:
 *   php scripts/fetch-provider-services.php
 *       writes /tmp/unlock-service-catalog.csv  and  .xlsx
 *
 *   php scripts/fetch-provider-services.php services
 *       writes services.csv and services.xlsx in the current directory
 *
 * Requires:
 *   - Outbound network access to api.unlock-service.net
 *   - .env with IMEI_API_KEY and (optionally) a USERNAME env or arg
 *
 * The script tries the DHRU Fusion standard endpoint first
 * (action=imeiservicelist) and falls back to a few common variants.
 * Whichever one returns a parseable response wins.
 */

declare(strict_types=1);

require __DIR__ . '/../includes/config.php';
$cfg = require __DIR__ . '/../includes/config.php';

$apiKey  = (string) ($cfg['api']['key'] ?? '');
$apiUrl  = rtrim((string) ($cfg['api']['url'] ?? 'https://api.unlock-service.net'), '/');
$username = (string) (getenv('UNLOCK_SERVICE_USERNAME') ?: 'rmandzor');

if ($apiKey === '' || str_starts_with($apiKey, 'replace-with')) {
    fwrite(STDERR, "IMEI_API_KEY missing in .env\n");
    exit(1);
}

$outPrefix = $argv[1] ?? '/tmp/unlock-service-catalog';

/* =========================================================================
 *  1. Try a few "list services" endpoint shapes and keep the first
 *     one whose response we can parse.
 *
 *  The PHP API endpoint documented by the provider is:
 *      {base}/?key={apikey}&accountinfo=servicelist
 *  Response shape:
 *      { "status": true, "response": "", "object": {
 *            "343": {"service":"343","name":"Apple Warranty Info",
 *                    "price":"0.02","time":"Instant","description":""},
 *            ...
 *      }}
 *
 *  The DHRU Fusion-style endpoints are kept as fallbacks in case the
 *  operator's account exposes a different shape.
 * ========================================================================= */
$candidates = [
    // Provider-documented PHP API endpoint (preferred).
    sprintf('%s/?key=%s&accountinfo=servicelist', $apiUrl, rawurlencode($apiKey)),
    // DHRU Fusion standard (fallback).
    sprintf('%s/?username=%s&apiaccesskey=%s&action=imeiservicelist',
            $apiUrl, rawurlencode($username), rawurlencode($apiKey)),
    sprintf('%s/?username=%s&apiaccesskey=%s&action=ImeiAllServices',
            $apiUrl, rawurlencode($username), rawurlencode($apiKey)),
    // Older simpler variants.
    sprintf('%s/?key=%s&action=services', $apiUrl, rawurlencode($apiKey)),
    sprintf('%s/?key=%s&action=listservices', $apiUrl, rawurlencode($apiKey)),
];

$services = [];
$successUrl = null;

foreach ($candidates as $url) {
    fwrite(STDERR, "  trying " . preg_replace('/(key|apiaccesskey)=[^&]+/', '$1=***', $url) . "\n");
    $body = http_get($url);
    if ($body === null) continue;

    $parsed = parse_services_response($body);
    if (!empty($parsed)) {
        $services = $parsed;
        $successUrl = $url;
        fwrite(STDERR, "  -> got " . count($services) . " services\n");
        break;
    }
}

if (!$services) {
    fwrite(STDERR, "Could not parse a service list from any endpoint.\n");
    fwrite(STDERR, "Login to the unlock-service dashboard and check the API docs page\n");
    fwrite(STDERR, "for the correct 'list services' endpoint shape.\n");
    exit(2);
}

/* =========================================================================
 *  2. Write CSV
 * ========================================================================= */
$csvPath = $outPrefix . '.csv';
$fp = fopen($csvPath, 'w');
fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens Thai/Unicode cleanly
fputcsv($fp, ['Service ID', 'Service Name', 'Credit (USD)', 'Delivery Time', 'API Type', 'Description']);
foreach ($services as $row) {
    fputcsv($fp, [
        $row['id'],
        $row['name'],
        $row['credit'],
        $row['delivery'] ?? '',
        $row['category'] ?? '',  // API type (PHP | DHRU)
        $row['desc']     ?? '',
    ]);
}
fclose($fp);

/* =========================================================================
 *  3. Write XLSX (minimal, no external dependencies)
 * ========================================================================= */
$xlsxPath = $outPrefix . '.xlsx';
write_minimal_xlsx($xlsxPath, $services);

fwrite(STDERR, "\nWrote:\n  $csvPath\n  $xlsxPath\n");
fwrite(STDERR, "Open either file in Excel / Sheets / LibreOffice.\n");

/* =========================================================================
 *  Helpers
 * ========================================================================= */

function http_get(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub-catalog-sync/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain, */*'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    return (string) $body;
}

/**
 * Provider response shapes we support:
 *
 *  A. Provider-documented PHP API (preferred):
 *       { "status": true, "response": "", "object": {
 *           "343": {"service":"343","name":"Apple Warranty Info",
 *                   "price":"0.02","time":"Instant","description":""},
 *           ...
 *       }}
 *
 *  B. DHRU Fusion:
 *       { "SUCCESS": [{ "ALLSERVICES": {
 *           "1": {"SERVICEID":"1","SERVICENAME":"...","CREDIT":"0.05",...},
 *           ...
 *       }}]}
 *
 *  C. Misc shapes some installs expose (data.imeiservices, services, ...).
 */
function parse_services_response(string $body): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return [];

    $allServices = null;
    foreach ([
        ['object'],                  // PHP API (preferred)
        ['SUCCESS', 0, 'ALLSERVICES'],
        ['SUCCESS', 0, 'LIST'],
        ['data', 'imeiservices'],
        ['data', 'services'],
        ['services'],
    ] as $path) {
        $node = $decoded;
        foreach ($path as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) { $node = null; break; }
            $node = $node[$seg];
        }
        if (is_array($node) && $node) { $allServices = $node; break; }
    }
    if (!$allServices) return [];

    $rows = [];
    foreach ($allServices as $key => $svc) {
        if (!is_array($svc)) continue;
        $id       = (string) ($svc['service']     ?? $svc['SERVICEID']   ?? $svc['ID']         ?? $svc['id']     ?? $key);
        $name     = (string) ($svc['name']        ?? $svc['SERVICENAME'] ?? $svc['NAME']       ?? $svc['title']  ?? '');
        $credit   = (string) ($svc['price']       ?? $svc['CREDIT']      ?? $svc['PRICE']      ?? $svc['credit'] ?? $svc['cost'] ?? '');
        $delivery = (string) ($svc['time']        ?? $svc['TIME']        ?? $svc['DELIVERYTIME']?? $svc['delivery'] ?? '');
        $desc     = (string) ($svc['description'] ?? $svc['DESCRIPTION'] ?? $svc['desc']       ?? '');
        // Derive API type from delivery time string. Anything that takes
        // more than ~60s (minutes / hours / manual) lives in the DHRU
        // queue; sub-minute jobs run via the PHP API.
        $apiType  = preg_match('/min|hour|manual|day/i', $delivery) ? 'DHRU' : 'PHP';

        if ($name === '') continue;
        $rows[] = [
            'id'       => $id,
            'name'     => $name,
            'credit'   => $credit,
            'delivery' => $delivery,
            'category' => $apiType,   // re-use the "category" column for the API type
            'desc'     => $desc,
        ];
    }
    usort($rows, fn ($a, $b) => strcmp($a['category'] . $a['name'], $b['category'] . $b['name']));
    return $rows;
}

/**
 * Minimal Open XML SpreadsheetML writer. No phpoffice dependency.
 * Builds the smallest valid .xlsx zip - one sheet with a header row + data.
 */
function write_minimal_xlsx(string $path, array $services): void
{
    $headers = ['Service ID', 'Service Name', 'Credit (USD)', 'Delivery Time', 'API Type', 'Description'];

    $rowsXml = '';
    $rowsXml .= xlsx_row(1, $headers, true);
    foreach ($services as $i => $r) {
        $rowsXml .= xlsx_row($i + 2, [
            $r['id'],
            $r['name'],
            $r['credit'],
            $r['delivery'] ?? '',
            $r['category'] ?? '',  // API type (PHP | DHRU)
            $r['desc']     ?? '',
        ], false);
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>'
        .   '<col min="1" max="1" width="11" customWidth="1"/>'
        .   '<col min="2" max="2" width="68" customWidth="1"/>'
        .   '<col min="3" max="3" width="14" customWidth="1"/>'
        .   '<col min="4" max="4" width="18" customWidth="1"/>'
        .   '<col min="5" max="5" width="11" customWidth="1"/>'
        .   '<col min="6" max="6" width="44" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        .   ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Services" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml"  ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    // Two styles: 0 = default, 1 = bold (used by header row).
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    if (file_exists($path)) unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Could not create $path");
    }
    $zip->addFromString('[Content_Types].xml',           $contentTypes);
    $zip->addFromString('_rels/.rels',                   $rootRels);
    $zip->addFromString('xl/workbook.xml',               $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels',    $workbookRels);
    $zip->addFromString('xl/styles.xml',                 $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml',      $sheet);
    $zip->close();
}

function xlsx_row(int $rowNum, array $cells, bool $header): string
{
    $xml = '<row r="' . $rowNum . '">';
    $col = 'A';
    foreach ($cells as $val) {
        $ref = $col . $rowNum;
        $style = $header ? ' s="1"' : '';
        // inlineStr keeps things readable + avoids managing a shared-strings table.
        $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
              . htmlspecialchars((string) $val, ENT_XML1, 'UTF-8')
              . '</t></is></c>';
        $col++;
    }
    $xml .= '</row>';
    return $xml;
}
