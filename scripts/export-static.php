<?php
/**
 * Static site exporter.
 *
 * Hits every public route via the local PHP dev server, mirrors the HTML
 * into dist/, rewrites links so the site works on a static host (e.g.
 * GitHub Pages) without rewrite rules.
 *
 * Run:
 *   php -S 127.0.0.1:8080 &        # local server with IMEI_API_PROVIDER=demo
 *   php scripts/export-static.php  # writes dist/
 */

declare(strict_types=1);

$BASE = 'http://127.0.0.1:8080';
$OUT  = __DIR__ . '/../dist';

// Wipe and recreate dist/
if (is_dir($OUT)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($OUT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($OUT);
}
mkdir($OUT, 0755, true);
mkdir($OUT . '/assets/css', 0755, true);
mkdir($OUT . '/assets/js', 0755, true);

// 1. Static assets - copy verbatim
copy(__DIR__ . '/../assets/css/style.css', $OUT . '/assets/css/style.css');

// 2. Build the list of dynamic routes -> output filenames
$brands   = require __DIR__ . '/../data/brands.php';
$services = require __DIR__ . '/../data/services.php';
$articles = require __DIR__ . '/../data/articles.php';

$routes = [
    '/'             => 'index.html',
    '/services.php' => 'services.html',
    '/brands.php'   => 'brands.html',
    '/articles.php' => 'articles.html',
    '/about.php'    => 'about.html',
    '/contact.php'  => 'contact.html',
    '/privacy.php'  => 'privacy.html',
    '/login.php'    => 'login.html',
    '/signup.php'   => 'signup.html',
];
foreach ($brands as $b) {
    $routes['/brand.php?slug=' . $b['slug']] = 'brand-' . $b['slug'] . '.html';
}
foreach ($services as $s) {
    $routes['/service.php?slug=' . $s['slug']] = 'service-' . $s['slug'] . '.html';
}
foreach ($articles as $a) {
    $routes['/article.php?slug=' . $a['slug']] = 'article-' . $a['slug'] . '.html';
}

// 3. Fetch + rewrite each route
$rewriteMap = [];
foreach ($routes as $url => $file) {
    $rewriteMap[$url] = $file;
}

function fetch(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'imeihub-static-exporter',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        throw new RuntimeException("fetch $url failed (HTTP $code)");
    }
    return (string) $body;
}

function rewriteLinks(string $html, array $map): string
{
    // /assets/... -> ./assets/...
    $html = preg_replace('~(src|href)="/assets/~', '$1="./assets/', $html);

    // Internal page links
    foreach ($map as $url => $file) {
        $html = str_replace('href="' . $url . '"', 'href="./' . $file . '"', $html);
    }

    // Hide auth-only routes from the public demo. The PHP server emits these
    // as absolute paths.
    $html = str_replace('href="/dashboard.php"',       'href="./dashboard.html"', $html);
    $html = str_replace('href="/topup.php"',           'href="./topup.html"',     $html);
    $html = str_replace('href="/logout.php"',          'href="./index.html"',     $html);
    $html = str_replace('href="/credits/history.php"', 'href="./dashboard.html"', $html);
    $html = str_replace('href="/login.php"',           'href="./login.html"',     $html);
    $html = str_replace('href="/signup.php"',          'href="./signup.html"',    $html);
    $html = str_replace('href="/check.php"',           'href="./index.html"',     $html);
    $html = str_replace('href="/bot.php"',             'href="./dashboard.html"', $html);
    $html = str_replace('href="/"',                    'href="./index.html"',     $html);
    // OAuth & signup form actions can't function statically.
    $html = preg_replace('~action="/api/auth/[^"]+"~', 'action="./login.html"', $html);

    // Fragments (e.g. /#how) - rewrite using ~ as delimiter so the inline # is fine
    $html = preg_replace('~href="/(#[a-z-]+)"~', 'href="./index.html$1"', $html);

    // Form actions
    $html = str_replace('action="/contact.php"', 'action="./contact.html"', $html);

    // OAuth start link can't function statically - point it at the demo login page
    $html = preg_replace('~href="/api/auth/google/start\.php[^"]*"~', 'href="./login.html"', $html);

    // Static demo banner so visitors know this isn't the live app.
    $banner = '<div style="background:#fbbf24;color:#0b1220;padding:8px 12px;font-size:.85rem;text-align:center;font-family:Inter,sans-serif;">'
            . 'Static demo - IMEI lookups run on a bundled TAC database. Real Stripe top-ups + Google sign-in require the PHP backend.'
            . '</div>';
    $html = preg_replace('#<body>#', "<body>$banner", $html, 1);

    // Strip the IMEI form JS reference + inject a static-mode shim that
    // returns demo results without needing a backend. The /assets/ prefix
    // has already been rewritten to ./assets/ above, so match that form.
    $html = str_replace('src="./assets/js/main.js"', 'src="./assets/js/main-static.js"', $html);

    return $html;
}

foreach ($routes as $url => $file) {
    $full = $BASE . $url;
    echo "  fetch  $url -> $file\n";
    $html = fetch($full);
    $html = rewriteLinks($html, $rewriteMap);
    file_put_contents($OUT . '/' . $file, $html);
}

// 4. Hand-crafted "logged-in" pages so visitors can preview the wallet.
$loggedInDashboard = <<<PHP_STUB
<?php
declare(strict_types=1);
function auth_user(): ?array {
    return ['id' => 1, 'email' => 'demo@example.com', 'name' => 'Demo User', 'image' => null, 'cached_balance' => '250.00'];
}
function auth_require(): array { return auth_user(); }
function credits_get_balance(int \$u, bool \$s = false): string { return '250.00'; }
function credits_format_usd(string|float|int \$a): string { return '$' . number_format((float) \$a, 2); }
function credits_get_month_stats(int \$u): array {
    return ['lookups_count' => 12, 'lookups_free' => 3, 'lookups_paid' => 9, 'spent' => '217.00'];
}
function credits_get_recent_usages(int \$u, int \$l = 10): array {
    \$now = time();
    return [
        ['public_id' => 'A', 'service_code' => 'BLACKLIST',     'service_name' => 'Blacklist Status Check',   'cost' => '15.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', \$now -    300), 'completed_at' => null],
        ['public_id' => 'B', 'service_code' => 'ICLOUD_STATUS', 'service_name' => 'iCloud Activation Lock',   'cost' => '39.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', \$now -   2700), 'completed_at' => null],
        ['public_id' => 'C', 'service_code' => 'IMEI_BASIC',    'service_name' => 'Free IMEI Check',          'cost' =>  '0.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', \$now -  86400), 'completed_at' => null],
        ['public_id' => 'D', 'service_code' => 'CARRIER',       'service_name' => 'Carrier & SIM-Lock Check', 'cost' => '29.00', 'status' => 'REFUNDED', 'error_message' => 'Provider timeout', 'created_at' => date('Y-m-d H:i:s', \$now - 90000), 'completed_at' => null],
        ['public_id' => 'E', 'service_code' => 'WARRANTY',      'service_name' => 'Warranty & Activation Date','cost'=> '29.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', \$now - 172800), 'completed_at' => null],
    ];
}
require __DIR__ . '/../includes/layout.php';
require __DIR__ . '/../includes/icons.php';
\$user    = auth_user();
\$userId  = (int) \$user['id'];
\$balance = credits_get_balance(\$userId);
\$stats   = credits_get_month_stats(\$userId);
\$recent  = credits_get_recent_usages(\$userId);
include __DIR__ . '/../_dashboard_body.php';
PHP_STUB;

// We render the dashboard / topup bodies inline against the running server,
// piggy-backing on demo stubs the same way we've been doing for screenshots.
$stubs = [
    'dashboard-stub.php' => <<<'PHP'
<?php
declare(strict_types=1);
function auth_user(): ?array {
    return ['id' => 1, 'email' => 'demo@example.com', 'name' => 'Demo User', 'image' => null, 'cached_balance' => '250.00'];
}
function auth_require(): array { return auth_user(); }
function credits_get_balance(int $u, bool $s = false): string { return '250.00'; }
function credits_format_usd(string|float|int $a): string { return '$' . number_format((float) $a, 2); }
function credits_get_month_stats(int $u): array {
    return ['lookups_count' => 12, 'lookups_free' => 3, 'lookups_paid' => 9, 'spent' => '217.00'];
}
function credits_get_recent_usages(int $u, int $l = 10): array {
    $now = time();
    return [
        ['public_id' => 'A', 'service_code' => 'BLACKLIST',     'service_name' => 'Blacklist Status Check',   'cost' => '15.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', $now -    300), 'completed_at' => null],
        ['public_id' => 'B', 'service_code' => 'ICLOUD_STATUS', 'service_name' => 'iCloud Activation Lock',   'cost' => '39.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', $now -   2700), 'completed_at' => null],
        ['public_id' => 'C', 'service_code' => 'IMEI_BASIC',    'service_name' => 'Free IMEI Check',          'cost' =>  '0.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', $now -  86400), 'completed_at' => null],
        ['public_id' => 'D', 'service_code' => 'CARRIER',       'service_name' => 'Carrier & SIM-Lock Check', 'cost' => '29.00', 'status' => 'REFUNDED', 'error_message' => 'Provider timeout', 'created_at' => date('Y-m-d H:i:s', $now - 90000), 'completed_at' => null],
        ['public_id' => 'E', 'service_code' => 'WARRANTY',      'service_name' => 'Warranty & Activation Date','cost'=> '29.00', 'status' => 'SUCCESS', 'error_message' => null, 'created_at' => date('Y-m-d H:i:s', $now - 172800), 'completed_at' => null],
    ];
}
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/icons.php';
$user    = auth_user();
$userId  = (int) $user['id'];
$balance = credits_get_balance($userId);
$stats   = credits_get_month_stats($userId);
$recent  = credits_get_recent_usages($userId);
layout_head('Dashboard · imeihub', 'Your imeihub account dashboard.');
?>
    <section class="dashboard">
        <div class="container">
            <header class="dashboard-head">
                <div>
                    <p class="hero-eyebrow" style="color:var(--text-muted);border-color:var(--border);background:var(--surface-alt)">Dashboard</p>
                    <h1>Hi, <?= htmlspecialchars((string) ($user['name'] ?: $user['email']), ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="dashboard-subtitle">Manage your credits, run lookups, and review your usage.</p>
                </div>
                <a href="/logout.php" class="link-quiet">Sign out</a>
            </header>
            <div class="dashboard-grid">
                <div class="stat-card">
                    <span class="stat-card-label">Credit balance</span>
                    <strong class="stat-card-value"><?= credits_format_usd($balance) ?></strong>
                    <a href="/topup.php" class="btn-primary">Top up credit</a>
                </div>
                <div class="stat-card">
                    <span class="stat-card-label">Lookups this month</span>
                    <strong class="stat-card-value"><?= (int) $stats['lookups_count'] ?></strong>
                    <span class="stat-card-meta"><?= (int) $stats['lookups_free'] ?> free, <?= (int) $stats['lookups_paid'] ?> paid</span>
                </div>
                <div class="stat-card">
                    <span class="stat-card-label">Spent this month</span>
                    <strong class="stat-card-value"><?= credits_format_usd($stats['spent']) ?></strong>
                    <span class="stat-card-meta"><a href="/credits/history.php">View full history &rarr;</a></span>
                </div>
            </div>
            <section class="dashboard-block">
                <div class="dashboard-block-head">
                    <h2>Recent lookups</h2>
                    <a href="/credits/history.php" class="link-more">View all <?= icon('arrow-right', 14) ?></a>
                </div>
                <table class="ledger-table">
                    <thead><tr><th>Service</th><th>Status</th><th class="num">Cost</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $r['service_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="ledger-sub"><?= htmlspecialchars((string) $r['service_code'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><span class="status status--<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="num"><?= credits_format_usd($r['cost']) ?></td>
                            <td class="muted"><?= htmlspecialchars((string) $r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </section>
<?php layout_foot(); ?>
PHP,
    'topup-stub.php' => <<<'PHP'
<?php
declare(strict_types=1);
function auth_user(): ?array {
    return ['id' => 1, 'email' => 'demo@example.com', 'name' => 'Demo User', 'image' => null, 'cached_balance' => '250.00'];
}
function auth_require(): array { return auth_user(); }
function credits_get_balance(int $u, bool $s = false): string { return '250.00'; }
function credits_format_usd(string|float|int $a): string { return '$' . number_format((float) $a, 2); }
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/icons.php';
$user = auth_require();
$balance = credits_get_balance((int) $user['id'], true);
layout_head('Top up credit · imeihub', 'Add credit to your imeihub wallet via Stripe.');
?>
    <section class="topup-shell">
        <div class="container container--narrow">
            <p class="breadcrumbs"><a href="/dashboard.php">Dashboard</a> &rsaquo; Top up</p>
            <h1>Top up credit</h1>
            <p class="dashboard-subtitle">Current balance: <strong><?= credits_format_usd($balance) ?></strong></p>
            <form id="topup-form" class="topup-form" autocomplete="off" onsubmit="event.preventDefault(); alert('In the live app this would redirect to Stripe Checkout.');">
                <fieldset>
                    <legend>Choose an amount</legend>
                    <div class="topup-presets">
                        <?php foreach ([100, 300, 500, 1000, 3000, 5000] as $preset): ?>
                            <label>
                                <input type="radio" name="amount" value="<?= $preset ?>"<?= $preset === 300 ? ' checked' : '' ?>>
                                <span class="preset-card"><span class="preset-card-amount">$<?= number_format($preset) ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="topup-custom">
                        <span>Or enter a custom amount ($50 - $10,000)</span>
                        <input type="number" name="custom" min="50" max="10000" step="1" placeholder="e.g. 750">
                    </label>
                </fieldset>
                <fieldset>
                    <legend>Payment method</legend>
                    <p class="dashboard-subtitle" style="margin:0 0 10px;">You'll be redirected to Stripe's secure checkout. Pay with <strong>credit / debit card</strong> or <strong>PromptPay</strong>.</p>
                </fieldset>
                <button type="submit" class="btn-primary btn-primary--lg"><span class="btn-label">Continue to payment</span></button>
            </form>
            <p class="topup-foot">Need help? <a href="/contact.php">Contact us</a>.</p>
        </div>
    </section>
<?php layout_foot(); ?>
PHP,
];

foreach ($stubs as $name => $content) {
    file_put_contents(__DIR__ . '/../' . $name, $content);
}

foreach (['dashboard-stub.php' => 'dashboard.html', 'topup-stub.php' => 'topup.html'] as $stub => $out) {
    echo "  fetch  /$stub -> $out\n";
    $html = fetch($BASE . '/' . $stub);
    // Make the logged-in chrome render: turn the "Sign in" button into the account chip
    // by injecting cookie-style hint via DOM rewrite. The PHP file already calls auth_user()
    // which in our stub returns a user, so the header is already correct.
    $html = rewriteLinks($html, $rewriteMap);
    file_put_contents($OUT . '/' . $out, $html);
    unlink(__DIR__ . '/../' . $stub);
}

// 5. The static-mode JS: bundles the demo TAC database so the IMEI form works.
$mainJs = file_get_contents(__DIR__ . '/../assets/js/main.js');
$demoFile = __DIR__ . '/../includes/imei_demo.php';
require_once $demoFile;
$demoDb = imei_demo_tac_db();
$staticJs = "/* imeihub static demo — backend-less IMEI lookup */\n"
    . "(function () {\n"
    . "  var DEMO_DB = " . json_encode($demoDb, JSON_PRETTY_PRINT) . ";\n"
    . "  function buildResp(imei, cost) {\n"
    . "    var tac  = imei.substr(0, 8);\n"
    . "    var info = DEMO_DB[tac] || { brand: 'Unknown', model: 'GSM Phone', release: '-', os: '-' };\n"
    . "    var details = {\n"
    . "      'Brand Name': info.brand, 'Model Name': info.model,\n"
    . "      'Model Number': info.model_no || '-',\n"
    . "      'IMEI': imei, 'TAC': tac,\n"
    . "      'Serial Number': imei.substr(8, 6),\n"
    . "      'Color': info.color || '-', 'Storage': info.storage || '-',\n"
    . "      'Release Year': info.release || '-', 'Operating System': info.os || '-'\n"
    . "    };\n"
    . "    return { ok: true, cached: false, imei: imei, tac: tac, brand: info.brand, model: info.model, cost: cost, details: details };\n"
    . "  }\n"
    . "  window.fetch = (function (orig) {\n"
    . "    return function (url, opts) {\n"
    . "      var isCheck = typeof url === 'string' && url.indexOf('/api/check.php') !== -1;\n"
    . "      var isUse   = typeof url === 'string' && url.indexOf('/api/services/use.php') !== -1;\n"
    . "      if (!isCheck && !isUse) return orig.apply(this, arguments);\n"
    . "      var imei = '';\n"
    . "      if (isUse) {\n"
    . "        try { var b = JSON.parse((opts && opts.body) || '{}'); imei = (b.input && b.input.imei) || ''; }\n"
    . "        catch (e) {}\n"
    . "      } else {\n"
    . "        var qs = opts && opts.body ? new URLSearchParams(opts.body) : new URLSearchParams();\n"
    . "        imei = qs.get('imei') || '';\n"
    . "      }\n"
    . "      var resp = buildResp(imei, isUse ? '0.10' : '0.00');\n"
    . "      return Promise.resolve(new Response(JSON.stringify(resp), { status: 200, headers: { 'Content-Type': 'application/json' } }));\n"
    . "    };\n"
    . "  })(window.fetch);\n"
    . "})();\n\n"
    . $mainJs;

file_put_contents($OUT . '/assets/js/main-static.js', $staticJs);

// 6. A demo landing page (README) so visitors arriving at gh-pages root see context.
file_put_contents($OUT . '/.nojekyll', '');

echo "\nDone. " . count(scandir($OUT)) . " entries written to dist/.\n";
echo "Open ./dist/index.html locally OR deploy as a static site.\n";
