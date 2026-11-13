<?php
declare(strict_types=1);

/**
 * CSV export for admins.
 *   /admin/export.php?type=users[&q=...]   all (or matching) users
 *   /admin/export.php?type=topups          all top-up orders
 *   /admin/export.php?type=ledger&id=N      one user's credit ledger
 */

require __DIR__ . '/../includes/admin.php';
admin_require();
$pdo = db();

/** Neutralize CSV/formula injection (=, +, @, or - that isn't a number). */
function csv_safe(mixed $v): string
{
    $s = (string) $v;
    if ($s !== '' && (in_array($s[0], ['=', '+', '@'], true) || ($s[0] === '-' && !is_numeric($s)))) {
        return "'" . $s;
    }
    return $s;
}

function csv_out(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_safe', $r));
    }
    fclose($out);
    exit;
}

$type = (string) ($_GET['type'] ?? 'users');
$today = date('Ymd');

if ($type === 'users') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $stmt = $pdo->prepare('SELECT id,email,name,is_admin,cached_balance,created_at FROM users WHERE email LIKE ? OR name LIKE ? ORDER BY id DESC');
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->query('SELECT id,email,name,is_admin,cached_balance,created_at FROM users ORDER BY id DESC');
    }
    $rows = [];
    foreach ($stmt as $u) {
        $rows[] = [$u['id'], $u['email'], $u['name'], ((int) $u['is_admin'] === 1 ? 'admin' : 'user'), $u['cached_balance'], $u['created_at']];
    }
    csv_out("imeihub-users-$today.csv", ['id', 'email', 'name', 'role', 'balance_usd', 'created_at'], $rows);
}

if ($type === 'topups') {
    $stmt = $pdo->query('SELECT public_id,user_id,amount,currency,status,provider,provider_charge_id,created_at,credited_at FROM topup_orders ORDER BY id DESC');
    $rows = [];
    foreach ($stmt as $t) {
        $rows[] = [$t['public_id'], $t['user_id'], $t['amount'], $t['currency'], $t['status'], $t['provider'], $t['provider_charge_id'], $t['created_at'], $t['credited_at']];
    }
    csv_out("imeihub-topups-$today.csv", ['public_id', 'user_id', 'amount', 'currency', 'status', 'provider', 'charge_id', 'created_at', 'credited_at'], $rows);
}

if ($type === 'ledger') {
    $uid = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT created_at,type,amount,balance_after,reference_type,reference_id,description FROM credit_transactions WHERE user_id = ? ORDER BY id DESC');
    $stmt->execute([$uid]);
    $rows = [];
    foreach ($stmt as $r) {
        $rows[] = [$r['created_at'], $r['type'], $r['amount'], $r['balance_after'], $r['reference_type'], $r['reference_id'], $r['description']];
    }
    csv_out("imeihub-ledger-user{$uid}-$today.csv", ['created_at', 'type', 'amount', 'balance_after', 'ref_type', 'ref_id', 'description'], $rows);
}

http_response_code(400);
echo 'Unknown export type.';
