<?php
declare(strict_types=1);

/**
 * Builds a .xlsx with the catalog services that are still missing a
 * provider Service ID, formatted so the upstream support team can
 * scan and fill in IDs + wholesale credits in two empty columns.
 *
 * Service names are copied VERBATIM from the provider's catalog dump
 * so they search 1:1 in their system. Internal codes are included as
 * a reference column - the operator will use them to wire each ID
 * back into data/service_provider_map.php.
 *
 * Usage:
 *   php scripts/build-pending-services-xlsx.php [output-path]
 *
 *   No args -> writes ./pending-services-for-provider.xlsx
 */

$outPath = $argv[1] ?? __DIR__ . '/../pending-services-for-provider.xlsx';

$rows = [
    // [Category, Service Name (verbatim), Internal Code]
    ['Apple IMEI Check',        'Apple Owner ID Info - (Read Description)',                                                        'APPLE_OWNER_ID_INFO'],
    ['Apple IMEI Check',        'Apple IMEI <-> SN <-> IMEI2 CONVERT',                                                             'APPLE_IMEI_SN_CONVERT'],

    ['GSX Services (Instant)',  'Apple Case History, Replacement',                                                                 'APPLE_GSX_CASE_REPLACE'],
    ['GSX Services (Instant)',  'Apple Sold By Info',                                                                              'APPLE_GSX_SOLD_BY'],

    ['GSX Services',            'Apple Case History',                                                                              'APPLE_GSX_CASE_S2'],
    ['GSX Services',            'Apple Repairs, Replacement Details',                                                              'APPLE_GSX_REPAIRS_S2'],
    ['GSX Services',            'Apple Case History, Repairs, Replacements, Diagnostics',                                          'APPLE_GSX_CASE_DIAG_S2'],
    ['GSX Services',            'Apple Sold By, Activation Policy',                                                                'APPLE_GSX_SOLD_POLICY_S2'],
    ['GSX Services',            'Apple Sold By, Case History, Replacement, Activation Policy',                                     'APPLE_GSX_FULL_LITE_S2'],
    ['GSX Services',            'Apple Sold By, Case History, Replacement, Activation Policy [ICCID & MAC] (FULL GSX) S2',         'APPLE_FULL_GSX_S2'],

    ['GSX Services (Picture)',  'Apple Case History (Picture)',                                                                    'APPLE_GSX_CASE_PIC'],
    ['GSX Services (Picture)',  'Apple Case History, Repairs, Replacements (Picture)',                                             'APPLE_GSX_CASE_REPAIR_PIC'],
    ['GSX Services (Picture)',  'Apple Sold By, Case History, Replacement (Picture)',                                              'APPLE_GSX_SOLD_REPLACE_PIC'],
    ['GSX Services (Picture)',  'Apple Sold By, Case History, Repairs, Replacement, Diagnostics (Picture)',                        'APPLE_GSX_SOLD_DIAG_PIC'],
    ['GSX Services (Picture)',  'Apple Full GSX (Picture)',                                                                        'APPLE_FULL_GSX_PIC'],

    ['Other IMEI Check',        'ZTE INFO',                                                                                        'ZTE_INFO'],
    ['Other IMEI Check',        'SAMSUNG INFO (Server 2)',                                                                         'SAMSUNG_INFO_S2'],
];

$headers = ['#', 'Category', 'Service Name', 'Internal Code', 'Service ID (please fill)', 'Wholesale USD (please fill)', 'Notes'];

$rowsXml  = xlsx_row(1, $headers, true);
foreach ($rows as $i => $r) {
    $rowsXml .= xlsx_row($i + 2, [
        (string) ($i + 1),
        $r[0],
        $r[1],
        $r[2],
        '',
        '',
        '',
    ], false);
}

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<cols>'
    .   '<col min="1" max="1" width="5"  customWidth="1"/>'
    .   '<col min="2" max="2" width="26" customWidth="1"/>'
    .   '<col min="3" max="3" width="78" customWidth="1"/>'
    .   '<col min="4" max="4" width="30" customWidth="1"/>'
    .   '<col min="5" max="5" width="22" customWidth="1"/>'
    .   '<col min="6" max="6" width="22" customWidth="1"/>'
    .   '<col min="7" max="7" width="30" customWidth="1"/>'
    . '</cols>'
    . '<sheetData>' . $rowsXml . '</sheetData>'
    . '</worksheet>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    .   ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Pending Services" sheetId="1" r:id="rId1"/></sheets>'
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

// 3 styles: 0=default, 1=header (bold), 2=highlight (italic, used for empty columns).
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

fwrite(STDERR, "Wrote $outPath (" . count($rows) . " pending services)\n");

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
