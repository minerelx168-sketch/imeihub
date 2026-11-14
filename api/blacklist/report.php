<?php
declare(strict_types=1);

/**
 * Report the IMEI of one of the caller's past orders as blacklisted.
 *   POST /api/blacklist/report.php  { "public_id": "...", "reason": "..." }
 * Stores imei + tac + serial; warns anyone who later checks the same IMEI.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/blacklist.php';

function out(int $c, array $b): never { http_response_code($c); echo json_encode($b); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(405, ['ok' => false, 'error' => 'POST only.']);

$user = auth_user();
if (!$user) out(401, ['ok' => false, 'error' => 'Not signed in.']);

$body   = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$id     = (string) ($body['public_id'] ?? '');
$reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
$action = strtolower((string) ($body['action'] ?? 'report')) === 'unreport' ? 'unreport' : 'report';

if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id)) out(400, ['ok' => false, 'error' => 'Invalid order id.']);
if (!rate_limit_allow('report:' . $user['id'], 20)) out(429, ['ok' => false, 'error' => 'Too many reports. Please try again later.']);

$stmt = db()->prepare('SELECT input, output FROM service_usages WHERE public_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$id, (int) $user['id']]);
$row = $stmt->fetch();
if (!$row) out(404, ['ok' => false, 'error' => 'Order not found.']);

$input  = json_decode((string) $row['input'], true)  ?: [];
$output = json_decode((string) $row['output'], true) ?: [];
$imei   = imei_normalize((string) ($input['imei'] ?? ''));
if (!imei_is_valid($imei)) out(422, ['ok' => false, 'error' => 'This order has no valid IMEI to report.']);

$serial = null;
$details = is_array($output['details'] ?? null) ? $output['details'] : [];
foreach (['Serial Number', 'Serial', 'SN'] as $k) {
    if (!empty($details[$k])) { $serial = (string) $details[$k]; break; }
}

try {
    if ($action === 'unreport') {
        $count = blacklist_unreport($imei, (int) $user['id']);
        out(200, ['ok' => true, 'reports' => $count, 'reported' => false]);
    }
    $count = blacklist_report($imei, $serial, (int) $user['id'], $reason);
    out(200, ['ok' => true, 'reports' => $count, 'reported' => true]);
} catch (Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage()]);
}
