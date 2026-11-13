<?php
declare(strict_types=1);
/**
 * Cron worker: poll DHRU (async) orders that are still PROCESSING.
 *
 * Run every 30 seconds via cron or systemd timer:
 *   * * * * * php /var/www/html/scripts/poll-dhru-orders.php >> /var/log/imeihub/dhru-poll.log 2>&1
 *   * * * * * sleep 30 && php /var/www/html/scripts/poll-dhru-orders.php >> /var/log/imeihub/dhru-poll.log 2>&1
 *
 * Safety:
 *   - Only touches rows with status=PROCESSING and last_polled_at older than 8s.
 *   - Maximum 20 rows per sweep to avoid hammering the provider.
 *   - Rows older than 30 minutes are auto-refunded (provider timeout).
 */

// Load app bootstrap
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/imei_provider.php';
require __DIR__ . '/../includes/credits_write.php';
require __DIR__ . '/../includes/functions.php';

$maxRows       = 20;
$debounceS     = 8;
$timeoutMinutes = 30;

$pdo = db();

// Fetch PROCESSING rows that haven't been polled recently
$stmt = $pdo->prepare(
    "SELECT id, public_id, provider_order_id, user_id, service_code, cost,
            input, created_at, last_polled_at,
            TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_minutes
     FROM service_usages
     WHERE status = 'PROCESSING'
       AND provider_order_id IS NOT NULL
       AND (last_polled_at IS NULL OR last_polled_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
     ORDER BY last_polled_at ASC
     LIMIT ?"
);
$stmt->execute([$debounceS, $maxRows]);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    exit(0);
}

$ts = date('Y-m-d H:i:s');
echo "[{$ts}] Polling " . count($rows) . " PROCESSING orders...\n";

foreach ($rows as $row) {
    $publicId       = (string) $row['public_id'];
    $providerOrder  = (string) $row['provider_order_id'];
    $ageMinutes     = (int) $row['age_minutes'];

    // Auto-refund if older than timeout
    if ($ageMinutes >= $timeoutMinutes) {
        echo "  [{$publicId}] TIMEOUT ({$ageMinutes}m) - refunding\n";
        credits_refund_usage($publicId, "Provider timeout after {$ageMinutes} minutes");
        continue;
    }

    // Poll the provider
    try {
        $result = imei_provider_query_dhru($providerOrder);
    } catch (Throwable $e) {
        echo "  [{$publicId}] POLL ERROR: {$e->getMessage()}\n";
        credits_touch_usage_polled($publicId);
        continue;
    }

    $status = (string) ($result['status'] ?? '');

    if ($status === 'success') {
        echo "  [{$publicId}] SUCCESS\n";
        credits_mark_usage_success($publicId, [
            'brand'   => $result['brand'] ?? null,
            'model'   => $result['model'] ?? null,
            'details' => $result['details'] ?? [],
        ]);
    } elseif ($status === 'failed') {
        echo "  [{$publicId}] FAILED - refunding: {$result['error']}\n";
        credits_refund_usage($publicId, (string) ($result['error'] ?? 'DHRU returned failure'));
    } else {
        // Still processing
        credits_touch_usage_polled($publicId);
    }
}

echo "[{$ts}] Done.\n";
