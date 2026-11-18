<?php
declare(strict_types=1);

/**
 * Binance Pay QR / payment instructions page.
 *
 * Receives the user after they POST to /api/topup/create.php with
 * method=binancepay. Looks up the order, fetches the QR/universal
 * URL from Binance (re-querying if missing), renders a QR code, and
 * polls status.php for CREDITED -> redirect to dashboard.
 *
 * The QR image is rendered client-side from the universalUrl using
 * Google Charts' QR endpoint, so we don't ship a QR library. The
 * fallback for users without the Binance app is the same universalUrl
 * as a tap-to-open link.
 */

require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/binancepay.php';

$user = auth_require();

$publicId = isset($_GET['id']) ? (string) $_GET['id'] : '';
$order    = null;
$payUrl   = '';
$errorMsg = '';

if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)) {
    try {
        $stmt = db()->prepare(
            'SELECT * FROM topup_orders WHERE public_id = ? AND user_id = ? AND provider = "binancepay" LIMIT 1'
        );
        $stmt->execute([$publicId, $user['id']]);
        $order = $stmt->fetch();
    } catch (Throwable $e) {
        $errorMsg = 'Database unavailable. Please retry from the top-up page.';
    }
}

if ($order && in_array($order['status'], ['PENDING', 'PAID'], true)) {
    try {
        $data = binancepay_query_order((string) $order['public_id']);
        // queryOrder returns checkoutUrl/qrcodeLink/universalUrl when
        // the order is still open. If Binance is rate-limiting we fall
        // back to "open Binance Pay" instructions only.
        $payUrl = (string) (
            $data['universalUrl']
            ?? $data['checkoutUrl']
            ?? $data['qrcodeLink']
            ?? ''
        );
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

layout_head('Pay with Binance Pay · imeihub', 'Scan the QR code to top up your imeihub wallet.');
?>
    <section class="topup-shell">
        <div class="container container--narrow">
            <p class="breadcrumbs"><a href="/dashboard.php">Dashboard</a> &rsaquo; <a href="/topup.php">Top up</a> &rsaquo; Binance Pay</p>

            <?php if (!$order): ?>
                <div class="topup-status topup-status--failed">
                    <h1>Order not found</h1>
                    <p>We couldn't find that top-up order. Start a fresh one from the top-up page.</p>
                    <a href="/topup.php" class="btn-primary">Back to top up</a>
                </div>
            <?php elseif ($order['status'] === 'CREDITED'): ?>
                <div class="topup-status topup-status--credited" data-public-id="<?= htmlspecialchars($order['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <h1>Top-up successful</h1>
                    <p class="topup-amount">$<?= number_format((float) $order['amount'], 2) ?> added to your wallet.</p>
                    <p class="dashboard-subtitle">Credited <?= htmlspecialchars((string) $order['credited_at'], ENT_QUOTES, 'UTF-8') ?>.</p>
                    <a href="/dashboard.php" class="btn-primary">Go to dashboard</a>
                </div>
            <?php else: ?>
                <div class="bp-pay" data-public-id="<?= htmlspecialchars($order['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="bp-pay-head">
                        <h1>Pay with Binance Pay</h1>
                        <p class="dashboard-subtitle">
                            Send <strong>$<?= number_format((float) $order['amount'], 2) ?> USDT</strong> via Binance Pay.
                            Your wallet is credited automatically after the network confirms the transfer
                            (usually under a minute).
                        </p>
                    </div>

                    <div class="bp-pay-grid">
                        <div class="bp-pay-qr">
                            <?php if ($payUrl !== ''): ?>
                                <img
                                    alt="Binance Pay QR code"
                                    src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=10&data=<?= rawurlencode($payUrl) ?>"
                                    width="260" height="260">
                            <?php else: ?>
                                <div class="bp-pay-qr-empty">
                                    QR unavailable. Use the link on the right to open Binance Pay.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bp-pay-info">
                            <h2>How to pay</h2>
                            <ol>
                                <li>Open the <strong>Binance app</strong> &rarr; tap the search bar &rarr; <strong>Pay</strong> &rarr; <strong>Scan</strong>.</li>
                                <li>Scan the QR code (or tap the button below on mobile).</li>
                                <li>Confirm the $<?= number_format((float) $order['amount'], 2) ?> USDT charge.</li>
                                <li>This page refreshes when payment lands &mdash; you'll see the credit immediately.</li>
                            </ol>

                            <?php if ($payUrl !== ''): ?>
                                <a href="<?= htmlspecialchars($payUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-primary btn-primary--lg" rel="noopener" target="_blank">
                                    Open in Binance app
                                </a>
                            <?php endif; ?>

                            <p class="bp-pay-bonus">
                                <strong>+5% bonus</strong> credited to your wallet on success.
                            </p>
                        </div>
                    </div>

                    <?php if ($errorMsg !== ''): ?>
                        <p class="field-error" style="margin-top:16px;">
                            <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
    (function () {
        var box = document.querySelector('[data-public-id]');
        if (!box) return;
        if (box.classList && box.classList.contains('topup-status--credited')) return;

        var publicId = box.getAttribute('data-public-id');
        var tries = 0, maxTries = 120; // ~10 min at 5s interval
        function poll() {
            tries += 1;
            fetch('/api/topup/status.php?id=' + encodeURIComponent(publicId), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.ok) return;
                    if (j.status === 'CREDITED' || j.status === 'FAILED' || j.status === 'EXPIRED') {
                        window.location.reload();
                    } else if (tries < maxTries) {
                        setTimeout(poll, 5000);
                    }
                })
                .catch(function () { if (tries < maxTries) setTimeout(poll, 8000); });
        }
        setTimeout(poll, 4000);
    })();
    </script>
<?php layout_foot(); ?>
