<?php
declare(strict_types=1);

/**
 * Stream every credit_transaction for the signed-in user as a UTF-8 CSV.
 * Optional ?type=TOPUP|USAGE|REFUND|ADJUSTMENT|BONUS filter.
 *
 * Streams row-by-row via php://output so a multi-thousand-row export
 * never buffers in memory.
 */

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/db.php';

$user = auth_user();
if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

$validTypes = ['TOPUP', 'USAGE', 'REFUND', 'ADJUSTMENT', 'BONUS'];
$type = isset($_GET['type']) ? strtoupper((string) $_GET['type']) : '';
if ($type !== '' && !in_array($type, $validTypes, true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unknown type.']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$filename = 'imeihub-credits-' . date('Y-m-d') . ($type !== '' ? '-' . strtolower($type) : '') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens Thai / emoji content correctly.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'id', 'created_at', 'type', 'amount', 'balance_after',
    'reference_type', 'reference_id', 'description',
]);

$sql = 'SELECT id, created_at, type, amount, balance_after, reference_type, reference_id, description
        FROM credit_transactions
        WHERE user_id = ?';
$params = [$user['id']];
if ($type !== '') {
    $sql .= ' AND type = ?';
    $params[] = $type;
}
$sql .= ' ORDER BY id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['id'],
        $row['created_at'],
        $row['type'],
        number_format((float) $row['amount'], 2, '.', ''),
        number_format((float) $row['balance_after'], 2, '.', ''),
        $row['reference_type'],
        $row['reference_id'],
        $row['description'],
    ]);
}

fclose($out);
