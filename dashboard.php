<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/credits.php';

$user = auth_require();
$userId = (int) $user['id'];

$balance = credits_get_balance($userId, true);    // strict, from ledger
$stats   = credits_get_month_stats($userId);
$recent  = credits_get_recent_usages($userId, 10);

layout_head('Dashboard · imeihub', 'Your imeihub account dashboard.');
?>
    <section class="dashboard">
        <div class="container">
            <header class="dashboard-head">
                <div>
                    <p class="hero-eyebrow" style="color:var(--text-muted);border-color:var(--border);background:var(--surface-alt)">Dashboard</p>
                    <h1>Hi, <?= htmlspecialchars((string) ($user['name'] ?: $user['email']), ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="dashboard-subtitle">Manage your credits, run lookups, and review your usage.</p>
                </div>
                <a href="/logout.php" class="link-quiet">Sign out</a>
            </header>

            <div class="dashboard-grid">
                <div class="stat-card">
                    <span class="stat-card-label">Credit balance</span>
                    <strong class="stat-card-value" id="balance"><?= credits_format_usd($balance) ?></strong>
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                        <a href="/check.php" class="btn-primary">Run a check</a>
                        <a href="/topup.php" class="btn-secondary">Top up</a>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-card-label">Lookups this month</span>
                    <strong class="stat-card-value"><?= (int) $stats['lookups_count'] ?></strong>
                    <span class="stat-card-meta">
                        <?= (int) $stats['lookups_free'] ?> free,
                        <?= (int) $stats['lookups_paid'] ?> paid
                    </span>
                </div>
                <div class="stat-card">
                    <span class="stat-card-label">Spent this month</span>
                    <strong class="stat-card-value"><?= credits_format_usd($stats['spent']) ?></strong>
                    <span class="stat-card-meta">
                        <a href="/credits/history.php">View full history &rarr;</a>
                    </span>
                </div>
                <div class="stat-card">
                    <span class="stat-card-label">Bot integrations</span>
                    <strong class="stat-card-value" style="font-size:1.2rem; font-weight:700;">Telegram / LINE</strong>
                    <span class="stat-card-meta">Run IMEI checks from a chat — link your shop.</span>
                    <a href="/bot.php" class="btn-primary" style="margin-top:8px">Set up bot &rarr;</a>
                </div>
            </div>

            <section class="dashboard-block">
                <div class="dashboard-block-head">
                    <h2>Recent lookups</h2>
                    <a href="/credits/history.php" class="link-more">View all <?= icon('arrow-right', 14) ?></a>
                </div>

                <?php if (!$recent): ?>
                    <div class="empty-state">
                        <p><strong>No lookups yet.</strong></p>
                        <p>Run your first IMEI check from the <a href="/">homepage</a> or browse the
                        <a href="/services.php">services catalog</a>.</p>
                    </div>
                <?php else: ?>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Status</th>
                                <th class="num">Cost</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string) ($r['service_name'] ?: $r['service_code']), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="ledger-sub"><?= htmlspecialchars((string) $r['service_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <?php $statusClass = strtolower($r['status']); ?>
                                    <span class="status status--<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="num"><?= credits_format_usd($r['cost']) ?></td>
                                <td class="muted"><?= htmlspecialchars((string) $r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </section>

    <script>
    // Light polling so balance reflects a top-up that lands while the page is open.
    (function () {
        var el = document.getElementById('balance');
        if (!el) return;
        function tick() {
            fetch('/api/credits/balance.php', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (j && j.ok) el.textContent = '$' + parseFloat(j.balance).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                })
                .catch(function () {});
        }
        setInterval(tick, 15000);
    })();
    </script>
<?php layout_foot(); ?>
