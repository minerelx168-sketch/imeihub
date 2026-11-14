<?php
declare(strict_types=1);

/**
 * Telegram bot webhook.
 *
 * Setup:
 *   1. Create a bot via @BotFather, copy the token into TELEGRAM_BOT_TOKEN.
 *   2. Pick any secret string, set TELEGRAM_WEBHOOK_SECRET.
 *   3. Tell Telegram where to deliver updates:
 *        curl -s "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://<your-domain>/api/webhooks/telegram.php?s=<TELEGRAM_WEBHOOK_SECRET>"
 *
 * The ?s=<secret> URL guard is our authentication - Telegram itself doesn't
 * sign requests. Anyone hitting this URL WITHOUT the right secret gets 401
 * before we touch the database.
 */

require __DIR__ . '/../../includes/bot.php';
require __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$cfg = require __DIR__ . '/../../includes/config.php';
$expectedSecret = (string) ($cfg['telegram']['webhook_secret'] ?? '');
// Prefer the header secret token (set it when registering the webhook with
// secret_token=...); it doesn't leak through access logs / Referer the way a
// ?s= query string can. Fall back to the query param for existing setups.
$gotSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ($_GET['s'] ?? ''));

if ($expectedSecret === '' || !hash_equals($expectedSecret, $gotSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$payload = (string) file_get_contents('php://input');
$event   = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'bad json']);
    exit;
}

// Persist for audit / replay (best effort).
try {
    db()->prepare(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_ok, raw_body)
         VALUES ("telegram", ?, ?, 1, ?)'
    )->execute([
        isset($event['update_id']) ? (string) $event['update_id'] : null,
        isset($event['message']) ? 'message' : (isset($event['edited_message']) ? 'edited_message' : 'other'),
        $payload,
    ]);
} catch (Throwable $e) {
    error_log('[telegram-webhook] log failed: ' . $e->getMessage());
}

// We only handle text messages right now. Ignore edits, callbacks, etc.
$msg = $event['message'] ?? null;
if (!$msg || !isset($msg['text'], $msg['chat']['id'])) {
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$chatId        = (string) $msg['chat']['id'];
$channelUserId = isset($msg['from']['id']) ? (string) $msg['from']['id'] : null;
$displayName   = trim(
    ($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? '')
) ?: ($msg['from']['username'] ?? null);
$text          = (string) $msg['text'];

try {
    $reply = bot_handle_message(BOT_CHANNEL_TELEGRAM, $chatId, $channelUserId, $displayName, $text);
    bot_send(BOT_CHANNEL_TELEGRAM, $chatId, $reply);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[telegram-webhook] handler failed: ' . $e->getMessage());
    bot_send(BOT_CHANNEL_TELEGRAM, $chatId, "⚠️ Something went wrong on our side. Please try again in a moment.");
    http_response_code(200); // Telegram retries on 5xx; we logged it, don't loop.
    echo json_encode(['ok' => false, 'error' => 'handler failed']);
}
