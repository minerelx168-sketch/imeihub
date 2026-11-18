<?php
declare(strict_types=1);

/**
 * Apply an admin credit adjustment, then redirect back to the user page.
 * POST only, admin-only, CSRF-guarded. The mutation itself is atomic and
 * ledger-backed (credits_admin_adjust).
 */

require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/credits_write.php';

$admin = admin_require();

$uid = isset($_GET['id']) ? (int) $_GET['id'] : 0; // for the redirect target fallback

function back(int $userId, string $key, string $val): never
{
    $userId = $userId ?: (int) ($_POST['user_id'] ?? 0);
    header('Location: /admin/user.php?id=' . $userId . '&' . $key . '=' . rawurlencode($val));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    back($uid, 'err', 'POST only.');
}
if (!admin_csrf_check($_POST['csrf'] ?? null)) {
    back((int) ($_POST['user_id'] ?? 0), 'err', 'Security check failed. Reload and try again.');
}

$userId = (int) ($_POST['user_id'] ?? 0);
$amount = (float) ($_POST['amount'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($userId <= 0) {
    back($userId, 'err', 'Missing user.');
}
if (abs($amount) < 0.01) {
    back($userId, 'err', 'Enter a non-zero amount.');
}

try {
    $res = credits_admin_adjust($userId, $amount, $reason, (int) $admin['id']);
    back($userId, 'msg', sprintf('Adjusted by %+.2f. New balance $%s.', $amount, $res['balance']));
} catch (Throwable $e) {
    back($userId, 'err', $e->getMessage());
}
