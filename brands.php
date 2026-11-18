<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/icons.php';
$brands = require __DIR__ . '/data/brands.php';

layout_head(
    'Phone brands · imeihub',
    'Browse mobile phone brands. Pick a brand to see common models and their IMEI/TAC info.',
    '<meta name="keywords" content="phone brands, IMEI check by brand, Apple IMEI, Samsung IMEI, Huawei IMEI, Xiaomi IMEI, phone models, TAC lookup">'
);
?>
    <section class="page-hero">
        <div class="container">
            <p class="hero-eyebrow">Brand directory</p>
            <h1>Mobile phone brands</h1>
            <p class="lede">
                We cover <?= count($brands) ?> major mobile phone manufacturers.
                Pick one to see the models we recognise, or jump straight to the
                <a href="/">IMEI checker</a>.
            </p>
        </div>
    </section>

    <section class="brands-list">
        <div class="container">
            <div class="brand-grid">
                <?php foreach ($brands as $b): ?>
                    <a class="brand-tile" href="/brand.php?slug=<?= urlencode($b['slug']) ?>">
                        <span class="brand-tile-name"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="brand-tile-meta"><?= count($b['models']) ?> models</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php layout_foot(); ?>
