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
$name = $service['name'];
$seoKeywords = implode(', ', array_filter([
    strip_tags(html_entity_decode($name)),
    'IMEI check',
    'IMEI lookup',
    $slug,
    str_replace('-', ' ', $slug),
    'phone check',
    'device info',
]));
layout_head(
    strip_tags(html_entity_decode($name)) . ' · imeihub',
    strip_tags($service['tagline']),
    '<meta name="keywords" content="' . htmlspecialchars($seoKeywords, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars(strip_tags(html_entity_decode($name)) . ' · imeihub', ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars(strip_tags($service['tagline']), ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:url" content="https://imeihub.net/service.php?slug=' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">'
);
?>
    <section class="page-hero">
        <div class="container">
            <p class="breadcrumbs">
                <a href="/services.php">Services</a> &rsaquo;
                <?= strip_tags($name) ?>
            </p>
            <div class="service-headline">
                <span class="service-headline-icon"><?= icon((string) $service['icon'], 28) ?></span>
                <h1>
                    <?= $name ?>
                    <?php if ($service['free']): ?>
                        <span class="pill pill-free">Free</span>
                    <?php endif; ?>
                </h1>
            </div>
            <p class="lede"><?= htmlspecialchars($service['tagline'], ENT_QUOTES, 'UTF-8') ?></p>

            <form id="imei-form" class="lookup-form lookup-form--hero" autocomplete="off" novalidate
                  data-code="<?= htmlspecialchars((string) $service['code'], ENT_QUOTES, 'UTF-8') ?>"
                  data-paid="<?= $service['free'] ? '0' : '1' ?>">
                <label for="imei" class="sr-only">IMEI number</label>
                <input
                    id="imei"
                    name="imei"
                    type="text"
                    inputmode="numeric"
                    pattern="\d*"
                    maxlength="17"
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

            <h3>How to find your IMEI</h3>
            <ul>
                <li>Dial <code>*#06#</code> on the phone &mdash; the IMEI shows up immediately.</li>
                <li>Or open <em>Settings &rarr; About phone &rarr; IMEI</em>.</li>
                <li>Or check the printed label inside the SIM tray / on the box.</li>
            </ul>
        </div>
    </section>
<?php layout_foot(); ?>
