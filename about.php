<?php
declare(strict_types=1);
if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/markdown') !== false) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Vary: Accept');
    echo "# About imeihub\n\n";
    echo "Canonical: https://imeihub.net/about.php | Support: support@imeihub.net\n\n";
    echo "imeihub is a free online IMEI checker and pay-per-lookup mobile verification platform covering 44 services (TAC specs, iCloud FMI, AppleCare warranty, Carrier SIM-Lock, MDM, Full GSX, GSMA Blacklist, Samsung Knox) validated against ISO/IEC 7812-1 and GSMA TAC registries.\n";
    return;
}
if (!headers_sent()) {
    header('Vary: Accept');
}
require __DIR__ . '/includes/layout.php';

$siteUrl = 'https://imeihub.net';
$pageUrl = $siteUrl . '/about.php';
$ogImage = $siteUrl . '/assets/img/og-image.png';
$dateMod = '2026-11-22';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => array_merge(
        layout_entity_nodes($siteUrl),
        [
            [
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'About', 'item' => $pageUrl],
                ],
            ],
            [
                '@type'         => 'AboutPage',
                'url'           => $pageUrl,
                'name'          => 'About imeihub',
                'description'   => 'About imeihub — free TAC IMEI lookup and transparent pay-per-check mobile verification with automatic refunds.',
                'datePublished' => '2024-06-01',
                'dateModified'  => $dateMod,
                'isPartOf'      => ['@id' => $siteUrl . '/#website'],
                'mainEntity'    => ['@id' => $siteUrl . '/#organization'],
            ],
        ]
    ),
];

$title = 'About · imeihub';
$desc  = 'About imeihub — a free tool for looking up the brand, model and specifications of any mobile phone by its IMEI number.';
layout_head(
    $title,
    $desc,
    '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . "\n"
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
            <p class="breadcrumbs"><a href="/">Home</a> &rsaquo; About</p>
            <h1>About imeihub</h1>
            <p class="lede">
                We help people identify mobile phones quickly and safely &mdash;
                using nothing but the 15-digit IMEI on the back of the device.
            </p>
        </div>
    </section>

    <section class="prose">
        <div class="container container--prose">
            <h2>What we do</h2>
            <p>
                imeihub is a free tool for looking up the brand, model and
                specifications of any mobile phone using its IMEI number. We
                also offer transparent pay-per-check premium reports for blacklist status,
                carrier lock, iCloud activation lock, MDM, Samsung Knox, and warranty
                information&mdash;backed by our <a href="/refund-policy">Automatic Refund Guarantee</a>.
            </p>

            <h2>Why it exists</h2>
            <p>
                The second-hand phone market is huge &mdash; and so is the
                market for stolen and blacklisted devices. A 15-second IMEI
                check before you hand over money can save you from buying a
                brick. We built imeihub so that this check takes one
                paste and one click, without intrusive CAPTCHA gates or forced subscriptions.
            </p>

            <h2>How accurate is the data?</h2>
            <p>
                For brand and model lookups, accuracy is essentially 100%
                &mdash; the first eight digits of an IMEI (Type Allocation Code / TAC)
                are issued by the <a href="https://www.gsma.com/solutions-and-impact/technologies/device-services/imei-database/" target="_blank" rel="noopener noreferrer">GSMA IMEI Database</a>
                and tie directly to a specific certified device. Every IMEI is validated
                against the <a href="https://www.iso.org/standard/70484.html" target="_blank" rel="noopener noreferrer">ISO/IEC 7812-1 Luhn checksum</a>.
                For blacklist and carrier checks, accuracy depends on the partner
                database; we work with established providers and automatically refund
                any lookup that fails to return data.
            </p>

            <h2>Privacy</h2>
            <p>
                We do not store personal information. IMEIs you look up are
                hashed and cached briefly so that repeat queries are fast and
                cheap to serve. See <a href="/privacy.php">our privacy
                policy</a> for details.
            </p>

            <p style="margin-top:32px">
                Questions or feedback? Email <a href="mailto:support@imeihub.net">support@imeihub.net</a>
                or <a href="/contact.php">get in touch</a>.
            </p>
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
