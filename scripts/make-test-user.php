<?php
declare(strict_types=1);

/**
 * Create (or refresh) a test user from the CLI. Dev helper.
 *
 *   php scripts/make-test-user.php <email> <password> [balance]
 *
 * - password is bcrypt-hashed (cost 12), matching the app's signup.
 * - email is marked verified so the account can log in immediately.
 * - [balance] (optional, default 0) is granted as an ADJUSTMENT ledger
 *   row and cached_balance is recomputed from the ledger, so the
 *   users.cached_balance == SUM(credit_transactions) invariant holds.
 * - Idempotent: re-running for the same email refreshes the password +
 *   verified flag and does not double-credit.
 *
 * In the Docker stack:
 *   docker compose exec app php scripts/make-test-user.php you@example.com pass1234 100
 */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/signup.php';

$email = strtolower(trim($argv[1] ?? ''));
$pass  = (string) ($argv[2] ?? '');
$bal   = number_format((float) ($argv[3] ?? 0), 2, '.', '');

if ($email === '' || !str_contains($email, '@') || strlen($pass) < 8) {
    fwrite(STDERR, "Usage: php scripts/make-test-user.php <email> <password (>=8 chars)> [balance]\n");
    exit(1);
}

$pdo  = db();
$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo->prepare(
    'INSERT INTO users (email, name, email_verified, password_hash, cached_balance)
     VALUES (?, "Test User", NOW(), ?, 0)
     ON DUPLICATE KEY UPDATE password_hash  = VALUES(password_hash),
                             email_verified = VALUES(email_verified)'
)->execute([$email, $hash]);

$id = (int) $pdo->query('SELECT id FROM users WHERE email=' . $pdo->quote($email))->fetchColumn();

// Grant starting credit through the ledger so the balance invariant holds.
if ((float) $bal > 0) {
    $ref = 'manual-seed-' . $id;
    $chk = $pdo->prepare('SELECT 1 FROM credit_transactions WHERE reference_type = "Admin" AND reference_id = ?');
    $chk->execute([$ref]);
    if (!$chk->fetchColumn()) {
        $sum   = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM credit_transactions WHERE user_id = $id")->fetchColumn();
        $after = number_format($sum + (float) $bal, 2, '.', '');
        $pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, amount, type, reference_type, reference_id, balance_after, description)
             VALUES (?, ?, "ADJUSTMENT", "Admin", ?, ?, "manual test user credit")'
        )->execute([$id, $bal, $ref, $after]);
    }
}

$pdo->prepare(
    'UPDATE users SET cached_balance =
        (SELECT COALESCE(SUM(amount),0) FROM credit_transactions WHERE user_id = ?)
     WHERE id = ?'
)->execute([$id, $id]);

// Self-check: run the real login path so the output is conclusive.
try {
    signup_login_with_password($email, $pass);
    $ok = 'yes';
} catch (Throwable $e) {
    $ok = 'NO (' . $e->getMessage() . ')';
}

$balNow = $pdo->query("SELECT cached_balance FROM users WHERE id = $id")->fetchColumn();
echo "OK: user #$id  $email  balance $balNow  login-verifies: $ok\n";
