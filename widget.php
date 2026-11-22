<?php
declare(strict_types=1);
if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/markdown') !== false) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Vary: Accept');
    echo "# imeihub Free Embeddable IMEI Checker Widget\n\n";
    echo "Canonical: https://imeihub.net/widget | Embed URL: https://imeihub.net/embed-imei-checker.php\n\n";
    echo "Embed a zero-maintenance, under-4KB iframe widget on any GSM forum, mobile repair shop, or tech blog to validate 15-digit IMEIs (ISO/IEC 7812-1) and link to instant TAC, iCloud FMI, AppleCare warranty, Carrier SIM-Lock, and Blacklist reports.\n";
    return;
}
if (!headers_sent()) {
    header('Vary: Accept');
}
require __DIR__ . '/includes/layout.php';

$siteUrl    = 'https://imeihub.net';
$pageUrl    = $siteUrl . '/widget';
$embedUrl   = $siteUrl . '/embed-imei-checker.php';
$checkerUrl = $siteUrl . '/service.php?slug=free-imei-check';
$ogImage    = $siteUrl . '/assets/img/og-image.png';
$dateMod    = '2026-11-22';

$faqs = [
    [
        'q' => 'Does the imeihub embeddable IMEI Checker Widget cost anything?',
        'a' => 'No. The embeddable lookup box is 100% complimentary ($0.00) for mobile repair shops, GSM forums, phone resellers, and tech blogs with zero maintenance and no API key required.',
    ],
    [
        'q' => 'Which verification reports can visitors launch from the embedded widget?',
        'a' => 'Visitors can validate any 15-digit handset identifier (ISO/IEC 7812-1 Luhn check) or Apple Serial Number and jump directly to TAC Model Lookup ($0.00), iCloud / FMI Status ($0.01–$0.03), AppleCare Warranty ($0.04), Carrier SIM-Lock ($0.05), or Worldwide Blacklist ($0.05–$0.10).',
    ],
    [
        'q' => 'Does embedding the widget slow down my website?',
        'a' => 'No. The iframe loads asynchronously with native lazy loading under 4 KB and zero external JavaScript dependencies.',
    ],
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => array_merge(
        layout_entity_nodes($siteUrl),
        [
            [
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Free IMEI Widget', 'item' => $pageUrl],
                ],
            ],
            [
                '@type'               => 'SoftwareApplication',
                'name'                => 'imeihub Free Embeddable IMEI Checker Widget',
                'applicationCategory' => 'UtilitiesApplication',
                'operatingSystem'     => 'Web',
                'url'                 => $pageUrl,
                'offers'              => ['@type' => 'Offer', 'price' => '0.00', 'priceCurrency' => 'USD'],
                'publisher'           => ['@id' => $siteUrl . '/#organization'],
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
        ]
    ),
];

$title = 'Free Embeddable IMEI Checker Widget · imeihub';
$desc  = 'Embed a free, lightweight IMEI & Apple Serial Checker widget on your GSM forum, phone repair shop, or tech blog in 30 seconds. Zero maintenance.';

layout_head(
    $title,
    $desc,
    '<link rel="canonical" href="' . $pageUrl . '">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="website">' . "\n"
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
            <p class="breadcrumbs">
                <a href="/">Home</a> &rsaquo; Free Widget
            </p>
            <h1>Free Embeddable IMEI Checker Widget <span class="pill pill-free">Free</span></h1>
            <p class="lede">
                Let your visitors validate 15-digit IMEIs and check iCloud FMI, AppleCare warranty, carrier SIM-lock, and GSMA blacklist status right from your website or GSM forum.
            </p>
        </div>
    </section>

    <section class="service-body">
        <div class="container">
            <h2>1. Live Widget Preview</h2>
            <p>This is exactly how the widget renders when embedded on your page:</p>
            <div style="margin:16px 0 28px;border:1px solid var(--border, #e5e7eb);border-radius:12px;overflow:hidden;background:#fff;">
                <iframe
                    src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
                    width="100%"
                    height="210"
                    style="border:0;display:block;"
                    loading="lazy"
                    title="imeihub Free IMEI Checker Widget Preview"
                ></iframe>
            </div>

            <h2>2. Copy &amp; Paste HTML Embed Code</h2>
            <p>Paste this snippet anywhere in your HTML, WordPress Custom HTML block, or forum sidebar:</p>
            <pre id="embed-code" style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px;overflow-x:auto;font-size:0.88rem;line-height:1.5;">&lt;!-- Free IMEI Checker Widget by imeihub.net --&gt;
&lt;iframe
  src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
  width="100%"
  height="210"
  style="border:1px solid #e5e7eb;border-radius:10px;"
  loading="lazy"
  title="Free IMEI Checker"
&gt;&lt;/iframe&gt;
&lt;p style="font-size:12px;margin-top:6px;font-family:sans-serif;"&gt;
  Powered by &lt;a href="<?= htmlspecialchars($checkerUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"&gt;imeihub Free IMEI Checker&lt;/a&gt;
&lt;/p&gt;</pre>
            <p>
                <button type="button" class="btn-signup" style="border:0;cursor:pointer;"
                        onclick="navigator.clipboard.writeText(document.getElementById('embed-code').innerText); this.textContent='Copied!';">
                    Copy Embed Code
                </button>
            </p>

            <h2>3. Direct Text Link (For Troubleshooting Guides &amp; FAQs)</h2>
            <p>Writing an iPhone buying guide or iCloud unlock FAQ? Link directly to our free lookup:</p>
            <pre id="text-link" style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:10px;overflow-x:auto;font-size:0.88rem;">&lt;a href="https://imeihub.net/service.php?slug=apple-icloud" target="_blank" rel="noopener"&gt;Check iCloud &amp; Find My iPhone Status on imeihub&lt;/a&gt;</pre>
            <p>
                <button type="button" class="btn-signin" style="cursor:pointer;"
                        onclick="navigator.clipboard.writeText(document.getElementById('text-link').innerText); this.textContent='Copied!';">
                    Copy Link HTML
                </button>
            </p>

            <div class="geo-citations" style="margin-top:24px">
                <strong>Standards &amp; developer API:</strong>
                All 15-digit identifiers are validated against <a href="https://www.iso.org/standard/70484.html" target="_blank" rel="noopener noreferrer">ISO/IEC 7812-1</a> and decoded with the <a href="https://www.gsma.com/solutions-and-impact/technologies/device-services/imei-database/" target="_blank" rel="noopener noreferrer">GSMA TAC database</a>.
                Need JSON responses instead? Explore our <a href="/api-docs">API &amp; Bulk Lookup Docs</a> or email <a href="mailto:support@imeihub.net">support@imeihub.net</a> for partner sandbox credits.
            </div>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="container">
            <h2>Frequently asked questions</h2>
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
