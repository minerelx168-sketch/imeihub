<?php
declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/bot.php';

$user   = auth_require();
$userId = (int) $user['id'];

$tokens = bot_list_tokens($userId);
$links  = bot_list_links($userId);

layout_head('Bot settings · imeihub', 'Link your imeihub account to Telegram or LINE so you can run IMEI checks from a chat.');
?>
<section class="dashboard">
    <div class="container">
        <header class="dashboard-head">
            <div>
                <p class="hero-eyebrow" style="color:var(--text-muted);border-color:var(--border);background:var(--surface-alt)">Account</p>
                <h1>Bot settings</h1>
                <p class="dashboard-subtitle">
                    Link Telegram or LINE to run IMEI checks straight from a chat.
                    Generate a token, paste it into the bot as <code>token XXXXXXXXXX</code>,
                    done.
                </p>
            </div>
            <a href="/dashboard.php" class="link-quiet">&larr; Back to dashboard</a>
        </header>

        <section class="dashboard-block">
            <div class="dashboard-block-head">
                <h2>Link tokens</h2>
                <button id="btn-create-token" class="btn-primary">+ Create token</button>
            </div>

            <p class="dashboard-subtitle" style="margin:0 0 16px;">
                Tokens are valid for <?= (int) BOT_TOKEN_LIFETIME_MIN ?> minutes
                and can be redeemed once. Send <code>token &lt;value&gt;</code> in the
                bot chat to bind that chat to this account.
            </p>

            <div id="new-token-banner" class="alert alert-success" hidden>
                <div>
                    <strong>Token created:</strong>
                    <code id="new-token-value" class="token-pill"></code>
                    <button class="btn-tiny" id="btn-copy-token">Copy</button>
                </div>
                <p style="margin:6px 0 0; font-size:.9rem;">
                    Open the bot chat and paste:
                    <code>token <span id="new-token-cmd"></span></code>
                </p>
            </div>

            <?php if (!$tokens): ?>
                <div class="empty-state">
                    <p><strong>No tokens yet.</strong></p>
                    <p>Click "+ Create token" above to start.</p>
                </div>
            <?php else: ?>
                <table class="ledger-table">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Status</th>
                            <th>Channel</th>
                            <th>Expires</th>
                            <th class="num">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tokens as $t):
                        $isConsumed = (int) $t['is_consumed'] === 1;
                        $isExpired  = !$isConsumed && (int) $t['is_expired'] === 1;
                        if ($isConsumed)    $status = ['REDEEMED', 'success'];
                        elseif ($isExpired) $status = ['EXPIRED',  'failed'];
                        else                $status = ['PENDING',  'pending'];
                    ?>
                        <tr data-token-id="<?= (int) $t['id'] ?>">
                            <td><code class="token-pill"><?= htmlspecialchars($t['token'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><span class="status status--<?= $status[1] ?>"><?= $status[0] ?></span></td>
                            <td><?= htmlspecialchars($t['channel'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="muted"><?= htmlspecialchars((string) $t['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num">
                                <?php if (!$isConsumed): ?>
                                    <button class="btn-tiny btn-tiny--danger js-revoke-token" data-id="<?= (int) $t['id'] ?>">Revoke</button>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="dashboard-block" style="margin-top:24px">
            <div class="dashboard-block-head">
                <h2>Linked chats</h2>
            </div>

            <?php if (!$links): ?>
                <div class="empty-state">
                    <p><strong>No chats linked yet.</strong></p>
                    <p>Once you redeem a token from Telegram or LINE, it will show up here.</p>
                </div>
            <?php else: ?>
                <table class="ledger-table">
                    <thead>
                        <tr>
                            <th>Channel</th>
                            <th>Chat / User</th>
                            <th>Last used</th>
                            <th class="num">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($links as $l): ?>
                        <tr data-link-id="<?= (int) $l['id'] ?>">
                            <td><strong><?= htmlspecialchars(ucfirst($l['channel']), ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td>
                                <?= htmlspecialchars($l['display_name'] ?: '(unknown)', ENT_QUOTES, 'UTF-8') ?>
                                <span class="ledger-sub">chat <?= htmlspecialchars((string) $l['channel_chat_id'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td class="muted"><?= htmlspecialchars((string) $l['last_used_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num">
                                <button class="btn-tiny btn-tiny--danger js-unlink" data-id="<?= (int) $l['id'] ?>">Unlink</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="dashboard-block" style="margin-top:24px">
            <div class="dashboard-block-head"><h2>How to link</h2></div>
            <ol class="bot-steps">
                <li>Click <strong>+ Create token</strong> above. You get a 10-character code.</li>
                <li>Open the imeihub bot in <strong>Telegram</strong> or <strong>LINE</strong> (see your shop owner for the bot link).</li>
                <li>Send: <code>token &lt;your code&gt;</code></li>
                <li>The bot replies <em>"Linked!"</em> — you can now paste an IMEI to run a free check, or <code>&lt;service-code&gt; &lt;imei&gt;</code> for a paid one.</li>
                <li>Send <code>services</code> to see the full menu, <code>balance</code> to see remaining credit.</li>
            </ol>
        </section>
    </div>
</section>

<script>
(function () {
    var banner    = document.getElementById('new-token-banner');
    var tokenVal  = document.getElementById('new-token-value');
    var tokenCmd  = document.getElementById('new-token-cmd');
    var btnCreate = document.getElementById('btn-create-token');
    var btnCopy   = document.getElementById('btn-copy-token');

    function showBanner(token) {
        tokenVal.textContent = token;
        tokenCmd.textContent = token;
        banner.hidden = false;
        banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    btnCreate.addEventListener('click', function () {
        btnCreate.disabled = true;
        fetch('/api/bot/token-create.php', { method: 'POST', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) throw new Error(j.error || 'failed');
                showBanner(j.token);
                setTimeout(function () { window.location.reload(); }, 1500);
            })
            .catch(function (e) {
                alert('Could not create token: ' + e.message);
                btnCreate.disabled = false;
            });
    });

    if (btnCopy) {
        btnCopy.addEventListener('click', function () {
            navigator.clipboard.writeText(tokenVal.textContent).then(function () {
                btnCopy.textContent = 'Copied!';
                setTimeout(function () { btnCopy.textContent = 'Copy'; }, 1500);
            });
        });
    }

    document.querySelectorAll('.js-revoke-token').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Revoke this token? It cannot be used to link a chat anymore.')) return;
            fetch('/api/bot/token-revoke.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(btn.dataset.id, 10) }),
            })
            .then(function (r) { return r.json(); })
            .then(function () { window.location.reload(); });
        });
    });

    document.querySelectorAll('.js-unlink').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Unlink this chat? You can re-link it later with a new token.')) return;
            fetch('/api/bot/unlink.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(btn.dataset.id, 10) }),
            })
            .then(function (r) { return r.json(); })
            .then(function () { window.location.reload(); });
        });
    });
})();
</script>
<?php layout_foot(); ?>
