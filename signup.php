<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/auth.php';

if (auth_user()) {
    header('Location: /dashboard.php');
    exit;
}

$next = auth_safe_next($_GET['next'] ?? null);

layout_head('Create account · imeihub', 'Sign up to imeihub with your email address.');
?>
<section class="auth-shell">
    <div class="container container--narrow">
        <div class="auth-card">
            <div class="auth-card-head">
                <h1>Create account</h1>
                <p>Sign up with email and verify with a 6-digit code.</p>
            </div>

            <!-- Step 1: email + password -->
            <form id="signup-form" class="auth-form" autocomplete="off" novalidate>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
                </label>
                <label class="pw-field">
                    <span>Password (8+ characters)</span>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password">
                    <button type="button" class="pw-toggle" aria-label="Show password">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </label>
                <button type="submit" class="btn-primary-block">
                    <span class="btn-label">Send verification code</span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>
                <p id="signup-error" class="field-error" hidden></p>
            </form>

            <!-- Step 2: OTP entry (hidden until step 1 succeeds) -->
            <form id="otp-form" class="auth-form" autocomplete="off" novalidate hidden>
                <p class="otp-info">
                    A 6-digit code was sent to <strong id="otp-email"></strong>.
                    It expires in 10 minutes.
                </p>
                <label>
                    <span>Verification code</span>
                    <input type="text" name="otp" required maxlength="6" inputmode="numeric"
                           autocomplete="one-time-code" class="otp-input"
                           pattern="\d{6}" placeholder="000000">
                </label>
                <button type="submit" class="btn-primary-block">
                    <span class="btn-label">Verify &amp; sign in</span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>
                <p id="otp-error" class="field-error" hidden></p>
                <p class="auth-fineprint">
                    Didn't get the code?
                    <a href="#" id="resend-otp">Resend it</a>
                </p>
            </form>

            <div class="auth-divider"><span>or</span></div>

            <a class="btn-google" href="/api/auth/google/start.php?next=<?= urlencode($next) ?>">
                <svg width="20" height="20" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path fill="#EA4335" d="M9 3.48c1.7 0 3.22.59 4.42 1.74l3.3-3.3C14.78.86 12.13 0 9 0 5.48 0 2.44 2.02.96 4.96l3.84 2.98C5.52 5.45 7.05 3.48 9 3.48z"/>
                    <path fill="#4285F4" d="M17.64 9.2c0-.63-.06-1.25-.17-1.83H9v3.47h4.84c-.21 1.13-.85 2.08-1.81 2.72l2.94 2.28c1.72-1.58 2.67-3.92 2.67-6.64z"/>
                    <path fill="#FBBC05" d="M4.79 10.66c-.18-.55-.29-1.13-.29-1.66s.1-1.11.29-1.66L.96 4.36C.35 5.57 0 6.95 0 9c0 2.05.35 3.43.96 4.64l3.83-2.98z"/>
                    <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.94-2.28c-.83.56-1.93.88-3.02.88-1.95 0-3.48-1.97-4.21-3.96L.96 13.44C2.44 16.39 5.48 18 9 18z"/>
                </svg>
                <span>Continue with Google</span>
            </a>

            <p class="auth-fineprint">
                Already have an account? <a href="/login.php">Sign in</a>
            </p>
        </div>
    </div>
</section>

<script>
(function () {
    var signupForm = document.getElementById('signup-form');
    var otpForm    = document.getElementById('otp-form');
    var signupErr  = document.getElementById('signup-error');
    var otpErr     = document.getElementById('otp-error');
    var otpEmail   = document.getElementById('otp-email');
    var resendLink = document.getElementById('resend-otp');
    var pendingEmail = '';

    function showError(el, msg) {
        el.textContent = msg;
        el.hidden = false;
    }
    function clearError(el) { el.hidden = true; el.textContent = ''; }
    function setLoading(form, on) {
        form.classList.toggle('loading', on);
        form.querySelectorAll('input, button').forEach(function (i) { i.disabled = on; });
    }

    signupForm.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError(signupErr);
        var fd = new FormData(signupForm);
        setLoading(signupForm, true);
        fetch('/api/auth/signup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: fd.get('email'), password: fd.get('password') })
        })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (resp) {
            if (!resp.body.ok) throw new Error(resp.body.error || 'Could not create account.');
            pendingEmail = resp.body.email;
            otpEmail.textContent = pendingEmail;
            signupForm.hidden = true;
            otpForm.hidden    = false;
            otpForm.querySelector('input[name="otp"]').focus();
        })
        .catch(function (e) { showError(signupErr, e.message); })
        .finally(function () { setLoading(signupForm, false); });
    });

    otpForm.addEventListener('submit', function (e) {
        e.preventDefault();
        clearError(otpErr);
        var fd = new FormData(otpForm);
        setLoading(otpForm, true);
        fetch('/api/auth/verify-email.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: pendingEmail, otp: (fd.get('otp') || '').trim() })
        })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (resp) {
            if (!resp.body.ok) throw new Error(resp.body.error || 'Verification failed.');
            window.location.href = resp.body.next || '/dashboard.php';
        })
        .catch(function (e) { showError(otpErr, e.message); })
        .finally(function () { setLoading(otpForm, false); });
    });

    resendLink.addEventListener('click', function (e) {
        e.preventDefault();
        if (!pendingEmail) return;
        clearError(otpErr);
        resendLink.textContent = 'Sending...';
        fetch('/api/auth/resend-otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: pendingEmail })
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            resendLink.textContent = j.ok ? 'Code resent ✓' : 'Resend it';
            if (!j.ok) showError(otpErr, j.error || 'Could not resend.');
            setTimeout(function () { resendLink.textContent = 'Resend it'; }, 4000);
        });
    });
})();
</script>
<?php layout_foot(); ?>
