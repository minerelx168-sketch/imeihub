<?php
declare(strict_types=1);

require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/layout.php';

admin_require();
$pdo = db();

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, email, name, is_admin, banned_at, cached_balance, created_at
         FROM users WHERE email LIKE ? OR name LIKE ? ORDER BY id DESC LIMIT 200'
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like]);
} else {
    $stmt = $pdo->query(
        'SELECT id, email, name, is_admin, banned_at, cached_balance, created_at
         FROM users ORDER BY id DESC LIMIT 200'
    );
}
$users = $stmt->fetchAll();

layout_head('Admin · Users');
admin_nav('users');
?>
<div class="container" style="padding-bottom:60px">
  <?php if (isset($_GET['deleted'])): ?>
    <p style="background:#ecfdf5;color:#065f46;padding:10px 14px;border-radius:8px">User deleted.</p>
  <?php endif; ?>
  <form method="get" style="margin:0 0 22px;display:flex;gap:10px;flex-wrap:wrap">
    <input type="search" name="q" value="<?= admin_h($q) ?>" placeholder="Search email or name&hellip;"
           style="padding:10px 12px;border-radius:8px;border:1px solid #374151;background:#0b0f17;color:#e5e7eb;min-width:280px">
    <button type="submit" style="padding:10px 18px;border-radius:8px;border:0;background:#4f46e5;color:#fff;cursor:pointer">Search</button>
    <?php if ($q !== ''): ?><a href="/admin/users.php" style="align-self:center;color:#9ca3af">Clear</a><?php endif; ?>
  </form>

  <p style="margin:0 0 12px">
    <a href="/admin/export.php?type=users<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" style="color:#2563eb;text-decoration:none">&#10515; Export users CSV<?= $q !== '' ? ' (filtered)' : '' ?></a>
    &nbsp;·&nbsp;
    <a href="/admin/export.php?type=topups" style="color:#2563eb;text-decoration:none">&#10515; Export top-ups CSV</a>
  </p>

  <p style="color:#9ca3af;margin:0 0 10px"><?= count($users) ?> user(s)<?= $q !== '' ? ' matching "' . admin_h($q) . '"' : '' ?></p>

  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead>
      <tr style="text-align:left;color:#9ca3af;border-bottom:1px solid #1f2937">
        <th style="padding:10px 12px">ID</th>
        <th style="padding:10px 12px">Email</th>
        <th style="padding:10px 12px">Name</th>
        <th style="padding:10px 12px;text-align:right">Balance</th>
        <th style="padding:10px 12px">Role</th>
        <th style="padding:10px 12px">Joined</th>
        <th style="padding:10px 12px"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr style="border-bottom:1px solid #111827">
        <td style="padding:10px 12px;color:#9ca3af">#<?= (int) $u['id'] ?></td>
        <td style="padding:10px 12px"><?= admin_h($u['email']) ?></td>
        <td style="padding:10px 12px"><?= admin_h($u['name'] ?? '—') ?></td>
        <td style="padding:10px 12px;text-align:right">$<?= number_format((float) $u['cached_balance'], 2) ?></td>
        <td style="padding:10px 12px"><?php
          if (!empty($u['banned_at'])) echo '<span style="color:#b91c1c;font-weight:600">banned</span>';
          elseif ((int) $u['is_admin'] === 1) echo '<span style="color:#b45309;font-weight:600">admin</span>';
          else echo '<span style="color:#6b7280">user</span>';
        ?></td>
        <td style="padding:10px 12px;color:#9ca3af"><?= admin_h(substr((string) $u['created_at'], 0, 10)) ?></td>
        <td style="padding:10px 12px"><a href="/admin/user.php?id=<?= (int) $u['id'] ?>" style="color:#60a5fa">Manage &rarr;</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?>
      <tr><td colspan="7" style="padding:20px 12px;color:#6b7280">No users found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php layout_foot();
