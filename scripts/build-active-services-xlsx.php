<?php
declare(strict_types=1);

/**
 * Builds active-services.xlsx - the list of IMEI Check services that
 * are currently sellable on the site.
 *
 * Data sources:
 *   sql/seed.sql                 -> code, name, description, cost, active
 *   data/service_provider_map.php -> provider service id + api type
 *   data/service_categories.php   -> the optgroup the code lives in
 *
 * Rows with active = 0 (the 17 pending services, the 14 phantom
 * codes from pre-anchor) are excluded by design - this file is meant
 * to answer "what can I sell today?".
 *
 * Usage:
 *   php scripts/build-active-services-xlsx.php [output-path]
 *   No args -> writes ./active-services.xlsx
 */

$outPath = $argv[1] ?? __DIR__ . '/../active-services.xlsx';

// ---- 1. Parse seed.sql for active rows (code, name, description, cost) ----

$seed     = (string) file_get_contents(__DIR__ . '/../sql/seed.sql');
$rows     = [];
// Match  ('CODE', 'Name', 'Description', cost, active),
// (multiline-safe, comment-tolerant since seed.sql trailing comments
// live AFTER the close paren).
if (preg_match_all(
    "/\\(\\s*'([A-Z0-9_]+)'\\s*,\\s*'((?:''|[^'])*)'\\s*,\\s*'((?:''|[^'])*)'\\s*,\\s*([0-9.]+)\\s*,\\s*([01])\\s*\\)/",
    $seed,
    $m,
    PREG_SET_ORDER
) === false) {
    fwrite(STDERR, "Could not parse seed.sql\n");
    exit(1);
}

foreach ($m as $row) {
    $code   = $row[1];
    $name   = str_replace("''", "'", $row[2]);
    $desc   = str_replace("''", "'", $row[3]);
    $cost   = $row[4];
    $active = (int) $row[5];
    if ($active !== 1) continue;
    $rows[$code] = [
        'code' => $code,
        'name' => $name,
        'desc' => $desc,
        'cost' => $cost,
    ];
}

// ---- 2. Provider IDs + api type ----

$map = require __DIR__ . '/../data/service_provider_map.php';
foreach ($rows as $code => &$row) {
    $entry = $map[$code] ?? '__MISSING__';
    if ($entry === '__MISSING__') {
        $row['provider_id'] = '';
        $row['api_type']    = '—';
    } elseif ($entry === null) {
        $row['provider_id'] = '—';
        $row['api_type']    = 'Free (local)';
    } elseif (is_array($entry)) {
        $row['provider_id'] = (string) ($entry['id'] ?? '');
        $row['api_type']    = strtoupper((string) ($entry['type'] ?? 'php'));
    } else {
        $row['provider_id'] = (string) $entry;
        $row['api_type']    = 'PHP';
    }
}
unset($row);

// ---- 3. Category for each code ----

$categories = require __DIR__ . '/../data/service_categories.php';
$catOf = [];
$catOrder = [];
foreach ($categories as $i => $grp) {
    $catOrder[$grp['name']] = $i;
    foreach ($grp['codes'] as $c) {
        $catOf[$c] = $grp['name'];
    }
}

foreach ($rows as $code => &$row) {
    $row['category'] = $catOf[$code] ?? 'Uncategorized';
}
unset($row);

// ---- 4. Order: by category position (from service_categories.php) then by name ----

uasort($rows, function ($a, $b) use ($catOrder) {
    $ai = $catOrder[$a['category']] ?? 999;
    $bi = $catOrder[$b['category']] ?? 999;
    if ($ai !== $bi) return $ai <=> $bi;
    return strcmp($a['name'], $b['name']);
});

// ---- 5. Build xlsx ----

$headers = ['#', 'Category', 'Internal Code', 'Service Name', 'Description', 'Retail (USD)', 'Provider Service ID', 'API Type'];

$rowsXml  = xlsx_row(1, $headers, true);
$i = 0;
foreach ($rows as $row) {
    $i++;
    $rowsXml .= xlsx_row($i + 1, [
        (string) $i,
        $row['category'],
        $row['code'],
        $row['name'],
        $row['desc'],
        '$' . number_format((float) $row['cost'], 2),
        $row['provider_id'],
        $row['api_type'],
    ], false);
}

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<cols>'
    .   '<col min="1" max="1" width="4"  customWidth="1"/>'
    .   '<col min="2" max="2" width="24" customWidth="1"/>'
    .   '<col min="3" max="3" width="26" customWidth="1"/>'
    .   '<col min="4" max="4" width="64" customWidth="1"/>'
    .   '<col min="5" max="5" width="54" customWidth="1"/>'
    .   '<col min="6" max="6" width="14" customWidth="1"/>'
    .   '<col min="7" max="7" width="18" customWidth="1"/>'
    .   '<col min="8" max="8" width="14" customWidth="1"/>'
    . '</cols>'
    . '<sheetData>' . $rowsXml . '</sheetData>'
    . '</worksheet>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    .   ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Active Services" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"    Target="styles.xml"/>'
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

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="2">'
    .   '<font><sz val="11"/><name val="Calibri"/></font>'
    .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
    . '<borders count="1"><border/></borders>'
    . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
    . '<cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs>'
    . '</styleSheet>';

if (file_exists($outPath)) unlink($outPath);
$zip = new ZipArchive();
if ($zip->open($outPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $outPath\n");
    exit(1);
}
$zip->addFromString('[Content_Types].xml',        $contentTypes);
$zip->addFromString('_rels/.rels',                $rootRels);
$zip->addFromString('xl/workbook.xml',            $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml',              $styles);
$zip->addFromString('xl/worksheets/sheet1.xml',   $sheet);
$zip->close();

fwrite(STDERR, sprintf("Wrote %s (%d active services across %d categories)\n",
    $outPath,
    count($rows),
    count(array_unique(array_column($rows, 'category')))
));

function xlsx_row(int $rowNum, array $cells, bool $header): string
{
    $xml = '<row r="' . $rowNum . '">';
    $col = 'A';
    foreach ($cells as $val) {
        $ref   = $col . $rowNum;
        $style = $header ? ' s="1"' : '';
        $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">'
              . htmlspecialchars((string) $val, ENT_XML1, 'UTF-8')
              . '</t></is></c>';
        $col++;
    }
    $xml .= '</row>';
    return $xml;
}
