<?php
declare(strict_types=1);

/**
 * Pre-flight for the uniq_ledger_ref UNIQUE index (sql/migrate-ledger-unique.sql).
 *
 * That index enforces at most one credit_transactions row per
 * (reference_type, reference_id, type). Adding it FAILS if the table already
 * holds duplicate non-NULL tuples (e.g. a historical double-credit). Run this
 * first; if it reports any group, resolve those rows before migrating - the
 * ledger is append-only, so a real duplicate needs a deliberate accounting
 * decision (an offsetting ADJUSTMENT), not a DELETE.
 *
 *   php scripts/check-ledger-dupes.php
 *
 * Exit code 0 = clean (safe to add the index), 1 = duplicates found.
 */

require __DIR__ . '/../includes/db.php';

$pdo = db();
$rows = $pdo->query(
    "SELECT reference_type, reference_id, type, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
     FROM credit_transactions
     WHERE reference_type IS NOT NULL AND reference_id IS NOT NULL
     GROUP BY reference_type, reference_id, type
     HAVING n > 1
     ORDER BY n DESC, reference_type, reference_id"
)->fetchAll();

if (!$rows) {
    echo "OK: no duplicate (reference_type, reference_id, type) tuples. Safe to add uniq_ledger_ref.\n";
    exit(0);
}

echo "DUPLICATES FOUND - the UNIQUE index cannot be added until these are resolved:\n\n";
printf("%-14s %-28s %-12s %5s   %s\n", 'type', 'reference_id', 'ref_type', 'count', 'row ids');
echo str_repeat('-', 90) . "\n";
foreach ($rows as $r) {
    printf(
        "%-14s %-28s %-12s %5d   %s\n",
        $r['type'], substr((string) $r['reference_id'], 0, 28),
        $r['reference_type'], (int) $r['n'], $r['ids']
    );
}
echo "\nResolve each group (keep one row, offset the extras with an ADJUSTMENT) before running the migration.\n";
exit(1);
