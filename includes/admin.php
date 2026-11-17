<?php
declare(strict_types=1);

/**
 * Admin authorization gate.
 *
 * Separate from the login/session code on purpose: authentication (who
 * you are) is handled by includes/auth.php; this file only answers "is
 * the current user an admin?" and guards admin-only pages. It never
 * touches the login flow.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

if (!function_exists('admin_is_current')) {
/**
 * True if there is a logged-in user AND that user has is_admin = 1.
 * Reads the flag fresh from the DB rather than the session row, so
 * revoking admin takes effect immediately.
 */
function admin_is_current(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $u = auth_user();
    if (!$u) {
        return $cached = false;
    }
    try {
        $stmt = db()->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $u['id']]);
        return $cached = ((int) $stmt->fetchColumn() === 1);
    } catch (Throwable $e) {
        return $cached = false;
    }
}
}

if (!function_exists('admin_require')) {
/**
 * Page guard for the /admin area. Redirects anonymous users to login
 * (preserving the return path) and returns 403 for signed-in non-admins.
 * Returns the current user row on success.
 */
function admin_require(): array
{
    $user = auth_require(); // logged-in or redirect to /login.php
    if (!admin_is_current()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>403 Forbidden</title>'
           . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:480px;margin:80px auto;padding:0 20px;color:#e5e7eb;background:#0b0f17">'
           . '<h1 style="font-size:20px">403 &mdash; Admins only</h1>'
           . '<p>Your account doesn\'t have admin access.</p>'
           . '<p><a href="/dashboard.php" style="color:#60a5fa">&larr; Back to dashboard</a></p></div>';
        exit;
    }
    return $user;
}
}

if (!function_exists('admin_csrf_token')) {
/**
 * Per-session CSRF token for admin mutations (credit edits, etc.).
 * Stored in the admin's session row is overkill; a signed token derived
 * from the session cookie is enough and stateless.
 */
function admin_csrf_token(): string
{
    $cfg    = require __DIR__ . '/config.php';
    $cookie = (string) ($_COOKIE[$cfg['session']['cookie_name']] ?? '');
    return hash_hmac('sha256', 'admin-csrf', $cookie ?: 'no-session');
}
}

if (!function_exists('admin_csrf_check')) {
function admin_csrf_check(?string $token): bool
{
    return is_string($token) && hash_equals(admin_csrf_token(), $token);
}
}

if (!function_exists('admin_h')) {
/** htmlspecialchars shorthand for admin templates. */
function admin_h(string|int|float|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
}

if (!function_exists('admin_nav')) {
/** Sub-navigation strip shown at the top of every admin page. */
function admin_nav(string $active = ''): void
{
    $items = [
        'index'  => ['/admin/index.php',  'Overview'],
        'users'  => ['/admin/users.php',  'Users'],
        'topups' => ['/admin/topups.php', 'Top-ups & reconcile'],
    ];
    echo '<div class="container" style="padding-top:24px">';
    echo '<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;border-bottom:1px solid #1f2937;padding-bottom:12px;margin-bottom:24px">';
    echo '<strong style="font-size:18px">Admin</strong>';
    foreach ($items as $key => [$href, $label]) {
        $style = $key === $active
            ? 'color:#fff;font-weight:600'
            : 'color:#9ca3af';
        echo '<a href="' . admin_h($href) . '" style="' . $style . ';text-decoration:none">' . admin_h($label) . '</a>';
    }
    echo '</div></div>';
}
}
