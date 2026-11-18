<?php
declare(strict_types=1);

/**
 * LINE Messaging API webhook.
 *
 * Setup:
 *   1. LINE Developers Console -> Create Messaging API channel
 *   2. Copy "Channel secret" into LINE_CHANNEL_SECRET
 *   3. Copy "Channel access token" into LINE_CHANNEL_ACCESS_TOKEN
 *   4. Webhook URL: https://<your-domain>/api/webhooks/line.php
 *   5. Enable "Use webhook" + disable "Auto-reply messages"
 *   6. Add the bot's QR code or LINE ID to the dashboard so shops can scan it.
 *
 * Security: LINE signs every request with HMAC-SHA256 over the raw body.
 * If the X-Line-Signature header is missing or wrong we reject with 401
 * before touching anything else.
 */

require __DIR__ . '/../../includes/bot.php';
require __DIR__ . '/../../includes/bot_line.php';
require __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$cfg     = require __DIR__ . '/../../includes/config.php';
$secret  = (string) ($cfg['line']['channel_secret'] ?? '');
$payload = (string) file_get_contents('php://input');
$sig     = (string) ($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');

if (!line_verify_signature($payload, $sig, $secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'signature failed']);
    exit;
}

$batch = json_decode($payload, true);
if (!is_array($batch) || !isset($batch['events']) || !is_array($batch['events'])) {
    // LINE pings webhooks with empty events array during setup verification.
    echo json_encode(['ok' => true]);
    exit;
}

// Persist the raw batch for audit. LINE doesn't sign a single id at the
// batch level, but the first event's webhookEventId is unique per delivery
// and is the natural dedup key. INSERT IGNORE means a retried delivery
// just no-ops on the log.
$firstEventId = $batch['events'][0]['webhookEventId'] ?? null;
try {
    db()->prepare(
        'INSERT IGNORE INTO webhook_events (provider, event_id, event_type, signature_ok, raw_body)
         VALUES ("line", ?, "batch", 1, ?)'
    )->execute([$firstEventId, $payload]);
} catch (Throwable $e) {
    error_log('[line-webhook] log failed: ' . $e->getMessage());
}

$handled = 0;
foreach ($batch['events'] as $event) {
    $type       = $event['type']       ?? '';
    $replyToken = $event['replyToken'] ?? null;
    $src        = $event['source']     ?? [];
    // For a 1-to-1 chat LINE gives us userId; for groups/rooms we get groupId/roomId.
    $chatId = $src['userId'] ?? $src['groupId'] ?? $src['roomId'] ?? null;
    if (!$chatId) continue;

    if ($type === 'follow') {
        bot_send(BOT_CHANNEL_LINE, $chatId, bot_message_help(), $replyToken);
        $handled++;
        continue;
    }

    if ($type !== 'message' || ($event['message']['type'] ?? '') !== 'text') {
        continue;
    }

    $text = (string) ($event['message']['text'] ?? '');
    $userId = $src['userId'] ?? null;

    // We don't get a display name in the webhook payload by default; LINE
    // exposes it via a separate profile API call we can wire up later.
    $displayName = null;

    try {
        $reply = bot_handle_message(BOT_CHANNEL_LINE, (string) $chatId, $userId, $displayName, $text);
        bot_send(BOT_CHANNEL_LINE, (string) $chatId, $reply, $replyToken);
        $handled++;
    } catch (Throwable $e) {
        error_log('[line-webhook] handler failed: ' . $e->getMessage());
        bot_send(BOT_CHANNEL_LINE, (string) $chatId, "⚠️ Something went wrong on our side. Please try again in a moment.", $replyToken);
    }
}

echo json_encode(['ok' => true, 'handled' => $handled]);
