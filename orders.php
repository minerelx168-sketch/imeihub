<?php
declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/blacklist.php';

$user   = auth_require();
$userId = (int) $user['id'];

// IMEIs this user has already reported -> show those flags red on load.
$reportedSet = blacklist_user_reported_imeis($userId);

$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$pdo = db();
$cnt = $pdo->prepare('SELECT COUNT(*) FROM service_usages WHERE user_id = ?');
$cnt->execute([$userId]);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare(
    'SELECT u.id, u.public_id, u.service_code, u.cost, u.status, u.input, u.created_at,
            sp.name AS service_name
     FROM service_usages u
     LEFT JOIN service_prices sp ON sp.code = u.service_code
     WHERE u.user_id = ?
     ORDER BY u.id DESC
     LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

// status -> dot color
function order_dot(string $status): string
{
    switch (strtoupper($status)) {
        case 'SUCCESS':    return '#16a34a';
        case 'PROCESSING':
        case 'PENDING':    return '#d97706';
        case 'REFUNDED':   return '#6b7280';
        case 'FAILED':     return '#dc2626';
        default:           return '#9ca3af';
    }
}
function order_label(string $status): string
{
    return strtoupper($status) === 'SUCCESS' ? 'Completed' : ucfirst(strtolower($status));
}

layout_head('Order history · imeihub', 'Your past IMEI lookups.');
?>
<section class="dashboard">
  <div class="container">
    <header class="dashboard-head">
      <div>
        <p class="hero-eyebrow" style="color:var(--text-muted);border-color:var(--border);background:var(--surface-alt)">History</p>
        <h1>History order</h1>
        <p class="dashboard-subtitle"><?= $total ?> order(s) on record</p>
      </div>
      <div class="bl-import-bar">
        <button type="button" class="btn-outline" id="bl-format">File format</button>
        <button type="button" class="btn-outline btn-outline--primary" id="bl-import-btn">Import blacklist</button>
        <input type="file" id="bl-import-file" accept=".csv,.xlsx,.txt" hidden>
      </div>
    </header>
    <p id="bl-import-msg" class="bl-import-msg" hidden></p>

    <section class="dashboard-block" style="margin-top:24px">
      <?php if (!$rows): ?>
        <div class="empty-state"><p><strong>No orders yet.</strong></p>
          <p>Run a check from the <a href="/">homepage</a> to see it here.</p></div>
      <?php else: ?>
        <div style="overflow-x:auto">
        <table class="ledger-table">
          <thead><tr>
            <th>Order</th><th>Service</th><th>Status</th><th>Date</th><th>IMEI</th>
            <th class="num">Credits</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $imei = (string) (json_decode((string) $r['input'], true)['imei'] ?? '');
              $pid  = (string) $r['public_id'];
          ?>
            <tr>
              <td class="muted">#<?= (int) $r['id'] ?></td>
              <td><strong><?= htmlspecialchars((string) ($r['service_name'] ?: $r['service_code']), ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td><span style="display:inline-flex;align-items:center;gap:7px">
                <span style="width:9px;height:9px;border-radius:50%;background:<?= order_dot((string) $r['status']) ?>"></span>
                <?= htmlspecialchars(order_label((string) $r['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="muted"><?= htmlspecialchars(substr((string) $r['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
              <td style="font-family:var(--font-mono)"><?= htmlspecialchars($imei, ENT_QUOTES, 'UTF-8') ?></td>
              <td class="num"><?= number_format((float) $r['cost'], 3) ?></td>
              <td style="white-space:nowrap;text-align:right">
                <?php if (strtoupper((string) $r['status']) === 'SUCCESS'): ?>
                <button type="button" class="order-act js-view" data-id="<?= htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') ?>" title="View result" aria-label="View result">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <?php endif; ?>
                <?php $isReported = $imei !== '' && isset($reportedSet[$imei]); ?>
                <button type="button" class="order-act order-act--report js-report<?= $isReported ? ' is-reported' : '' ?>" data-id="<?= htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') ?>" data-imei="<?= htmlspecialchars($imei, ENT_QUOTES, 'UTF-8') ?>" title="<?= $isReported ? 'Reported — click to cancel' : 'Report this IMEI as blacklisted' ?>" aria-label="Report IMEI">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 22V4h13l-2 4 2 4H4"/></svg>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if ($page > 1): ?><a class="pagination-link" href="?page=<?= $page - 1 ?>">&larr; Previous</a>
      <?php else: ?><span class="pagination-link is-disabled">&larr; Previous</span><?php endif; ?>
      <span class="pagination-info">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="pagination-link" href="?page=<?= $page + 1 ?>">Next &rarr;</a>
      <?php else: ?><span class="pagination-link is-disabled">Next &rarr;</span><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
</section>

<div id="order-modal" class="bl-overlay" hidden>
  <div class="result result--report" style="max-width:560px;width:100%">
    <div class="result-banner result-banner--success" id="om-banner">Result</div>
    <div class="result-card"><div id="om-body" class="result-lines"></div>
      <p style="text-align:center;margin:18px 0 0"><button type="button" class="bl-dismiss" id="om-close" style="background:var(--text-muted)">Close</button></p>
    </div>
  </div>
</div>

<div id="bl-format-modal" class="bl-overlay" hidden>
  <div class="bl-card" style="max-width:520px">
    <h3 style="margin:0 0 6px;color:var(--text-strong)">Blacklist file format</h3>
    <p style="color:var(--text-soft);margin:0 0 14px;font-size:.92rem;line-height:1.6">
      Upload a <strong>.csv</strong> or <strong>.xlsx</strong> file with two columns:
      <strong>IMEI</strong> and <strong>Reason</strong>. A header row is optional and
      only these two columns are read. Each valid 15-digit IMEI is flagged on your account.
    </p>
    <table class="bl-sample">
      <thead><tr><th>IMEI</th><th>Reason</th></tr></thead>
      <tbody>
        <tr><td>356938035643809</td><td>stolen</td></tr>
        <tr><td>490154203237518</td><td>unpaid installment</td></tr>
      </tbody>
    </table>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
      <button type="button" class="btn-outline" id="bl-sample-dl">Download sample .csv</button>
      <button type="button" class="bl-dismiss" id="bl-format-close" style="background:var(--text-muted)">Close</button>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('order-modal');
  var body  = document.getElementById('om-body');
  var banner = document.getElementById('om-banner');

  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];});}
  function classify(k,v){var x=String(v).trim(),key=String(k).toLowerCase();
    if(/^(blacklisted|stolen|lost|fraud|denied|expired|invalid|sold|locked)$/i.test(x))return 'danger';
    if(/^(activated|active|clean|unlocked|covered|in warranty)$/i.test(x))return 'success';
    var alert=/(find\s*my|fmi|icloud|mdm|sim.?lock|activation\s*lock|locked|jailbreak)/i;
    if(/^on$/i.test(x))return alert.test(key)?'danger':'success';
    if(/^off$/i.test(x))return alert.test(key)?'success':'muted';
    var warnY=/(repair|blacklist|fraud|lost|stolen|replaced|refurbished|demo|jailbreak|loaner)/i;
    if(/^yes$/i.test(x))return warnY.test(key)?'warn':'success';
    if(/^no$/i.test(x))return warnY.test(key)?'success':null;
    return null;}
  function valueHtml(k,v){var c=classify(k,v),t=/^[\w.+/-]{1,16}$/.test(String(v).trim());
    return (c&&t)?'<span class="pill pill-'+c+'">'+esc(v)+'</span>':esc(v);}

  function openModal(){ modal.hidden=false; }
  function closeModal(){ modal.hidden=true; }
  document.getElementById('om-close').addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){ if(e.target===modal) closeModal(); });

  document.querySelectorAll('.js-view').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-id');
      banner.textContent = 'Loading…';
      body.innerHTML = '<div class="rline">Loading result…</div>';
      openModal();
      fetch('/api/orders/view.php?id='+encodeURIComponent(id), {credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(d){
          if(!d.ok){ banner.textContent='Not found'; body.innerHTML='<div class="rline">'+esc(d.error||'Error')+'</div>'; return; }
          banner.textContent = (d.service||'Result');
          var det = d.details||{};
          var html='';
          if (d.details_curated) {
            Object.keys(det).forEach(function(k){ var v=det[k]; if(v===''||v==null)return;
              if(Array.isArray(v)){ if(!v.length)return;
                html+='<div class="rline rline--section"><span class="rk">'+esc(k)+':</span></div>';
                v.forEach(function(item){ html+='<div class="rline rline--sub">'+esc(item)+'</div>'; }); }
              else if(String(k).toLowerCase()==='model'){ html+='<div class="rline rline--model"><span class="rk">Model:</span> <strong>'+esc(v)+'</strong></div>'; }
              else { html+='<div class="rline"><span class="rk">'+esc(k)+':</span> '+valueHtml(k,v)+'</div>'; } });
            if(html==='') html='<div class="rline">No data available.</div>';
          } else {
            var brand=d.brand||det['Brand Name']||det.Brand||'';
            var model=d.model||det['Model Name']||det.Model||'';
            html='<div class="rline rline--model"><span class="rk">Model:</span> <strong>'+esc((brand+' '+model).trim()||'Device')+'</strong></div>';
            var skip={'brand name':1,'brand':1,'model':1,'model name':1,'model description':1,'manufacturer':1};
            Object.keys(det).forEach(function(k){ if(skip[k.toLowerCase()])return; var v=det[k]; if(v===''||v==null)return;
              html+='<div class="rline"><span class="rk">'+esc(k)+':</span> '+valueHtml(k,v)+'</div>'; });
          }
          body.innerHTML = html;
        }).catch(function(){ banner.textContent='Error'; body.innerHTML='<div class="rline">Network error.</div>'; });
    });
  });

  // ---- report / un-report toggle (red state persists; cancellable) ----
  function setReported(imei, on){
    if(!imei) return;
    document.querySelectorAll('.js-report[data-imei="'+imei+'"]').forEach(function(b){
      b.classList.toggle('is-reported', on);
      b.title = on ? 'Reported — click to cancel' : 'Report this IMEI as blacklisted';
    });
  }
  document.querySelectorAll('.js-report').forEach(function(btn){
    btn.addEventListener('click', function(){
      if(btn.classList.contains('busy')) return;
      var id = btn.getAttribute('data-id');
      var imei = btn.getAttribute('data-imei');
      var payload;
      if(btn.classList.contains('is-reported')){
        if(!confirm('Cancel your blacklist report for this IMEI?')) return;
        payload = {public_id:id, action:'unreport'};
      } else {
        if(!confirm('Report this IMEI as blacklisted? Anyone who checks it afterwards will be warned.')) return;
        payload = {public_id:id, action:'report', reason:(prompt('Reason (optional) — e.g. stolen, unpaid installment:') || '')};
      }
      btn.classList.add('busy');
      fetch('/api/blacklist/report.php', {method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
        .then(function(r){return r.json();})
        .then(function(d){
          btn.classList.remove('busy');
          if(!d.ok){ alert(d.error||'Could not update report.'); return; }
          setReported(imei, !!d.reported);
        }).catch(function(){ btn.classList.remove('busy'); alert('Network error.'); });
    });
  });

  // ---- file-format popup + sample download (no export of our data) ----
  var fmtModal = document.getElementById('bl-format-modal');
  document.getElementById('bl-format').addEventListener('click', function(){ fmtModal.hidden=false; });
  document.getElementById('bl-format-close').addEventListener('click', function(){ fmtModal.hidden=true; });
  fmtModal.addEventListener('click', function(e){ if(e.target===fmtModal) fmtModal.hidden=true; });
  document.getElementById('bl-sample-dl').addEventListener('click', function(){
    var csv = 'IMEI,Reason\n356938035643809,stolen\n490154203237518,unpaid installment\n';
    var a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'imei-blacklist-sample.csv';
    document.body.appendChild(a); a.click(); a.remove();
  });

  // ---- bulk import (.csv / .xlsx) ----
  var msg = document.getElementById('bl-import-msg');
  var fileInput = document.getElementById('bl-import-file');
  var importBtn = document.getElementById('bl-import-btn');
  importBtn.addEventListener('click', function(){ fileInput.click(); });
  fileInput.addEventListener('change', function(){
    if(!fileInput.files || !fileInput.files.length) return;
    var fd = new FormData(); fd.append('file', fileInput.files[0]);
    importBtn.disabled = true;
    msg.hidden = false; msg.className = 'bl-import-msg'; msg.textContent = 'Importing…';
    fetch('/api/blacklist/import.php', {method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(d){
        importBtn.disabled = false; fileInput.value = '';
        if(!d.ok){ msg.className='bl-import-msg bl-import-msg--err'; msg.textContent=d.error||'Import failed.'; return; }
        msg.className = 'bl-import-msg bl-import-msg--ok';
        msg.textContent = 'Imported ' + d.imported + ' IMEI(s)' + (d.skipped ? (', skipped ' + d.skipped + ' invalid') : '') + '. Reloading…';
        setTimeout(function(){ location.reload(); }, 1200);
      }).catch(function(){ importBtn.disabled=false; fileInput.value=''; msg.className='bl-import-msg bl-import-msg--err'; msg.textContent='Network error.'; });
  });
})();
</script>
<?php layout_foot(); ?>
