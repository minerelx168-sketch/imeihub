<?php
declare(strict_types=1);

/**
 * Hard-delete a user. POST, admin-only, CSRF-guarded.
 *
 * Refuses if the account has any financial history (ledger / top-ups /
 * usage) — that data is append-only and must stay auditable, so a user
 * with history should be banned, not deleted. For a clean account the
 * row is removed; sessions / oauth / verifications / bot links cascade.
 * Admins and your own account cannot be deleted.
 */

require __DIR__ . '/../includes/admin.php';
$admin = admin_require();
$pdo = db();

function go(string $loc): never { header('Location: ' . $loc); exit; }
function back_user(int $id, string $k, string $v): never { go('/admin/user.php?id=' . $id . '&' . $k . '=' . rawurlencode($v)); }

$userId = (int) ($_POST['user_id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') back_user($userId, 'err', 'POST only.');
if (!admin_csrf_check($_POST['csrf'] ?? null)) back_user($userId, 'err', 'Security check failed. Reload and try again.');
if ($userId <= 0) back_user($userId, 'err', 'Missing user.');
if ($userId === (int) $admin['id']) back_user($userId, 'err', 'You cannot delete your own account.');

$stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
$stmt->execute([$userId]);
$isAdmin = $stmt->fetchColumn();
if ($isAdmin === false) back_user($userId, 'err', 'User not found.');
if ((int) $isAdmin === 1) back_user($userId, 'err', 'Cannot delete an admin account.');

$count = function (string $table) use ($pdo, $userId): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE user_id = ?"); // $table is a fixed literal below
    $s->execute([$userId]);
    return (int) $s->fetchColumn();
};
$tx = $count('credit_transactions');
$tp = $count('topup_orders');
$su = $count('service_usages');
if ($tx + $tp + $su > 0) {
    back_user($userId, 'err', "Cannot delete: account has financial history ($tx ledger, $tp top-ups, $su lookups). Ban it instead.");
}

try {
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    go('/admin/users.php?deleted=1');
} catch (Throwable $e) {
    back_user($userId, 'err', 'Delete failed: ' . $e->getMessage());
}
