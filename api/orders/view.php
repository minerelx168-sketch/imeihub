<?php
declare(strict_types=1);

/**
 * Return a past order's stored result so the customer can re-view it.
 *   GET /api/orders/view.php?id=<usage public_id>   (own orders only)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/service_fields.php';

function out(int $c, array $b): never { http_response_code($c); echo json_encode($b); exit; }

$user = auth_user();
if (!$user) out(401, ['ok' => false, 'error' => 'Not signed in.']);

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id)) out(400, ['ok' => false, 'error' => 'Invalid order id.']);

$stmt = db()->prepare(
    'SELECT u.public_id, u.service_code, u.cost, u.status, u.input, u.output, u.created_at,
            sp.name AS service_name
     FROM service_usages u
     LEFT JOIN service_prices sp ON sp.code = u.service_code
     WHERE u.public_id = ? AND u.user_id = ? LIMIT 1'
);
$stmt->execute([$id, (int) $user['id']]);
$row = $stmt->fetch();
if (!$row) out(404, ['ok' => false, 'error' => 'Order not found.']);

$input  = json_decode((string) $row['input'], true)  ?: [];
$output = json_decode((string) $row['output'], true) ?: [];
$imei   = (string) ($input['imei'] ?? '');
$code   = (string) $row['service_code'];
$details = (array) ($output['details'] ?? []);
$curated = service_result_has_template($code);

out(200, [
    'ok'              => true,
    'public_id'       => $row['public_id'],
    'service'         => $row['service_name'] ?: $row['service_code'],
    'status'          => $row['status'],
    'cost'            => $row['cost'],
    'created_at'      => $row['created_at'],
    'imei'            => $imei,
    'tac'             => $imei !== '' ? imei_tac($imei) : '',
    'brand'           => $output['brand'] ?? null,
    'model'           => $output['model'] ?? null,
    'details'         => $curated ? service_filter_details($code, $details) : $details,
    'details_curated' => $curated,
]);
