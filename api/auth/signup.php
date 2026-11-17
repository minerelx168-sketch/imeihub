<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../../includes/signup.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

$email    = (string) ($body['email']    ?? '');
$password = (string) ($body['password'] ?? '');

try {
    $result = signup_create_pending_user($email, $password);
    echo json_encode([
        'ok'      => true,
        'message' => 'A 6-digit code has been emailed to you. Enter it to finish creating your account.',
        'email'   => $result['email'],
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
