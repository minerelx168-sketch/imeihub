<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Community IMEI blacklist helpers.
 *
 * A report flags an IMEI immediately (community-sourced warning); admins
 * can dismiss false reports. All reads tolerate a missing table (returns
 * "not flagged") so the lookup flow never breaks if the migration hasn't
 * run yet.
 */

if (!function_exists('blacklist_status')) {
/**
 * Returns the active-report summary for an IMEI, or null if it has none.
 *   ['reports' => int, 'first_reported' => 'Y-m-d H:i:s', 'reason' => string|null]
 */
function blacklist_status(string $imei): ?array
{
    $imei = imei_normalize($imei);
    if ($imei === '') {
        return null;
    }
    try {
        // Single-quoted SQL literals so the query is correct under any
        // sql_mode (ANSI_QUOTES would treat "active" as an identifier).
        $stmt = db()->prepare(
            "SELECT COUNT(*) AS reports, MIN(created_at) AS first_reported
             FROM blacklist_reports WHERE imei = ? AND status = 'active'"
        );
        $stmt->execute([$imei]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['reports'] === 0) {
            return null;
        }
        $r2 = db()->prepare(
            "SELECT reason FROM blacklist_reports
             WHERE imei = ? AND status = 'active' AND reason IS NOT NULL AND reason <> ''
             ORDER BY id DESC LIMIT 1"
        );
        $r2->execute([$imei]);
        $reason = $r2->fetchColumn();
    } catch (Throwable $e) {
        return null; // table may not exist yet
    }
    return [
        'reports'        => (int) $row['reports'],
        'first_reported' => (string) $row['first_reported'],
        'reason'         => ($reason !== false && $reason !== null && $reason !== '') ? (string) $reason : null,
    ];
}
}

if (!function_exists('blacklist_report')) {
/**
 * File (or refresh) a report for an IMEI by a given user. One active
 * report per (imei, user); re-reporting updates the reason and re-activates.
 * Returns the active report count for the IMEI afterwards.
 */
function blacklist_report(string $imei, ?string $serial, int $userId, ?string $reason): int
{
    $imei = imei_normalize($imei);
    if (strlen($imei) !== 15) {
        throw new RuntimeException('A valid 15-digit IMEI is required to report.');
    }
    $tac = imei_tac($imei);
    db()->prepare(
        "INSERT INTO blacklist_reports (imei, tac, serial_number, reported_by, reason, status)
         VALUES (?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE
            serial_number = VALUES(serial_number),
            reason        = VALUES(reason),
            status        = 'active',
            created_at    = created_at"
    )->execute([
        $imei,
        $tac,
        $serial !== null && $serial !== '' ? substr($serial, 0, 64) : null,
        $userId,
        $reason !== null && $reason !== '' ? substr($reason, 0, 255) : null,
    ]);

    $s = db()->prepare("SELECT COUNT(*) FROM blacklist_reports WHERE imei = ? AND status = 'active'");
    $s->execute([$imei]);
    return (int) $s->fetchColumn();
}
}

if (!function_exists('blacklist_unreport')) {
/**
 * Cancel the caller's own active report for an IMEI (status -> dismissed).
 * Re-reporting later reactivates it. Returns the remaining active count.
 */
function blacklist_unreport(string $imei, int $userId): int
{
    $imei = imei_normalize($imei);
    if (strlen($imei) !== 15) {
        throw new RuntimeException('A valid 15-digit IMEI is required.');
    }
    db()->prepare(
        "UPDATE blacklist_reports SET status = 'dismissed' WHERE imei = ? AND reported_by = ?"
    )->execute([$imei, $userId]);

    $s = db()->prepare("SELECT COUNT(*) FROM blacklist_reports WHERE imei = ? AND status = 'active'");
    $s->execute([$imei]);
    return (int) $s->fetchColumn();
}
}

if (!function_exists('blacklist_user_reported_imeis')) {
/**
 * Set of IMEIs the user currently has an active report on, as imei => true
 * for O(1) lookups when rendering the order list. Tolerates a missing table.
 */
function blacklist_user_reported_imeis(int $userId): array
{
    try {
        $s = db()->prepare("SELECT imei FROM blacklist_reports WHERE reported_by = ? AND status = 'active'");
        $s->execute([$userId]);
        return array_fill_keys($s->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) {
        return [];
    }
}
}

if (!function_exists('blacklist_import')) {
/**
 * Bulk-file (or refresh) reports from an uploaded list. $items is a list of
 * ['imei' => <15 digits>, 'reason' => <string>]; callers must validate the
 * IMEI first. Upserts one active report per (imei, user) in a single
 * transaction. Returns the number of rows applied.
 */
function blacklist_import(array $items, int $userId): int
{
    if ($items === []) {
        return 0;
    }
    $pdo  = db();
    $stmt = $pdo->prepare(
        "INSERT INTO blacklist_reports (imei, tac, serial_number, reported_by, reason, status)
         VALUES (?, ?, NULL, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'active'"
    );
    $pdo->beginTransaction();
    try {
        $n = 0;
        foreach ($items as $it) {
            $imei   = (string) $it['imei'];
            $reason = (string) ($it['reason'] ?? '');
            $stmt->execute([$imei, imei_tac($imei), $userId, $reason !== '' ? substr($reason, 0, 255) : null]);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $n;
}
}
