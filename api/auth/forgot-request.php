<?php
declare(strict_types=1);

/**
 * Step 1 of password reset: email a 6-digit reset code (via Resend).
 *   POST /api/auth/forgot-request.php  { "email": "..." }
 * Always returns ok (does not reveal whether the email is registered).
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

$body  = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
$email = (string) ($body['email'] ?? '');

if (!rate_limit_allow('reset:' . client_ip(), 5)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a minute and try again.']);
    exit;
}

try {
    signup_request_reset($email);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
