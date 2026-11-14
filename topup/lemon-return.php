<?php
declare(strict_types=1);
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

$user = auth_require();

$publicId = isset($_GET['id']) ? (string) $_GET['id'] : '';
$order = null;

if ($publicId !== '' && preg_match('/^[0-9A-Z]{26}$/', $publicId)) {
    try {
        $stmt = db()->prepare(
            'SELECT public_id, amount, currency, status, paid_at, credited_at
             FROM topup_orders
             WHERE public_id = ? AND user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$publicId, $user['id']]);
        $order = $stmt->fetch();
    } catch (Throwable $e) {
        // DB unavailable - render the pending state below.
    }
}

layout_head('Top-up status · imeihub', 'Your top-up is being processed.');
?>
    <section class="topup-shell">
        <div class="container container--narrow">
            <p class="breadcrumbs"><a href="/dashboard.php">Dashboard</a> &rsaquo; Top up &rsaquo; Status</p>

            <?php if (!$order): ?>
                <div class="topup-status topup-status--pending">
                    <h1>Processing your payment&hellip;</h1>
                    <p>We're confirming your top-up. This usually takes a few seconds.</p>
                    <p class="dashboard-subtitle">If this page does not update automatically, refresh in a moment.</p>
                </div>
            <?php else: ?>
                <?php
                $status = $order['status'];
                $statusClass = strtolower($status);
                ?>
                <div class="topup-status topup-status--<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"
                     data-public-id="<?= htmlspecialchars($order['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($status === 'CREDITED'): ?>
                        <h1>Top-up successful</h1>
                        <p class="topup-amount">$<?= number_format((float) $order['amount'], 2) ?> added to your wallet.</p>
                        <p class="dashboard-subtitle">Credited <?= htmlspecialchars((string) $order['credited_at'], ENT_QUOTES, 'UTF-8') ?>.</p>
                        <a href="/dashboard.php" class="btn-primary">Go to dashboard</a>
                    <?php elseif ($status === 'FAILED'): ?>
                        <h1>Payment failed</h1>
                        <p>The payment could not be completed. No credit was added to your wallet.</p>
                        <a href="/topup.php" class="btn-primary">Try again</a>
                    <?php else: ?>
                        <h1>Almost there&hellip;</h1>
                        <p>Your payment has been received. We're waiting for the final confirmation
                            to credit $<?= number_format((float) $order['amount'], 2) ?> to your wallet.</p>
                        <p class="dashboard-subtitle">This page refreshes automatically.</p>
                    <?php endif; ?>
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
        var tries = 0;
        var maxTries = 30;

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
                .catch(function () {
                    if (tries < maxTries) setTimeout(poll, 5000);
                });
        }
        setTimeout(poll, 3000);
    })();
    </script>
<?php layout_foot(); ?>
