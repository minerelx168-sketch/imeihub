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

// Compute strict balance from the ledger; surface drift as a debug hint.
$cached = credits_get_balance((int) $user['id'], false);
$strict = credits_get_balance((int) $user['id'], true);

echo json_encode([
    'ok'        => true,
    'balance'   => $strict,
    'currency'  => 'USD',
    'cached'    => $cached,
    'drift'     => $cached !== $strict,
]);
