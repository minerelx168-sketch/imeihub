<?php
declare(strict_types=1);

/**
 * Ban / unban a user. POST, admin-only, CSRF-guarded.
 * Ban sets users.banned_at and revokes all their sessions (immediate
 * logout); auth.php then refuses any session for a banned user, so they
 * cannot use the site even if they log in again. Unban clears the flag.
 * Admins and your own account cannot be banned.
 */

require __DIR__ . '/../includes/admin.php';
$admin = admin_require();
$pdo = db();

function back(int $userId, string $key, string $val): never
{
    header('Location: /admin/user.php?id=' . $userId . '&' . $key . '=' . rawurlencode($val));
    exit;
}

$userId = (int) ($_POST['user_id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') back($userId, 'err', 'POST only.');
if (!admin_csrf_check($_POST['csrf'] ?? null)) back($userId, 'err', 'Security check failed. Reload and try again.');

$action = (string) ($_POST['action'] ?? '');
if ($userId <= 0) back($userId, 'err', 'Missing user.');
if ($userId === (int) $admin['id']) back($userId, 'err', 'You cannot ban your own account.');

$stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
$stmt->execute([$userId]);
$isAdmin = $stmt->fetchColumn();
if ($isAdmin === false) back($userId, 'err', 'User not found.');
if ((int) $isAdmin === 1) back($userId, 'err', 'Cannot ban an admin account.');

try {
    if ($action === 'ban') {
        $pdo->prepare('UPDATE users SET banned_at = NOW() WHERE id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
        back($userId, 'msg', 'User banned and logged out.');
    } elseif ($action === 'unban') {
        $pdo->prepare('UPDATE users SET banned_at = NULL WHERE id = ?')->execute([$userId]);
        back($userId, 'msg', 'User unbanned.');
    } else {
        back($userId, 'err', 'Unknown action.');
    }
} catch (Throwable $e) {
    back($userId, 'err', $e->getMessage());
}
