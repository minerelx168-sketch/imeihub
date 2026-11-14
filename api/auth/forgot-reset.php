<?php
declare(strict_types=1);

/**
 * Step 2 of password reset: verify the code + set a new password.
 *   POST /api/auth/forgot-reset.php  { "email": "...", "otp": "123456", "password": "..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../../includes/signup.php';
require __DIR__ . '/../../includes/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$body     = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$email    = (string) ($body['email'] ?? '');
$otp      = (string) ($body['otp'] ?? '');
$password = (string) ($body['password'] ?? '');

if (!rate_limit_allow('resetv:' . client_ip(), 10)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many attempts. Please wait a minute and try again.']);
    exit;
}

try {
    signup_reset_password($email, $otp, $password);
    echo json_encode(['ok' => true, 'next' => '/login.php']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
