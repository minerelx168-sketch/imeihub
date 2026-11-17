<?php
declare(strict_types=1);

/**
 * Send a one-off test email to confirm Resend is configured.
 *   php scripts/test-resend.php <to-email>
 * Reads RESEND_API_KEY / RESEND_FROM from .env. The key is masked in
 * output so it's safe to run/screenshot.
 */

require __DIR__ . '/../includes/resend.php';
$cfg = require __DIR__ . '/../includes/config.php';

$to = $argv[1] ?? '';
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/test-resend.php <to-email>\n");
    exit(1);
}

$key  = (string) ($cfg['resend']['api_key'] ?? '');
$from = (string) ($cfg['resend']['from'] ?? '');

echo "from:    " . ($from !== '' ? $from : '(NOT SET)') . "\n";
echo "api_key: " . ($key === '' ? '(NOT SET)' : substr($key, 0, 6) . '…' . substr($key, -4) . ' [' . strlen($key) . ' chars]') . "\n";

if ($key === '') {
    fwrite(STDERR, "RESEND_API_KEY is not set in .env\n");
    exit(2);
}

echo "sending test email to {$to} ...\n";
$ok = resend_send_email(
    $to,
    'imeihub · Resend test',
    '<p>If you can read this, Resend is wired up correctly for imeihub.</p>',
    'If you can read this, Resend is wired up correctly for imeihub.'
);

echo $ok
    ? "RESULT: SENT ok  — check the inbox (and spam) of {$to}\n"
    : "RESULT: FAILED — see the [resend] line in your server log (docker compose logs app)\n";
exit($ok ? 0 : 1);
