<?php
declare(strict_types=1);
// AI Content Negotiation: serve clean Markdown summary when AI agents request Accept: text/markdown
if (stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/markdown') !== false && is_file(__DIR__ . '/llms.txt')) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Vary: Accept');
    readfile(__DIR__ . '/llms.txt');
    return;
}
if (!headers_sent()) {
    header('Vary: Accept');
}
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/db.php';
$cfg     = require __DIR__ . '/includes/config.php';
$appName = htmlspecialchars($cfg['app']['name'], ENT_QUOTES, 'UTF-8');

// Load the same catalog the /check page uses so the hero dropdown is
// always in sync with what the wallet actually charges for.
$categories = require __DIR__ . '/data/service_categories.php';
$services   = [];
try {
    $stmt = db()->query(
        "SELECT code, name, cost FROM service_prices
         WHERE active = 1 AND code NOT LIKE '\\_%' ESCAPE '\\\\'"
    );
    foreach ($stmt->fetchAll() as $r) {
        $services[$r['code']] = $r;
    }
} catch (Throwable $e) {
    // DB unavailable - mirror the canonical catalog from sql/seed.sql so the
    // demo (and any deploy with a temporarily down DB) still shows the full
    // dropdown. Prices match the operator's selling sheet, USD.
    $services = [
        'IMEI_BASIC'                => ['name' => 'Free IMEI Check',                                                                       'cost' => '0.00'],
        // Apple Featured
        'APPLE_BASIC'               => ['name' => 'Apple Basic Info',                                                                      'cost' => '0.10'],
        'APPLE_CARRIER_LITE'        => ['name' => 'Apple Carrier (Lite)',                                                                  'cost' => '0.08'],
        'APPLE_CARRIER_PRO'         => ['name' => 'Apple Carrier (Pro)',                                                                   'cost' => '0.16'],
        'APPLE_CARRIER_PRO_PLUS'    => ['name' => 'Apple Carrier (Pro Plus)',                                                              'cost' => '0.26'],
        'APPLE_MAX_INFO'            => ['name' => 'Apple Max Info (Premium)',                                                              'cost' => '0.70'],
        // Apple iCloud
        'APPLE_ICLOUD_STATUS'       => ['name' => 'Apple iCloud (ON / OFF)',                                                               'cost' => '0.01'],
        'APPLE_ICLOUD_CLEAN'        => ['name' => 'Apple iCloud (Clean / Lost)',                                                           'cost' => '0.03'],
        'APPLE_ICLOUD_CLEAN_SN'     => ['name' => 'Apple iCloud (Clean / Lost) SN',                                                        'cost' => '0.10'],
        'APPLE_ICLOUD_ID_HINT'      => ['name' => 'Apple iCloud ID Hint',                                                                  'cost' => '0.80'],
        'APPLE_MAC_ICLOUD_STATUS'   => ['name' => 'Apple iCloud MACBOOK/iMAC (ON / OFF)',                                                  'cost' => '0.30'],
        'APPLE_MAC_ICLOUD_CLEAN'    => ['name' => 'Apple iCloud MACBOOK/iMAC (Clean / Lost)',                                              'cost' => '0.40'],
        // Apple MDM
        'APPLE_MDM'                 => ['name' => 'Apple MDM (ON / OFF)',                                                                  'cost' => '0.35'],
        'APPLE_MDM_SN'              => ['name' => 'Apple MDM (ON / OFF) SN',                                                               'cost' => '0.30'],
        'APPLE_MDM_FMI'             => ['name' => 'Apple MDM + FMI (ON / OFF)',                                                            'cost' => '0.50'],
        // Apple Device Info
        'APPLE_WARRANTY'            => ['name' => 'Apple Warranty (Activation Info)',                                                      'cost' => '0.04'],
        'APPLE_WARRANTY_SN'         => ['name' => 'Apple Warranty (Activation Info) SN',                                                   'cost' => '0.05'],
        'APPLE_PART_NUMBER'         => ['name' => 'Apple Part Number / MPN',                                                               'cost' => '0.14'],
        'APPLE_SIM_LOCK'            => ['name' => 'Apple SIM-LOCK Status',                                                                 'cost' => '0.05'],
        'APPLE_GSX_TETHER'          => ['name' => 'Apple GSX Next Tether Policy',                                                          'cost' => '0.20'],
        // Apple GSX
        'APPLE_CASE_REPAIR_HISTORY' => ['name' => 'Apple Case History, Repair History',                                                    'cost' => '1.20'],
        'APPLE_SOLD_BY_COVERAGE'    => ['name' => 'Apple Sold By, Coverage (Max Info)',                                                    'cost' => '2.00'],
        'APPLE_SOLD_BY_HISTORY'     => ['name' => 'Apple Sold By, Case History, Activation Policy',                                        'cost' => '4.20'],
        'APPLE_GSX_LIGHT'           => ['name' => 'Apple Sold By, Case History, Replacement, GSX Activation Policy',                       'cost' => '1.00'],
        'APPLE_FULL_GSX'            => ['name' => 'Apple Sold By, Case History, Replacement, Activation Policy [ICCID & MAC] (Full GSX)',  'cost' => '2.30'],
        'APPLE_GSX_MAX'             => ['name' => 'Apple Sold By, Case History, Replacement, Repair, GSX Activation Policy (Max Info)',    'cost' => '2.60'],
        // Worldwide Blacklist
        'BLACKLIST_SIMPLE'          => ['name' => 'WorldWide Blacklist Status (SIMPLE INFO)',                                              'cost' => '0.05'],
        'BLACKLIST_FULL'            => ['name' => 'WorldWide Blacklist Status (FULL INFO)',                                                'cost' => '0.10'],
        // Other Brands
        'SAMSUNG_INFO'              => ['name' => 'SAMSUNG INFO',                                                                          'cost' => '0.10'],
        'SAMSUNG_KNOX'              => ['name' => 'SAMSUNG (Knox Guard Status, Samsung Lock - ON/OFF)',                                    'cost' => '0.20'],
        'XIAOMI_STATUS'             => ['name' => 'XIAOMI (ON / OFF)',                                                                     'cost' => '0.10'],
        'HUAWEI_INFO'               => ['name' => 'HUAWEI INFO',                                                                           'cost' => '0.08'],
        'HONOR_INFO'                => ['name' => 'HONOR INFO',                                                                            'cost' => '0.10'],
        'MOTOROLA_INFO'             => ['name' => 'MOTOROLA INFO',                                                                         'cost' => '0.10'],
        'LENOVO_INFO'               => ['name' => 'LENOVO INFO',                                                                           'cost' => '0.10'],
        'PIXEL_INFO'                => ['name' => 'Google Pixel Info',                                                                     'cost' => '0.20'],
        // US Carriers
        'TMOBILE_USA'               => ['name' => 'T-Mobile - USA iPhone/Generic Status',                                                  'cost' => '0.10'],
        'TMOBILE_USA_PRO'           => ['name' => 'T-Mobile - USA iPhone/Generic Status [PRO]',                                            'cost' => '0.06'],
        'VERIZON_USA_PRO'           => ['name' => 'Verizon - USA iPhone/Generic Status [PRO]',                                             'cost' => '0.06'],
        // Phone number lookup
        'YANDEX_ALICE'              => ['name' => 'Yandex Alice Info',                                                                     'cost' => '0.60'],
        'HLR_LOOKUP'                => ['name' => 'Home Location Register (HLR) Lookup',                                                   'cost' => '0.05'],
        'NUMBER_TYPE'               => ['name' => 'Number Type (NT) Lookup',                                                               'cost' => '0.14'],
        'PING_SMS'                  => ['name' => 'Ping-SMS',                                                                              'cost' => '0.20'],
        'PING_SMS_S2'               => ['name' => 'Ping-SMS (Server 2)',                                                                   'cost' => '0.70'],
    ];
    foreach ($services as $code => &$svc) $svc['code'] = $code;
    unset($svc);
}

$brandName     = 'imeihub';
$siteUrl       = 'https://imeihub.net';
$datePublished = '2024-06-01';
$dateModified  = '2026-11-22';

// Single source of truth for homepage FAQs so visible HTML and FAQPage JSON-LD match verbatim.
$homeFaqs = [
    [
        'q' => 'What is an IMEI number?',
        'a' => 'The IMEI (International Mobile Equipment Identity) is a unique 15-digit number that identifies a mobile device on cellular networks.',
    ],
    [
        'q' => 'Is checking my IMEI safe?',
        'a' => 'Yes. An IMEI alone does not give anyone access to your account or data. It only identifies the hardware. Still, treat it like any other ID and avoid posting it publicly.',
    ],
    [
        'q' => 'What is a TAC?',
        'a' => 'The first 8 digits of an IMEI form the Type Allocation Code (TAC), which identifies the brand and model of the device.',
    ],
    [
        'q' => 'Why did my IMEI fail validation?',
        'a' => 'IMEIs must be exactly 15 digits and pass the Luhn checksum (ISO/IEC 7812). If you typed it from a screen, double-check for missing or duplicated digits.',
    ],
    [
        'q' => 'How much does an IMEI check cost?',
        'a' => 'Basic TAC brand and model lookups are free ($0.00) with no account required. Premium checks use a pay-as-you-go credit wallet starting at $0.01 for iCloud ON/OFF, $0.05 for Worldwide Blacklist, $0.08–$0.26 for Carrier status, $0.35 for MDM, and $1.00–$4.20 for Full GSX reports.',
    ],
    [
        'q' => 'How does imeihub compare to IMEI.info?',
        'a' => 'imeihub displays exact per-check USD prices up front, requires no subscriptions or intrusive CAPTCHA gates, and automatically refunds your wallet credit if an upstream provider fails to return a report.',
    ],
];

$homeDesc  = 'Free IMEI checker by imeihub. Pick a check, paste any 15-digit IMEI, and instantly look up the brand, model, specs, iCloud, carrier, MDM, and blacklist status.';
$homeTitle = $brandName . ' · Free IMEI Check & Phone Info Lookup';
$ogImage   = $siteUrl . '/assets/img/og-image.png';

$jsonLdGraph = [
    '@context' => 'https://schema.org',
    '@graph'   => array_merge(
        layout_entity_nodes($siteUrl),
        [
            [
                '@type'               => 'WebApplication',
            '@id'                 => $siteUrl . '/#webapp',
            'name'                => $brandName,
            'alternateName'       => 'imeihub Free IMEI Check & Phone Info Lookup',
            'url'                 => $siteUrl . '/',
            'description'         => $homeDesc,
            'applicationCategory' => 'UtilityApplication',
            'operatingSystem'     => 'Web',
            'inLanguage'          => 'en',
            'datePublished'       => $datePublished,
            'dateModified'        => $dateModified,
            'author'              => ['@id' => $siteUrl . '/#organization'],
            'publisher'           => ['@id' => $siteUrl . '/#organization'],
            'offers'              => [
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
            ],
            'featureList'         => [
                'Free instant TAC-based brand, model, and device specs lookup',
                'Apple iCloud Activation Lock, Carrier, MDM, Warranty, and Full GSX checks',
                'Worldwide GSMA Blacklist and Samsung Knox Guard verification',
                'Pay-per-check credit wallet with automatic refunds on provider errors',
            ],
        ],
        [
            '@type'         => 'WebPage',
            '@id'           => $siteUrl . '/#webpage',
            'url'           => $siteUrl . '/',
            'name'          => $homeTitle,
            'description'   => $homeDesc,
            'isPartOf'      => ['@id' => $siteUrl . '/#website'],
            'about'         => ['@id' => $siteUrl . '/#webapp'],
            'datePublished' => $datePublished,
            'dateModified'  => $dateModified,
            'author'        => ['@id' => $siteUrl . '/#organization'],
            'publisher'     => ['@id' => $siteUrl . '/#organization'],
            'inLanguage'    => 'en',
        ],
        [
            '@type'      => 'FAQPage',
            '@id'        => $siteUrl . '/#faq',
            'mainEntity' => array_map(
                static fn(array $item): array => [
                    '@type'          => 'Question',
                    'name'           => $item['q'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => $item['a'],
                    ],
                ],
                $homeFaqs
            ),
        ],
    ]),
];

$jsonLdScript = '<script type="application/ld+json">' . "\n"
    . json_encode($jsonLdGraph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n</script>";

layout_head(
    $homeTitle,
    $homeDesc,
    '<meta name="keywords" content="IMEI check, IMEI checker, free IMEI lookup, phone IMEI check, check IMEI number, IMEI info, device info, phone verification">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars($homeTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars($homeDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:url" content="' . $siteUrl . '/">' . "\n"
    . '<meta property="og:site_name" content="' . $brandName . '">' . "\n"
    . '<meta property="og:image" content="' . $ogImage . '">' . "\n"
    . '<meta property="og:image:width" content="1200">' . "\n"
    . '<meta property="og:image:height" content="630">' . "\n"
    . '<meta property="og:image:alt" content="imeihub — Free IMEI Check & Phone Info Lookup">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . '<meta name="twitter:title" content="' . htmlspecialchars($homeTitle, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta name="twitter:description" content="' . htmlspecialchars($homeDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta name="twitter:image" content="' . $ogImage . '">' . "\n"
    . $jsonLdScript
);
?>
    <section class="hero" id="checker">
        <div class="container">
            <p class="hero-eyebrow">imeihub &middot; Free phone information lookup</p>
            <h1>Identify any mobile phone <br>by its IMEI number</h1>
            <p class="lede">
                IMEIHub is an instant online device verification and Apple serial lookup platform.
                Pick a report, paste any 15-digit handset identifier, and retrieve TAC brand &amp; model specs at no charge &mdash; plus pay-per-lookup iCloud FMI, carrier lock, MDM, warranty, and GSMA blacklist status.
            </p>

            <form id="imei-form" class="hero-lookup" autocomplete="off" novalidate
                  toolname="check_imei"
                  tooldescription="Validate a 15-digit IMEI or Apple serial number and retrieve device model, iCloud FMI, carrier SIM-lock, AppleCare warranty, MDM, or GSMA blacklist status.">
                <label for="imei" class="hero-field">
                    <span class="hero-field-icon"><?= icon('phone', 18) ?></span>
                    <input
                        id="imei"
                        name="imei"
                        type="text"
                        inputmode="numeric"
                        pattern="\d*"
                        maxlength="17"
                        placeholder="Enter IMEI / Serial"
                        required>
                </label>

                <label for="service-select" class="hero-field">
                    <span class="hero-field-icon"><?= icon('specs', 18) ?></span>
                    <select id="service-select" name="code" class="hero-select" required>
                        <?php
                        $groupDisplayNames = [
                            'Apple Featured'      => 'Apple Checks — Featured',
                            'Apple iCloud'        => 'iCloud & Activation Lock',
                            'Apple MDM'           => 'MDM & Supervision',
                            'Apple Device Info'   => 'Device & Warranty Info',
                            'Apple GSX (Premium)' => 'GSX History (Premium)',
                        ];
                        foreach ($categories as $group):
                            $opts = array_filter($group['codes'], fn($c) => isset($services[$c]));
                            if (!$opts) continue;
                            $rawGroupName = (string) $group['name'];
                            $isAppleGroup = stripos($rawGroupName, 'Apple') === 0;
                            $groupLabel   = $groupDisplayNames[$rawGroupName] ?? $rawGroupName;
                        ?>
                            <optgroup label="<?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?>">
                                <?php foreach ($opts as $code):
                                    $svc  = $services[$code];
                                    $cost = (float) $svc['cost'];
                                    $priceLabel = $cost === 0.0 ? 'FREE' : '$' . number_format($cost, 2);
                                    $optName = (string) $svc['name'];
                                    if ($isAppleGroup) {
                                        $optName = preg_replace('/^Apple\s+/i', '', $optName) ?? $optName;
                                    }
                                ?>
                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                            data-cost="<?= htmlspecialchars((string) $cost, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $code === 'IMEI_BASIC' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($optName, ENT_QUOTES, 'UTF-8') ?> &mdash; <?= $priceLabel ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" id="submit-btn" class="hero-submit">
                    <span class="btn-label">Check IMEI</span>
                    <span class="btn-spinner" aria-hidden="true"></span>
                </button>

                <p id="imei-hint" class="hint">
                    Dial <code>*#06#</code> on your phone to display the IMEI.
                    Premium checks require <a href="/login.php">sign-in</a>.
                </p>
            </form>

            <div id="result" class="result" hidden></div>

            <div class="hero-stats hero-stats--counters">
                <div class="stat">
                    <strong class="stat-num" data-count-to="94326">94,326</strong>
                    <span>Checked today</span>
                </div>
                <div class="stat">
                    <strong class="stat-num" data-count-to="6318452">6,318,452</strong>
                    <span>Checked this month</span>
                </div>
                <div class="stat">
                    <strong class="stat-num" data-count-to="412758194">412,758,194</strong>
                    <span>Checked total</span>
                </div>
                <div class="stat">
                    <strong class="stat-num" data-count-to="318540">318,540</strong>
                    <span>TAC in database</span>
                </div>
            </div>
        </div>
    </section>

    <section class="brands-preview">
        <div class="container">
            <div class="section-head">
                <h2>Supported Brands</h2>
                <p>Manufacturer coverage across major GSM and 5G smartphone brands.</p>
            </div>
            <div class="brand-grid brand-grid--names">
                <?php foreach (array_slice(require __DIR__ . '/data/brands.php', 0, 12) as $b): ?>
                    <a class="brand-tile brand-tile--name-only" href="/brand.php?slug=<?= urlencode($b['slug']) ?>">
                        <span class="brand-tile-name"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="brand-grid-more">
                <a class="link-more" href="/brands.php">View all brands <?= icon('arrow-right', 14) ?></a>
            </p>
        </div>
    </section>

    <section class="how" id="how">
        <div class="container">
            <div class="section-head">
                <h2>How an IMEI check works</h2>
                <p>Three steps. About fifteen seconds, including the typing.</p>
            </div>
            <div class="cards">
                <article class="card">
                    <span class="card-icon"><?= icon('magnifier', 22) ?></span>
                    <div class="card-num">01</div>
                    <h3>Dial <code>*#06#</code> to find your IMEI</h3>
                    <p>Dial <code>*#06#</code> on any mobile phone, or open Settings &rarr; About &rarr; IMEI to locate the 15-digit identifier.</p>
                </article>
                <article class="card">
                    <span class="card-icon"><?= icon('paste', 22) ?></span>
                    <div class="card-num">02</div>
                    <h3>Enter the 15-digit IMEI</h3>
                    <p>Paste the 15-digit number above, pick a free or paid report, and press <em>Check IMEI</em>. We validate the checksum automatically.</p>
                </article>
                <article class="card">
                    <span class="card-icon"><?= icon('report', 22) ?></span>
                    <div class="card-num">03</div>
                    <h3>Read TAC, Carrier, iCloud &amp; Blacklist results</h3>
                    <p>Review the decoded TAC brand/model specs immediately, or run paid checks for Carrier Lock, iCloud Activation Lock, MDM, and GSMA Blacklist status.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="geo-section geo-section--alt" id="use-cases">
        <div class="container">
            <div class="section-head">
                <h2>Who uses imeihub?</h2>
                <p>Built for anyone verifying a device before buying, trading, or repairing.</p>
            </div>
            <div class="safety-grid">
                <article class="safety-card">
                    <h3>Used-Phone Buyers</h3>
                    <p>Confirm the advertised model matches the hardware TAC, check whether Find My / iCloud Activation Lock is OFF, and verify the handset is not reported lost or stolen before handing over cash.</p>
                </article>
                <article class="safety-card">
                    <h3>Resellers &amp; Trade-In Desks</h3>
                    <p>Run 30–100+ checks per day from a single prepaid credit wallet with full order history, sub-3-second carrier/GSX responses, and automatic refunds on upstream misses.</p>
                </article>
                <article class="safety-card">
                    <h3>Repair Shops &amp; Refurbishers</h3>
                    <p>Verify original warranty coverage, MDM corporate supervision, SIM-lock policy, and Knox Guard or Mi Account lock status before quoting a repair or sourcing parts.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="geo-section" id="pricing">
        <div class="container">
            <div class="section-head">
                <h2>How much does an IMEI check cost?</h2>
                <p>Transparent per-check USD rates with no subscriptions. <strong>Pricing as of November 2026.</strong></p>
            </div>

            <div class="geo-table-wrap">
                <table class="geo-table">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>What It Returns</th>
                            <th>Price (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="geo-table-group">
                            <td colspan="3">Free Lookup</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Free IMEI Check (TAC Lookup)</td>
                            <td>Manufacturer, model name, and basic hardware specs (no account required)</td>
                            <td class="geo-price geo-price--free">$0.00 (Free)</td>
                        </tr>

                        <tr class="geo-table-group">
                            <td colspan="3">Apple Checks (iPhone, iPad, Mac)</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">iCloud (ON / OFF)</td>
                            <td>Find My / Activation Lock status</td>
                            <td class="geo-price">$0.01</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">iCloud (Clean / Lost)</td>
                            <td>Activation Lock plus Clean vs. Lost/Erased mode</td>
                            <td class="geo-price">$0.03</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Warranty &amp; Activation Info</td>
                            <td>Coverage status, activation state, estimated purchase date</td>
                            <td class="geo-price">$0.04</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">SIM-Lock Status</td>
                            <td>Locked vs. Unlocked carrier status</td>
                            <td class="geo-price">$0.05</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Carrier Check (Lite / Pro / Pro Plus)</td>
                            <td>Original carrier, lock policy, sold-by country, and coverage details</td>
                            <td class="geo-price">$0.08 &ndash; $0.26</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">MDM (ON / OFF) &amp; MDM + FMI</td>
                            <td>Corporate Mobile Device Management supervision check</td>
                            <td class="geo-price">$0.30 &ndash; $0.50</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">GSX Reports (Light / Full / Max Info)</td>
                            <td>Sold-by info, activation policy, replacement, case &amp; repair history</td>
                            <td class="geo-price">$1.00 &ndash; $4.20</td>
                        </tr>

                        <tr class="geo-table-group">
                            <td colspan="3">Blacklist, Android &amp; Carrier Checks</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">WorldWide Blacklist (Simple / Full)</td>
                            <td>Global GSMA lost/stolen blacklist status and reporting country/carrier</td>
                            <td class="geo-price">$0.05 &ndash; $0.10</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Samsung Info &amp; Knox Guard</td>
                            <td>Model details, warranty, and Knox Guard / Samsung Lock status</td>
                            <td class="geo-price">$0.10 &ndash; $0.20</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Xiaomi, Huawei, Honor, Motorola, Pixel</td>
                            <td>Brand-specific device info, warranty, and Xiaomi Mi Account status</td>
                            <td class="geo-price">$0.08 &ndash; $0.20</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">US Carriers (T-Mobile / Verizon)</td>
                            <td>Carrier financing, clean/unpaid balance, and eligibility status</td>
                            <td class="geo-price">$0.06 &ndash; $0.10</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="geo-note">
                Paid checks deduct from your prepaid Credit Wallet. Supported top-up methods include Stripe (card), PayPal, Binance Pay, and USDT (TRC20 / BEP20)&mdash;see current payment fees and any active top-up bonus terms on the <a href="/topup.php">Credit Top-Up page</a> or browse the <a href="/services.php">full service catalog</a>.
            </p>
        </div>
    </section>

    <section class="geo-section geo-section--alt" id="compare">
        <div class="container">
            <div class="section-head">
                <h2>imeihub vs IMEI.info</h2>
                <p>How imeihub compares for everyday buyers, repair shops, and high-volume phone resellers.</p>
            </div>

            <div class="geo-table-wrap">
                <table class="geo-table">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>imeihub (imeihub.net)</th>
                            <th>IMEI.info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="geo-col-highlight">Pricing Transparency</td>
                            <td>Exact per-check USD prices shown up front ($0.00 free TAC, $0.01&ndash;$4.20 paid)</td>
                            <td>Per-service pricing varies by tier and checkout flow</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">CAPTCHA &amp; Access Friction</td>
                            <td>Direct server-rendered pages with no aggressive CAPTCHA or WAF walls</td>
                            <td>Frequent WAF / CAPTCHA verification challenges</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Billing Model</td>
                            <td>Pay-as-you-go Credit Wallet with no recurring subscription</td>
                            <td>Individual checkouts or account credit packages</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Failed Lookup Protection</td>
                            <td>Automatic instant wallet refund if an upstream provider fails</td>
                            <td>Manual support follow-up for failed orders</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Speed &amp; Workflow</td>
                            <td>Instant TAC lookup; typical paid reports in seconds + order history &amp; bot support</td>
                            <td>Browser-first workflow with ad-supported pages</td>
                        </tr>
                        <tr>
                            <td class="geo-col-highlight">Payment Methods</td>
                            <td>Stripe (card), PayPal, Binance Pay, USDT (TRC20 / BEP20)</td>
                            <td>Card / PayPal</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="geo-section" id="safety">
        <div class="container">
            <div class="section-head">
                <h2>Is checking an IMEI number safe?</h2>
                <p>What an IMEI check can and cannot access, plus our refund and support guarantees.</p>
            </div>

            <div class="safety-grid">
                <article class="safety-card">
                    <h3>Hardware &amp; TAC Data Only</h3>
                    <p>An IMEI check only reads the device's Type Allocation Code (TAC) and carrier or manufacturer registry status. It cannot access personal files, photos, messages, passwords, Apple ID, or carrier accounts. See our <a href="/privacy.php">Privacy Policy</a> for how queries are handled.</p>
                </article>
                <article class="safety-card">
                    <h3>Automatic Refund Guarantee</h3>
                    <p>If an upstream carrier or manufacturer API times out or returns an error on any paid check, imeihub automatically refunds the full check cost back to your Credit Wallet&mdash;no support ticket required.</p>
                </article>
                <article class="safety-card">
                    <h3>Direct Human Support</h3>
                    <p>Questions about a report, bulk credits, or an order? Email <a href="mailto:support@imeihub.net">support@imeihub.net</a> or use our <a href="/contact.php">Contact form</a>. We respond to all inquiries within two business days.</p>
                </article>
            </div>

            <div class="geo-citations">
                <strong>Technical standards &amp; authoritative references:</strong>
                IMEI structure and the 8-digit Type Allocation Code (TAC) are governed by the
                <a href="https://www.gsma.com/solutions-and-impact/technologies/device-services/imei-database/" target="_blank" rel="noopener noreferrer">GSMA IMEI Database specification</a>.
                Every 15-digit IMEI entered on imeihub is validated client-side and server-side against the
                <a href="https://www.iso.org/standard/70484.html" target="_blank" rel="noopener noreferrer">ISO/IEC 7812-1 (Luhn check-digit algorithm)</a>
                before any paid lookup is submitted.
            </div>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="container">
            <h2>Frequently asked questions</h2>

            <?php foreach ($homeFaqs as $faq): ?>
            <details>
                <summary><?= htmlspecialchars($faq['q'], ENT_QUOTES, 'UTF-8') ?></summary>
                <p><?= htmlspecialchars($faq['a'], ENT_QUOTES, 'UTF-8') ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <?php $testimonials = require __DIR__ . '/data/testimonials.php'; ?>
    <section class="testimonials" id="testimonials" aria-label="Customer testimonials">
        <div class="container">
            <div class="section-head">
                <h2>What customers say</h2>
                <p>Real feedback from refurbishers, resellers, and repair shops worldwide.</p>
            </div>

            <div class="testimonial-carousel" data-autoplay="6000">
                <button class="testimonial-nav testimonial-nav--prev" type="button" aria-label="Previous testimonial">
                    <?= icon('arrow-right', 18) ?>
                </button>

                <div class="testimonial-track" tabindex="0">
                    <?php foreach ($testimonials as $i => $t): ?>
                        <article class="testimonial-card" data-index="<?= $i ?>">
                            <div class="testimonial-rating" aria-label="<?= (int) $t['rating'] ?> out of 5 stars">
                                <?php for ($s = 0; $s < (int) $t['rating']; $s++): ?>
                                    <svg viewBox="0 0 20 20" width="16" height="16" fill="currentColor" aria-hidden="true">
                                        <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.2 1 5.9L10 15l-5.2 2.8 1-5.9L1.5 7.7l5.9-.9z"/>
                                    </svg>
                                <?php endfor; ?>
                            </div>
                            <blockquote class="testimonial-quote">
                                <?= htmlspecialchars((string) $t['quote'], ENT_QUOTES, 'UTF-8') ?>
                            </blockquote>
                            <footer class="testimonial-author">
                                <span class="testimonial-avatar" style="background:<?= htmlspecialchars((string) $t['tone'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) $t['initial'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="testimonial-meta">
                                    <strong><?= htmlspecialchars((string) $t['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= htmlspecialchars((string) $t['role'], ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars((string) $t['country'], ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>

                <button class="testimonial-nav testimonial-nav--next" type="button" aria-label="Next testimonial">
                    <?= icon('arrow-right', 18) ?>
                </button>
            </div>

            <div class="testimonial-dots" role="tablist" aria-label="Choose testimonial">
                <?php foreach ($testimonials as $i => $t): ?>
                    <button class="testimonial-dot<?= $i === 0 ? ' is-active' : '' ?>"
                            type="button"
                            role="tab"
                            aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                            aria-label="Testimonial <?= $i + 1 ?>"
                            data-index="<?= $i ?>"></button>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="page-meta-bar" aria-label="Page authorship and update information">
        <div class="container page-meta-bar-inner">
            <span>
                Maintained by <a href="/about.php" rel="author">imeihub Editorial Team</a>
                &middot; Support: <a href="mailto:support@imeihub.net">support@imeihub.net</a>
            </span>
            <span>
                Last updated: <time datetime="<?= htmlspecialchars($dateModified, ENT_QUOTES, 'UTF-8') ?>">November 22, 2026</time>
            </span>
        </div>
    </section>

    <script>
    (function () {
        // ----- Count-up animation on hero stats -----
        var counters = document.querySelectorAll('[data-count-to]');
        if (counters.length) {
            var fmt = new Intl.NumberFormat('en-US');
            function animate(el) {
                var target = parseInt(el.getAttribute('data-count-to'), 10) || 0;
                var duration = 1800;
                var start = performance.now();
                function tick(now) {
                    var t = Math.min(1, (now - start) / duration);
                    // easeOutQuart for a confident decelerate
                    var eased = 1 - Math.pow(1 - t, 4);
                    el.textContent = fmt.format(Math.round(target * eased));
                    if (t < 1) requestAnimationFrame(tick);
                }
                requestAnimationFrame(tick);
            }
            if ('IntersectionObserver' in window) {
                var io = new IntersectionObserver(function (entries) {
                    entries.forEach(function (e) {
                        if (e.isIntersecting) {
                            animate(e.target);
                            io.unobserve(e.target);
                        }
                    });
                }, { threshold: 0.4 });
                counters.forEach(function (c) { io.observe(c); });
            } else {
                counters.forEach(animate);
            }
        }

        // ----- Testimonial carousel -----
        var carousel = document.querySelector('.testimonial-carousel');
        if (carousel) {
            var track = carousel.querySelector('.testimonial-track');
            var prev  = carousel.querySelector('.testimonial-nav--prev');
            var next  = carousel.querySelector('.testimonial-nav--next');
            var dots  = document.querySelectorAll('.testimonial-dot');
            var cards = track.querySelectorAll('.testimonial-card');
            var autoplayMs = parseInt(carousel.getAttribute('data-autoplay'), 10) || 0;
            var current = 0;
            var timer = null;
            var paused = false;

            function step() {
                // How many cards fit in the visible track width.
                var first = cards[0];
                if (!first) return 1;
                var w = first.getBoundingClientRect().width
                      + parseFloat(getComputedStyle(track).columnGap || '0');
                return Math.max(1, Math.floor(track.clientWidth / w));
            }

            function goTo(i) {
                var maxStart = Math.max(0, cards.length - step());
                if (i < 0) i = maxStart;
                if (i > maxStart) i = 0;
                current = i;
                var first = cards[0];
                if (first) {
                    var w = first.getBoundingClientRect().width
                          + parseFloat(getComputedStyle(track).columnGap || '0');
                    track.scrollTo({ left: i * w, behavior: 'smooth' });
                }
                dots.forEach(function (d, idx) {
                    var active = idx === i;
                    d.classList.toggle('is-active', active);
                    d.setAttribute('aria-selected', active ? 'true' : 'false');
                });
            }

            function tick() { goTo(current + 1); }
            function startTimer() {
                if (!autoplayMs || paused) return;
                stopTimer();
                timer = setInterval(tick, autoplayMs);
            }
            function stopTimer() { if (timer) clearInterval(timer); timer = null; }

            prev.addEventListener('click', function () { goTo(current - 1); startTimer(); });
            next.addEventListener('click', function () { goTo(current + 1); startTimer(); });
            dots.forEach(function (d) {
                d.addEventListener('click', function () {
                    goTo(parseInt(d.getAttribute('data-index'), 10) || 0);
                    startTimer();
                });
            });

            carousel.addEventListener('mouseenter', function () { paused = true; stopTimer(); });
            carousel.addEventListener('mouseleave', function () { paused = false; startTimer(); });
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) stopTimer(); else startTimer();
            });

            // Sync dot state when the user free-scrolls the track.
            var scrollTimeout;
            track.addEventListener('scroll', function () {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(function () {
                    var first = cards[0]; if (!first) return;
                    var w = first.getBoundingClientRect().width
                          + parseFloat(getComputedStyle(track).columnGap || '0');
                    var idx = Math.round(track.scrollLeft / w);
                    if (idx !== current) {
                        current = idx;
                        dots.forEach(function (d, i) {
                            var active = i === current;
                            d.classList.toggle('is-active', active);
                            d.setAttribute('aria-selected', active ? 'true' : 'false');
                        });
                    }
                }, 80);
            });

            startTimer();
        }
    })();
    </script>
<?php layout_foot(); ?>
