<?php
declare(strict_types=1);
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/credits.php';

$user    = auth_require();
$balance = credits_get_balance((int) $user['id'], true);
$cfg     = require __DIR__ . '/../includes/config.php';
$trc20   = (string) ($cfg['crypto']['trc20']['address'] ?? '');
$bep20   = (string) ($cfg['crypto']['bep20']['address'] ?? '');
$minUsd  = (float) ($cfg['crypto']['min_usd'] ?? 1);

layout_head('Top up with crypto · imeihub', 'Send USDT to our address and paste the TxID to credit your imeihub wallet instantly.');
?>
    <section class="topup-shell">
        <div class="container container--narrow">
            <p class="breadcrumbs">
                <a href="/dashboard.php">Dashboard</a> &rsaquo;
                <a href="/topup.php">Top up</a> &rsaquo;
                Crypto
            </p>

            <h1>Top up with crypto</h1>
            <p class="dashboard-subtitle">
                Current balance: <strong><?= credits_format_usd($balance) ?></strong>
            </p>

            <div class="crypto-card">
                <h2 class="crypto-h">Step 1 — Send USDT to one of our addresses</h2>
                <p class="crypto-sub">
                    Minimum top-up: <strong>$<?= number_format($minUsd, 2) ?></strong>.
                    Whatever amount you actually send (after the network fee) will be credited to your wallet.
                </p>

                <?php if ($trc20 !== ''): ?>
                <div class="crypto-addr">
                    <div class="crypto-addr-h">
                        <span class="crypto-chip">USDT &middot; TRC20 (TRON)</span>
                    </div>
                    <div class="crypto-addr-row">
                        <code class="crypto-addr-val" id="addr-trc20"><?= htmlspecialchars($trc20, ENT_QUOTES, 'UTF-8') ?></code>
                        <button type="button" class="crypto-copy" data-target="addr-trc20" aria-label="Copy TRC20 address">Copy</button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($bep20 !== ''): ?>
                <div class="crypto-addr">
                    <div class="crypto-addr-h">
                        <span class="crypto-chip">USDT / USDC &middot; BEP-20 (BSC)</span>
                    </div>
                    <div class="crypto-addr-row">
                        <code class="crypto-addr-val" id="addr-bep20"><?= htmlspecialchars($bep20, ENT_QUOTES, 'UTF-8') ?></code>
                        <button type="button" class="crypto-copy" data-target="addr-bep20" aria-label="Copy BEP-20 address">Copy</button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($trc20 === '' && $bep20 === ''): ?>
                    <div class="alert alert-warning" style="margin-top:18px;">
                        Crypto top-up is not yet configured on this site. Please pick another payment method.
                    </div>
                <?php else: ?>

                <h2 class="crypto-h crypto-h--step2">Step 2 — Paste the TxID</h2>
                <p class="crypto-sub">
                    Open your wallet, copy the transaction ID of the transfer you sent, and paste it below.
                    Credits land in your wallet within seconds of the on-chain confirmations clearing.
                </p>

                <form id="crypto-form" class="crypto-form" autocomplete="off" novalidate>
                    <label class="crypto-input">
                        <span>Transaction ID</span>
                        <input type="text" name="txid" inputmode="text" autocomplete="off" spellcheck="false"
                               placeholder="e.g. 0x9c… or 5b4f… (TRON / BSC)" required>
                    </label>
                    <p id="crypto-msg" class="crypto-msg" hidden></p>
                    <button type="submit" class="btn-primary-block" id="crypto-submit">
                        <span class="btn-label">Verify &amp; credit</span>
                        <span class="btn-spinner" aria-hidden="true"></span>
                    </button>
                </form>

                <?php endif; ?>
            </div>

            <p class="topup-foot">
                We watch the chain directly &mdash; if the funds arrived but the system can't see
                enough confirmations yet, the verifier asks you to retry shortly.
                Same TxID can only be claimed once. Need help?
                <a href="/contact.php">Contact us</a>.
            </p>
        </div>
    </section>

    <script>
    (function () {
        var form   = document.getElementById('crypto-form');
        if (!form) return;
        var input  = form.querySelector('input[name="txid"]');
        var submit = document.getElementById('crypto-submit');
        var msg    = document.getElementById('crypto-msg');
        var pollTimer = null;

        document.querySelectorAll('.crypto-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var t = document.getElementById(btn.getAttribute('data-target'));
                if (!t) return;
                var text = t.textContent || '';
                var done = function () {
                    var old = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function () { btn.textContent = old; }, 1400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () { done(); });
                } else {
                    // Fallback for older browsers / non-HTTPS dev hosts.
                    var r = document.createRange(); r.selectNode(t);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(r);
                    try { document.execCommand('copy'); done(); } catch (e) {}
                }
            });
        });

        function setMsg(kind, text) {
            msg.className = 'crypto-msg crypto-msg--' + kind;
            msg.textContent = text;
            msg.hidden = false;
        }
        function setHtml(kind, html) {
            msg.className = 'crypto-msg crypto-msg--' + kind;
            msg.innerHTML = html;
            msg.hidden = false;
        }
        function busy(on) {
            submit.disabled = !!on;
            submit.classList.toggle('loading', !!on);
            input.disabled = !!on;
        }
        function clearPoll() {
            if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
        }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
            });
        }

        function verify(txid, autoRetry) {
            busy(true);
            setMsg('info', 'Checking the chain…');
            fetch('/api/topup/crypto-verify.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ txid: txid }),
            }).then(function (r) {
                return r.json().then(function (j) { return { status: r.status, body: j }; });
            }).then(function (resp) {
                var b = resp.body || {};
                if (resp.status === 401) {
                    var next = encodeURIComponent(window.location.pathname);
                    window.location.href = '/login.php?next=' + next;
                    return;
                }
                if (b.ok) {
                    busy(false);
                    var amount = escapeHtml(b.amount_usd || '0.00');
                    var token  = escapeHtml(b.token || 'USDT');
                    var bal    = escapeHtml(b.balance || '0.00');
                    var head   = b.credited_now ? 'Credited!' : 'Already credited';
                    setHtml('ok',
                        '<strong>' + head + '</strong> Added <strong>$' + amount + '</strong> ' + token +
                        '. New balance: <strong>$' + bal + '</strong>. ' +
                        '<a href="/dashboard.php">Back to dashboard &rarr;</a>'
                    );
                    return;
                }
                if (resp.status === 202 && b.pending) {
                    // Still waiting for confirmations — auto-retry every 8s, max 8 times.
                    var n   = b.confirmations || 0;
                    var min = b.min_required || 0;
                    setHtml('info',
                        'Waiting for confirmations (' + escapeHtml(String(n)) + '/' + escapeHtml(String(min)) +
                        ')&hellip; re-checking automatically.'
                    );
                    if (autoRetry > 0) {
                        clearPoll();
                        pollTimer = setTimeout(function () { verify(txid, autoRetry - 1); }, 8000);
                    } else {
                        busy(false);
                        setMsg('warn', 'Still pending. Wait a bit and click "Verify" again.');
                    }
                    return;
                }
                busy(false);
                setMsg('error', b.error || ('Verification failed (HTTP ' + resp.status + ').'));
            }).catch(function () {
                busy(false);
                setMsg('error', 'Network error — please check your connection and try again.');
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearPoll();
            var txid = (input.value || '').trim();
            if (!txid) {
                setMsg('warn', 'Paste a transaction ID first.');
                return;
            }
            verify(txid, 8); // up to 8 auto-retries (~64s)
        });
    })();
    </script>
<?php layout_foot(); ?>
