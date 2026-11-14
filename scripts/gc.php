<?php
declare(strict_types=1);

/**
 * Garbage-collect expired / stale rows from non-financial tables.
 *
 * Financial + audit-of-record tables are NEVER touched here:
 *   credit_transactions, topup_orders, service_usages, blacklist_reports.
 *
 *   php scripts/gc.php           # dry run: report how many rows WOULD be deleted
 *   php scripts/gc.php --apply    # actually delete
 *
 * Safe to run on a cron, e.g. hourly:
 *   17 * * * * php /var/www/imeihub/scripts/gc.php --apply >> /var/log/imeihub-gc.log 2>&1
 */

require __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);
$pdo   = db();

// label => [ WHERE clause, table ]. Each prunes only expired/stale rows.
$jobs = [
    'sessions (expired)'                 => ['sessions',            'expires_at < NOW()'],
    'email_verifications (used/expired)' => ['email_verifications', '(consumed_at IS NOT NULL OR expires_at < NOW()) AND created_at < NOW() - INTERVAL 7 DAY'],
    'oauth_states (stale)'               => ['oauth_states',        'created_at < NOW() - INTERVAL 1 DAY'],
    'bot_link_tokens (used/expired)'     => ['bot_link_tokens',     '(consumed_at IS NOT NULL OR expires_at < NOW()) AND created_at < NOW() - INTERVAL 7 DAY'],
    'webhook_events (>90 days)'          => ['webhook_events',      'received_at < NOW() - INTERVAL 90 DAY'],
    'imei_lookups cache (>30 days)'      => ['imei_lookups',        'created_at < NOW() - INTERVAL 30 DAY'],
    'imei_rate_limit (stale)'            => ['imei_rate_limit',     'window_start < NOW() - INTERVAL 1 DAY'],
];

echo ($apply ? "APPLYING garbage collection" : "DRY RUN (use --apply to delete)") . "\n\n";
printf("%-40s %10s\n", 'target', $apply ? 'deleted' : 'would del');
echo str_repeat('-', 52) . "\n";

$total = 0;
foreach ($jobs as $label => [$table, $where]) {
    try {
        if ($apply) {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE $where");
            $stmt->execute();
            $n = $stmt->rowCount();
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE $where");
            $n = (int) $stmt->fetchColumn();
        }
        $total += $n;
        printf("%-40s %10d\n", $label, $n);
    } catch (Throwable $e) {
        printf("%-40s %10s  (%s)\n", $label, 'ERR', $e->getMessage());
    }
}

echo str_repeat('-', 52) . "\n";
printf("%-40s %10d\n", 'TOTAL', $total);
echo $apply ? "\nDone.\n" : "\nNothing deleted (dry run).\n";
