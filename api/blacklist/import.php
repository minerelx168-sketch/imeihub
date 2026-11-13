<?php
declare(strict_types=1);

/**
 * Bulk-import a customer's IMEI blacklist from an uploaded .csv or .xlsx.
 *   POST /api/blacklist/import.php   (multipart/form-data, field "file")
 *
 * Only two columns are read: IMEI and Reason. A header row (first cell not a
 * valid IMEI) is skipped automatically. Invalid / duplicate IMEIs are
 * counted and skipped. There is deliberately NO export counterpart - the
 * platform never hands back its blacklist.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/blacklist.php';
require __DIR__ . '/../../includes/blacklist_import_parse.php';

function out(int $c, array $b): never { http_response_code($c); echo json_encode($b); exit; }

const BL_IMPORT_MAX_ROWS  = 5000;
const BL_IMPORT_MAX_BYTES = 2097152; // 2 MB

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(405, ['ok' => false, 'error' => 'POST only.']);

$user = auth_user();
if (!$user) out(401, ['ok' => false, 'error' => 'Not signed in.']);
if (!rate_limit_allow('blimport:' . $user['id'], 5)) {
    out(429, ['ok' => false, 'error' => 'Too many imports. Please wait a minute and try again.']);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $msg = (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE)
        ? 'File is too large.' : 'No file uploaded.';
    out(400, ['ok' => false, 'error' => $msg]);
}
if (($file['size'] ?? 0) > BL_IMPORT_MAX_BYTES) {
    out(413, ['ok' => false, 'error' => 'File too large (max 2 MB).']);
}
$tmp = (string) $file['tmp_name'];
if (!is_uploaded_file($tmp)) {
    out(400, ['ok' => false, 'error' => 'Upload failed.']);
}

$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
try {
    if ($ext === 'csv' || $ext === 'txt') {
        $rows = bl_parse_csv($tmp);
    } elseif ($ext === 'xlsx') {
        $rows = bl_parse_xlsx($tmp);
    } else {
        out(415, ['ok' => false, 'error' => 'Only .csv or .xlsx files are supported.']);
    }
} catch (Throwable $e) {
    out(422, ['ok' => false, 'error' => 'Could not read the file: ' . $e->getMessage()]);
}

// Build the validated list: column 0 = IMEI, column 1 = reason.
$items   = [];
$seen    = [];
$skipped = 0;
$first   = true;
foreach ($rows as $cells) {
    if (count($items) >= BL_IMPORT_MAX_ROWS) break;
    $imei = imei_normalize((string) ($cells[0] ?? ''));
    if ($first) {
        $first = false;
        if (!imei_is_valid($imei)) continue; // header row -> skip
    }
    if (!imei_is_valid($imei)) { $skipped++; continue; }
    if (isset($seen[$imei])) continue;        // de-dupe within the file
    $seen[$imei] = true;
    $items[] = ['imei' => $imei, 'reason' => trim((string) ($cells[1] ?? ''))];
}

if ($items === []) {
    out(422, ['ok' => false, 'error' => 'No valid 15-digit IMEIs found. Use two columns: IMEI, Reason.']);
}

try {
    $imported = blacklist_import($items, (int) $user['id']);
} catch (Throwable $e) {
    out(500, ['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()]);
}

out(200, ['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);
