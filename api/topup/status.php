<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/db.php';

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

$publicId = isset($_GET['id']) ? (string) $_GET['id'] : '';
if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid order id.']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT public_id, amount, currency, status, paid_at, credited_at, created_at
     FROM topup_orders
     WHERE public_id = ? AND user_id = ?
     LIMIT 1'
);
$stmt->execute([$publicId, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found.']);
    exit;
}

echo json_encode([
    'ok'          => true,
    'public_id'   => $order['public_id'],
    'amount'      => number_format((float) $order['amount'], 2, '.', ''),
    'currency'    => $order['currency'],
    'status'      => $order['status'],
    'paid_at'     => $order['paid_at'],
    'credited_at' => $order['credited_at'],
    'created_at'  => $order['created_at'],
]);
