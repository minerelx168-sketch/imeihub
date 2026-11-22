<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';

$siteUrl = 'https://imeihub.net';
$pageUrl = $siteUrl . '/refund-policy';
$ogImage = $siteUrl . '/assets/img/og-image.png';
$dateMod = '2026-11-22';

$faqs = [
    [
        'q' => 'How does the imeihub Automatic Refund Guarantee work?',
        'a' => 'Whenever an upstream carrier or manufacturer lookup provider times out, returns an invalid response, or fails to deliver a report on a paid check, imeihub automatically returns the full check cost to your Credit Wallet immediately—no support ticket required.',
    ],
    [
        'q' => 'Are free IMEI checks charged?',
        'a' => 'No. Basic TAC brand and model checks are always free ($0.00) and never deduct from your wallet balance.',
    ],
    [
        'q' => 'What if I entered an invalid 15-digit IMEI?',
        'a' => 'imeihub validates every 15-digit IMEI against the ISO/IEC 7812 Luhn checksum before submitting a paid request, so malformed numbers are rejected before any credit is charged.',
    ],
    [
        'q' => 'How do I contact support about a billing or wallet question?',
        'a' => 'Email support@imeihub.net or submit a request via https://imeihub.net/contact.php with your order ID or account email. We respond within two business days.',
    ],
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Refund Policy', 'item' => $pageUrl],
            ],
        ],
        [
            '@type'         => 'WebPage',
            'url'           => $pageUrl,
            'name'          => 'Refund Policy & Auto-Refund Guarantee · imeihub',
            'description'   => 'imeihub automatic wallet refund guarantee for failed IMEI lookups, billing terms, and support contact.',
            'datePublished' => '2026-11-22',
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
                $faqs
            ),
        ],
    ],
];

$title = 'Refund Policy & Auto-Refund Guarantee · imeihub';
$desc  = 'Transparent refund policy for imeihub Credit Wallet top-ups and paid IMEI checks, including our instant Automatic Refund Guarantee on failed lookups.';

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
                <a href="/">Home</a> &rsaquo; Refund Policy
            </p>
            <h1>Refund Policy &amp; Auto-Refund Guarantee</h1>
            <p class="lede">
                You only pay for completed IMEI reports. If an upstream lookup fails or times out,
                imeihub automatically returns the credit to your wallet.
            </p>
        </div>
    </section>

    <section class="prose">
        <div class="container container--prose">
            <h2>1. Automatic Wallet Refund on Failed Checks</h2>
            <p>
                Every paid check on imeihub is backed by an automated ledger safeguard. If an
                upstream carrier, manufacturer, or GSMA API returns an error, rejects the order,
                or times out before producing a report, our system automatically credits the full
                cost of that check back to your imeihub Credit Wallet. You can verify every charge
                and automatic refund in your account transaction history.
            </p>

            <h2>2. Pre-Check Validation (No Charge for Typos)</h2>
            <p>
                Before any paid request is submitted, imeihub validates that the entered IMEI is
                15 digits and passes the <a href="https://www.iso.org/standard/70484.html" target="_blank" rel="noopener noreferrer">ISO/IEC 7812-1 Luhn checksum</a>.
                Malformed numbers are blocked before any credit is deducted.
            </p>

            <h2>3. Completed Reports &amp; Unused Wallet Credit</h2>
            <p>
                Once a paid IMEI report is successfully returned by the registry (for example,
                returning an iCloud ON/OFF, Blacklist Clean/Lost, Carrier, MDM, or GSX result),
                the lookup fee is non-refundable because upstream provider costs are incurred
                immediately upon delivery. If you believe a completed report contained a technical
                error or duplicate charge, contact us within 7 days with your Order ID and we will
                investigate and credit your wallet if confirmed.
            </p>
            <p>
                Unused wallet balances never expire and can be spent on any service in the
                <a href="/services.php">imeihub service catalog</a> with zero monthly subscription fees.
            </p>

            <h2>4. Contact Support</h2>
            <p>
                For billing inquiries, duplicate top-up issues, or refund reviews, email
                <a href="mailto:support@imeihub.net">support@imeihub.net</a> or use our
                <a href="/contact.php">Contact page</a>. We respond within two business days.
            </p>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="container">
            <h2>Refund Policy FAQ</h2>
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
