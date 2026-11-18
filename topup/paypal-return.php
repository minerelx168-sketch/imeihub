<?php
declare(strict_types=1);

/**
 * PayPal return URL. The buyer hits this after approving the payment
 * on PayPal's checkout page. We:
 *   1. Look up the topup_order by the public_id in the query string.
 *   2. Call paypal_capture_order() on the saved provider_charge_id
 *      (PayPal order id) - this moves money and triggers the webhook.
 *   3. If capture status is COMPLETED, credit the wallet immediately
 *      (idempotent) so the user sees the new balance without waiting
 *      for the webhook.
 *
 * If anything fails here, the webhook is still our safety net.
 */

require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/credits_write.php';
require __DIR__ . '/../includes/paypal.php';

$user = auth_require();

$publicId = isset($_GET['id']) ? (string) $_GET['id'] : '';
$order    = null;
$captured = false;
$failed   = false;
$errorMsg = '';

if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)) {
    try {
        $stmt = db()->prepare(
            'SELECT * FROM topup_orders WHERE public_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$publicId, $user['id']]);
        $order = $stmt->fetch();
    } catch (Throwable $e) {
        // DB unavailable - render the pending state below.
    }
}

if ($order && in_array($order['status'], ['PENDING', 'PAID'], true) && !empty($order['provider_charge_id'])) {
    try {
        $resp = paypal_capture_order((string) $order['provider_charge_id']);
        $status = (string) ($resp['status'] ?? '');
        if ($status === 'COMPLETED') {
            credits_issue_topup($publicId);
            $captured = true;
            // Re-fetch order to show fresh status.
            $stmt = db()->prepare('SELECT * FROM topup_orders WHERE id = ?');
            $stmt->execute([$order['id']]);
            $order = $stmt->fetch();
        } else {
            $failed = true;
            $errorMsg = 'PayPal returned status: ' . $status;
        }
    } catch (Throwable $e) {
        $failed = true;
        $errorMsg = $e->getMessage();
    }
}

layout_head('Top-up status · imeihub', 'Your PayPal top-up is being processed.');
?>
    <section class="topup-shell">
        <div class="container container--narrow">
            <p class="breadcrumbs"><a href="/dashboard.php">Dashboard</a> &rsaquo; Top up &rsaquo; PayPal</p>

            <?php if (!$order): ?>
                <div class="topup-status topup-status--pending">
                    <h1>Order not found</h1>
                    <p>We couldn't find that top-up order. If you completed the payment, it'll still be credited via the PayPal webhook within a minute.</p>
                    <a href="/dashboard.php" class="btn-primary">Go to dashboard</a>
                </div>
            <?php elseif ($order['status'] === 'CREDITED'): ?>
                <div class="topup-status topup-status--credited" data-public-id="<?= htmlspecialchars($order['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <h1>Top-up successful</h1>
                    <p class="topup-amount">$<?= number_format((float) $order['amount'], 2) ?> added to your wallet.</p>
                    <p class="dashboard-subtitle">Credited <?= htmlspecialchars((string) $order['credited_at'], ENT_QUOTES, 'UTF-8') ?>.</p>
                    <a href="/dashboard.php" class="btn-primary">Go to dashboard</a>
                </div>
            <?php elseif ($failed): ?>
                <div class="topup-status topup-status--failed">
                    <h1>Payment failed</h1>
                    <p>PayPal couldn't capture the payment. No credit was added to your wallet.</p>
                    <?php if ($errorMsg !== ''): ?>
                        <p class="dashboard-subtitle"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <a href="/topup.php" class="btn-primary">Try again</a>
                </div>
            <?php else: ?>
                <div class="topup-status topup-status--pending" data-public-id="<?= htmlspecialchars($order['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <h1>Almost there&hellip;</h1>
                    <p>PayPal is confirming your payment. We'll credit $<?= number_format((float) $order['amount'], 2) ?> as soon as the confirmation arrives.</p>
                    <p class="dashboard-subtitle">This page refreshes automatically.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
    (function () {
        var box = document.querySelector('.topup-status[data-public-id]');
        if (!box) return;
        if (box.classList.contains('topup-status--credited')) return;
        if (box.classList.contains('topup-status--failed')) return;

        var publicId = box.getAttribute('data-public-id');
        var tries = 0, maxTries = 30;
        function poll() {
            tries += 1;
            fetch('/api/topup/status.php?id=' + encodeURIComponent(publicId), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.ok) return;
                    if (j.status === 'CREDITED' || j.status === 'FAILED') {
                        window.location.reload();
                    } else if (tries < maxTries) {
                        setTimeout(poll, 3000);
                    }
                })
                .catch(function () { if (tries < maxTries) setTimeout(poll, 5000); });
        }
        setTimeout(poll, 2000);
    })();
    </script>
<?php layout_foot(); ?>
