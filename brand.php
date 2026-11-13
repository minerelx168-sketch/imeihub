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
$brandName  = htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8');
$brandColor = htmlspecialchars($brand['color'], ENT_QUOTES);
$country    = htmlspecialchars($brand['country'], ENT_QUOTES, 'UTF-8');
$brandKeywords = implode(', ', [
    "$brandName IMEI check",
    "$brandName IMEI lookup",
    "check $brandName phone",
    "$brandName serial number",
    'IMEI checker',
    'phone verification',
]);
$brandDesc = "Look up any $brandName phone by IMEI. Common $brandName models and how to find the IMEI on your device.";
layout_head(
    "$brandName IMEI Check · imeihub",
    $brandDesc,
    '<meta name="keywords" content="' . htmlspecialchars($brandKeywords, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:title" content="' . htmlspecialchars("$brandName IMEI Check · imeihub", ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:description" content="' . htmlspecialchars($brandDesc, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:url" content="https://imeihub.net/brand.php?slug=' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">'
);
?>
    <section class="page-hero">
        <div class="container">
            <p class="breadcrumbs">
                <a href="/brands.php">Brands</a> &rsaquo; <?= $brandName ?>
            </p>
            <div class="brand-headline">
                <div>
                    <h1><?= $brandName ?></h1>
                    <p class="brand-meta"><?= $country ?> &middot; <?= count($brand['models']) ?> models indexed</p>
                </div>
            </div>
            <p class="lede" style="margin-top:18px">
                <?= $brandName ?> is a mobile manufacturer from <?= $country ?>.
                Have a specific device? Enter its IMEI on the <a href="/">checker</a>
                to confirm the exact variant.
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
                (TAC) of the IMEI. <a href="/">Run a check</a> and we'll resolve the
                exact name through our lookup provider.
            </p>
        </div>
    </section>
<?php layout_foot(); ?>
