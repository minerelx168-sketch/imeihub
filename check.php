<?php
declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/credits.php';
require __DIR__ . '/includes/db.php';

$user    = auth_require();
$userId  = (int) $user['id'];
$balance = credits_get_balance($userId, true);

$categories = require __DIR__ . '/data/service_categories.php';

// Pull all active services so the dropdown only shows things that
// actually charge correctly. Keyed by code for O(1) lookups while
// rendering the optgroups.
$services = [];
try {
    $stmt = db()->query(
        "SELECT code, name, cost FROM service_prices
         WHERE active = 1 AND code NOT LIKE '\\_%' ESCAPE '\\\\'"
    );
    foreach ($stmt->fetchAll() as $r) {
        $services[$r['code']] = $r;
    }
} catch (Throwable $e) {
    // Render the page without options if DB is down.
}

// Default selection: the cheapest paid Apple check, falls back to Free.
$defaultCode = 'IMEI_BASIC';
foreach (['APPLE_ICLOUD_STATUS', 'APPLE_BASIC', 'IMEI_BASIC'] as $candidate) {
    if (isset($services[$candidate])) { $defaultCode = $candidate; break; }
}

layout_head('Check IMEI · imeihub', 'Run any IMEI lookup from a single grouped service picker.');
?>
<section class="check-shell">
    <div class="container container--narrow">
        <header class="check-head">
            <p class="hero-eyebrow" style="color:var(--text-muted);border-color:var(--border);background:var(--surface-alt)">IMEI Check</p>
            <h1>Run a check</h1>
            <p class="check-sub">
                Pick a service, paste a 15-digit IMEI (or serial), and we'll
                run the lookup against our wholesale network.
            </p>
            <p class="check-balance">
                Balance: <strong><?= credits_format_usd($balance) ?></strong>
                &middot; <a href="/topup.php">Top up</a>
            </p>
        </header>

        <form id="check-form" class="check-form" autocomplete="off" novalidate>
            <fieldset>
                <legend>IMEI or serial</legend>
                <input
                    id="imei"
                    name="imei"
                    type="text"
                    inputmode="numeric"
                    pattern="\d*"
                    maxlength="17"
                    placeholder="Enter IMEI / Serial"
                    autocomplete="off"
                    required>
                <p class="hint" style="color:var(--text-muted);text-align:left;margin:8px 4px 0;">
                    Dial <code>*#06#</code> on the phone to display the IMEI.
                </p>
            </fieldset>

            <fieldset>
                <legend>Service</legend>
                <select name="code" id="service-select" required>
                    <?php foreach ($categories as $group):
                        $groupServices = array_filter(
                            $group['codes'],
                            fn($c) => isset($services[$c])
                        );
                        if (!$groupServices) continue;
                    ?>
                        <optgroup label="<?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php foreach ($groupServices as $code):
                                $svc  = $services[$code];
                                $cost = (float) $svc['cost'];
                                $label = $svc['name'];
                                $priceLabel = $cost === 0.0
                                    ? 'FREE'
                                    : '$' . number_format($cost, 2);
                            ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                        data-cost="<?= htmlspecialchars((string) $cost, ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $code === $defaultCode ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> &mdash; <?= $priceLabel ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <div id="selected-summary" class="check-summary">
                <div>
                    <span class="check-summary-label">Selected</span>
                    <span class="check-summary-name" id="sel-name">&nbsp;</span>
                </div>
                <div class="check-summary-cost">
                    <span class="check-summary-label">Price</span>
                    <strong id="sel-cost">&nbsp;</strong>
                </div>
            </div>

            <button type="submit" id="check-submit" class="btn-primary-block">
                <span class="btn-label">Run check</span>
                <span class="btn-spinner" aria-hidden="true"></span>
            </button>
            <p id="check-error" class="field-error" hidden></p>
        </form>

        <div id="result" class="result" hidden></div>
    </div>
</section>

<script>
(function () {
    var form    = document.getElementById('check-form');
    var sel     = document.getElementById('service-select');
    var imei    = document.getElementById('imei');
    var submit  = document.getElementById('check-submit');
    var err     = document.getElementById('check-error');
    var resultEl = document.getElementById('result');
    var selName = document.getElementById('sel-name');
    var selCost = document.getElementById('sel-cost');
    var requestStartedAt = 0;
    var lastReport = null;
    var progressTimer = null;
    var progressStartedAt = 0;

    function updateSummary() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt) return;
        var raw = opt.text || '';
        // option text is "Name — $X.XX — ⚡ Instant"; show just the name part.
        var name = raw.split(' — ')[0];
        selName.textContent = name;
        var cost = parseFloat(opt.getAttribute('data-cost') || '0');
        selCost.textContent = cost === 0 ? 'FREE' : '$' + cost.toFixed(2);
    }
    sel.addEventListener('change', updateSummary);
    updateSummary();

    imei.addEventListener('input', function () {
        var cleaned = imei.value.replace(/\D+/g, '').slice(0, 17);
        if (cleaned !== imei.value) imei.value = cleaned;
    });

    function luhnOk(s) {
        if (!/^\d{15}$/.test(s)) return false;
        var sum = 0;
        for (var i = 0; i < 15; i++) {
            var d = parseInt(s[i], 10);
            if (i % 2 === 1) { d *= 2; if (d > 9) d -= 9; }
            sum += d;
        }
        return sum % 10 === 0;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    function classifyValue(key, val) {
        var v = String(val).trim(), k = String(key).toLowerCase();
        if (/^(blacklisted|stolen|lost|fraud|denied|expired|invalid|sold)/i.test(v)) return 'danger';
        if (/^(locked)$/i.test(v)) return 'danger';
        if (/^(activated|active|clean|unlocked|covered|in warranty)$/i.test(v)) return 'success';
        var alertKey = /(find\s*my|fmi|icloud|mdm|sim.?lock|activation\s*lock|locked|jailbreak)/i;
        if (/^on$/i.test(v))  return alertKey.test(k) ? 'danger'  : 'success';
        if (/^off$/i.test(v)) return alertKey.test(k) ? 'success' : 'muted';
        var warnYesKey = /(repair|blacklist|fraud|lost|stolen|replaced|refurbished|demo|jailbreak|loaner)/i;
        if (/^yes$/i.test(v)) return warnYesKey.test(k) ? 'warn'    : 'success';
        if (/^no$/i.test(v))  return warnYesKey.test(k) ? 'success' : null;
        return null;
    }
    function valueHtml(k, v) {
        var c = classifyValue(k, v), t = /^[\w.+/-]{1,16}$/.test(String(v).trim());
        return (c && t) ? '<span class="pill pill-' + c + '">' + escapeHtml(v) + '</span>' : escapeHtml(v);
    }
    function renderStatus(type, title, innerHtml) {
        stopProgress(); // any real outcome (success / error / refund) replaces the in-progress card
        resultEl.hidden = false;
        resultEl.className = 'result result--report';
        resultEl.innerHTML =
            '<div class="result-banner result-banner--' + type + '">' + escapeHtml(title) + '</div>' +
            '<div class="result-card">' + innerHtml + '</div>';
    }

    // In-progress card: replaces the silent spinner during a lookup so the user
    // can see the request is still alive. Pure UX - it does not change the
    // server flow, the credit deduction, or the timeout / refund behaviour.
    // The stage label + bar advance off the CLIENT clock (we don't actually
    // know upstream progress); the bar caps at 95% until the result arrives.
    function renderInProgress(serviceName, isPaid) {
        resultEl.hidden = false;
        resultEl.className = 'result result--progress';
        resultEl.innerHTML =
            '<div class="result-progress" role="status" aria-live="polite">' +
              '<div class="result-progress-title">Working on your check…</div>' +
              '<div class="result-progress-service">' + escapeHtml(serviceName || 'IMEI lookup') + '</div>' +
              '<div class="result-progress-bar"><div class="result-progress-bar-fill" id="rpfill"></div></div>' +
              '<div class="result-progress-stage" id="rpstage">Looking up your IMEI…</div>' +
            '</div>';
        progressStartedAt = Date.now();
        if (progressTimer) clearInterval(progressTimer);
        progressTimer = setInterval(function () { updateProgress(isPaid); }, 1000);
        updateProgress(isPaid);
    }
    function updateProgress(isPaid) {
        var stageEl = document.getElementById('rpstage');
        var fillEl  = document.getElementById('rpfill');
        if (!stageEl || !fillEl) { stopProgress(); return; }
        var t = (Date.now() - progressStartedAt) / 1000;
        var stage, pct;
        if (t < 2)        { stage = 'Looking up your IMEI…';                                              pct = t * 9; }
        else if (t < 7)   { stage = 'Querying provider servers…';                                          pct = 18 + (t - 2) * 6.4; }
        else if (t < 15)  { stage = 'Still working — this can take a moment for premium reports…';        pct = 50 + (t - 7) * 3.125; }
        else if (t < 25)  { stage = 'Provider is still processing — typical for premium GSX lookups…';    pct = 75 + (t - 15) * 1.3; }
        else              { stage = 'Almost there — please hold on…';                                     pct = Math.min(95, 88 + (t - 25) * 0.4); }
        if (!isPaid && t >= 2) stage = 'Looking up your IMEI…'; // free lookups are local; don't promise provider work
        stageEl.textContent = stage;
        fillEl.style.width = Math.max(0, Math.min(95, pct)) + '%';
    }
    function stopProgress() {
        if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
    }
    function showBlacklistPopup(bl) {
        if (!bl) return;
        var oldp = document.getElementById('bl-popup'); if (oldp) oldp.remove();
        var n = bl.reports || 1, when = bl.first_reported ? String(bl.first_reported).slice(0, 10) : '';
        var reason = bl.reason ? '<p class="bl-reason">Reported reason: ' + escapeHtml(bl.reason) + '</p>' : '';
        var ov = document.createElement('div'); ov.id = 'bl-popup'; ov.className = 'bl-overlay';
        ov.innerHTML = '<div class="bl-card" role="alertdialog" aria-modal="true">' +
            '<div class="bl-badge">&#9888; BLACKLIST ALERT</div>' +
            '<h3>This IMEI has been reported</h3>' +
            '<p>Reported <strong>' + n + ' time' + (n > 1 ? 's' : '') + '</strong> by users on this platform' +
            (when ? ' (first on ' + escapeHtml(when) + ')' : '') + '. It may be lost, stolen, or carry outstanding debt &mdash; proceed with caution before buying or financing this device.</p>' +
            reason + '<button type="button" class="bl-dismiss">I understand</button></div>';
        document.body.appendChild(ov);
        function close() { ov.remove(); }
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.bl-dismiss').addEventListener('click', close);
    }
    function showError(msg, title, type) {
        renderStatus(type || 'error', title || 'Order Failed', '<p class="result-msg">' + escapeHtml(msg) + '</p>');
    }
    function renderResult(data) {
        var details = data.details || {};
        var lines;
        if (data.details_curated) {
            // Server pinned the exact fields + order for this service; render
            // them verbatim. Array values are repeating sections (Cases /
            // Repair / Warranty history) shown as indented sub-lists.
            lines = '';
            Object.keys(details).forEach(function (k) {
                var v = details[k];
                if (v === null || v === undefined || v === '') return;
                if (Array.isArray(v)) {
                    if (!v.length) return;
                    lines += '<div class="rline rline--section"><span class="rk">' + escapeHtml(k) + ':</span></div>';
                    v.forEach(function (item) { lines += '<div class="rline rline--sub">' + escapeHtml(item) + '</div>'; });
                } else if (String(k).toLowerCase() === 'model') {
                    lines += '<div class="rline rline--model"><span class="rk">Model:</span> <strong>' + escapeHtml(v) + '</strong></div>';
                } else {
                    lines += '<div class="rline"><span class="rk">' + escapeHtml(k) + ':</span> ' + valueHtml(k, v) + '</div>';
                }
            });
            if (lines === '') lines = '<div class="rline">No data available for this IMEI.</div>';
        } else {
            var brand = data.brand || details['Brand Name'] || details.Brand || details.Manufacturer || '';
            var model = data.model || details['Model Name'] || details.Model || details['Model Description'] || '';
            var modelStr = (details['Model Description'] || details['Model'] || (brand + ' ' + model)).trim() || details['Model Name'] || 'Unknown device';
            var skip = {'brand name':1,'brand':1,'manufacturer':1,'model':1,'model name':1,'model description':1};
            lines = '<div class="rline rline--model"><span class="rk">Model:</span> <strong>' + escapeHtml(modelStr) + '</strong></div>';
            Object.keys(details).forEach(function (k) {
                if (skip[String(k).toLowerCase()]) return;
                var v = details[k]; if (v === null || v === undefined || v === '') return;
                lines += '<div class="rline"><span class="rk">' + escapeHtml(k) + ':</span> ' + valueHtml(k, v) + '</div>';
            });
        }
        var secs = requestStartedAt ? ((Date.now() - requestStartedAt) / 1000).toFixed(1) : null;
        var dateStr = new Date().toLocaleString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'}).toUpperCase();
        var blWarn = data.blacklist ? '<div class="bl-inline">&#9888; This IMEI was reported ' + (data.blacklist.reports || 1) + ' time(s) as blacklisted &mdash; proceed with caution.</div>' : '';
        // Free lookups don't include the community blacklist alert (paid feature).
        var freeNote = data.free
            ? '<div class="result-free-note"><strong>&#8505; Heads up:</strong> Community blacklist alerts are not included with free lookups. Pick a paid service above to see if this IMEI has been reported as lost / stolen / outstanding debt.</div>'
            : '';
        lastReport = { data: data, imei: (imei.value.replace(/\D+/g, '') || 'report'), secs: secs, dateStr: dateStr };
        renderStatus('success', 'Order Processed!', blWarn +
            '<div class="result-lines">' + lines + '</div>' + freeNote +
            '<div class="result-chips">' +
              '<span class="result-chip">' + (secs !== null ? escapeHtml(secs) + ' SECONDS' : 'COMPLETED') + '</span>' +
              '<span class="result-chip">' + escapeHtml(dateStr) + '</span>' +
            '</div>');
        resultEl.insertAdjacentHTML('beforeend',
            '<div class="result-actions" role="group" aria-label="Save or share result">' +
              '<button type="button" class="result-action" data-action="save" aria-label="Save as image">' + ICON_SAVE + '<span>Save</span></button>' +
              '<button type="button" class="result-action" data-action="share" aria-label="Share">' + ICON_SHARE + '<span>Share</span></button>' +
            '</div>');
        showBlacklistPopup(data.blacklist);
    }

    var ICON_SAVE  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
    var ICON_SHARE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>';

    // Re-collect the rendered fields as a flat list so the PNG export matches
    // the on-screen card (curated order, sections, model, status pills).
    function collectReportRows(data) {
        var details = data.details || {}, rows = [];
        if (data.blacklist) {
            rows.push({ kind: 'warn', text: '⚠ This IMEI was reported ' + (data.blacklist.reports || 1) + ' time(s) as blacklisted — proceed with caution.' });
        }
        if (data.details_curated) {
            Object.keys(details).forEach(function (k) {
                var v = details[k];
                if (v === null || v === undefined || v === '') return;
                if (Array.isArray(v)) {
                    if (!v.length) return;
                    rows.push({ kind: 'section', key: k });
                    v.forEach(function (it) { rows.push({ kind: 'sub', text: String(it) }); });
                } else if (String(k).toLowerCase() === 'model') {
                    rows.push({ kind: 'model', val: String(v) });
                } else {
                    rows.push({ kind: 'kv', key: k, val: String(v) });
                }
            });
        } else {
            var brand = data.brand || details['Brand Name'] || details.Brand || details.Manufacturer || '';
            var model = data.model || details['Model Name'] || details.Model || details['Model Description'] || '';
            var modelStr = (details['Model Description'] || details['Model'] || (brand + ' ' + model)).trim() || details['Model Name'] || 'Unknown device';
            rows.push({ kind: 'model', val: modelStr });
            var skip = { 'brand name':1,'brand':1,'manufacturer':1,'model':1,'model name':1,'model description':1 };
            Object.keys(details).forEach(function (k) {
                if (skip[String(k).toLowerCase()]) return;
                var v = details[k]; if (v === null || v === undefined || v === '') return;
                rows.push({ kind: 'kv', key: k, val: String(v) });
            });
        }
        return rows;
    }

    // Draw the report to an offscreen canvas (no external lib, so it works in
    // mobile Safari where foreignObject->canvas is unreliable). Two passes:
    // measure height first, then render.
    function buildReportCanvas(report) {
        var data = report.data || {}, rows = collectReportRows(data);
        var DPR = Math.max(2, Math.min(3, window.devicePixelRatio || 1));
        var W = 760, padX = 44, maxW = W - padX * 2;
        var FF = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif';
        var INK = '#0b1220', SUB = '#64748b', LINE = '#e9edf5';
        var PILL = {
            success: { bg:'#dcfce7', fg:'#166534' }, danger: { bg:'#fee2e2', fg:'#b91c1c' },
            warn: { bg:'#fef3c7', fg:'#92400e' }, muted: { bg:'#eef2f7', fg:'#475569' }
        };
        var scratch = document.createElement('canvas').getContext('2d');
        function wrap(ctx, text, mw) {
            var words = String(text).split(/\s+/), out = [], cur = '';
            for (var i = 0; i < words.length; i++) {
                var t = cur ? cur + ' ' + words[i] : words[i];
                if (ctx.measureText(t).width > mw && cur) { out.push(cur); cur = words[i]; }
                else cur = t;
            }
            if (cur) out.push(cur);
            return out.length ? out : [''];
        }
        function rr(ctx, x, y, w, h, r) {
            ctx.beginPath(); ctx.moveTo(x + r, y);
            ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
            ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
        }
        function pillOk(v) { return /^[\w.+/\-]{1,16}$/.test(String(v).trim()); }

        function run(ctx, draw) {
            var y = 36;
            ctx.font = '700 24px ' + FF;
            if (draw) { ctx.fillStyle = '#4f46e5'; ctx.fillText('imeihub', padX, y); }
            y += 36;
            if (draw) { ctx.strokeStyle = LINE; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(padX, y); ctx.lineTo(W - padX, y); ctx.stroke(); }
            y += 22;
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                if (r.kind === 'warn') {
                    ctx.font = '600 15px ' + FF;
                    var wl = wrap(ctx, r.text, maxW - 28), bh = wl.length * 21 + 22;
                    if (draw) {
                        rr(ctx, padX, y, maxW, bh, 12); ctx.fillStyle = '#fef2f2'; ctx.fill();
                        ctx.strokeStyle = '#fecaca'; ctx.lineWidth = 1; ctx.stroke();
                        ctx.fillStyle = '#b91c1c';
                        for (var j = 0; j < wl.length; j++) ctx.fillText(wl[j], padX + 14, y + 11 + j * 21);
                    }
                    y += bh + 16;
                } else if (r.kind === 'model') {
                    ctx.font = '700 25px ' + FF;
                    var ml = wrap(ctx, r.val, maxW);
                    if (draw) { ctx.fillStyle = INK; for (var m = 0; m < ml.length; m++) ctx.fillText(ml[m], padX, y + m * 32); }
                    y += ml.length * 32 + 12;
                } else if (r.kind === 'section') {
                    ctx.font = '700 18px ' + FF;
                    if (draw) { ctx.fillStyle = INK; ctx.fillText(r.key + ':', padX, y); }
                    y += 30;
                } else if (r.kind === 'sub') {
                    ctx.font = '400 15px ' + FF;
                    var sl = wrap(ctx, '•  ' + r.text, maxW - 16);
                    if (draw) { ctx.fillStyle = SUB; for (var s = 0; s < sl.length; s++) ctx.fillText(sl[s], padX + 16, y + s * 21); }
                    y += sl.length * 21 + 4;
                } else {
                    var label = r.key + ': ';
                    ctx.font = '400 17px ' + FF;
                    var lw = ctx.measureText(label).width;
                    var cls = classifyValue(r.key, r.val);
                    if (cls && pillOk(r.val)) {
                        var val = String(r.val).trim();
                        ctx.font = '700 14px ' + FF;
                        var pw = ctx.measureText(val).width + 20;
                        if (draw) {
                            ctx.font = '400 17px ' + FF; ctx.fillStyle = SUB; ctx.fillText(label, padX, y);
                            var pc = PILL[cls] || PILL.muted;
                            rr(ctx, padX + lw + 2, y - 1, pw, 24, 12); ctx.fillStyle = pc.bg; ctx.fill();
                            ctx.font = '700 14px ' + FF; ctx.fillStyle = pc.fg; ctx.fillText(val, padX + lw + 12, y + 3);
                        }
                        y += 32;
                    } else {
                        ctx.font = '400 17px ' + FF;
                        var vlines = wrap(ctx, String(r.val), maxW - lw);
                        if (draw) {
                            ctx.fillStyle = SUB; ctx.fillText(label, padX, y);
                            ctx.fillStyle = INK;
                            for (var v2 = 0; v2 < vlines.length; v2++) ctx.fillText(vlines[v2], padX + lw, y + v2 * 24);
                        }
                        y += vlines.length * 24 + 7;
                    }
                }
            }
            y += 6;
            ctx.font = '600 12px ' + FF;
            var chip = ((report.secs != null ? report.secs + ' SECONDS   ·   ' : '') + (report.dateStr || '')).toUpperCase();
            if (draw) { ctx.fillStyle = SUB; ctx.fillText(chip, padX, y); }
            y += 28;
            if (draw) { ctx.strokeStyle = LINE; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(padX, y); ctx.lineTo(W - padX, y); ctx.stroke(); }
            y += 14;
            ctx.font = '600 13px ' + FF;
            if (draw) { ctx.fillStyle = SUB; ctx.fillText('imeihub.net', padX, y); }
            return y + 30;
        }

        scratch.textBaseline = 'top';
        var H = run(scratch, false);
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(W * DPR); canvas.height = Math.round(H * DPR);
        var ctx = canvas.getContext('2d');
        ctx.scale(DPR, DPR); ctx.textBaseline = 'top';
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
        run(ctx, true);
        return canvas;
    }

    function reportFileName() {
        return 'imeihub-' + ((lastReport && lastReport.imei) || 'report') + '.png';
    }
    function withReportBlob(cb) {
        if (!lastReport) return;
        try {
            buildReportCanvas(lastReport).toBlob(function (blob) { if (blob) cb(blob); }, 'image/png');
        } catch (e) { /* canvas/toBlob unsupported */ }
    }
    function saveReport() {
        withReportBlob(function (blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = reportFileName();
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        });
    }
    function shareReport() {
        withReportBlob(function (blob) {
            var file = null;
            try { file = new File([blob], reportFileName(), { type: 'image/png' }); } catch (e) {}
            if (file && navigator.canShare && navigator.canShare({ files: [file] }) && navigator.share) {
                navigator.share({ files: [file], title: 'IMEI Report', text: 'IMEI check result — imeihub.net' }).catch(function () {});
            } else {
                saveReport(); // no native share (e.g. desktop Firefox): download instead
            }
        });
    }
    resultEl.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.result-action') : null;
        if (!btn) return;
        var act = btn.getAttribute('data-action');
        if (act === 'save') saveReport();
        else if (act === 'share') shareReport();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        err.hidden = true;
        resultEl.hidden = true;
        resultEl.className = 'result';

        var imeiVal = imei.value.replace(/\D+/g, '');
        if (!luhnOk(imeiVal)) {
            err.textContent = 'Invalid IMEI. Please enter 15 digits (check for typos).';
            err.hidden = false;
            return;
        }

        submit.classList.add('loading');
        submit.disabled = true;
        requestStartedAt = Date.now();

        // Show the staged in-progress card so the user can see we're working
        // rather than staring at a frozen spinner.
        var selOpt = sel.options[sel.selectedIndex];
        var selServiceName = (selOpt && selOpt.text || '').split(' — ')[0] || 'IMEI lookup';
        var selCostNum = parseFloat(selOpt && selOpt.getAttribute('data-cost') || '0');
        renderInProgress(selServiceName, selCostNum > 0);
        resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Bound the wait so a hung upstream doesn't leave the user staring at
        // a spinner forever. The server's own shutdown handler refunds the
        // wallet if the script dies before the result is persisted.
        var abortCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var fetchTimer = setTimeout(function () { if (abortCtrl) abortCtrl.abort(); }, 75000);

        fetch('/api/services/use.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: sel.value, input: { imei: imeiVal } }),
            signal: abortCtrl ? abortCtrl.signal : undefined
        })
        .then(function (r) {
            return r.json().then(function (j) { return { status: r.status, body: j }; });
        })
        .then(function (resp) {
            if (resp.status === 401) {
                stopProgress();
                var next = encodeURIComponent(window.location.pathname);
                window.location.href = '/login.php?next=' + next;
                return;
            }
            if (resp.status === 402 || (resp.body && resp.body.error_code === 'INSUFFICIENT_CREDIT')) {
                renderStatus('warn', 'Insufficient Credit',
                    '<p class="result-msg">' + escapeHtml(resp.body.error || 'Please top up your wallet to run this check.') + '</p>' +
                    '<p class="result-cta"><a href="/topup.php" class="link-more">Top up credit &rarr;</a></p>');
                resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            if (resp.body && resp.body.status === 'processing' && resp.body.public_id) {
                renderStatus('info', 'Processing…',
                    '<p class="imei-meta" style="text-align:center">Reference <code>' + escapeHtml(resp.body.public_id) + '</code></p>' +
                    '<div class="result-processing"><div class="result-spinner" aria-hidden="true"></div><div><strong>Your order has been placed with the provider.</strong>' +
                    '<p class="dashboard-subtitle" style="margin:6px 0 0;">This service takes a few minutes; the result will appear in your <a href="/orders.php">order history</a>.</p></div></div>');
                resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                showBlacklistPopup(resp.body.blacklist);
                return;
            }
            if (!resp.body || resp.body.ok !== true) {
                var refunded = resp.body && resp.body.refunded;
                var note = refunded ? ' Your credit has been refunded automatically.' : '';
                showError(((resp.body && resp.body.error) || 'Lookup failed (HTTP ' + resp.status + ').') + note,
                    refunded ? 'Order Refunded' : 'Order Failed', refunded ? 'warn' : 'error');
                resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            renderResult(resp.body);
            resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })
        .catch(function (e) {
            var aborted = e && (e.name === 'AbortError');
            // Client stopped waiting, but the server may have finished anyway -
            // don't claim a refund. Send the user to Orders for the real outcome.
            var html = aborted
                ? '<p class="result-msg">This took longer than expected, so we stopped waiting &mdash; but your ' +
                  'lookup may still be completing. Open your <a href="/orders.php" class="link-more">order history</a>: ' +
                  'if it succeeded the result is there, and if the provider never responded your credit is refunded ' +
                  'automatically. Please check there before submitting again.</p>'
                : '<p class="result-msg">We couldn\'t confirm the result. If you submitted a paid lookup, open your ' +
                  '<a href="/orders.php" class="link-more">order history</a> before retrying &mdash; the result or an ' +
                  'automatic refund will appear there, so you won\'t be double-charged.</p>';
            renderStatus(aborted ? 'warn'  : 'error',
                         aborted ? 'Still Working…' : 'Connection Error', html);
        })
        .finally(function () {
            clearTimeout(fetchTimer);
            submit.classList.remove('loading');
            submit.disabled = false;
        });
    });
})();
</script>
<?php layout_foot(); ?>
