<?php
declare(strict_types=1);

/**
 * Pure parsers for the blacklist bulk-import (no side effects, so they can be
 * unit-tested). Each returns a list of rows, each row a list of cell strings.
 * Callers pick column 0 = IMEI, column 1 = reason.
 */

if (!function_exists('bl_parse_csv')) {
function bl_parse_csv(string $path): array
{
    $raw = (string) file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3); // strip UTF-8 BOM
    }
    // Pick the delimiter that splits the first line into the most cells.
    $firstLine = strtok($raw, "\r\n") ?: '';
    $delims = [
        ','  => substr_count($firstLine, ','),
        ';'  => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];
    arsort($delims);
    $delim = (string) (array_key_first($delims) ?: ',');

    $rows = [];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
        if ($cells === [null] || ($cells[0] === null && count($cells) === 1)) {
            continue; // blank line
        }
        $rows[] = $cells;
    }
    fclose($fh);
    return $rows;
}
}

if (!function_exists('bl_parse_xlsx')) {
function bl_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX is not supported here; please upload a CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('not a valid xlsx archive');
    }

    // Shared strings: cells of type "s" reference this table by index.
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $sx = @simplexml_load_string($ssXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;            // plain string
                } else {
                    $buf = '';                               // rich-text runs
                    foreach ($si->r as $r) { $buf .= (string) $r->t; }
                    $shared[] = $buf;
                }
            }
        }
    }

    // First worksheet (sheet1.xml is the Excel / Sheets default; else scan).
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n !== false && preg_match('#^xl/worksheets/.+\.xml$#', $n)) {
                $sheetXml = $zip->getFromName($n);
                break;
            }
        }
    }
    $zip->close();
    if (!is_string($sheetXml)) {
        throw new RuntimeException('no worksheet found');
    }

    $sx = @simplexml_load_string($sheetXml);
    if ($sx === false) {
        throw new RuntimeException('worksheet is not valid xml');
    }

    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $t = (string) $c['t'];
            if ($t === 's') {
                $cells[] = $shared[(int) $c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $cells[] = (string) ($c->is->t ?? '');
            } else {
                $cells[] = (string) ($c->v ?? '');
            }
        }
        if ($cells !== []) {
            $rows[] = $cells;
        }
    }
    return $rows;
}
}
