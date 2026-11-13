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

try {
    $row = bot_create_token((int) $user['id']);
    echo json_encode([
        'ok'         => true,
        'token'      => $row['token'],
        'expires_at' => $row['expires_at'],
        'ttl_min'    => BOT_TOKEN_LIFETIME_MIN,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
