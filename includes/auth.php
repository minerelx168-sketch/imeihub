<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Session and current-user helpers.
 *
 * Sessions are stored server-side in the `sessions` table. The client gets a
 * single opaque cookie (32 random bytes, hex). This avoids JWT complexity and
 * lets us revoke a session by deleting one row.
 */

if (!function_exists('auth_random_id')) {
function auth_random_id(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}
}

if (!function_exists('auth_user')) {
/**
 * Returns the currently logged-in user as an associative array, or null.
 * Includes a fresh `cached_balance` straight from the users table.
 */
function auth_user(): ?array
{
    static $cached = null;
    static $resolved = false;
    if ($resolved) return $cached;
    $resolved = true;

    $cfg = require __DIR__ . '/config.php';
    $cookie = $cfg['session']['cookie_name'];
    if (empty($_COOKIE[$cookie])) return $cached = null;

    $sid = (string) $_COOKIE[$cookie];
    if (!preg_match('/^[0-9a-f]{64}$/', $sid)) return $cached = null;

    try {
        $pdo = db();
    } catch (Throwable $e) {
        return $cached = null;
    }

    // A banned user's session must not resolve. The banned_at filter is
    // wrapped so a deployment that hasn't run the admin migration yet
    // (no banned_at column) still authenticates normally instead of
    // 500ing on every page.
    try {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.name, u.image, u.cached_balance, s.expires_at
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > NOW() AND u.banned_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$sid]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.name, u.image, u.cached_balance, s.expires_at
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$sid]);
    }
    $row = $stmt->fetch();
    if (!$row) return $cached = null;

    // Touch last_seen so we know which sessions are stale.
    $pdo->prepare('UPDATE sessions SET last_seen_at = NOW() WHERE id = ?')->execute([$sid]);

    return $cached = $row;
}
}

if (!function_exists('auth_require')) {
/**
 * Redirect to /login if no user is logged in. Pages call this at the top.
 */
function auth_require(?string $redirectTo = null): array
{
    $user = auth_user();
    if ($user) return $user;
    $back = $redirectTo ?? ($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
    header('Location: /login.php?next=' . urlencode($back));
    exit;
}
}

if (!function_exists('auth_start_session')) {
/**
 * Creates a session row, sets the cookie. Returns the session id.
 */
function auth_start_session(int $userId): string
{
    $cfg = require __DIR__ . '/config.php';
    $lifetime = max(1, (int) $cfg['session']['lifetime_days']);
    $sid = auth_random_id();

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO sessions (id, user_id, expires_at, user_agent, ip)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, ?)'
    );
    $ip = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) ?: inet_pton('0.0.0.0');
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $stmt->execute([$sid, $userId, $lifetime, $ua, $ip]);

    setcookie($cfg['session']['cookie_name'], $sid, [
        'expires'  => time() + $lifetime * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $sid;
}
}

if (!function_exists('auth_destroy_session')) {
function auth_destroy_session(): void
{
    $cfg = require __DIR__ . '/config.php';
    $cookie = $cfg['session']['cookie_name'];
    if (empty($_COOKIE[$cookie])) return;
    $sid = (string) $_COOKIE[$cookie];

    try {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sid]);
    } catch (Throwable $e) {
        // best-effort: cookie still cleared below
    }

    setcookie($cookie, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
}

if (!function_exists('auth_safe_next')) {
/**
 * Validate a "next" url so an attacker can't redirect to an external site.
 */
function auth_safe_next(?string $next): string
{
    if (!$next) return '/dashboard.php';
    // only allow relative paths starting with /
    if (!preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@/?%-]*$#', $next)) {
        return '/dashboard.php';
    }
    return $next;
}
}
