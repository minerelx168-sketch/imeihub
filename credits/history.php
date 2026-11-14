<?php
declare(strict_types=1);

require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/credits.php';
require __DIR__ . '/../includes/icons.php';

$user   = auth_require();
$userId = (int) $user['id'];

$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$validTypes = ['TOPUP', 'USAGE', 'REFUND', 'ADJUSTMENT', 'BONUS'];
$type = isset($_GET['type']) ? strtoupper((string) $_GET['type']) : '';
if ($type !== '' && !in_array($type, $validTypes, true)) {
    $type = '';
}

$result = credits_get_transactions($userId, $perPage, $offset, $type !== '' ? $type : null);
$rows   = $result['rows'];
$total  = (int) $result['total'];
$pages  = max(1, (int) ceil($total / $perPage));

$balance = credits_get_balance($userId, true);

// Count per type so the filter pills can show numbers.
$typeCounts = ['ALL' => 0];
foreach ($validTypes as $t) $typeCounts[$t] = 0;
try {
    $stmt = db()->prepare(
        'SELECT type, COUNT(*) AS c FROM credit_transactions WHERE user_id = ? GROUP BY type'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $r) {
        $typeCounts[$r['type']] = (int) $r['c'];
        $typeCounts['ALL'] += (int) $r['c'];
    }
} catch (Throwable $e) {
    // DB unavailable - counts stay zero.
}

function history_qs(array $overrides): string
{
    $q = array_filter(array_merge([
        'page' => $_GET['page'] ?? null,
        'type' => $_GET['type'] ?? null,
    ], $overrides), fn ($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
}

layout_head('Credit history · imeihub', 'Every credit movement on your imeihub account.');
?>
    <section class="dashboard">
        <div class="container">
            <header class="dashboard-head">
                <div>
                    <p class="hero-eyebrow" style="color:var(--text-muted);border-color:var(--border);background:var(--surface-alt)">Account</p>
                    <h1>Credit history</h1>
                    <p class="dashboard-subtitle">
                        Current balance: <strong><?= credits_format_usd($balance) ?></strong>
                        &middot; <?= $total ?> transactions on record
                    </p>
                </div>
                <a href="/api/credits/export.php<?= $type !== '' ? '?type=' . urlencode($type) : '' ?>"
                   class="btn-secondary">
                    Export CSV
                </a>
            </header>

            <nav class="filter-pills" aria-label="Filter by transaction type">
                <a class="pill-link<?= $type === '' ? ' is-active' : '' ?>" href="<?= history_qs(['type' => null, 'page' => 1]) ?: '/credits/history.php' ?>">
                    All <span class="pill-link-count"><?= $typeCounts['ALL'] ?></span>
                </a>
                <?php foreach ($validTypes as $t):
                    if (($typeCounts[$t] ?? 0) === 0) continue;
                ?>
                <a class="pill-link<?= $type === $t ? ' is-active' : '' ?>" href="<?= history_qs(['type' => $t, 'page' => 1]) ?>">
                    <?= htmlspecialchars(ucfirst(strtolower($t)), ENT_QUOTES, 'UTF-8') ?>
                    <span class="pill-link-count"><?= $typeCounts[$t] ?></span>
                </a>
                <?php endforeach; ?>
            </nav>

            <section class="dashboard-block" style="margin-top:24px">
                <?php if (!$rows): ?>
                    <div class="empty-state">
                        <p><strong>No transactions yet.</strong></p>
                        <p>
                            <?php if ($type !== ''): ?>
                                No <?= htmlspecialchars(strtolower($type), ENT_QUOTES, 'UTF-8') ?> entries found.
                                <a href="/credits/history.php">View all</a>.
                            <?php else: ?>
                                Your credit ledger is empty.
                                <a href="/topup.php">Top up</a> to get started.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="ledger-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th class="num">Amount</th>
                                <th class="num">Balance after</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $isPos = (float) $r['amount'] >= 0;
                            $signClass = $isPos ? 'amount--positive' : 'amount--negative';
                            $typeLow = strtolower($r['type']);
                        ?>
                            <tr>
                                <td class="muted"><?= htmlspecialchars((string) $r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="status status--<?= htmlspecialchars($typeLow === 'topup' ? 'success' : ($typeLow === 'usage' ? 'pending' : ($typeLow === 'refund' ? 'refunded' : 'pending')), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($r['type'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['description']): ?>
                                        <strong><?= htmlspecialchars((string) $r['description'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php endif; ?>
                                    <?php if ($r['reference_id']): ?>
                                        <span class="ledger-sub"><?= htmlspecialchars((string) ($r['reference_type'] ?: ''), ENT_QUOTES, 'UTF-8') ?> #<?= htmlspecialchars((string) $r['reference_id'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <span class="amount <?= $signClass ?>">
                                        <?= ($isPos ? '+' : '') . number_format((float) $r['amount'], 2) ?>
                                    </span>
                                </td>
                                <td class="num"><?= number_format((float) $r['balance_after'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Pagination">
                <?php if ($page > 1): ?>
                    <a class="pagination-link" href="<?= history_qs(['page' => $page - 1]) ?>">&larr; Previous</a>
                <?php else: ?>
                    <span class="pagination-link is-disabled">&larr; Previous</span>
                <?php endif; ?>

                <span class="pagination-info">Page <?= $page ?> of <?= $pages ?></span>

                <?php if ($page < $pages): ?>
                    <a class="pagination-link" href="<?= history_qs(['page' => $page + 1]) ?>">Next &rarr;</a>
                <?php else: ?>
                    <span class="pagination-link is-disabled">Next &rarr;</span>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
    </section>
<?php layout_foot(); ?>
