<?php
declare(strict_types=1);

require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/layout.php';

admin_require();
$pdo = db();

$kpi = $pdo->query(
    'SELECT
        (SELECT COUNT(*) FROM users)                                                              AS users,
        (SELECT COALESCE(SUM(cached_balance),0) FROM users)                                       AS outstanding,
        (SELECT COALESCE(SUM(amount),0) FROM credit_transactions
            WHERE type IN ("TOPUP","BONUS") AND created_at >= CURDATE())                          AS topup_today,
        (SELECT COUNT(*) FROM service_usages WHERE created_at >= CURDATE())                        AS lookups_today,
        (SELECT COALESCE(SUM(cost),0) FROM service_usages
            WHERE status = "SUCCESS" AND created_at >= CURDATE())                                  AS revenue_today'
)->fetch();

// integrity flags for the alert strip
$driftCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM (
        SELECT u.id FROM users u
        WHERE ABS(u.cached_balance -
            (SELECT COALESCE(SUM(amount),0) FROM credit_transactions ct WHERE ct.user_id = u.id)) >= 0.01
     ) z'
)->fetchColumn();

function kpi_card(string $label, string $value, string $sub = ''): void
{
    echo '<div style="background:#0f1623;border:1px solid #1f2937;border-radius:12px;padding:18px 22px;min-width:170px;flex:1">';
    echo '<div style="color:#9ca3af;font-size:13px">' . admin_h($label) . '</div>';
    echo '<div style="font-size:26px;font-weight:700;margin-top:2px;color:#f9fafb">' . admin_h($value) . '</div>';
    if ($sub !== '') echo '<div style="color:#6b7280;font-size:12px;margin-top:4px">' . admin_h($sub) . '</div>';
    echo '</div>';
}

layout_head('Admin · Overview');
admin_nav('index');
?>
<div class="container" style="padding-bottom:60px">
  <?php if ($driftCount > 0): ?>
    <p style="background:#7f1d1d;color:#fee2e2;padding:10px 14px;border-radius:8px">
      &#9888; <?= $driftCount ?> wallet(s) drifted from the ledger.
      <a href="/admin/topups.php" style="color:#fecaca;text-decoration:underline">Review &rarr;</a>
    </p>
  <?php endif; ?>

  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px">
    <?php
      kpi_card('Users', number_format((int) $kpi['users']));
      kpi_card('Outstanding credit', '$' . number_format((float) $kpi['outstanding'], 2), 'sum of wallet balances');
      kpi_card('Top-ups today', '$' . number_format((float) $kpi['topup_today'], 2));
      kpi_card('Lookups today', number_format((int) $kpi['lookups_today']));
      kpi_card('Revenue today', '$' . number_format((float) $kpi['revenue_today'], 2), 'successful paid lookups');
    ?>
  </div>

  <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px">
    <a href="/admin/users.php" style="color:#60a5fa">Manage users &rarr;</a>
    <a href="/admin/topups.php" style="color:#60a5fa">Top-ups &amp; reconciliation &rarr;</a>
  </div>
</div>
<?php layout_foot();
