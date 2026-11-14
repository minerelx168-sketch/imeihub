<?php
/**
 * Reconcile users.cached_balance against the credit_transactions ledger.
 *
 * Usage:
 *   php scripts/reconcile-balance.php             # report drift (read-only)
 *   php scripts/reconcile-balance.php --fix       # update cached_balance from ledger
 *
 * The ledger is the source of truth. cached_balance is a denormalized cache for
 * fast display; if any drift is detected it indicates a code bug, not a finance
 * problem - the user's real balance is always SUM(amount).
 */

declare(strict_types=1);

require __DIR__ . '/../includes/db.php';

$fix = in_array('--fix', $argv, true);

$pdo = db();

$rows = $pdo->query(
    'SELECT u.id, u.email, u.cached_balance,
            COALESCE(SUM(ct.amount), 0) AS ledger_balance
     FROM users u
     LEFT JOIN credit_transactions ct ON ct.user_id = u.id
     GROUP BY u.id, u.email, u.cached_balance
     ORDER BY u.id'
)->fetchAll();

$total = count($rows);
$drift = 0;
printf("Reconciling %d user(s)...\n\n", $total);

foreach ($rows as $r) {
    $cached = (string) $r['cached_balance'];
    $ledger = (string) $r['ledger_balance'];

    // String compare on canonical decimal form.
    $cachedF = number_format((float) $cached, 2, '.', '');
    $ledgerF = number_format((float) $ledger, 2, '.', '');

    if ($cachedF !== $ledgerF) {
        $drift++;
        printf("DRIFT  user=%d  email=%s  cached=%s  ledger=%s\n",
            $r['id'], $r['email'], $cachedF, $ledgerF);

        if ($fix) {
            $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
                ->execute([$ledgerF, $r['id']]);
            echo "       fixed -> cached_balance = $ledgerF\n";
        }
    }
}

printf("\nDone. %d user(s) checked, %d with drift%s.\n",
    $total, $drift, $fix ? ' (all fixed)' : '');

if ($drift > 0 && !$fix) {
    echo "Re-run with --fix to update cached_balance from ledger.\n";
}

exit($drift > 0 && !$fix ? 1 : 0);
