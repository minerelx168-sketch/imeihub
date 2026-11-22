<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
$services = require __DIR__ . '/data/services.php';

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]+/i', '', (string) $_GET['slug']) : '';
$service = null;
foreach ($services as $s) {
    if ($s['slug'] === $slug) { $service = $s; break; }
}

if (!$service) {
    http_response_code(404);
    layout_head('Service not found · imeihub');
    ?>
    <section class="page-hero">
        <div class="container">
            <h1>Service not found</h1>
            <p class="lede">
                The service <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code>
                does not exist. <a href="/services.php">See all services &rarr;</a>
            </p>
        </div>
    </section>
    <?php
    layout_foot();
    return;
}

require_once __DIR__ . '/includes/icons.php';
$name      = $service['name'];
$plainName = strip_tags(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
$siteUrl   = 'https://imeihub.net';
$pageUrl   = $siteUrl . '/service.php?slug=' . urlencode($slug);
$ogImage   = $siteUrl . '/assets/img/og-image.png';
$dateMod   = '2026-11-22';

// Canonical fallback prices (USD) matching index.php / sql/seed.sql
$fallbackPrices = [
    'IMEI_BASIC'          => '0.00',
    'APPLE_BASIC'         => '0.10',
    'APPLE_ICLOUD_CLEAN'  => '0.03',
    'APPLE_ICLOUD_STATUS' => '0.01',
    'APPLE_WARRANTY'      => '0.04',
    'APPLE_SIM_LOCK'      => '0.05',
    'APPLE_MDM'           => '0.35',
    'APPLE_FULL_GSX'      => '2.30',
    'BLACKLIST_FULL'      => '0.10',
    'BLACKLIST_SIMPLE'    => '0.05',
    'SAMSUNG_INFO'        => '0.10',
    'SAMSUNG_KNOX'        => '0.20',
    'HUAWEI_INFO'         => '0.08',
    'XIAOMI_STATUS'       => '0.10',
    'PIXEL_INFO'          => '0.20',
];
$priceUsd = $fallbackPrices[$service['code']] ?? ($service['free'] ? '0.00' : '0.10');
$priceDisplay = ((float) $priceUsd === 0.0) ? 'Free ($0.00)' : '$' . number_format((float) $priceUsd, 2) . ' USD';

// Extract returned fields from data/service_result_fields.php for the Sample Report table
$fieldMap   = require __DIR__ . '/data/service_result_fields.php';
$rawFields  = $fieldMap[$service['code']] ?? [];
$fieldNames = [];
foreach ($rawFields as $f) {
    $fieldNames[] = is_array($f) ? (string) $f[0] : (string) $f;
}
if (!$fieldNames && $service['code'] === 'IMEI_BASIC') {
    $fieldNames = ['Brand / Manufacturer', 'Model Name', 'TAC (8-digit Type Allocation Code)', 'Luhn Checksum Validation', 'Basic Hardware Specifications'];
}

$serviceFaqs = [
    [
        'q' => 'How much does the ' . $plainName . ' cost?',
        'a' => $service['free']
            ? 'The ' . $plainName . ' is 100% free ($0.00) with no account or credit card required.'
            : 'The ' . $plainName . ' costs ' . $priceDisplay . ' per lookup from your prepaid imeihub Credit Wallet, with no subscription required. If an upstream provider fails to return data, your credit is automatically refunded.',
    ],
    [
        'q' => 'How long does the ' . $plainName . ' take?',
        'a' => 'Results are delivered directly on the same page—typically in under a second for free TAC lookups and a few seconds for live carrier or manufacturer reports.',
    ],
    [
        'q' => 'Is running a ' . $plainName . ' safe?',
        'a' => 'Yes. An IMEI check only reads hardware/TAC allocation and registry status. It cannot access personal files, photos, passwords, or accounts.',
    ],
];

$jsonLdGraph = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Services', 'item' => $siteUrl . '/services.php'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $plainName, 'item' => $pageUrl],
            ],
        ],
        [
            '@type'       => 'Service',
            '@id'         => $pageUrl . '#service',
            'name'        => $plainName,
            'description' => strip_tags((string) $service['description']),
            'url'         => $pageUrl,
            'serviceType' => 'IMEI Verification & Mobile Device Lookup',
            'provider'    => [
                '@type' => 'Organization',
                'name'  => 'imeihub',
                'url'   => $siteUrl,
            ],
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => number_format((float) $priceUsd, 2, '.', ''),
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'url'           => $pageUrl,
            ],
        ],
        [
            '@type'         => 'WebPage',
            'url'           => $pageUrl,
            'name'          => $plainName . ' · imeihub',
            'description'   => strip_tags((string) $service['tagline']),
            'datePublished' => '2024-06-01',
            'dateModified'  => $dateMod,
            'author'        => ['@type' => 'Organization', 'name' => 'imeihub', 'url' => $siteUrl],
            'publisher'     => ['@type' => 'Organization', 'name' => 'imeihub', 'url' => $siteUrl],
        ],
        [
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(
                static fn(array $item): array => [
                    '@type'          => 'Question',
                    'name'           => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ],
                $serviceFaqs
            ),
        ],
    ],
];

$seoKeywords = implode(', ', array_filter([
    $plainName,
    'IMEI check',
    'IMEI lookup',
    $slug,
    str_replace('-', ' ', $slug),
    'phone check',
    'device info',
]));
$pageTitle = $plainName . ' · imeihub';
$pageDesc  = strip_tags((string) $service['tagline']) . ' Price: ' . $priceDisplay . '. Instant results on imeihub.';

layout_head(
    $pageTitle,
    $pageDesc,
    '<meta name="keywords" content="' . htmlspecialchars($seoKeywords, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:url" content="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:site_name" content="imeihub">' . "\n"
    . '<meta property="og:image" content="' . $ogImage . '">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . '<meta name="twitter:title" content="' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta name="twitter:description" content="' . htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta name="twitter:image" content="' . $ogImage . '">' . "\n"
    . '<script type="application/ld+json">' . "\n"
    . json_encode($jsonLdGraph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n</script>"
);
?>
    <section class="page-hero">
        <div class="container">
            <p class="breadcrumbs">
                <a href="/">Home</a> &rsaquo;
                <a href="/services.php">Services</a> &rsaquo;
                <?= htmlspecialchars($plainName, ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div class="service-headline">
                <span class="service-headline-icon"><?= icon((string) $service['icon'], 28) ?></span>
                <h1>
                    <?= $name ?>
                    <?php if ($service['free']): ?>
                        <span class="pill pill-free">Free</span>
                    <?php else: ?>
                        <span class="pill pill-paid"><?= htmlspecialchars($priceDisplay, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </h1>
            </div>
            <p class="lede"><?= htmlspecialchars($service['tagline'], ENT_QUOTES, 'UTF-8') ?></p>

            <form id="imei-form" class="lookup-form lookup-form--hero" autocomplete="off" novalidate
                  data-code="<?= htmlspecialchars((string) $service['code'], ENT_QUOTES, 'UTF-8') ?>"
                  data-paid="<?= $service['free'] ? '0' : '1' ?>">
                <label for="imei" class="sr-only">IMEI number</label>
                <?php $prefillImei = isset($_GET['imei']) ? preg_replace('/[^0-9A-Za-z]/', '', (string) $_GET['imei']) : ''; ?>
                <input
                    id="imei"
                    name="imei"
                    type="text"
                    inputmode="numeric"
                    pattern="\d*"
                    maxlength="17"
                    value="<?= htmlspecialchars($prefillImei, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Enter 15-digit IMEI"
                    required>
                <button type="submit" id="submit-btn">
                    <span class="btn-label"><?= $service['free'] ? 'Check' : 'Run paid check' ?></span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>
            </form>

            <div id="result" class="result" hidden></div>
        </div>
    </section>

    <section class="service-body">
        <div class="container">
            <h2>About this check</h2>
            <p><?= htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8') ?></p>

            <?php if ($fieldNames): ?>
            <h2>What this report returns</h2>
            <p>Fields included when available from the registry (pricing as of November 2026: <strong><?= htmlspecialchars($priceDisplay, ENT_QUOTES, 'UTF-8') ?></strong>):</p>
            <div class="geo-table-wrap" style="margin:16px 0 28px">
                <table class="geo-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Report Field</th>
                            <th>Included</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fieldNames as $idx => $fname): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td class="geo-col-highlight"><?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>Yes (when returned by registry)</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <h3>How to find your IMEI</h3>
            <ul>
                <li>Dial <code>*#06#</code> on the phone &mdash; the 15-digit IMEI shows up immediately.</li>
                <li>Or open <em>Settings &rarr; About phone &rarr; IMEI</em>.</li>
                <li>Or check the printed label inside the SIM tray / on the original retail box.</li>
            </ul>

            <div class="geo-citations" style="margin-top:24px">
                <strong>Standards &amp; guarantee:</strong>
                IMEI numbers are validated against <a href="https://www.iso.org/standard/70484.html" target="_blank" rel="noopener noreferrer">ISO/IEC 7812-1 (Luhn checksum)</a> and decoded using the <a href="https://www.gsma.com/solutions-and-impact/technologies/device-services/imei-database/" target="_blank" rel="noopener noreferrer">GSMA TAC database</a>.
                <?php if (!$service['free']): ?>
                Paid checks are covered by our <a href="/refund-policy">Automatic Refund Guarantee</a>.
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="container">
            <h2>Frequently asked questions</h2>
            <?php foreach ($serviceFaqs as $faq): ?>
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
