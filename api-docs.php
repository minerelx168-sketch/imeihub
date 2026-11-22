<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';

$siteUrl = 'https://imeihub.net';
$pageUrl = $siteUrl . '/api-docs';
$ogImage = $siteUrl . '/assets/img/og-image.png';
$dateMod = '2026-11-22';

$faqs = [
    [
        'q' => 'How can phone resellers run bulk or automated IMEI checks on imeihub?',
        'a' => 'Resellers can use a single prepaid Credit Wallet with instant dashboard lookups, Telegram and LINE bots for mobile shop staff, and JSON API access for POS or inventory systems.',
    ],
    [
        'q' => 'What services are available for bulk and API workflows?',
        'a' => 'All 44 imeihub services are supported, including Free TAC Lookup ($0.00), Apple iCloud ON/OFF ($0.01), Clean/Lost ($0.03), Carrier Pro ($0.16), MDM ($0.35), Full GSX ($2.30), Worldwide Blacklist ($0.05–$0.10), and Samsung Knox Guard ($0.20).',
    ],
    [
        'q' => 'Are failed API or bot lookups automatically refunded?',
        'a' => 'Yes. Every paid check submitted via the web dashboard, Telegram/LINE bot, or API is protected by our Automatic Refund Guarantee—failed upstream queries are immediately credited back to your wallet.',
    ],
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'API & Bulk Checks', 'item' => $pageUrl],
            ],
        ],
        [
            '@type'         => 'TechArticle',
            'url'           => $pageUrl,
            'headline'      => 'imeihub Bulk IMEI Checks, Bot Integration & Reseller API',
            'description'   => 'High-speed IMEI verification workflows for phone resellers, refurbishers, and repair shops: Credit Wallet, Telegram/LINE bots, and JSON API documentation.',
            'datePublished' => '2026-11-22',
            'dateModified'  => $dateMod,
            'author'        => ['@type' => 'Organization', 'name' => 'imeihub Editorial Team', 'url' => $siteUrl . '/about.php'],
            'publisher'     => [
                '@type' => 'Organization',
                'name'  => 'imeihub',
                'url'   => $siteUrl,
                'logo'  => ['@type' => 'ImageObject', 'url' => $siteUrl . '/assets/img/logo.png', 'width' => 512, 'height' => 512],
            ],
        ],
        [
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(
                static fn(array $item): array => [
                    '@type'          => 'Question',
                    'name'           => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ],
                $faqs
            ),
        ],
    ],
];

$title = 'Bulk IMEI Check, Bot & Reseller API · imeihub';
$desc  = 'Automate IMEI checks for your phone shop or trade-in desk with imeihub: 44 services from $0.01, Telegram & LINE bots, Credit Wallet CSV ledger, JSON API, and automatic refunds.';

layout_head(
    $title,
    $desc,
    '<link rel="canonical" href="' . $pageUrl . '">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="article">' . "\n"
    . '<meta property="og:url" content="' . $pageUrl . '">' . "\n"
    . '<meta property="og:image" content="' . $ogImage . '">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . '<meta name="twitter:image" content="' . $ogImage . '">' . "\n"
    . '<script type="application/ld+json">' . "\n"
    . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n</script>"
);
?>
    <section class="page-hero">
        <div class="container">
            <p class="breadcrumbs"><a href="/">Home</a> &rsaquo; API &amp; Bulk Checks</p>
            <h1>Bulk IMEI Checks, Bot &amp; Reseller API</h1>
            <p class="lede">
                <strong>Built for resellers, refurbishers, and repair shops:</strong> Run 44 instant IMEI checks
                from $0.01 per lookup using one shared Credit Wallet, Telegram &amp; LINE shop bots, full transaction exports,
                and an Automatic Refund Guarantee on upstream misses.
            </p>
        </div>
    </section>

    <section class="geo-section">
        <div class="container">
            <div class="safety-grid">
                <article class="safety-card">
                    <h3>1. Instant Web &amp; Dashboard History</h3>
                    <p>Submit IMEIs back-to-back on the same page with zero CAPTCHA delays. Every check, cost, and automatic refund is logged in your <a href="/dashboard.php">Dashboard</a> and exportable for bookkeeping.</p>
                </article>
                <article class="safety-card">
                    <h3>2. Telegram &amp; LINE Shop Bots</h3>
                    <p>Link your <a href="/bot.php">Telegram or LINE bot</a> to your imeihub wallet so counter staff can verify iCloud, Blacklist, and Carrier status straight from their phone in seconds.</p>
                </article>
                <article class="safety-card">
                    <h3>3. Reseller JSON API Onboarding</h3>
                    <p>Need programmatic integration for your POS, trade-in kiosk, or inventory software? Email <a href="mailto:support@imeihub.net">support@imeihub.net</a> with your estimated monthly volume to enable API access.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="geo-section geo-section--alt">
        <div class="container">
            <div class="section-head">
                <h2>Sample JSON Check Workflow</h2>
                <p>Structured JSON responses designed for clean parsing in PHP, Python, Node.js, or ERP systems.</p>
            </div>

            <div class="geo-table-wrap" style="padding:20px;background:#0b1020;color:#e2e8f0;font-family:var(--font-mono);font-size:.85rem;overflow-x:auto">
<pre style="margin:0;white-space:pre">POST https://imeihub.net/api/check
Content-Type: application/json

{
  "imei": "353915101234560",
  "code": "APPLE_ICLOUD_CLEAN"
}

--- Response (200 OK) ---
{
  "ok": true,
  "imei": "353915101234560",
  "service": "APPLE_ICLOUD_CLEAN",
  "cost_usd": "0.03",
  "fields": {
    "FMI Status": "OFF",
    "iCloud Status": "CLEAN"
  },
  "auto_refunded": false
}</pre>
            </div>

            <div class="geo-citations">
                <strong>Pricing &amp; SLA:</strong>
                View all 44 service codes and USD rates in machine-readable JSON at <a href="/ai/service.json">/ai/service.json</a>,
                review our <a href="/refund-policy">Automatic Refund Guarantee</a>, or compare <a href="/vs-ifreeicloud">imeihub vs iFreeiCloud.co.uk</a>.
            </div>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="container">
            <h2>Reseller &amp; API FAQ</h2>
            <?php foreach ($faqs as $faq): ?>
            <details>
                <summary><?= htmlspecialchars($faq['q'], ENT_QUOTES, 'UTF-8') ?></summary>
                <p><?= htmlspecialchars($faq['a'], ENT_QUOTES, 'UTF-8') ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="page-meta-bar" aria-label="Page authorship and update information">
        <div class="container page-meta-bar-inner">
            <span>
                Maintained by <a href="/about.php" rel="author">imeihub Editorial Team</a>
                &middot; Support: <a href="mailto:support@imeihub.net">support@imeihub.net</a>
            </span>
            <span>
                Last updated: <time datetime="<?= $dateMod ?>">November 22, 2026</time>
            </span>
        </div>
    </section>
<?php layout_foot(); ?>
