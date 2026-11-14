<?php
declare(strict_types=1);

require __DIR__ . '/../../../includes/google_oauth.php';
require __DIR__ . '/../../../includes/auth.php';

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><meta charset=utf-8><title>Sign-in failed</title>"
        . "<body style='font-family:system-ui;max-width:560px;margin:80px auto;padding:0 24px;'>"
        . "<h1>Sign-in failed</h1>"
        . "<p>{$safe}</p>"
        . "<p><a href='/login.php'>Try again</a></p>"
        . "</body>";
    exit;
}

// Google sometimes returns ?error=access_denied if the user cancels.
if (isset($_GET['error'])) {
    fail('Google returned an error: ' . (string) $_GET['error']);
}

$code  = isset($_GET['code'])  ? (string) $_GET['code']  : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';
if ($code === '' || $state === '') {
    fail('Missing code or state. Please start sign-in again.');
}

try {
    $stateRow = google_oauth_consume_state($state);
    $tokens   = google_oauth_exchange_code($code);
    $info     = google_oauth_fetch_userinfo((string) ($tokens['access_token'] ?? ''));
    $userId   = google_oauth_login_or_signup($info, $tokens);
    auth_start_session($userId);
} catch (Throwable $e) {
    fail($e->getMessage(), 500);
}

$next = auth_safe_next($stateRow['redirect_to'] ?? '/dashboard.php');
header('Location: ' . $next);
exit;
