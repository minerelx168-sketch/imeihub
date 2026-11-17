<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth.php';

if (auth_user()) {
    header('Location: /dashboard.php');
    exit;
}

layout_head('Reset password · imeihub', 'Reset your imeihub account password by email.');
?>
<section class="auth-shell">
    <div class="container container--narrow">
        <div class="auth-card">
            <div class="auth-card-head">
                <h1>Reset password</h1>
                <p id="fp-sub">Enter your email and we'll send a 6-digit reset code.</p>
            </div>

            <form id="fp-request" class="auth-form" autocomplete="off" novalidate>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
                </label>
                <button type="submit" class="btn-primary-block">
                    <span class="btn-label">Send reset code</span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>
                <p class="fp-error field-error" hidden></p>
            </form>

            <form id="fp-reset" class="auth-form" autocomplete="off" novalidate hidden>
                <label>
                    <span>6-digit code</span>
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" required placeholder="123456" autocomplete="one-time-code">
                </label>
                <label class="pw-field">
                    <span>New password</span>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
                    <button type="button" class="pw-toggle" aria-label="Show password">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </label>
                <button type="submit" class="btn-primary-block">
                    <span class="btn-label">Reset password</span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>
                <p class="fp-error field-error" hidden></p>
                <p class="auth-fineprint">Didn't get it? <a href="#" id="fp-resend">Resend code</a></p>
            </form>

            <p class="auth-fineprint">Remembered your password? <a href="/login.php">Back to sign in</a></p>
        </div>
    </div>
</section>
<script>
(function () {
    var reqForm = document.getElementById('fp-request');
    var resForm = document.getElementById('fp-reset');
    var sub     = document.getElementById('fp-sub');
    var email   = '';

    function setErr(form, msg) {
        var e = form.querySelector('.fp-error');
        if (msg) { e.textContent = msg; e.hidden = false; } else { e.hidden = true; }
    }
    function busy(form, on) {
        var b = form.querySelector('button[type=submit]');
        if (on) { b.classList.add('loading'); b.disabled = true; } else { b.classList.remove('loading'); b.disabled = false; }
    }

    function sendCode() {
        return fetch('/api/auth/forgot-request.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email })
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
    }

    reqForm.addEventListener('submit', function (e) {
        e.preventDefault();
        setErr(reqForm, '');
        email = reqForm.querySelector('input[name=email]').value.trim();
        if (!email) { setErr(reqForm, 'Please enter your email.'); return; }
        busy(reqForm, true);
        sendCode().then(function (resp) {
            if (!resp.body || resp.body.ok !== true) { setErr(reqForm, (resp.body && resp.body.error) || 'Could not send the code.'); return; }
            reqForm.hidden = true;
            resForm.hidden = false;
            sub.textContent = 'We sent a 6-digit code to ' + email + ' (if an account exists). Enter it below with your new password.';
            resForm.querySelector('input[name=otp]').focus();
        }).catch(function () { setErr(reqForm, 'Network error. Please try again.'); })
          .finally(function () { busy(reqForm, false); });
    });

    resForm.addEventListener('submit', function (e) {
        e.preventDefault();
        setErr(resForm, '');
        var otp = resForm.querySelector('input[name=otp]').value.replace(/\D+/g, '');
        var pw  = resForm.querySelector('input[name=password]').value;
        if (!/^\d{6}$/.test(otp)) { setErr(resForm, 'Enter the 6-digit code.'); return; }
        if (pw.length < 8) { setErr(resForm, 'Password must be at least 8 characters.'); return; }
        busy(resForm, true);
        fetch('/api/auth/forgot-reset.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: email, otp: otp, password: pw })
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
          .then(function (resp) {
            if (!resp.body || resp.body.ok !== true) { setErr(resForm, (resp.body && resp.body.error) || 'Could not reset.'); return; }
            window.location.href = '/login.php?reset=1';
          }).catch(function () { setErr(resForm, 'Network error. Please try again.'); })
          .finally(function () { busy(resForm, false); });
    });

    var resend = document.getElementById('fp-resend');
    if (resend) resend.addEventListener('click', function (e) {
        e.preventDefault();
        if (!email) return;
        setErr(resForm, '');
        sendCode().then(function () { sub.textContent = 'A new code was sent to ' + email + '.'; });
    });
})();
</script>
<?php layout_foot(); ?>
