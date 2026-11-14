<?php
/**
 * imeihub integration test suite.
 *
 * Covers every assertion in section 8 of the brief:
 *   T1. user signup -> balance = 0
 *   T2. top-up happy path -> ledger + cached_balance updated
 *   T3. webhook replay -> balance does NOT double
 *   T4. deduct happy path -> balance drops by cost
 *   T5. insufficient balance -> InsufficientCreditError, ledger untouched
 *   T6. provider failure -> auto-refund (REFUND row, status REFUNDED, balance restored)
 *   T7. concurrent deduct (5 forks on a 100-baht wallet, cost 15)
 *       -> balance never negative; survivors = floor(100/15) at most
 *   T8. RECONCILIATION INVARIANT
 *       After every assertion, users.cached_balance must equal SUM(ledger).
 *
 * Requires:
 *   - .env points at a real MySQL with schema.sql + seed.sql applied
 *   - PHP pcntl extension for the concurrency test (Linux/macOS, not Windows)
 *
 * Usage:
 *   php scripts/run-tests.php
 *
 * Exit code: 0 if all pass, 1 if anything failed.
 */

declare(strict_types=1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/credits.php';
require __DIR__ . '/../includes/credits_write.php';

$failures = 0;
$current  = '';

function section(string $name): void
{
    global $current;
    $current = $name;
    echo "\n\033[1m$name\033[0m\n";
}

function assert_that(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  \033[32mPASS\033[0m  $label" . ($detail ? "  ($detail)" : '') . "\n";
    } else {
        echo "  \033[31mFAIL\033[0m  $label" . ($detail ? "  ($detail)" : '') . "\n";
        $failures++;
    }
}

function reconcile(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT cached_balance FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $cached = (float) ($stmt->fetchColumn() ?: 0);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $ledger = (float) $stmt->fetchColumn();
    return ['cached' => $cached, 'ledger' => $ledger];
}

function invariant(int $userId, string $note = ''): void
{
    $b = reconcile($userId);
    assert_that(
        "users.cached_balance == SUM(credit_transactions)" . ($note ? " [$note]" : ''),
        abs($b['cached'] - $b['ledger']) < 0.01,
        "cached=$b[cached]  ledger=$b[ledger]"
    );
}

function with_test_user(callable $fn): void
{
    // Use db() inline rather than caching the handle: the concurrency
    // test calls db_disconnect() before forking, and a cached PDO held
    // here would keep the parent's socket alive across the fork (which a
    // child would then tear down). Calling db() each time always returns
    // the current process's live connection.
    $email = 'tests+' . bin2hex(random_bytes(4)) . '@example.com';
    db()->prepare('INSERT INTO users (email, name, cached_balance) VALUES (?, ?, 0)')
        ->execute([$email, 'Test User']);
    $userId = (int) db()->lastInsertId();
    try {
        $fn($userId);
    } finally {
        db()->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM service_usages    WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM topup_orders     WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM users            WHERE id      = ?')->execute([$userId]);
    }
}

function with_topup_order(int $userId, float $amount, callable $fn): void
{
    require_once __DIR__ . '/../includes/ulid.php';
    $publicId  = ulid();
    $idemKey   = 'test-' . bin2hex(random_bytes(8));
    db()->prepare(
        'INSERT INTO topup_orders
            (public_id, user_id, amount, currency, status, provider, idempotency_key)
         VALUES (?, ?, ?, "USD", "PENDING", "stripe", ?)'
    )->execute([$publicId, $userId, number_format($amount, 2, '.', ''), $idemKey]);
    $fn($publicId);
}

// =========================================================================
//  Test fixture: a known paid service priced at 15.
//
//  The deduct/refund/concurrency assertions below are written against a
//  fixed cost of 15 (balance 100 -> 85, floor(100/15) = 6 survivors).
//  The live USD catalog renamed this lookup to BLACKLIST_SIMPLE / _FULL
//  at sub-dollar prices, so seed a dedicated 'BLACKLIST' row here instead
//  of coupling the test to whatever the production price list happens to
//  be. Removed again before the summary.
// =========================================================================
db()->prepare(
    "INSERT INTO service_prices (code, name, cost, active)
     VALUES ('BLACKLIST', 'Blacklist Status Check (test fixture)', 15.00, 1)
     ON DUPLICATE KEY UPDATE cost = VALUES(cost), active = VALUES(active)"
)->execute();

// =========================================================================
//  T1. signup
// =========================================================================
section('T1. Fresh user has zero balance');
with_test_user(function (int $userId) {
    $b = reconcile($userId);
    assert_that('cached_balance starts at 0',   $b['cached'] === 0.0);
    assert_that('ledger starts at 0',           $b['ledger'] === 0.0);
    invariant($userId, 'signup');
});

// =========================================================================
//  T2. topup happy path
// =========================================================================
section('T2. Top-up credits the wallet');
with_test_user(function (int $userId) {
    with_topup_order($userId, 500.00, function (string $publicId) use ($userId) {
        $result = credits_issue_topup($publicId);
        $b = reconcile($userId);
        assert_that('credit_now flag',        $result['credited_now'] === true);
        assert_that('status -> CREDITED',     $result['order']['status'] === 'CREDITED');
        assert_that('ledger == 500',          abs($b['ledger'] - 500.00) < 0.01, "ledger=$b[ledger]");
        invariant($userId, 'after topup');
    });
});

// =========================================================================
//  T3. webhook replay
// =========================================================================
section('T3. Webhook replay does not double-credit');
with_test_user(function (int $userId) {
    with_topup_order($userId, 300.00, function (string $publicId) use ($userId) {
        $r1 = credits_issue_topup($publicId);
        $r2 = credits_issue_topup($publicId);
        $r3 = credits_issue_topup($publicId);
        $b = reconcile($userId);
        assert_that('first call credits',          $r1['credited_now'] === true);
        assert_that('second call is no-op',        $r2['credited_now'] === false);
        assert_that('third call is no-op',         $r3['credited_now'] === false);
        assert_that('balance == 300 (single credit)', abs($b['ledger'] - 300.00) < 0.01, "ledger=$b[ledger]");
        // Confirm only ONE ledger row exists.
        $stmt = db()->prepare('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ? AND type = "TOPUP"');
        $stmt->execute([$userId]);
        assert_that('exactly one TOPUP ledger row', (int) $stmt->fetchColumn() === 1);
        invariant($userId, 'after replay');
    });
});

// =========================================================================
//  T4. deduct happy path
// =========================================================================
section('T4. Deduct on paid service drops balance');
with_test_user(function (int $userId) {
    // Seed 100 baht via a synthetic TOPUP.
    db()->prepare(
        'INSERT INTO credit_transactions (user_id, amount, type, balance_after, description)
         VALUES (?, 100, "TOPUP", 100, "test seed")'
    )->execute([$userId]);
    db()->prepare('UPDATE users SET cached_balance = 100 WHERE id = ?')->execute([$userId]);

    $usage = credits_deduct($userId, 'BLACKLIST', ['imei' => '359152060000003']);
    $b = reconcile($userId);
    assert_that('usage row created PENDING',   $usage['status'] === 'PENDING');
    assert_that('balance == 85',               abs($b['ledger'] - 85.00) < 0.01, "ledger=$b[ledger]");
    invariant($userId, 'after deduct');
});

// =========================================================================
//  T5. insufficient
// =========================================================================
section('T5. Insufficient balance throws, no ledger row created');
with_test_user(function (int $userId) {
    db()->prepare(
        'INSERT INTO credit_transactions (user_id, amount, type, balance_after, description)
         VALUES (?, 5, "TOPUP", 5, "test seed")'
    )->execute([$userId]);
    db()->prepare('UPDATE users SET cached_balance = 5 WHERE id = ?')->execute([$userId]);

    $threw = false;
    try {
        credits_deduct($userId, 'BLACKLIST', ['imei' => '359152060000003']); // cost 15
    } catch (InsufficientCreditError $e) {
        $threw = true;
    }
    assert_that('throws InsufficientCreditError', $threw);

    $b = reconcile($userId);
    assert_that('ledger unchanged',           abs($b['ledger'] - 5.00) < 0.01, "ledger=$b[ledger]");
    $stmt = db()->prepare('SELECT COUNT(*) FROM service_usages WHERE user_id = ?');
    $stmt->execute([$userId]);
    assert_that('no service_usages row written', (int) $stmt->fetchColumn() === 0);
    invariant($userId, 'after rejection');
});

// =========================================================================
//  T6. refund
// =========================================================================
section('T6. Refund restores balance, marks usage REFUNDED, idempotent');
with_test_user(function (int $userId) {
    db()->prepare(
        'INSERT INTO credit_transactions (user_id, amount, type, balance_after, description)
         VALUES (?, 50, "TOPUP", 50, "test seed")'
    )->execute([$userId]);
    db()->prepare('UPDATE users SET cached_balance = 50 WHERE id = ?')->execute([$userId]);

    $usage = credits_deduct($userId, 'BLACKLIST', ['imei' => '359152060000003']);
    credits_refund_usage($usage['public_id'], 'simulated provider failure');
    $b = reconcile($userId);
    assert_that('balance restored to 50', abs($b['ledger'] - 50.00) < 0.01, "ledger=$b[ledger]");

    $stmt = db()->prepare('SELECT status FROM service_usages WHERE public_id = ?');
    $stmt->execute([$usage['public_id']]);
    assert_that('usage status -> REFUNDED', $stmt->fetchColumn() === 'REFUNDED');

    // Idempotency
    credits_refund_usage($usage['public_id'], 'second call');
    $b2 = reconcile($userId);
    assert_that('second refund is no-op',  abs($b2['ledger'] - 50.00) < 0.01, "ledger=$b2[ledger]");

    $stmt = db()->prepare('SELECT COUNT(*) FROM credit_transactions WHERE user_id = ? AND type = "REFUND"');
    $stmt->execute([$userId]);
    assert_that('exactly one REFUND row', (int) $stmt->fetchColumn() === 1);
    invariant($userId, 'after refund');
});

// =========================================================================
//  T7. concurrency
// =========================================================================
section('T7. Concurrent deducts cannot drive balance negative');
if (!function_exists('pcntl_fork')) {
    echo "  \033[33mSKIP\033[0m  pcntl extension not available\n";
} else {
    with_test_user(function (int $userId) {
        db()->prepare(
            'INSERT INTO credit_transactions (user_id, amount, type, balance_after, description)
             VALUES (?, 100, "TOPUP", 100, "test seed")'
        )->execute([$userId]);
        db()->prepare('UPDATE users SET cached_balance = 100 WHERE id = ?')->execute([$userId]);

        // Drop the parent's connection before forking so children don't
        // inherit (and then tear down) a shared MySQL socket. Each process
        // — children and the parent below — reopens its own on next use.
        db_disconnect();

        $pids = []; $succ = 0; $fail = 0;
        for ($i = 0; $i < 10; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    credits_deduct($userId, 'BLACKLIST', ['imei' => '359152060000003', 'worker' => $i]); // 15 each
                    exit(0);
                } catch (Throwable $_) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) $succ++;
            else $fail++;
        }
        // Force the parent PDO to drop its connection so we read the
        // children's committed work.  Each child opened its own PDO
        // (static cached), but the parent's PDO already exists from
        // setup. Re-querying still sees latest committed data thanks
        // to MySQL's default REPEATABLE READ.
        $b = reconcile($userId);
        $maxAllowed = floor(100 / 15); // 6
        assert_that('balance never negative',      $b['ledger'] >= 0,                          "ledger=$b[ledger]");
        assert_that("succ <= floor(100/15) = $maxAllowed", $succ <= $maxAllowed,               "succ=$succ");
        assert_that('balance == 100 - succ * 15',  abs($b['ledger'] - (100 - $succ * 15)) < 0.01,
            "expected=" . (100 - $succ * 15) . ", got=$b[ledger], succ=$succ, fail=$fail");
        invariant($userId, 'after concurrency');
    });
}

// Remove the test fixture service so we don't leave it in the catalog.
db()->prepare("DELETE FROM service_prices WHERE code = 'BLACKLIST'")->execute();

// =========================================================================
//  Summary
// =========================================================================
echo "\n";
if ($failures === 0) {
    echo "\033[1;32mAll tests passed.\033[0m\n";
    exit(0);
}
echo "\033[1;31m$failures assertion(s) failed.\033[0m\n";
exit(1);
