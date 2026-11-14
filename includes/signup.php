<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/resend.php';

/**
 * Email + password signup with Resend-delivered OTP.
 *
 * Sequence:
 *   1. signup_create_pending_user($email, $password)
 *        - INSERT users row (email_verified = NULL, password_hash = bcrypt)
 *        - generate 6-digit OTP, store SHA-256(otp) in email_verifications
 *        - send the OTP via Resend
 *   2. signup_verify_otp($email, $otp)
 *        - find latest unconsumed row, compare hash
 *        - on match: mark email_verified + consumed_at, return user_id
 *        - on miss : bump attempts, 5 strikes = consume + force re-send
 *   3. signup_resend_otp($email)
 *        - rate-limited (max 3 sends per 5 minutes per email)
 *        - invalidates older rows, creates+sends a new one
 *
 * Login (signup_login_with_password) is the inverse - verifies the
 * bcrypt hash, refuses to authenticate unverified accounts.
 *
 * Errors are thrown as RuntimeException with user-safe messages; the
 * route handler is expected to catch and forward to the client.
 */

const SIGNUP_OTP_TTL_MIN     = 10;
const SIGNUP_OTP_MAX_ATTEMPTS = 5;
const SIGNUP_RESEND_WINDOW_S  = 300;   // 5 minutes
const SIGNUP_RESEND_MAX_PER_WINDOW = 3;

if (!function_exists('signup_normalize_email')) {
function signup_normalize_email(string $email): string
{
    return strtolower(trim($email));
}
}

if (!function_exists('signup_password_is_strong_enough')) {
function signup_password_is_strong_enough(string $password): bool
{
    // Minimum 8 characters. Could tighten further but this matches most
    // expectations and doesn't hurt UX for casual users.
    return strlen($password) >= 8;
}
}

if (!function_exists('signup_find_user_by_email')) {
function signup_find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([signup_normalize_email($email)]);
    return $stmt->fetch() ?: null;
}
}

if (!function_exists('signup_send_otp_email')) {
function signup_send_otp_email(string $to, string $otp): bool
{
    $cfg     = require __DIR__ . '/config.php';
    $appName = htmlspecialchars($cfg['app']['name'], ENT_QUOTES, 'UTF-8');
    $appUrl  = htmlspecialchars($cfg['app']['url'],  ENT_QUOTES, 'UTF-8');
    $otpSafe = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f6f8fc;padding:32px 16px;">
  <table align="center" cellspacing="0" cellpadding="0" style="background:#fff;border:1px solid #e5e9f0;border-radius:12px;max-width:520px;width:100%;">
    <tr><td style="padding:32px 36px 8px 36px;">
      <h2 style="margin:0 0 8px;font-size:1.4rem;color:#0b1220;letter-spacing:-.01em;">Verify your email</h2>
      <p style="margin:0 0 24px;color:#475569;line-height:1.6;">
        Welcome to <strong>{$appName}</strong>! Enter the code below to
        finish creating your account. It expires in 10 minutes.
      </p>
      <div style="background:#f6f8fc;border:1px solid #e5e9f0;border-radius:10px;text-align:center;padding:22px;">
        <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:2rem;letter-spacing:.4em;font-weight:700;color:#050818;">{$otpSafe}</div>
      </div>
      <p style="margin:24px 0 0;color:#64748b;font-size:.9rem;line-height:1.6;">
        Didn't request this? You can safely ignore this email; the
        account stays inactive until the code is entered.
      </p>
    </td></tr>
    <tr><td style="padding:16px 36px 28px;border-top:1px solid #eef2f7;color:#94a3b8;font-size:.82rem;">
      Sent by {$appName} &middot; <a href="{$appUrl}" style="color:#4f46e5;text-decoration:none;">{$appUrl}</a>
    </td></tr>
  </table>
</div>
HTML;

    $text = "Your {$appName} verification code is: {$otp}\n\n"
          . "It expires in 10 minutes. If you didn't request this, you can ignore the email.";

    return resend_send_email($to, "Your {$appName} verification code: {$otp}", $html, $text);
}
}

if (!function_exists('signup_issue_otp')) {
/**
 * Create + persist an OTP row for $userId and return the (plaintext) code
 * so the caller can email it. Caller is responsible for the actual send.
 */
function signup_issue_otp(int $userId, string $purpose = 'signup'): string
{
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $otp);
    db()->prepare(
        'INSERT INTO email_verifications
            (user_id, code_hash, purpose, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
    )->execute([$userId, $hash, $purpose, SIGNUP_OTP_TTL_MIN]);
    return $otp;
}
}

if (!function_exists('signup_create_pending_user')) {
/**
 * Either creates a fresh unverified user OR re-uses an existing
 * unverified row (so users can re-trigger signup from the same email).
 *
 * If a verified user with this email already exists, throws.
 */
function signup_create_pending_user(string $email, string $password): array
{
    $email = signup_normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please enter a valid email address.');
    }
    if (!signup_password_is_strong_enough($password)) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = signup_find_user_by_email($email);
        if ($existing) {
            if ($existing['email_verified'] !== null && $existing['password_hash']) {
                throw new RuntimeException('An account with this email already exists. Try signing in instead.');
            }
            // Unverified row OR OAuth-only row that's adding a password - update.
            $pdo->prepare(
                'UPDATE users SET password_hash = ? WHERE id = ?'
            )->execute([password_hash($password, PASSWORD_BCRYPT), $existing['id']]);
            $userId = (int) $existing['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO users (email, password_hash) VALUES (?, ?)'
            )->execute([$email, password_hash($password, PASSWORD_BCRYPT)]);
            $userId = (int) $pdo->lastInsertId();
        }

        // Throttle: max N sends per window.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM email_verifications
             WHERE user_id = ? AND purpose = "signup"
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$userId, SIGNUP_RESEND_WINDOW_S]);
        if ((int) $stmt->fetchColumn() >= SIGNUP_RESEND_MAX_PER_WINDOW) {
            throw new RuntimeException('Too many codes sent. Please wait a few minutes before trying again.');
        }

        $otp = signup_issue_otp($userId, 'signup');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    signup_send_otp_email($email, $otp);
    return ['user_id' => $userId, 'email' => $email];
}
}

if (!function_exists('signup_verify_otp')) {
/**
 * Compares the user's OTP against the latest unconsumed row for that email.
 * On success: marks email_verified, marks the OTP row consumed, returns the
 *             user row.
 * On failure: bumps attempts; after MAX_ATTEMPTS the row is consumed so the
 *             user must request a fresh code.
 */
function signup_verify_otp(string $email, string $otp): array
{
    $email = signup_normalize_email($email);
    if (!preg_match('/^\d{6}$/', $otp)) {
        throw new RuntimeException('Code must be 6 digits.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT u.id AS user_id, ev.id AS ev_id, ev.code_hash, ev.attempts, ev.expires_at
             FROM users u
             LEFT JOIN email_verifications ev
               ON ev.user_id = u.id
              AND ev.purpose = "signup"
              AND ev.consumed_at IS NULL
             WHERE u.email = ?
             ORDER BY ev.id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !$row['ev_id']) {
            throw new RuntimeException('No active code for this email. Request a new one.');
        }
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            $pdo->prepare('UPDATE email_verifications SET consumed_at = NOW() WHERE id = ?')
                ->execute([$row['ev_id']]);
            throw new RuntimeException('Code expired. Request a new one.');
        }

        $hash = hash('sha256', $otp);
        if (!hash_equals($row['code_hash'], $hash)) {
            $newAttempts = (int) $row['attempts'] + 1;
            if ($newAttempts >= SIGNUP_OTP_MAX_ATTEMPTS) {
                $pdo->prepare(
                    'UPDATE email_verifications SET attempts = ?, consumed_at = NOW() WHERE id = ?'
                )->execute([$newAttempts, $row['ev_id']]);
                $pdo->commit();
                throw new RuntimeException('Too many wrong attempts. Request a new code.');
            }
            $pdo->prepare('UPDATE email_verifications SET attempts = ? WHERE id = ?')
                ->execute([$newAttempts, $row['ev_id']]);
            $pdo->commit();
            throw new RuntimeException('Incorrect code.');
        }

        $pdo->prepare(
            'UPDATE email_verifications SET consumed_at = NOW(), attempts = attempts + 1 WHERE id = ?'
        )->execute([$row['ev_id']]);
        $pdo->prepare(
            'UPDATE users SET email_verified = COALESCE(email_verified, NOW()) WHERE id = ?'
        )->execute([$row['user_id']]);

        $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $userStmt->execute([$row['user_id']]);
        $user = $userStmt->fetch();
        $pdo->commit();
        return $user;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}

if (!function_exists('signup_resend_otp')) {
/**
 * Send a fresh OTP. Throttled per email + window.
 */
function signup_resend_otp(string $email): void
{
    $email = signup_normalize_email($email);
    $user  = signup_find_user_by_email($email);
    if (!$user) {
        // Don't leak which emails exist - act as if it worked.
        return;
    }
    if ($user['email_verified'] !== null) {
        throw new RuntimeException('This email is already verified. Try signing in.');
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM email_verifications
         WHERE user_id = ? AND purpose = "signup"
           AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $stmt->execute([$user['id'], SIGNUP_RESEND_WINDOW_S]);
    if ((int) $stmt->fetchColumn() >= SIGNUP_RESEND_MAX_PER_WINDOW) {
        throw new RuntimeException('Too many codes sent. Please wait a few minutes.');
    }

    $otp = signup_issue_otp((int) $user['id'], 'signup');
    signup_send_otp_email($email, $otp);
}
}

if (!function_exists('signup_login_with_password')) {
/**
 * Verify email + password. Returns the user row on success or throws on
 * failure. Returns the SAME generic error for "no such user" and "wrong
 * password" so we don't leak which emails are registered.
 */
function signup_login_with_password(string $email, string $password): array
{
    $email = signup_normalize_email($email);
    $user  = signup_find_user_by_email($email);

    $generic = 'Incorrect email or password.';
    if (!$user || !$user['password_hash']) {
        // Run a fake bcrypt verify to keep response times constant.
        password_verify($password, '$2y$10$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        throw new RuntimeException($generic);
    }
    if (!password_verify($password, $user['password_hash'])) {
        throw new RuntimeException($generic);
    }
    if ($user['email_verified'] === null) {
        throw new RuntimeException('Please verify your email first. Check your inbox for the 6-digit code.');
    }
    return $user;
}
}

if (!function_exists('signup_send_reset_email')) {
function signup_send_reset_email(string $to, string $otp): bool
{
    $cfg     = require __DIR__ . '/config.php';
    $appName = htmlspecialchars($cfg['app']['name'], ENT_QUOTES, 'UTF-8');
    $appUrl  = htmlspecialchars($cfg['app']['url'],  ENT_QUOTES, 'UTF-8');
    $otpSafe = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:#f6f8fc;padding:32px 16px;">
  <table align="center" cellspacing="0" cellpadding="0" style="background:#fff;border:1px solid #e5e9f0;border-radius:12px;max-width:520px;width:100%;">
    <tr><td style="padding:32px 36px 8px 36px;">
      <h2 style="margin:0 0 8px;font-size:1.4rem;color:#0b1220;letter-spacing:-.01em;">Reset your password</h2>
      <p style="margin:0 0 24px;color:#475569;line-height:1.6;">
        We received a request to reset your <strong>{$appName}</strong>
        password. Enter the code below to set a new one. It expires in 10 minutes.
      </p>
      <div style="background:#f6f8fc;border:1px solid #e5e9f0;border-radius:10px;text-align:center;padding:22px;">
        <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:2rem;letter-spacing:.4em;font-weight:700;color:#050818;">{$otpSafe}</div>
      </div>
      <p style="margin:24px 0 0;color:#64748b;font-size:.9rem;line-height:1.6;">
        Didn't request this? You can safely ignore this email &mdash; your
        password stays unchanged.
      </p>
    </td></tr>
    <tr><td style="padding:16px 36px 28px;border-top:1px solid #eef2f7;color:#94a3b8;font-size:.82rem;">
      Sent by {$appName} &middot; <a href="{$appUrl}" style="color:#4f46e5;text-decoration:none;">{$appUrl}</a>
    </td></tr>
  </table>
</div>
HTML;

    $text = "Your {$appName} password reset code is: {$otp}\n\n"
          . "It expires in 10 minutes. If you didn't request this, you can ignore the email.";

    return resend_send_email($to, "Your {$appName} password reset code: {$otp}", $html, $text);
}
}

if (!function_exists('signup_request_reset')) {
/**
 * Send a password-reset OTP IF a password account exists for the email.
 * Silent for unknown / OAuth-only emails (no enumeration). Throttled.
 */
function signup_request_reset(string $email): void
{
    $email = signup_normalize_email($email);
    $user  = signup_find_user_by_email($email);
    if (!$user || empty($user['password_hash'])) {
        return; // unknown or OAuth-only: act as if it worked
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM email_verifications
         WHERE user_id = ? AND purpose = "reset"
           AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
    );
    $stmt->execute([(int) $user['id'], SIGNUP_RESEND_WINDOW_S]);
    if ((int) $stmt->fetchColumn() >= SIGNUP_RESEND_MAX_PER_WINDOW) {
        throw new RuntimeException('Too many reset codes sent. Please wait a few minutes.');
    }
    $otp = signup_issue_otp((int) $user['id'], 'reset');
    signup_send_reset_email($email, $otp);
}
}

if (!function_exists('signup_reset_password')) {
/**
 * Verify a 'reset' OTP and set a new password. Revokes existing sessions.
 */
function signup_reset_password(string $email, string $otp, string $newPassword): void
{
    $email = signup_normalize_email($email);
    if (!preg_match('/^\d{6}$/', $otp)) {
        throw new RuntimeException('Code must be 6 digits.');
    }
    if (!signup_password_is_strong_enough($newPassword)) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT u.id AS user_id, ev.id AS ev_id, ev.code_hash, ev.attempts, ev.expires_at
             FROM users u
             LEFT JOIN email_verifications ev
               ON ev.user_id = u.id AND ev.purpose = "reset" AND ev.consumed_at IS NULL
             WHERE u.email = ?
             ORDER BY ev.id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !$row['ev_id']) {
            throw new RuntimeException('No active reset code for this email. Request a new one.');
        }
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            $pdo->prepare('UPDATE email_verifications SET consumed_at = NOW() WHERE id = ?')->execute([$row['ev_id']]);
            throw new RuntimeException('Code expired. Request a new one.');
        }
        if (!hash_equals($row['code_hash'], hash('sha256', $otp))) {
            $newAttempts = (int) $row['attempts'] + 1;
            if ($newAttempts >= SIGNUP_OTP_MAX_ATTEMPTS) {
                $pdo->prepare('UPDATE email_verifications SET attempts = ?, consumed_at = NOW() WHERE id = ?')->execute([$newAttempts, $row['ev_id']]);
                $pdo->commit();
                throw new RuntimeException('Too many wrong attempts. Request a new code.');
            }
            $pdo->prepare('UPDATE email_verifications SET attempts = ? WHERE id = ?')->execute([$newAttempts, $row['ev_id']]);
            $pdo->commit();
            throw new RuntimeException('Incorrect code.');
        }
        // Success: consume code, set new password, ensure verified, revoke sessions.
        $pdo->prepare('UPDATE email_verifications SET consumed_at = NOW(), attempts = attempts + 1 WHERE id = ?')->execute([$row['ev_id']]);
        $pdo->prepare('UPDATE users SET password_hash = ?, email_verified = COALESCE(email_verified, NOW()) WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $row['user_id']]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$row['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
}
