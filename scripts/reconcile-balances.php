<?php
declare(strict_types=1);

/**
 * Repair cached_balance drift against the credit ledger.
 *
 * The ledger (credit_transactions) is the source of truth: credits_deduct()
 * and credits_get_balance(strict) both recompute from SUM(amount). The
 * users.cached_balance column is only a fast display cache. If something ever
 * sets cached_balance without a matching ledger row (a manual SQL update, a
 * partial import, a pre-ledger migration), the header shows money the spend
 * path won't honour. This script finds and fixes that drift.
 *
 *   php scripts/reconcile-balances.php           # dry run: report drift only
 *   php scripts/reconcile-balances.php --apply    # set cached_balance = SUM(ledger)
 *
 * NOTE: this reconciles cached_balance DOWN/UP to the ledger; it never creates
 * money. To actually grant credit, use the admin "edit credit" action (which
 * appends an ADJUSTMENT ledger row) - not this script.
 */

require __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);
$pdo   = db();

$rows = $pdo->query(
    'SELECT u.id, u.email, u.cached_balance,
            COALESCE((SELECT SUM(amount) FROM credit_transactions WHERE user_id = u.id), 0) AS ledger
     FROM users u
     HAVING ABS(cached_balance - ledger) >= 0.005
     ORDER BY u.id'
)->fetchAll();

if (!$rows) {
    echo "OK: every user's cached_balance already matches the ledger. Nothing to do.\n";
    exit(0);
}

echo ($apply ? "APPLYING fixes" : "DRY RUN (use --apply to write)") . " - drifted accounts:\n\n";
printf("%-6s %-32s %12s %12s\n", 'id', 'email', 'cached', 'ledger');
echo str_repeat('-', 66) . "\n";

$fixed = 0;
foreach ($rows as $r) {
    printf(
        "%-6s %-32s %12.2f %12.2f\n",
        $r['id'], substr((string) $r['email'], 0, 32), (float) $r['cached_balance'], (float) $r['ledger']
    );
    if ($apply) {
        $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
            ->execute([number_format((float) $r['ledger'], 2, '.', ''), (int) $r['id']]);
        $fixed++;
    }
}

echo "\n" . ($apply
    ? "Done. Repaired {$fixed} account(s): cached_balance now equals the ledger.\n"
    : count($rows) . " account(s) need repair. Re-run with --apply to fix.\n");
exit(0);
