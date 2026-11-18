<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credits.php';
require_once __DIR__ . '/credits_write.php';
require_once __DIR__ . '/imei_provider.php';
require_once __DIR__ . '/functions.php';

/**
 * Channel-agnostic bot library.
 *
 * The two supported channels are "telegram" and "line". This file
 * holds:
 *   - link-token lifecycle (create / consume / revoke)
 *   - chat -> user lookup
 *   - the text-command router that turns "imei 359..." into a paid
 *     /api/services/use-equivalent call
 *
 * Channel-specific senders live in includes/bot_telegram.php and
 * includes/bot_line.php and expose a single function each:
 *     telegram_send_text(string $chatId, string $text): bool
 *     line_send_text(string $chatId, string $text, ?string $replyToken = null): bool
 *
 * bot_send() picks the right one based on the channel string.
 */

const BOT_TOKEN_LIFETIME_MIN = 30;
const BOT_CHANNEL_TELEGRAM   = 'telegram';
const BOT_CHANNEL_LINE       = 'line';

/* =========================================================================
 *  Tokens
 * ========================================================================= */

if (!function_exists('bot_create_token')) {
function bot_create_token(int $userId): array
{
    // 10 hex chars - matches the Motalvip-style short token from the brief.
    // Loop on collision (vanishingly unlikely with 10 hex = 40 bits of entropy).
    $pdo = db();
    for ($i = 0; $i < 5; $i++) {
        $token = bin2hex(random_bytes(5));
        try {
            $pdo->prepare(
                'INSERT INTO bot_link_tokens (user_id, token, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
            )->execute([$userId, $token, BOT_TOKEN_LIFETIME_MIN]);

            $stmt = $pdo->prepare('SELECT * FROM bot_link_tokens WHERE id = ?');
            $stmt->execute([$pdo->lastInsertId()]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            if ($e->errorInfo[1] === 1062) continue; // duplicate token, retry
            throw $e;
        }
    }
    throw new RuntimeException('Could not generate a unique token after 5 tries.');
}
}

if (!function_exists('bot_list_tokens')) {
function bot_list_tokens(int $userId, int $limit = 20): array
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        "SELECT id, token, channel, consumed_at, expires_at, created_at,
                (expires_at <= NOW())  AS is_expired,
                (consumed_at IS NOT NULL) AS is_consumed
         FROM bot_link_tokens
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT $limit"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
}

if (!function_exists('bot_revoke_token')) {
function bot_revoke_token(int $userId, int $tokenId): bool
{
    $stmt = db()->prepare(
        'DELETE FROM bot_link_tokens
         WHERE id = ? AND user_id = ? AND consumed_at IS NULL'
    );
    $stmt->execute([$tokenId, $userId]);
    return $stmt->rowCount() > 0;
}
}

/**
 * Atomically consume a token: validate it, look up the issuing user,
 * upsert the bot_links row, mark the token consumed.
 *
 * Returns the resolved user_id on success. Throws on any failure path so
 * the webhook handler can surface a precise error to the chat.
 */
if (!function_exists('bot_consume_link_token')) {
function bot_consume_link_token(
    string $token,
    string $channel,
    string $chatId,
    ?string $channelUserId,
    ?string $displayName
): int {
    if (!preg_match('/^[a-f0-9]{10}$/i', $token)) {
        throw new RuntimeException('Invalid token format.');
    }
    $token = strtolower($token);
    if (!in_array($channel, [BOT_CHANNEL_TELEGRAM, BOT_CHANNEL_LINE], true)) {
        throw new RuntimeException('Unknown channel.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, consumed_at, expires_at
             FROM bot_link_tokens
             WHERE token = ?
             FOR UPDATE'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Token not found.');
        }
        if ($row['consumed_at'] !== null) {
            throw new RuntimeException('Token already used.');
        }
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            throw new RuntimeException('Token expired. Generate a new one in the dashboard.');
        }

        $userId = (int) $row['user_id'];

        // Upsert the link. If a link already exists for this chat the user
        // may have switched accounts - rebind to the new user.
        $pdo->prepare(
            'INSERT INTO bot_links
                (user_id, channel, channel_chat_id, channel_user_id, display_name)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id          = VALUES(user_id),
                channel_user_id  = VALUES(channel_user_id),
                display_name     = VALUES(display_name),
                last_used_at     = NOW()'
        )->execute([$userId, $channel, $chatId, $channelUserId, $displayName]);

        $pdo->prepare(
            'UPDATE bot_link_tokens
             SET consumed_at = NOW(), channel = ?
             WHERE id = ?'
        )->execute([$channel, $row['id']]);

        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
}

/* =========================================================================
 *  Link lookup + management
 * ========================================================================= */

if (!function_exists('bot_resolve_user')) {
function bot_resolve_user(string $channel, string $chatId): ?array
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return null;
    }
    // banned_at filter mirrors auth_user() so a banned account can't keep
    // spending through the bot. Wrapped so a pre-migration DB (no banned_at
    // column) still resolves links instead of erroring.
    try {
        $stmt = $pdo->prepare(
            'SELECT bl.id AS link_id, bl.user_id, bl.display_name,
                    u.email, u.name, u.cached_balance
             FROM bot_links bl
             JOIN users u ON u.id = bl.user_id
             WHERE bl.channel = ? AND bl.channel_chat_id = ? AND u.banned_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$channel, $chatId]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            'SELECT bl.id AS link_id, bl.user_id, bl.display_name,
                    u.email, u.name, u.cached_balance
             FROM bot_links bl
             JOIN users u ON u.id = bl.user_id
             WHERE bl.channel = ? AND bl.channel_chat_id = ?
             LIMIT 1'
        );
        $stmt->execute([$channel, $chatId]);
    }
    $row = $stmt->fetch();
    if (!$row) return null;
    // Touch last_used_at so admins can see active links.
    $pdo->prepare('UPDATE bot_links SET last_used_at = NOW() WHERE id = ?')
        ->execute([$row['link_id']]);
    return $row;
}
}

if (!function_exists('bot_list_links')) {
function bot_list_links(int $userId): array
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, channel, channel_chat_id, channel_user_id, display_name,
                created_at, last_used_at
         FROM bot_links WHERE user_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
}

if (!function_exists('bot_unlink')) {
function bot_unlink(int $userId, int $linkId): bool
{
    $stmt = db()->prepare('DELETE FROM bot_links WHERE id = ? AND user_id = ?');
    $stmt->execute([$linkId, $userId]);
    return $stmt->rowCount() > 0;
}
}

/* =========================================================================
 *  Sending
 * ========================================================================= */

if (!function_exists('bot_send')) {
/**
 * Channel-aware text sender. Returns true on success.
 *
 * $reply is opaque to the caller: Telegram ignores it, LINE uses it as
 * the reply token (which only works once per inbound event). When the
 * reply token has been used or is unavailable, we fall back to LINE's
 * push API.
 */
function bot_send(string $channel, string $chatId, string $text, ?string $reply = null): bool
{
    if ($channel === BOT_CHANNEL_TELEGRAM) {
        require_once __DIR__ . '/bot_telegram.php';
        return telegram_send_text($chatId, $text);
    }
    if ($channel === BOT_CHANNEL_LINE) {
        require_once __DIR__ . '/bot_line.php';
        return line_send_text($chatId, $text, $reply);
    }
    return false;
}
}

/* =========================================================================
 *  Message router
 * ========================================================================= */

if (!function_exists('bot_handle_message')) {
/**
 * Routes a single inbound text message to the right handler.
 *
 * Recognised commands:
 *     token XXXXXXXXXX      bind this chat to the user that issued the token
 *     help, /help, /start   help text
 *     services              list available service codes + prices
 *     balance               current credit balance (linked users only)
 *     <imei>                free local IMEI_BASIC lookup
 *     <code> <imei>         paid lookup with service code, deducts credit
 *
 * Reply text is returned for the caller to send back through bot_send().
 */
function bot_handle_message(
    string $channel,
    string $chatId,
    ?string $channelUserId,
    ?string $displayName,
    string $text
): string {
    $text = trim($text);
    $lc   = strtolower($text);

    // 1. token linking
    if (preg_match('/^token\s+([a-f0-9]{10})$/i', $text, $m)) {
        try {
            $userId = bot_consume_link_token($m[1], $channel, $chatId, $channelUserId, $displayName);
            $stmt = db()->prepare('SELECT email, name FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $u = $stmt->fetch();
            $who = $u['name'] ?: $u['email'];
            return "✅ Linked! You're signed in as $who.\n\nSend an IMEI to try a free check, or type 'services' to see the menu.";
        } catch (Throwable $e) {
            return "❌ " . $e->getMessage();
        }
    }

    // 2. help / start
    if (in_array($lc, ['help', '/help', '/start', 'start'], true)) {
        return bot_message_help();
    }

    // 3. authenticated commands need a link
    $userRow = bot_resolve_user($channel, $chatId);

    // 4. services menu (works for everyone)
    if (in_array($lc, ['services', '/services', 'menu'], true)) {
        return bot_message_services();
    }

    // 5. balance
    if (in_array($lc, ['balance', '/balance', 'credit'], true)) {
        if (!$userRow) return bot_message_unlinked();
        return "💰 Balance: " . credits_format_usd($userRow['cached_balance']);
    }

    // 6. "<code> <imei>" — paid lookup
    if (preg_match('/^([A-Z][A-Z0-9_]{1,31})\s+(\d{14,17})$/i', $text, $m)) {
        $code = strtoupper($m[1]);
        $imei = imei_normalize($m[2]);
        if (!$userRow) return bot_message_unlinked();
        if (!imei_is_valid($imei)) return "❌ Invalid IMEI. Use 15 digits.";
        return bot_run_lookup((int) $userRow['user_id'], $code, $imei);
    }

    // 7. Bare IMEI - free IMEI_BASIC lookup (anyone, linked or not)
    if (preg_match('/^\d{14,17}$/', $text)) {
        $imei = imei_normalize($text);
        if (!imei_is_valid($imei)) return "❌ Invalid IMEI. Use 15 digits.";
        return bot_run_lookup($userRow ? (int) $userRow['user_id'] : 0, 'IMEI_BASIC', $imei);
    }

    return "🤔 Sorry, I didn't understand that.\n\n" . bot_message_help();
}
}

if (!function_exists('bot_message_help')) {
function bot_message_help(): string
{
    return "👋 *imeihub bot*\n"
        . "\n"
        . "Commands:\n"
        . "  token XXXXXXXXXX   – link this chat to your imeihub account\n"
        . "  <imei>             – free check (brand, model, basic specs)\n"
        . "  <code> <imei>      – paid check (deducts credit)\n"
        . "  services           – list service codes + prices\n"
        . "  balance            – your credit balance\n"
        . "  help               – this message\n"
        . "\n"
        . "Examples:\n"
        . "  359152060000003\n"
        . "  blacklist_full 359152060000003\n"
        . "  apple_icloud_clean 359152060000003\n";
}
}

if (!function_exists('bot_message_unlinked')) {
function bot_message_unlinked(): string
{
    return "🔑 This chat is not linked to an account yet.\n"
        . "\n"
        . "Go to imeihub → Settings → Bot, click \"Create token\", then send:\n"
        . "  token XXXXXXXXXX";
}
}

if (!function_exists('bot_message_services')) {
function bot_message_services(): string
{
    try {
        $pdo = db();
        $stmt = $pdo->query(
            'SELECT code, name, cost FROM service_prices
             WHERE active = 1
             ORDER BY cost ASC, name ASC
             LIMIT 50'
        );
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return "Services list unavailable right now.";
    }

    if (!$rows) return "No services configured.";

    $out = "📋 Available checks:\n";
    foreach ($rows as $r) {
        $price = (float) $r['cost'] === 0.0 ? 'FREE' : '$' . number_format((float) $r['cost'], 2);
        $code  = strtolower($r['code']);
        $out .= sprintf("\n%s  %s  – %s", str_pad($price, 8), $code, $r['name']);
    }
    $out .= "\n\nUsage:  <code> <imei>";
    return $out;
}
}

if (!function_exists('bot_run_lookup')) {
/**
 * Run an IMEI lookup as if it had come from the web UI. For paid
 * services the user must be linked; for IMEI_BASIC anyone can run it.
 */
function bot_run_lookup(int $userId, string $code, string $imei): string
{
    $code = strtoupper($code);

    // Free local TAC lookup - no DB write, no provider call.
    if ($code === 'IMEI_BASIC') {
        require_once __DIR__ . '/imei_demo.php';
        $r = imei_demo_lookup($imei, '0');
        return bot_format_result($r, 'Free IMEI Check', '0.00');
    }

    if ($userId === 0) {
        return bot_message_unlinked();
    }

    // Verify the code exists + active before deducting.
    $stmt = db()->prepare('SELECT name, cost, active FROM service_prices WHERE code = ?');
    $stmt->execute([$code]);
    $svc = $stmt->fetch();
    if (!$svc || (int) $svc['active'] !== 1) {
        return "❌ Unknown service code: $code\nType 'services' to see the menu.";
    }

    // Per-user rate limit, mirroring the web /api/services/use.php guard, so a
    // linked chat can't hammer the upstream provider.
    if (!rate_limit_allow('bot:' . $userId, 30)) {
        return "⏳ Too many requests. Please wait a minute and try again.";
    }

    try {
        $usage = credits_deduct($userId, $code, ['imei' => $imei, 'source' => 'bot']);
    } catch (InsufficientCreditError $e) {
        return "❌ Not enough credit.\n" . $e->getMessage() . "\nTop up at imeihub.com/topup";
    } catch (Throwable $e) {
        return "❌ Could not start lookup: " . $e->getMessage();
    }

    // Provider dispatch.
    $serviceMap        = require __DIR__ . '/../data/service_provider_map.php';
    $providerServiceId = $serviceMap[$code] ?? null;
    try {
        if ($providerServiceId === null) {
            require_once __DIR__ . '/imei_demo.php';
            $result = imei_demo_lookup($imei, '0');
        } else {
            $result = imei_provider_lookup($imei, (string) $providerServiceId);
        }
    } catch (Throwable $e) {
        credits_refund_usage($usage['public_id'], 'bot: provider exception: ' . $e->getMessage());
        return "❌ Provider failed — your credit was refunded automatically.";
    }

    if (($result['status'] ?? '') !== 'success') {
        credits_refund_usage($usage['public_id'], 'bot: ' . ($result['error'] ?? 'provider failure'));
        return "❌ " . ($result['error'] ?? 'Lookup failed') . " — your credit was refunded.";
    }

    credits_mark_usage_success($usage['public_id'], [
        'brand'   => $result['brand']   ?? null,
        'model'   => $result['model']   ?? null,
        'details' => $result['details'] ?? [],
    ]);

    return bot_format_result($result, (string) $svc['name'], (string) $svc['cost']);
}
}

if (!function_exists('bot_format_result')) {
function bot_format_result(array $r, string $serviceName, string $cost): string
{
    $brand = $r['brand'] ?? 'Unknown';
    $model = $r['model'] ?? 'GSM Phone';
    $costNum = (float) $cost;
    $costLine = $costNum === 0.0 ? '— FREE —' : sprintf('Cost: $%.2f', $costNum);

    $out  = "🔍 $serviceName\n";
    $out .= "$brand $model\n";
    $out .= "$costLine\n\n";

    foreach (($r['details'] ?? []) as $k => $v) {
        if (is_scalar($v) && $v !== '' && !in_array($k, ['Brand Name', 'Model Name'], true)) {
            $out .= "• " . $k . ': ' . $v . "\n";
        }
    }
    return $out;
}
}
