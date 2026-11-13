<?php
declare(strict_types=1);

require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/credits.php';
require __DIR__ . '/../includes/layout.php';

admin_require();
$pdo = db();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT id, email, name, is_admin, banned_at, email_verified, cached_balance, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    layout_head('Admin · User not found');
    admin_nav('users');
    echo '<div class="container" style="padding-bottom:60px"><p>User #' . admin_h($id) . ' not found. <a href="/admin/users.php" style="color:#60a5fa">Back</a></p></div>';
    layout_foot();
    exit;
}

$ledger = credits_get_balance($id, true);            // source of truth
$cached = number_format((float) $user['cached_balance'], 2, '.', '');
$drift  = abs((float) $ledger - (float) $cached) >= 0.01;

$tx     = credits_get_transactions($id, 25);
$usages = credits_get_recent_usages($id, 25);

$topupStmt = $pdo->prepare(
    'SELECT public_id, amount, currency, status, provider, provider_charge_id, created_at, credited_at
     FROM topup_orders WHERE user_id = ? ORDER BY id DESC LIMIT 25'
);
$topupStmt->execute([$id]);
$topups = $topupStmt->fetchAll();

$msg = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$err = isset($_GET['err']) ? (string) $_GET['err'] : '';

$th = 'padding:8px 10px;text-align:left;color:#9ca3af;border-bottom:1px solid #1f2937';
$td = 'padding:8px 10px;border-bottom:1px solid #111827';

layout_head('Admin · ' . $user['email']);
admin_nav('users');
?>
<div class="container" style="padding-bottom:60px">
  <p style="margin:0 0 6px"><a href="/admin/users.php" style="color:#9ca3af">&larr; All users</a></p>
  <?php $banned = !empty($user['banned_at']); ?>
  <h1 style="margin:0 0 4px;font-size:22px"><?= admin_h($user['name'] ?? $user['email']) ?>
    <?php if ((int) $user['is_admin'] === 1): ?><span style="font-size:13px;color:#fbbf24">(admin)</span><?php endif; ?>
    <?php if ($banned): ?><span style="font-size:13px;color:#fff;background:#b91c1c;padding:2px 8px;border-radius:6px;margin-left:4px">BANNED</span><?php endif; ?>
  </h1>
  <p style="color:#9ca3af;margin:0 0 18px"><?= admin_h($user['email']) ?> &middot; user #<?= (int) $user['id'] ?>
     &middot; joined <?= admin_h(substr((string) $user['created_at'], 0, 10)) ?>
     &middot; email <?= $user['email_verified'] ? 'verified' : '<span style="color:#f87171">unverified</span>' ?></p>

  <?php if ($msg): ?><p style="background:#064e3b;color:#d1fae5;padding:10px 14px;border-radius:8px"><?= admin_h($msg) ?></p><?php endif; ?>
  <?php if ($err): ?><p style="background:#7f1d1d;color:#fee2e2;padding:10px 14px;border-radius:8px"><?= admin_h($err) ?></p><?php endif; ?>

  <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin-bottom:30px">
    <div style="background:#0f1623;border:1px solid #1f2937;border-radius:12px;padding:18px 22px;min-width:220px">
      <div style="color:#9ca3af;font-size:13px">Balance (ledger)</div>
      <div style="font-size:28px;font-weight:700;color:#f9fafb">$<?= number_format((float) $ledger, 2) ?></div>
      <div style="color:<?= $drift ? '#f87171' : '#6b7280' ?>;font-size:12px;margin-top:4px">
        cached $<?= number_format((float) $cached, 2) ?><?= $drift ? ' — DRIFT!' : ' — in sync' ?>
      </div>
    </div>

    <form method="post" action="/admin/credit.php" style="background:#0f1623;border:1px solid #1f2937;border-radius:12px;padding:18px 22px;min-width:300px">
      <div style="color:#9ca3af;font-size:13px;margin-bottom:8px">Adjust credit (+grant / &minus;deduct)</div>
      <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
      <input type="hidden" name="csrf" value="<?= admin_h(admin_csrf_token()) ?>">
      <input type="number" name="amount" step="0.01" required placeholder="e.g. 10 or -5"
             style="padding:9px 11px;border-radius:8px;border:1px solid #374151;background:#0b0f17;color:#e5e7eb;width:120px">
      <input type="text" name="reason" maxlength="200" placeholder="Reason (audited)"
             style="padding:9px 11px;border-radius:8px;border:1px solid #374151;background:#0b0f17;color:#e5e7eb;width:100%;margin-top:8px;box-sizing:border-box">
      <button type="submit" style="margin-top:10px;padding:9px 18px;border-radius:8px;border:0;background:#4f46e5;color:#fff;cursor:pointer">Apply</button>
    </form>
  </div>

  <?php if ((int) $user['is_admin'] !== 1): ?>
  <div style="border:1px solid #fecaca;background:#fef2f2;border-radius:12px;padding:16px 20px;margin-bottom:30px">
    <strong style="color:#991b1b">Danger zone</strong>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px">
      <form method="post" action="/admin/ban.php" onsubmit="return confirm('<?= $banned ? 'Unban' : 'Ban' ?> this user?')">
        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="csrf" value="<?= admin_h(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $banned ? 'unban' : 'ban' ?>">
        <button type="submit" style="padding:8px 16px;border-radius:8px;border:1px solid #f59e0b;background:#fff;color:#b45309;cursor:pointer;font-weight:600"><?= $banned ? 'Unban user' : 'Ban user' ?></button>
      </form>
      <form method="post" action="/admin/delete.php" onsubmit="return confirm('Permanently delete this account? Only works if it has no financial history.')">
        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
        <input type="hidden" name="csrf" value="<?= admin_h(admin_csrf_token()) ?>">
        <button type="submit" style="padding:8px 16px;border-radius:8px;border:0;background:#b91c1c;color:#fff;cursor:pointer;font-weight:600">Delete account</button>
      </form>
    </div>
    <p style="color:#7f1d1d;font-size:12px;margin:10px 0 0">Ban revokes sessions and blocks login immediately. Delete is permanent and only allowed for accounts with no ledger / top-ups / usage.</p>
  </div>
  <?php endif; ?>

  <h2 style="font-size:16px;margin:0 0 8px">Top-up orders</h2>
  <div style="overflow-x:auto;margin-bottom:30px"><table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead><tr><th style="<?= $th ?>">When</th><th style="<?= $th ?>">Provider</th><th style="<?= $th ?>;text-align:right">Amount</th><th style="<?= $th ?>">Status</th><th style="<?= $th ?>">Charge id</th></tr></thead>
    <tbody>
    <?php foreach ($topups as $t): ?>
      <tr><td style="<?= $td ?>"><?= admin_h(substr((string) $t['created_at'], 0, 16)) ?></td>
          <td style="<?= $td ?>"><?= admin_h($t['provider']) ?></td>
          <td style="<?= $td ?>;text-align:right">$<?= number_format((float) $t['amount'], 2) ?></td>
          <td style="<?= $td ?>"><?= admin_h($t['status']) ?></td>
          <td style="<?= $td ?>;color:#6b7280"><?= admin_h($t['provider_charge_id'] ?? '—') ?></td></tr>
    <?php endforeach; if (!$topups): ?><tr><td colspan="5" style="<?= $td ?>;color:#6b7280">No top-ups.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <h2 style="font-size:16px;margin:0 0 8px">Service usage</h2>
  <div style="overflow-x:auto;margin-bottom:30px"><table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead><tr><th style="<?= $th ?>">When</th><th style="<?= $th ?>">Service</th><th style="<?= $th ?>;text-align:right">Cost</th><th style="<?= $th ?>">Status</th></tr></thead>
    <tbody>
    <?php foreach ($usages as $u): ?>
      <tr><td style="<?= $td ?>"><?= admin_h(substr((string) $u['created_at'], 0, 16)) ?></td>
          <td style="<?= $td ?>"><?= admin_h($u['service_name'] ?? $u['service_code']) ?></td>
          <td style="<?= $td ?>;text-align:right">$<?= number_format((float) $u['cost'], 2) ?></td>
          <td style="<?= $td ?>"><?= admin_h($u['status']) ?></td></tr>
    <?php endforeach; if (!$usages): ?><tr><td colspan="4" style="<?= $td ?>;color:#6b7280">No usage yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <h2 style="font-size:16px;margin:0 0 8px">Credit ledger
    <a href="/admin/export.php?type=ledger&id=<?= (int) $user['id'] ?>" style="font-size:12px;font-weight:400;color:#2563eb;text-decoration:none">&#10515; CSV</a>
  </h2>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead><tr><th style="<?= $th ?>">When</th><th style="<?= $th ?>">Type</th><th style="<?= $th ?>;text-align:right">Amount</th><th style="<?= $th ?>;text-align:right">After</th><th style="<?= $th ?>">Description</th></tr></thead>
    <tbody>
    <?php foreach ($tx['rows'] as $r): $amt = (float) $r['amount']; ?>
      <tr><td style="<?= $td ?>"><?= admin_h(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td style="<?= $td ?>"><?= admin_h($r['type']) ?></td>
          <td style="<?= $td ?>;text-align:right;color:<?= $amt < 0 ? '#f87171' : '#34d399' ?>"><?= ($amt >= 0 ? '+' : '') . number_format($amt, 2) ?></td>
          <td style="<?= $td ?>;text-align:right;color:#9ca3af">$<?= number_format((float) $r['balance_after'], 2) ?></td>
          <td style="<?= $td ?>;color:#9ca3af"><?= admin_h($r['description'] ?? '') ?></td></tr>
    <?php endforeach; if (!$tx['rows']): ?><tr><td colspan="5" style="<?= $td ?>;color:#6b7280">No ledger rows.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php layout_foot();
