<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ulid.php';

/**
 * MUTATING credit helpers. Every function in this file:
 *   - runs inside a single DB transaction
 *   - takes a SELECT ... FOR UPDATE lock on the user row
 *   - inserts into credit_transactions (append-only, NEVER updates)
 *   - updates users.cached_balance to keep the cache aligned
 *
 * If you see something here that mutates an existing credit_transactions
 * row, that is a bug - fix the call site, do not change this file.
 */

class InsufficientCreditError extends RuntimeException {}
class TopUpAlreadyCreditedError extends RuntimeException {}

/**
 * Admin manual credit adjustment (positive = grant, negative = deduct).
 * Appends ONE ADJUSTMENT ledger row and updates cached_balance atomically
 * under a FOR UPDATE lock, recomputing from the ledger so the
 * cached_balance == SUM(ledger) invariant holds. Never drives the balance
 * negative. The acting admin + reason are recorded for the audit trail.
 */
if (!function_exists('credits_admin_adjust')) {
function credits_admin_adjust(int $userId, float $delta, string $reason, int $adminId): array
{
    if (abs($delta) < 0.01) {
        throw new RuntimeException('Adjustment amount must be non-zero.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('User not found: ' . $userId);
        }

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $balance    = (float) $stmt->fetchColumn();
        $newBalance = $balance + $delta;
        if ($newBalance < 0) {
            throw new RuntimeException(
                sprintf('Adjustment would make balance negative (have %.2f, delta %.2f).', $balance, $delta)
            );
        }

        $desc = sprintf('Admin #%d: %s', $adminId, substr(trim($reason) !== '' ? trim($reason) : 'manual adjustment', 0, 200));
        $pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, amount, type, reference_type, reference_id, balance_after, description)
             VALUES (?, ?, "ADJUSTMENT", "Admin", ?, ?, ?)'
        )->execute([
            $userId,
            number_format($delta, 2, '.', ''),
            // ULID (not time()) so two adjustments in the same second can't
            // collide on the uniq_ledger_ref (reference_type, reference_id, type) key.
            'admin-' . $adminId . '-' . ulid(),
            number_format($newBalance, 2, '.', ''),
            $desc,
        ]);
        $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
            ->execute([number_format($newBalance, 2, '.', ''), $userId]);

        $pdo->commit();
        return ['balance' => number_format($newBalance, 2, '.', '')];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}

/**
 * Insert a PENDING top-up order. The caller (api/topup/<provider>-create)
 * will then ask the provider to create a checkout/order and store the
 * resulting external id on the row via credits_attach_charge_id().
 */
if (!function_exists('credits_create_topup_order')) {
function credits_create_topup_order(
    int $userId,
    string $amount,
    string $currency,
    string $idempotencyKey,
    string $provider = 'stripe'
): array {
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount) || (float) $amount <= 0) {
        throw new RuntimeException('Invalid top-up amount.');
    }
    if (!in_array($provider, ['stripe', 'paypal', 'binancepay', 'crypto-trc20', 'crypto-bep20', 'lemonsqueezy'], true)) {
        throw new RuntimeException('Unknown payment provider: ' . $provider);
    }

    $pdo = db();

    // Idempotency: if a row already exists for this key, return it.
    $stmt = $pdo->prepare('SELECT * FROM topup_orders WHERE idempotency_key = ? LIMIT 1');
    $stmt->execute([$idempotencyKey]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing;
    }

    $publicId = ulid();
    $pdo->prepare(
        'INSERT INTO topup_orders
            (public_id, user_id, amount, currency, status, provider, idempotency_key)
         VALUES (?, ?, ?, ?, "PENDING", ?, ?)'
    )->execute([$publicId, $userId, $amount, $currency, $provider, $idempotencyKey]);

    $stmt = $pdo->prepare('SELECT * FROM topup_orders WHERE id = ?');
    $stmt->execute([$pdo->lastInsertId()]);
    return $stmt->fetch();
}
}

if (!function_exists('credits_attach_charge_id')) {
function credits_attach_charge_id(int $orderId, string $chargeId): void
{
    db()->prepare('UPDATE topup_orders SET provider_charge_id = ? WHERE id = ?')
        ->execute([$chargeId, $orderId]);
}
}

/**
 * Credit a paid top-up order. Idempotent: if the order is already CREDITED,
 * returns silently without double-issuing credits. This is the function the
 * provider webhooks call.
 *
 * If the order's provider has a bonus_pct configured in
 * data/payment_methods.php, a second BONUS row is appended in the same
 * transaction (e.g. Binance Pay = +5% to encourage USDT settlement).
 * Both rows share the order's public_id as reference_id so they sort
 * together in the credit history view.
 */
if (!function_exists('credits_issue_topup')) {
function credits_issue_topup(string $publicId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM topup_orders WHERE public_id = ? FOR UPDATE');
        $stmt->execute([$publicId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Top-up order not found: ' . $publicId);
        }

        if ($order['status'] === 'CREDITED') {
            $pdo->commit();
            return ['order' => $order, 'credited_now' => false];
        }
        if (!in_array($order['status'], ['PENDING', 'PAID'], true)) {
            throw new RuntimeException('Top-up cannot be credited in status: ' . $order['status']);
        }

        // Lock the user row.
        $stmt = $pdo->prepare('SELECT id, cached_balance FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$order['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('User not found for top-up: ' . $order['user_id']);
        }

        // Source of truth balance (ledger). Don't trust cached_balance.
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE user_id = ?');
        $stmt->execute([$order['user_id']]);
        $balance = (float) $stmt->fetchColumn();

        $amount   = (float) $order['amount'];
        $provider = (string) $order['provider'];
        [$providerLabel, $bonusPct] = credits_provider_meta($provider);
        $bonus = $bonusPct > 0 ? round($amount * $bonusPct, 2) : 0.0;

        $newBalance = $balance + $amount;
        $pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, amount, type, reference_type, reference_id, balance_after, description)
             VALUES (?, ?, "TOPUP", "TopUpOrder", ?, ?, ?)'
        )->execute([
            $order['user_id'],
            number_format($amount, 2, '.', ''),
            $order['public_id'],
            number_format($newBalance, 2, '.', ''),
            'Top-up via ' . $providerLabel,
        ]);

        if ($bonus > 0) {
            $newBalance += $bonus;
            $pdo->prepare(
                'INSERT INTO credit_transactions
                    (user_id, amount, type, reference_type, reference_id, balance_after, description)
                 VALUES (?, ?, "BONUS", "TopUpOrder", ?, ?, ?)'
            )->execute([
                $order['user_id'],
                number_format($bonus, 2, '.', ''),
                $order['public_id'],
                number_format($newBalance, 2, '.', ''),
                sprintf('+%s%% %s bonus', rtrim(rtrim(number_format($bonusPct * 100, 1), '0'), '.'), $providerLabel),
            ]);
        }

        // Update cached balance + order status.
        $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
            ->execute([number_format($newBalance, 2, '.', ''), $order['user_id']]);

        $pdo->prepare(
            'UPDATE topup_orders
             SET status = "CREDITED",
                 paid_at    = COALESCE(paid_at, NOW()),
                 credited_at = NOW()
             WHERE id = ?'
        )->execute([$order['id']]);

        $pdo->commit();

        $stmt = $pdo->prepare('SELECT * FROM topup_orders WHERE id = ?');
        $stmt->execute([$order['id']]);
        return ['order' => $stmt->fetch(), 'credited_now' => true, 'bonus' => $bonus];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
}

if (!function_exists('credits_provider_meta')) {
/**
 * Returns [displayLabel, bonusPct] for a topup_orders.provider string.
 * Looks the provider up in data/payment_methods.php and falls back to
 * a titlecased version of the provider name with zero bonus.
 */
function credits_provider_meta(string $provider): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $methods = require dirname(__DIR__) . '/data/payment_methods.php';
        foreach ($methods as $m) {
            $p = (string) ($m['provider'] ?? '');
            if ($p !== '' && !isset($cache[$p])) {
                $cache[$p] = [(string) ($m['label'] ?? ucfirst($p)), (float) ($m['bonus_pct'] ?? 0)];
            }
        }
    }
    // Crypto orders store the chain in the provider value (crypto-trc20 /
    // crypto-bep20); render that as "USDT TRC20" / "USDT BEP-20" in the
    // ledger description so the history reads cleanly.
    if (strncmp($provider, 'crypto-', 7) === 0) {
        return ['USDT ' . strtoupper(substr($provider, 7)), 0.0];
    }
    return $cache[$provider] ?? [ucfirst($provider), 0.0];
}
}

/**
 * Mark an order as FAILED (e.g. Stripe sent us a payment_failed event).
 * Does NOT issue credits, does NOT touch the ledger.
 */
if (!function_exists('credits_mark_order_failed')) {
function credits_mark_order_failed(string $publicId, string $reason = ''): void
{
    db()->prepare(
        'UPDATE topup_orders
         SET status = "FAILED", updated_at = NOW()
         WHERE public_id = ? AND status IN ("PENDING", "PAID")'
    )->execute([$publicId]);
}
}

/**
 * Deduct credit + record service usage. ATOMIC.
 *
 * Wraps the whole sequence in a SERIALIZABLE transaction with FOR UPDATE
 * locks on the user row. Recomputes balance from the ledger (not the
 * cached_balance) so a stale cache cannot let a user overdraw.
 *
 * Free services (cost = 0) still create a service_usage row, but skip
 * the ledger insert entirely - we don't store no-op ±0 transactions
 * because they only pollute the audit log.
 *
 * Returns the freshly inserted service_usage row.
 */
if (!function_exists('credits_deduct')) {
function credits_deduct(int $userId, string $serviceCode, array $input): array
{
    $pdo = $orig = db();

    // SERIALIZABLE is the safe default for any code path that reads then
    // writes off the same balance.
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    } catch (Throwable $_) {
        // Not all MySQL deployments allow this per-session SQL. The FOR
        // UPDATE lock below is the actual correctness guarantee.
    }
    $pdo->beginTransaction();
    try {
        // 1. Resolve the active service + price.
        $stmt = $pdo->prepare(
            'SELECT code, name, cost, active FROM service_prices WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$serviceCode]);
        $service = $stmt->fetch();
        if (!$service || (int) $service['active'] !== 1) {
            throw new RuntimeException("Unknown or inactive service: $serviceCode");
        }
        $cost = (float) $service['cost'];

        // 2. Lock the user row so concurrent deducts serialize through us.
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('User not found: ' . $userId);
        }

        // 3. Source-of-truth balance = SUM(ledger). Never trust cached_balance.
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $balance = (float) $stmt->fetchColumn();

        if ($cost > 0 && $balance < $cost) {
            throw new InsufficientCreditError(
                sprintf('Insufficient balance: need %.2f, have %.2f.', $cost, $balance)
            );
        }

        // 4. Insert the usage row (PENDING).
        $publicId = ulid();
        $pdo->prepare(
            'INSERT INTO service_usages
                (public_id, user_id, service_code, cost, input, status)
             VALUES (?, ?, ?, ?, ?, "PENDING")'
        )->execute([
            $publicId,
            $userId,
            $serviceCode,
            number_format($cost, 2, '.', ''),
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $usageId = (int) $pdo->lastInsertId();

        // 5. If the service is paid, append the ledger entry + update cache.
        if ($cost > 0) {
            $newBalance = $balance - $cost;
            $pdo->prepare(
                'INSERT INTO credit_transactions
                    (user_id, amount, type, reference_type, reference_id, balance_after, description)
                 VALUES (?, ?, "USAGE", "ServiceUsage", ?, ?, ?)'
            )->execute([
                $userId,
                number_format(-$cost, 2, '.', ''),
                $publicId,
                number_format($newBalance, 2, '.', ''),
                $service['name'],
            ]);
            $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
                ->execute([number_format($newBalance, 2, '.', ''), $userId]);
        }

        $stmt = $pdo->prepare('SELECT * FROM service_usages WHERE id = ?');
        $stmt->execute([$usageId]);
        $row = $stmt->fetch();

        $pdo->commit();
        return $row;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}

/**
 * Mark a successful run + persist provider output. No ledger change.
 * Accepts both PENDING (sync PHP API) and PROCESSING (async DHRU) so a
 * polled order can settle without juggling the state machine itself.
 */
if (!function_exists('credits_mark_usage_success')) {
function credits_mark_usage_success(string $publicId, array $output): void
{
    db()->prepare(
        'UPDATE service_usages
         SET status = "SUCCESS",
             output = ?,
             completed_at = NOW(3),
             error_message = NULL
         WHERE public_id = ? AND status IN ("PENDING", "PROCESSING")'
    )->execute([
        json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $publicId,
    ]);
}
}

/**
 * Mark a usage as PROCESSING (DHRU order placed). Stamps the provider
 * order id so subsequent polls can map back without a join. Records
 * last_polled_at = NOW() so the debounce window starts fresh.
 *
 * Idempotent: if the row is already PROCESSING / SUCCESS / FAILED /
 * REFUNDED we don't downgrade its state.
 */
if (!function_exists('credits_mark_usage_processing')) {
function credits_mark_usage_processing(string $publicId, string $providerOrderId): void
{
    db()->prepare(
        'UPDATE service_usages
         SET status            = "PROCESSING",
             provider_order_id = ?,
             last_polled_at    = NOW(3)
         WHERE public_id = ? AND status = "PENDING"'
    )->execute([$providerOrderId, $publicId]);
}
}

/**
 * Bump last_polled_at on a PROCESSING usage. Used by status.php to
 * debounce calls to the provider when a tight polling loop hammers it.
 */
if (!function_exists('credits_touch_usage_polled')) {
function credits_touch_usage_polled(string $publicId): void
{
    db()->prepare(
        'UPDATE service_usages SET last_polled_at = NOW(3)
         WHERE public_id = ? AND status = "PROCESSING"'
    )->execute([$publicId]);
}
}

/**
 * Refund a usage: append a REFUND ledger row + update cached_balance + mark
 * usage status = REFUNDED. ATOMIC. Idempotent: if the usage is already
 * REFUNDED or never had a charge (cost = 0), no-op.
 */
if (!function_exists('credits_refund_usage')) {
function credits_refund_usage(string $publicId, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM service_usages WHERE public_id = ? FOR UPDATE');
        $stmt->execute([$publicId]);
        $usage = $stmt->fetch();
        if (!$usage) {
            throw new RuntimeException('Usage not found: ' . $publicId);
        }
        if ($usage['status'] === 'REFUNDED') {
            $pdo->commit();
            return; // idempotent
        }

        $cost = (float) $usage['cost'];

        // Free usage - just mark FAILED, no ledger change.
        if ($cost <= 0) {
            $pdo->prepare(
                'UPDATE service_usages
                 SET status = "FAILED",
                     error_message = ?,
                     completed_at = NOW(3)
                 WHERE id = ?'
            )->execute([substr($reason, 0, 500), $usage['id']]);
            $pdo->commit();
            return;
        }

        // Paid usage - lock user, recompute balance from ledger, append REFUND.
        $userId = (int) $usage['user_id'];
        $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $balance    = (float) $stmt->fetchColumn();
        $newBalance = $balance + $cost;

        $pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, amount, type, reference_type, reference_id, balance_after, description)
             VALUES (?, ?, "REFUND", "ServiceUsage", ?, ?, ?)'
        )->execute([
            $userId,
            number_format($cost, 2, '.', ''),       // positive: credit IN
            $publicId,
            number_format($newBalance, 2, '.', ''),
            'Refund: ' . substr($reason, 0, 200),
        ]);

        $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
            ->execute([number_format($newBalance, 2, '.', ''), $userId]);

        $pdo->prepare(
            'UPDATE service_usages
             SET status = "REFUNDED",
                 error_message = ?,
                 completed_at = NOW(3)
             WHERE id = ?'
        )->execute([substr($reason, 0, 500), $usage['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}
