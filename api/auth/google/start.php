<?php
declare(strict_types=1);

require __DIR__ . '/../../../includes/google_oauth.php';
require __DIR__ . '/../../../includes/auth.php';

$next = auth_safe_next($_GET['next'] ?? null);

try {
    $url = google_oauth_authorize_url($next);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
}

header('Location: ' . $url);
exit;
