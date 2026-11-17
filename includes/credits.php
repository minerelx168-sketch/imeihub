<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Credit ledger helpers.
 *
 * This file holds READ-side helpers (balance, transactions, monthly stats).
 * Mutating helpers (top-up credit, deduct on usage, refund) live in
 * lib/credits_write.php and must be called inside DB transactions.
 *
 * Money is always handled as DECIMAL strings via PDO so we never round-trip
 * through PHP floats - DON'T cast amounts to (float) for math.
 */

if (!function_exists('credits_get_balance')) {
/**
 * Returns the user's balance as a string (e.g. "250.00").
 *
 * The cached_balance column is fast but denormalized. We compare it
 * against SUM(amount) every call and fall back to (and repair) the
 * ledger value on drift, so a stale cache can never short-change a
 * user OR over-spend their wallet.
 */
function credits_get_balance(int $userId, bool $strict = false): string
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return '0.00';
    }

    if ($strict) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM credit_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return number_format((float) $stmt->fetchColumn(), 2, '.', '');
    }

    // Non-strict: read the fast cache but compare it against the ledger and
    // self-heal on drift, so a display can never show money the spend path
    // (which always recomputes from the ledger) won't honour.
    $stmt = $pdo->prepare(
        'SELECT u.cached_balance,
                COALESCE((SELECT SUM(amount) FROM credit_transactions WHERE user_id = u.id), 0) AS ledger
         FROM users u WHERE u.id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return '0.00';

    $cached = (float) $row['cached_balance'];
    $ledger = (float) $row['ledger'];
    if (abs($cached - $ledger) >= 0.005) {
        // Ledger is the source of truth; repair the stale cache (best-effort).
        try {
            $pdo->prepare('UPDATE users SET cached_balance = ? WHERE id = ?')
                ->execute([number_format($ledger, 2, '.', ''), $userId]);
        } catch (Throwable $e) {
            // ignore; we still return the correct (ledger) value below
        }
        return number_format($ledger, 2, '.', '');
    }
    return number_format($cached, 2, '.', '');
}
}

if (!function_exists('credits_get_transactions')) {
/**
 * Returns the user's most recent ledger rows.
 * limit clamped to [1, 100]. offset >= 0.
 */
function credits_get_transactions(int $userId, int $limit = 20, int $offset = 0, ?string $type = null): array
{
    $limit  = max(1, min(100, $limit));
    $offset = max(0, $offset);

    try {
        $pdo = db();
    } catch (Throwable $e) {
        return ['rows' => [], 'total' => 0];
    }

    $where  = 'WHERE user_id = ?';
    $params = [$userId];
    if ($type && in_array($type, ['TOPUP', 'USAGE', 'REFUND', 'ADJUSTMENT', 'BONUS'], true)) {
        $where .= ' AND type = ?';
        $params[] = $type;
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM credit_transactions $where");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, amount, type, reference_type, reference_id, balance_after,
                description, created_at
         FROM credit_transactions
         $where
         ORDER BY id DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total];
}
}

if (!function_exists('credits_get_recent_usages')) {
function credits_get_recent_usages(int $userId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT u.public_id, u.service_code, u.cost, u.status, u.error_message,
                u.created_at, u.completed_at, sp.name AS service_name
         FROM service_usages u
         LEFT JOIN service_prices sp ON sp.code = u.service_code
         WHERE u.user_id = ?
         ORDER BY u.id DESC
         LIMIT $limit"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
}

if (!function_exists('credits_get_month_stats')) {
/**
 * Lookups + spend within the current calendar month.
 */
function credits_get_month_stats(int $userId): array
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return ['lookups_count' => 0, 'lookups_free' => 0, 'lookups_paid' => 0, 'spent' => '0.00'];
    }

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN cost = 0 THEN 1 ELSE 0 END) AS free_count,
            SUM(CASE WHEN cost > 0 THEN 1 ELSE 0 END) AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'SUCCESS' THEN cost ELSE 0 END), 0) AS spent
         FROM service_usages
         WHERE user_id = ?
           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return [
        'lookups_count' => (int) ($row['total'] ?? 0),
        'lookups_free'  => (int) ($row['free_count'] ?? 0),
        'lookups_paid'  => (int) ($row['paid_count'] ?? 0),
        'spent'         => number_format((float) ($row['spent'] ?? 0), 2, '.', ''),
    ];
}
}

if (!function_exists('credits_format_usd')) {
function credits_format_usd(string|float|int $amount): string
{
    return '$' . number_format((float) $amount, 2);
}
}
