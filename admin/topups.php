<?php
declare(strict_types=1);

/**
 * Money-integrity dashboard.
 *
 * Two anti-fraud / anti-bug checks the operator should watch:
 *   1. Wallet integrity — any user whose cached_balance no longer equals
 *      SUM(ledger). Drift means a bug or tampering; the ledger is truth.
 *   2. Top-up reconciliation — every CREDITED order should trace back to
 *      a signature-VERIFIED provider webhook. A credited order with no
 *      verified webhook is suspicious (manual/forged credit) and gets
 *      flagged here.
 */

require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/layout.php';

admin_require();
$pdo = db();

// --- 1. wallet integrity (cached_balance vs ledger) ---
$drift = $pdo->query(
    'SELECT x.id, x.email, x.cached, x.ledger FROM (
        SELECT u.id, u.email, u.cached_balance AS cached,
               (SELECT COALESCE(SUM(amount),0) FROM credit_transactions ct WHERE ct.user_id = u.id) AS ledger
        FROM users u
     ) x WHERE ABS(x.cached - x.ledger) >= 0.01 ORDER BY ABS(x.cached - x.ledger) DESC LIMIT 100'
)->fetchAll();

// --- 2. top-up reconciliation: CREDITED orders vs verified webhooks ---
$orders = $pdo->query(
    'SELECT public_id, user_id, amount, provider, provider_charge_id, status, created_at
     FROM topup_orders WHERE status = "CREDITED" ORDER BY id DESC LIMIT 100'
)->fetchAll();

$verifyStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM webhook_events
     WHERE signature_ok = 1 AND provider = ? AND raw_body LIKE CONCAT("%", ?, "%")'
);
$rows = [];
$flagged = 0;
foreach ($orders as $o) {
    $needle = (string) ($o['provider_charge_id'] ?: $o['public_id']);
    $verifyStmt->execute([(string) $o['provider'], $needle]);
    $ok = (int) $verifyStmt->fetchColumn() > 0;
    if (!$ok) $flagged++;
    $o['verified'] = $ok;
    $rows[] = $o;
}

$th = 'padding:8px 10px;text-align:left;color:#9ca3af;border-bottom:1px solid #1f2937';
$td = 'padding:8px 10px;border-bottom:1px solid #111827';

layout_head('Admin · Top-ups & reconcile');
admin_nav('topups');
?>
<div class="container" style="padding-bottom:60px">

  <h2 style="font-size:16px;margin:0 0 8px">Wallet integrity</h2>
  <?php if (!$drift): ?>
    <p style="background:#064e3b;color:#d1fae5;padding:10px 14px;border-radius:8px">All wallets reconcile — cached_balance matches the ledger.</p>
  <?php else: ?>
    <p style="background:#7f1d1d;color:#fee2e2;padding:10px 14px;border-radius:8px"><strong><?= count($drift) ?> wallet(s) drifted</strong> from the ledger. Investigate — the ledger is the source of truth.</p>
    <div style="overflow-x:auto;margin-bottom:14px"><table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr><th style="<?= $th ?>">User</th><th style="<?= $th ?>;text-align:right">cached</th><th style="<?= $th ?>;text-align:right">ledger</th><th style="<?= $th ?>;text-align:right">diff</th></tr></thead>
      <tbody>
      <?php foreach ($drift as $d): ?>
        <tr><td style="<?= $td ?>"><a href="/admin/user.php?id=<?= (int) $d['id'] ?>" style="color:#60a5fa">#<?= (int) $d['id'] ?> <?= admin_h($d['email']) ?></a></td>
            <td style="<?= $td ?>;text-align:right">$<?= number_format((float) $d['cached'], 2) ?></td>
            <td style="<?= $td ?>;text-align:right">$<?= number_format((float) $d['ledger'], 2) ?></td>
            <td style="<?= $td ?>;text-align:right;color:#f87171"><?= number_format((float) $d['cached'] - (float) $d['ledger'], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <h2 style="font-size:16px;margin:26px 0 8px">Top-up reconciliation
    <span style="font-weight:400;color:<?= $flagged ? '#f87171' : '#6b7280' ?>;font-size:13px">
      &middot; <?= $flagged ?> of <?= count($rows) ?> credited orders lack a verified webhook
    </span>
  </h2>
  <p style="color:#9ca3af;margin:0 0 10px;font-size:13px">A credited order with no signature-verified provider webhook may be a manual or forged credit. Manual admin adjustments intentionally have none.</p>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead><tr><th style="<?= $th ?>">When</th><th style="<?= $th ?>">User</th><th style="<?= $th ?>">Provider</th><th style="<?= $th ?>;text-align:right">Amount</th><th style="<?= $th ?>">Charge id</th><th style="<?= $th ?>">Webhook</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $o): ?>
      <tr style="<?= $o['verified'] ? '' : 'background:#fef2f2' ?>">
        <td style="<?= $td ?>"><?= admin_h(substr((string) $o['created_at'], 0, 16)) ?></td>
        <td style="<?= $td ?>"><a href="/admin/user.php?id=<?= (int) $o['user_id'] ?>" style="color:#60a5fa">#<?= (int) $o['user_id'] ?></a></td>
        <td style="<?= $td ?>"><?= admin_h($o['provider']) ?></td>
        <td style="<?= $td ?>;text-align:right">$<?= number_format((float) $o['amount'], 2) ?></td>
        <td style="<?= $td ?>;color:#6b7280"><?= admin_h($o['provider_charge_id'] ?? '—') ?></td>
        <td style="<?= $td ?>"><?= $o['verified'] ? '<span style="color:#059669;font-weight:600">verified</span>' : '<span style="color:#b91c1c;font-weight:600">&#9888; none</span>' ?></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" style="<?= $td ?>;color:#6b7280">No credited orders yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php layout_foot();
