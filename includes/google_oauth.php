<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Minimal Google OAuth 2.0 client (Authorization Code flow).
 *
 * We deliberately avoid the google/auth SDK to keep dependencies zero.
 * Scope is limited to "openid email profile" - enough to identify the user.
 *
 * Spec we follow:
 *   https://developers.google.com/identity/protocols/oauth2/web-server
 */

const GOOGLE_AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL    = 'https://oauth2.googleapis.com/token';
const GOOGLE_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

if (!function_exists('google_oauth_redirect_uri')) {
function google_oauth_redirect_uri(): string
{
    $cfg = require __DIR__ . '/config.php';
    return $cfg['app']['url'] . '/api/auth/google/callback.php';
}
}

if (!function_exists('google_oauth_authorize_url')) {
/**
 * Persist a fresh state token, return the URL to send the user to.
 */
function google_oauth_authorize_url(?string $redirectTo = null): string
{
    $cfg = require __DIR__ . '/config.php';
    if ($cfg['google']['client_id'] === '') {
        throw new RuntimeException('Google OAuth not configured. Set GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET in .env.');
    }

    $state = auth_random_id();
    $nonce = auth_random_id();

    db()->prepare(
        'INSERT INTO oauth_states (state, nonce, redirect_to) VALUES (?, ?, ?)'
    )->execute([$state, $nonce, $redirectTo]);

    $params = [
        'client_id'     => $cfg['google']['client_id'],
        'redirect_uri'  => google_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'nonce'         => $nonce,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];

    return GOOGLE_AUTH_URL . '?' . http_build_query($params);
}
}

if (!function_exists('google_oauth_consume_state')) {
/**
 * Validate the state returned by Google. Returns the stored row or throws.
 * Single-use: the row is deleted after consumption.
 */
function google_oauth_consume_state(string $state): array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $state)) {
        throw new RuntimeException('Invalid state token.');
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT state, nonce, redirect_to FROM oauth_states
         WHERE state = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([$state]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('State expired or unknown. Please try signing in again.');
    }
    $pdo->prepare('DELETE FROM oauth_states WHERE state = ?')->execute([$state]);

    // Opportunistic GC of stale state rows.
    $pdo->exec('DELETE FROM oauth_states WHERE created_at < (NOW() - INTERVAL 30 MINUTE)');

    return $row;
}
}

if (!function_exists('google_oauth_exchange_code')) {
/**
 * Trade an authorization code for an access/id token.
 */
function google_oauth_exchange_code(string $code): array
{
    $cfg = require __DIR__ . '/config.php';

    $body = http_build_query([
        'code'          => $code,
        'client_id'     => $cfg['google']['client_id'],
        'client_secret' => $cfg['google']['client_secret'],
        'redirect_uri'  => google_oauth_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);

    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub/1.0',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Token exchange failed: ' . $err);
    }
    $decoded = json_decode((string) $resp, true);
    if (!is_array($decoded) || $code >= 400 || !isset($decoded['access_token'])) {
        $msg = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? 'unknown') : 'parse error';
        throw new RuntimeException('Token exchange rejected: ' . $msg);
    }
    return $decoded;
}
}

if (!function_exists('google_oauth_fetch_userinfo')) {
function google_oauth_fetch_userinfo(string $accessToken): array
{
    $ch = curl_init(GOOGLE_USERINFO_URL);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'imeihub/1.0',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        throw new RuntimeException('userinfo fetch failed: ' . ($err ?: ('HTTP ' . $code)));
    }
    $info = json_decode((string) $resp, true);
    if (!is_array($info) || empty($info['sub']) || empty($info['email'])) {
        throw new RuntimeException('userinfo response missing sub or email');
    }
    return $info;
}
}

if (!function_exists('google_oauth_login_or_signup')) {
/**
 * Given Google userinfo + tokens, find or create the matching local user and
 * upsert the oauth_accounts row. Returns the user id.
 *
 * Wrapped in a DB transaction so the user + oauth_account row are atomic.
 */
function google_oauth_login_or_signup(array $userinfo, array $tokens): int
{
    if (isset($userinfo['email_verified']) && $userinfo['email_verified'] !== true && $userinfo['email_verified'] !== 'true') {
        throw new RuntimeException('Google account email is not verified - please verify it before signing in.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sub   = (string) $userinfo['sub'];
        $email = strtolower(trim((string) $userinfo['email']));
        $name  = isset($userinfo['name']) ? (string) $userinfo['name'] : null;
        $image = isset($userinfo['picture']) ? (string) $userinfo['picture'] : null;

        // Match by provider+sub first (Google's stable identifier).
        $stmt = $pdo->prepare(
            'SELECT user_id FROM oauth_accounts WHERE provider = "google" AND provider_account_id = ? LIMIT 1'
        );
        $stmt->execute([$sub]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            // Fall back to matching by email; otherwise create.
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $userId = $stmt->fetchColumn();

            if (!$userId) {
                $pdo->prepare(
                    'INSERT INTO users (email, name, image, email_verified)
                     VALUES (?, ?, ?, NOW())'
                )->execute([$email, $name, $image]);
                $userId = (int) $pdo->lastInsertId();
            } else {
                // Existing user signed in with a new Google account - update profile if missing.
                $pdo->prepare(
                    'UPDATE users SET
                        name = COALESCE(NULLIF(?, ""), name),
                        image = COALESCE(NULLIF(?, ""), image),
                        email_verified = COALESCE(email_verified, NOW())
                     WHERE id = ?'
                )->execute([$name, $image, $userId]);
            }
        } else {
            $pdo->prepare(
                'UPDATE users SET
                    name = COALESCE(NULLIF(?, ""), name),
                    image = COALESCE(NULLIF(?, ""), image)
                 WHERE id = ?'
            )->execute([$name, $image, $userId]);
        }

        $pdo->prepare(
            'INSERT INTO oauth_accounts
                (user_id, provider, provider_account_id, access_token, refresh_token, expires_at, token_type, scope, id_token)
             VALUES (?, "google", ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id      = VALUES(user_id),
                access_token = VALUES(access_token),
                refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
                expires_at   = VALUES(expires_at),
                token_type   = VALUES(token_type),
                scope        = VALUES(scope),
                id_token     = VALUES(id_token)'
        )->execute([
            $userId,
            $sub,
            $tokens['access_token']  ?? null,
            $tokens['refresh_token'] ?? null,
            isset($tokens['expires_in']) ? time() + (int) $tokens['expires_in'] : null,
            $tokens['token_type']    ?? null,
            $tokens['scope']         ?? null,
            $tokens['id_token']      ?? null,
        ]);

        $pdo->commit();
        return (int) $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
}
