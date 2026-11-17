<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../../includes/signup.php';
require __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only.']);
    exit;
}

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true) ?: $_POST;

$email    = (string) ($body['email']    ?? '');
$password = (string) ($body['password'] ?? '');
$next     = auth_safe_next((string) ($body['next'] ?? '/dashboard.php'));

// Per-IP brute-force throttle (re-using the IMEI rate_limit table since
// it already does per-key window counting).
require __DIR__ . '/../../includes/functions.php';
if (!rate_limit_allow('login:' . client_ip(), 8)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many login attempts. Please wait a minute and try again.']);
    exit;
}

try {
    $user = signup_login_with_password($email, $password);
    auth_start_session((int) $user['id']);
    echo json_encode(['ok' => true, 'next' => $next]);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
