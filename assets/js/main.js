(function () {
    'use strict';

    var form    = document.getElementById('imei-form');
    var input   = document.getElementById('imei');
    var button  = document.getElementById('submit-btn');
    var resultEl = document.getElementById('result');
    var requestStartedAt = 0;

    if (!form) return;

    // Auto-strip non-digits as the user types.
    input.addEventListener('input', function () {
        var cleaned = input.value.replace(/\D+/g, '').slice(0, 17);
        if (cleaned !== input.value) input.value = cleaned;
    });

    function luhnOk(s) {
        if (!/^\d{15}$/.test(s)) return false;
        var sum = 0;
        for (var i = 0; i < 15; i++) {
            var d = parseInt(s[i], 10);
            if (i % 2 === 1) {
                d *= 2;
                if (d > 9) d -= 9;
            }
            sum += d;
        }
        return sum % 10 === 0;
    }

    // Banner + card shell shared by every result state. `type` drives the
    // banner color: success (green), error (red), warn (amber), info (blue).
    function renderStatus(type, title, innerHtml) {
        stopProgress(); // any real outcome (success / error / refund) replaces the in-progress card
        resultEl.hidden = false;
        resultEl.className = 'result result--report';
        resultEl.innerHTML =
            '<div class="result-banner result-banner--' + type + '">' + escapeHtml(title) + '</div>' +
            '<div class="result-card">' + innerHtml + '</div>';
    }

    // In-progress card: replaces the silent spinner during a paid lookup so the
    // user can see we're still working. Pure UX - the server flow, credit
    // deduction, and 75s client abort are unchanged. The stage label + bar
    // advance off the CLIENT clock (we don't know upstream progress) and the
    // bar caps at 95% until the result arrives.
    var progressTimer = null;
    var progressStartedAt = 0;
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

    function showError(msg, title, type) {
        renderStatus(type || 'error', title || 'Order Failed',
            '<p class="result-msg">' + escapeHtml(msg) + '</p>');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    // Pick a color class for a field based on both the key (context) and
    // the value. We need the key because "Yes" / "ON" / "OFF" flip
    // meaning depending on what is being asked - "Find My iPhone: ON" is
    // a problem for a buyer; "Activation Status: Activated" is fine;
    // "Refurbished: No" is good; "Open Repair Case: Yes" is a warning.
    function classifyValue(key, val) {
        var v = String(val).trim();
        var k = String(key).toLowerCase();

        // Unambiguous "danger" tokens.
        if (/^(blacklisted|stolen|lost|fraud|denied|expired|invalid|sold)/i.test(v)) return 'danger';
        if (/^(locked)$/i.test(v)) return 'danger';

        // Unambiguous "success" tokens.
        if (/^(activated|active|clean|unlocked|covered|in warranty)$/i.test(v)) return 'success';

        // ON / OFF - depends on what is being toggled.
        var alertKey = /(find\s*my|fmi|icloud|mdm|sim.?lock|activation\s*lock|locked|jailbreak)/i;
        if (/^on$/i.test(v))  return alertKey.test(k) ? 'danger'  : 'success';
        if (/^off$/i.test(v)) return alertKey.test(k) ? 'success' : 'muted';

        // Yes / No - the "yes is a problem" set is small and explicit.
        var warnYesKey = /(repair|blacklist|fraud|lost|stolen|replaced|refurbished|demo|jailbreak|loaner)/i;
        if (/^yes$/i.test(v)) return warnYesKey.test(k) ? 'warn'    : 'success';
        if (/^no$/i.test(v))  return warnYesKey.test(k) ? 'success' : null;

        return null;
    }

    // Render the "we placed the order, waiting on the provider" state.
    // Used for DHRU services (1-5+ min). The caller starts polling
    // /api/services/status.php?id=<publicId> right after this.
    function renderProcessing(publicId) {
        renderStatus('info', 'Processing…',
            '<p class="imei-meta" style="text-align:center">Reference <code>' + escapeHtml(publicId) + '</code></p>' +
            '<div class="result-processing">' +
              '<div class="result-spinner" aria-hidden="true"></div>' +
              '<div>' +
                '<strong>Your order has been placed with the provider.</strong>' +
                '<p class="dashboard-subtitle" style="margin:6px 0 0;">' +
                  'This service typically takes 1&ndash;5 minutes. This page updates ' +
                  'automatically when the result is ready &mdash; safe to leave open or ' +
                  'come back later via your dashboard.' +
                '</p>' +
              '</div>' +
            '</div>');
    }

    // Poll the status endpoint until the lookup completes or fails.
    // Backs off based on the retry_after the server suggests; gives up
    // after ~10 minutes of attempts, at which point the user can
    // revisit from the dashboard (a cron sweep also keeps stuck rows
    // moving). We re-arm the form so the user can submit a new one
    // without waiting for this poll to finish.
    var activePollTimer = null;
    function pollStatus(publicId, retryAfterSec) {
        if (activePollTimer) clearTimeout(activePollTimer);
        var delay = Math.max(3, parseInt(retryAfterSec, 10) || 8) * 1000;
        var deadline = Date.now() + 10 * 60 * 1000; // 10 min

        function tick() {
            if (Date.now() > deadline) return;
            fetch('/api/services/status.php?id=' + encodeURIComponent(publicId), {
                credentials: 'same-origin',
            }).then(function (r) {
                return r.json().then(function (j) { return { status: r.status, body: j }; });
            }).then(function (resp) {
                var body = resp.body || {};
                if (body.ok && body.status === 'success') {
                    renderResult(body);
                    resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    return;
                }
                if (body.ok === false || body.status === 'failed' || body.status === 'refunded') {
                    var note = body.refunded ? ' Your credit has been refunded automatically.' : '';
                    showError((body.error || 'Lookup failed.') + note,
                        body.refunded ? 'Order Refunded' : 'Order Failed',
                        body.refunded ? 'warn' : 'error');
                    return;
                }
                // Still processing - schedule the next tick.
                activePollTimer = setTimeout(tick, Math.max(3, parseInt(body.retry_after, 10) || 8) * 1000);
            }).catch(function () {
                // Treat as transient - keep polling with a slightly
                // longer gap so we don't hammer the server.
                activePollTimer = setTimeout(tick, delay + 2000);
            });
        }

        activePollTimer = setTimeout(tick, delay);
    }

    // A value is rendered as a colored pill when it is a short status
    // token (EXPIRED / CLEAN / OFF / NO / UNLOCKED ...) that classifyValue
    // can color. Longer values (model names, dates, IMEI numbers, free
    // text like "Out Of Warranty") render as plain text.
    function valueHtml(key, val) {
        var cls = classifyValue(key, val);
        var token = /^[\w.+/-]{1,16}$/.test(String(val).trim());
        if (cls && token) {
            return '<span class="pill pill-' + cls + '">' + escapeHtml(val) + '</span>';
        }
        return escapeHtml(val);
    }

    // Attention popup shown when a checked IMEI has community blacklist
    // reports. bl = { reports, first_reported, reason } or null.
    function showBlacklistPopup(bl) {
        if (!bl) return;
        var old = document.getElementById('bl-popup');
        if (old) old.remove();
        var n = bl.reports || 1;
        var when = bl.first_reported ? String(bl.first_reported).slice(0, 10) : '';
        var reason = bl.reason ? '<p class="bl-reason">Reported reason: ' + escapeHtml(bl.reason) + '</p>' : '';
        var ov = document.createElement('div');
        ov.id = 'bl-popup';
        ov.className = 'bl-overlay';
        ov.innerHTML =
            '<div class="bl-card" role="alertdialog" aria-modal="true">' +
              '<div class="bl-badge">&#9888; BLACKLIST ALERT</div>' +
              '<h3>This IMEI has been reported</h3>' +
              '<p>Reported <strong>' + n + ' time' + (n > 1 ? 's' : '') + '</strong> by users on this platform' +
                (when ? ' (first on ' + escapeHtml(when) + ')' : '') + '. ' +
                'It may be lost, stolen, or carry outstanding debt &mdash; proceed with caution before buying or financing this device.</p>' +
                reason +
              '<button type="button" class="bl-dismiss">I understand</button>' +
            '</div>';
        document.body.appendChild(ov);
        function close() { ov.remove(); }
        ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.bl-dismiss').addEventListener('click', close);
    }

    function renderResult(data) {
        var details = data.details || {};
        var lines;

        if (data.details_curated) {
            // The server already pinned the exact fields + order for this
            // service (data/service_result_fields.php). Render them verbatim;
            // the leading "Model" field becomes the bold heading line.
            lines = '';
            Object.keys(details).forEach(function (k) {
                var v = details[k];
                if (v === null || v === undefined || v === '') return;
                if (Array.isArray(v)) {
                    if (!v.length) return;
                    lines += '<div class="rline rline--section"><span class="rk">' + escapeHtml(k) + ':</span></div>';
                    v.forEach(function (item) {
                        lines += '<div class="rline rline--sub">' + escapeHtml(item) + '</div>';
                    });
                } else if (String(k).toLowerCase() === 'model') {
                    lines += '<div class="rline rline--model"><span class="rk">Model:</span> <strong>' + escapeHtml(v) + '</strong></div>';
                } else {
                    lines += '<div class="rline"><span class="rk">' + escapeHtml(k) + ':</span> ' + valueHtml(k, v) + '</div>';
                }
            });
            if (lines === '') lines = '<div class="rline">No data available for this IMEI.</div>';
        } else {
            var brand = data.brand || details.Brand || details['Brand Name'] || details.Manufacturer || '';
            var model = data.model || details.Model || details['Model Name'] || details['Model Description'] || '';
            var modelStr = (details['Model Description'] || details['Model'] || (brand + ' ' + model)).trim()
                           || details['Model Name'] || 'Unknown device';

            // Keys already represented by the Model line / not worth repeating.
            var skip = { 'brand name': 1, 'brand': 1, 'manufacturer': 1, 'model': 1, 'model name': 1, 'model description': 1 };

            lines = '<div class="rline rline--model"><span class="rk">Model:</span> <strong>' + escapeHtml(modelStr) + '</strong></div>';
            Object.keys(details).forEach(function (k) {
                if (skip[String(k).toLowerCase()]) return;
                var v = details[k];
                if (v === null || v === undefined || v === '') return;
                lines += '<div class="rline"><span class="rk">' + escapeHtml(k) + ':</span> ' + valueHtml(k, v) + '</div>';
            });
        }

        var secs = requestStartedAt ? ((Date.now() - requestStartedAt) / 1000).toFixed(1) : null;
        var dateStr = new Date().toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
        }).toUpperCase();

        var blWarn = data.blacklist
            ? '<div class="bl-inline">&#9888; This IMEI was reported ' + (data.blacklist.reports || 1) +
              ' time(s) as blacklisted &mdash; proceed with caution.</div>'
            : '';
        // Free lookups never include the community blacklist alert (it is a
        // paid-only feature). Tell the user explicitly so a missing alert
        // doesn't read as "this IMEI is clean".
        var freeNote = data.free
            ? '<div class="result-free-note"><strong>&#8505; Heads up:</strong> ' +
              'Community blacklist alerts are not included with free lookups. ' +
              'To check whether this IMEI has been reported as lost / stolen / outstanding debt, ' +
              '<a href="/check.php">run a paid service</a>.</div>'
            : '';
        var inner = blWarn + '<div class="result-lines">' + lines + '</div>' + freeNote +
            '<div class="result-chips">' +
              '<span class="result-chip">' + (secs !== null ? escapeHtml(secs) + ' SECONDS' : 'COMPLETED') + '</span>' +
              '<span class="result-chip">' + escapeHtml(dateStr) + '</span>' +
            '</div>';
        renderStatus('success', 'Order Processed!', inner);
        showBlacklistPopup(data.blacklist);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var imei = input.value.replace(/\D+/g, '');

        resultEl.hidden = true;
        resultEl.className = 'result';

        if (!luhnOk(imei)) {
            showError('Please enter a valid 15-digit IMEI (check for typos).', 'Invalid IMEI', 'warn');
            return;
        }

        form.classList.add('loading');
        button.disabled = true;
        requestStartedAt = Date.now();

        // If the form has a <select name="code">, use the selected option's
        // data-cost to decide between the free (IMEI_BASIC) and paid paths.
        // Otherwise fall back to the static data-paid + data-code attributes
        // that per-service landing pages use.
        var paid, code, serviceName;
        var select = form.querySelector('select[name="code"]');
        if (select) {
            code = select.value;
            var opt = select.options[select.selectedIndex];
            paid = parseFloat(opt && opt.getAttribute('data-cost') || '0') > 0;
            serviceName = ((opt && opt.text) || '').split(' — ')[0];
        } else {
            paid = form.getAttribute('data-paid') === '1';
            code = form.getAttribute('data-code');
            serviceName = form.getAttribute('data-service-name') || '';
        }
        renderInProgress(serviceName || 'IMEI lookup', paid);
        resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        // Slow upstream providers (or a flaky mobile network) can leave the
        // request hanging indefinitely; abort after 75s so the user sees a
        // defined error instead of a spinner that never resolves. The server
        // still finishes its own work (and refunds on its own death), so the
        // user is never charged without recourse.
        var abortCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var fetchTimer = setTimeout(function () { if (abortCtrl) abortCtrl.abort(); }, 75000);
        var fetchOpts = abortCtrl ? { signal: abortCtrl.signal } : {};
        var fetchPromise;

        if (paid && code) {
            // Auth-required, paid path. Deducts credit, refunds on failure.
            fetchPromise = fetch('/api/services/use.php', Object.assign({
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code, input: { imei: imei } })
            }, fetchOpts));
        } else {
            // Anonymous free path - posts to the legacy check endpoint.
            var body = new URLSearchParams();
            body.set('imei', imei);
            var serviceId = form.getAttribute('data-service');
            if (serviceId) body.set('service', serviceId);

            fetchPromise = fetch('/api/check.php', Object.assign({
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }, fetchOpts));
        }

        fetchPromise
            .then(function (res) {
                return res.json().then(function (json) {
                    return { status: res.status, body: json };
                });
            })
            .then(function (resp) {
                // 401 on a paid lookup -> push to /login and come back.
                if (resp.status === 401) {
                    stopProgress();
                    var next = encodeURIComponent(window.location.pathname + window.location.search);
                    window.location.href = '/login.php?next=' + next;
                    return;
                }
                // 402 = insufficient credit. Surface a Top-up CTA inline.
                if (resp.status === 402 || (resp.body && resp.body.error_code === 'INSUFFICIENT_CREDIT')) {
                    renderStatus('warn', 'Insufficient Credit',
                        '<p class="result-msg">' + escapeHtml(resp.body.error || 'Please top up your wallet to run this check.') + '</p>' +
                        '<p class="result-cta"><a href="/topup.php" class="link-more">Top up credit &rarr;</a></p>');
                    return;
                }
                if (!resp.body || resp.body.ok !== true) {
                    var refunded = resp.body && resp.body.refunded;
                    var note = refunded ? ' Your credit has been refunded automatically.' : '';
                    showError(((resp.body && resp.body.error) || 'Lookup failed (HTTP ' + resp.status + ').') + note,
                        refunded ? 'Order Refunded' : 'Order Failed',
                        refunded ? 'warn' : 'error');
                    return;
                }
                // DHRU async services: order was placed, no result yet.
                // Render the processing card and start polling.
                if (resp.body.status === 'processing' && resp.body.public_id) {
                    renderProcessing(resp.body.public_id);
                    resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    showBlacklistPopup(resp.body.blacklist);
                    pollStatus(resp.body.public_id, resp.body.retry_after);
                    return;
                }
                renderResult(resp.body);
                resultEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function (e) {
                var aborted = e && (e.name === 'AbortError');
                // We stopped waiting on the client, but the server may have
                // finished the lookup regardless - so we can't claim it was
                // refunded. Point the user to Orders, where the real outcome
                // (a completed result OR an automatic refund) will appear.
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
                form.classList.remove('loading');
                button.disabled = false;
            });
    });
})();
