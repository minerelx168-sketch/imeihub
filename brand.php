<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
$brands = require __DIR__ . '/data/brands.php';

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]+/i', '', (string) $_GET['slug']) : '';
$brand = null;
foreach ($brands as $b) {
    if ($b['slug'] === $slug) {
        $brand = $b;
        break;
    }
}

if (!$brand) {
    http_response_code(404);
    layout_head('Brand not found · imeihub');
    ?>
    <section class="page-hero">
        <div class="container">
            <h1>Brand not found</h1>
            <p class="lede">The brand <code><?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?></code> is not in our catalog. <a href="/brands.php">Browse all brands &rarr;</a></p>
        </div>
    </section>
    <?php
    layout_foot();
    return;
}

require_once __DIR__ . '/includes/icons.php';
$rawBrandName = (string) $brand['name'];
$brandName    = htmlspecialchars($rawBrandName, ENT_QUOTES, 'UTF-8');
$brandColor   = htmlspecialchars($brand['color'], ENT_QUOTES);
$country      = htmlspecialchars($brand['country'], ENT_QUOTES, 'UTF-8');
$siteUrl      = 'https://imeihub.net';
$pageUrl      = $siteUrl . '/brand.php?slug=' . urlencode($slug);
$ogImage      = $siteUrl . '/assets/img/og-image.png';
$dateMod      = '2026-11-22';

$brandFaqs = [
    [
        'q' => "How do I find the IMEI on a {$rawBrandName} phone?",
        'a' => "Dial *#06# on your {$rawBrandName} dialer to display the 15-digit IMEI (and IMEI2 on Dual SIM models), or open Settings > About Phone > IMEI.",
    ],
    [
        'q' => "What does a free {$rawBrandName} IMEI check show?",
        'a' => "A free TAC lookup decodes the first 8 digits of the IMEI against the GSMA Type Allocation Code database to confirm the exact {$rawBrandName} model name, variant, and basic hardware specifications.",
    ],
    [
        'q' => "Can I check blacklist or lock status for a {$rawBrandName} device?",
        'a' => "Yes. After running the free TAC model check, signed-in users can run Worldwide GSMA Blacklist checks ($0.05–$0.10) and brand-specific carrier, warranty, or lock reports from their prepaid Credit Wallet.",
    ],
];

$brandKeywords = implode(', ', [
    "$rawBrandName IMEI check",
    "$rawBrandName IMEI lookup",
    "check $rawBrandName phone",
    "$rawBrandName serial number",
    'IMEI checker',
    'phone verification',
]);
$brandTitle = "$rawBrandName IMEI Check · imeihub";
$brandDesc  = "Free {$rawBrandName} IMEI check and TAC lookup on imeihub. Verify {$rawBrandName} models, warranty, carrier lock, and worldwide blacklist status instantly.";

$jsonLdGraph = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Brands', 'item' => $siteUrl . '/brands.php'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $rawBrandName, 'item' => $pageUrl],
            ],
        ],
        [
            '@type'         => 'CollectionPage',
            'url'           => $pageUrl,
            'name'          => $brandTitle,
            'description'   => $brandDesc,
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
                $brandFaqs
            ),
        ],
    ],
];

layout_head(
    $brandTitle,
    $brandDesc,
    '<meta name="keywords" content="' . htmlspecialchars($brandKeywords, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars($brandDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:url" content="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:site_name" content="imeihub">' . "\n"
    . '<meta property="og:image" content="' . $ogImage . '">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . '<meta name="twitter:title" content="' . htmlspecialchars($brandTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta name="twitter:description" content="' . htmlspecialchars($brandDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
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
                <a href="/brands.php">Brands</a> &rsaquo; <?= $brandName ?>
            </p>
            <div class="brand-headline">
                <div>
                    <h1><?= $brandName ?> IMEI Check</h1>
                    <p class="brand-meta"><?= $country ?> &middot; <?= count($brand['models']) ?> popular models indexed</p>
                </div>
            </div>
            <p class="lede" style="margin-top:18px">
                <?= $brandName ?> is a mobile manufacturer from <?= $country ?>.
                Enter any 15-digit <?= $brandName ?> IMEI on the <a href="/">imeihub checker</a>
                to decode the GSMA TAC model variant for free, or run paid blacklist and lock checks.
            </p>
        </div>
    </section>

    <section class="model-list">
        <div class="container">
            <h2>Common <?= $brandName ?> models</h2>
            <ul class="models">
                <?php foreach ($brand['models'] as $m): ?>
                    <li><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="lede-soft" style="margin-top:32px">
                Don't see your model? Models are identified from the first 8 digits
                (Type Allocation Code / TAC) issued by the <a href="https://www.gsma.com/solutions-and-impact/technologies/device-services/imei-database/" target="_blank" rel="noopener noreferrer">GSMA IMEI Database</a>.
                <a href="/">Run a free IMEI check</a> or browse <a href="/services.php">all paid verification services</a>.
            </p>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="container">
            <h2><?= $brandName ?> IMEI FAQ</h2>
            <?php foreach ($brandFaqs as $faq): ?>
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
