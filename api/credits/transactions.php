<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/credits.php';

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

$limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 20;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$type   = isset($_GET['type'])   ? strtoupper((string) $_GET['type']) : null;

$result = credits_get_transactions((int) $user['id'], $limit, $offset, $type);

echo json_encode([
    'ok'      => true,
    'total'   => $result['total'],
    'limit'   => $limit,
    'offset'  => $offset,
    'rows'    => array_map(static function (array $r): array {
        return [
            'id'             => (int) $r['id'],
            'amount'         => number_format((float) $r['amount'], 2, '.', ''),
            'type'           => $r['type'],
            'reference_type' => $r['reference_type'],
            'reference_id'   => $r['reference_id'],
            'balance_after'  => number_format((float) $r['balance_after'], 2, '.', ''),
            'description'    => $r['description'],
            'created_at'     => $r['created_at'],
        ];
    }, $result['rows']),
]);
