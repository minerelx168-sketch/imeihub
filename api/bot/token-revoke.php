<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/bot.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$user = auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;
$tokenId = (int) ($body['id'] ?? 0);
if ($tokenId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing token id.']);
    exit;
}

$ok = bot_revoke_token((int) $user['id'], $tokenId);
echo json_encode(['ok' => $ok]);
