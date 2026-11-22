<?php
declare(strict_types=1);
/**
 * imeihub — Lightweight Embeddable Iframe Widget (/embed-imei-checker.php)
 * Explicitly allows cross-origin framing so partner websites & GSM forums can embed it.
 */
header('Content-Type: text/html; charset=UTF-8');
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors *');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Free IMEI &amp; Apple Serial Checker · imeihub</title>
<meta name="robots" content="noindex, follow">
<style>
  * { box-sizing: border-box; }
  body { margin:0; padding:16px; font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",Roboto,sans-serif; background:#fff; color:#111827; }
  .hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:8px; }
  .title { font-size:0.98rem; font-weight:700; color:#0f172a; }
  .badge { font-size:0.72rem; background:#eef2ff; color:#4f46e5; padding:2px 8px; border-radius:999px; font-weight:600; }
  .row { display:flex; gap:8px; flex-wrap:wrap; }
  select, input { padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:0.92rem; color:#111827; background:#fff; }
  input { flex:1; min-width:170px; }
  button { padding:10px 16px; border:none; border-radius:8px; background:#4f46e5; color:#fff; font-weight:600; cursor:pointer; }
  button:hover { background:#4338ca; }
  .msg { margin-top:8px; font-size:0.82rem; color:#4b5563; }
  .foot { margin-top:10px; font-size:0.78rem; color:#6b7280; }
  .foot a { color:#4f46e5; text-decoration:none; font-weight:600; }
</style>
</head>
<body>
  <div class="hdr">
    <div class="title">Free IMEI &amp; iCloud Status Checker</div>
    <span class="badge">Instant Lookup</span>
  </div>
  <form id="f" class="row">
    <select id="svc" aria-label="Check Type">
      <option value="free-imei-check">Free IMEI Check ($0.00)</option>
      <option value="apple-icloud">iCloud / FMI Clean-Lost</option>
      <option value="apple-warranty">AppleCare Warranty</option>
      <option value="apple-sim-lock">Carrier / SIM-Lock</option>
      <option value="blacklist">Blacklist Check</option>
    </select>
    <input id="q" type="text" maxlength="18" placeholder="Enter 15-digit IMEI or Serial..." required>
    <button type="submit">Check Now</button>
  </form>
  <div id="msg" class="msg">Dial <strong>*#06#</strong> on any phone to view its 15-digit IMEI.</div>
  <div class="foot">Powered by <a href="https://imeihub.net/service.php?slug=free-imei-check" target="_blank" rel="noopener">imeihub.net</a></div>

  <script>
    function luhnOk(s) {
      var sum = 0;
      for (var i = 0; i < 15; i++) {
        var d = parseInt(s[i], 10);
        if (i % 2 === 1) { d *= 2; if (d > 9) d -= 9; }
        sum += d;
      }
      return sum % 10 === 0;
    }
    document.getElementById('f').addEventListener('submit', function (e) {
      e.preventDefault();
      var val = document.getElementById('q').value.replace(/[\s-]/g, '').toUpperCase();
      var slug = document.getElementById('svc').value;
      var msg = document.getElementById('msg');
      if (/^\d{15}$/.test(val)) {
        if (!luhnOk(val)) {
          msg.innerHTML = "<span style='color:#dc2626;'>Invalid IMEI checksum &mdash; please verify the 15 digits.</span>";
          return;
        }
      } else if (!/^[A-Z0-9]{10,12}$/.test(val)) {
        msg.innerHTML = "<span style='color:#dc2626;'>Enter a 15-digit IMEI or 10&ndash;12 character Apple Serial Number.</span>";
        return;
      }
      msg.innerHTML = "<span style='color:#059669;'>Opening live report on imeihub.net&hellip;</span>";
      window.open('https://imeihub.net/service.php?slug=' + encodeURIComponent(slug) + '&imei=' + encodeURIComponent(val), '_blank', 'noopener');
    });
  </script>
</body>
</html>
