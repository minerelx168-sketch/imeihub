<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/icons.php';
$services = require __DIR__ . '/data/services.php';

layout_head(
    'IMEI lookup services · imeihub',
    'All IMEI lookup services — free phone info check, blacklist, carrier, iCloud activation lock, warranty status and more.',
    '<meta name="keywords" content="IMEI lookup services, IMEI check, blacklist check, iCloud check, carrier check, warranty check, phone verification, IMEI info">'
);
?>
    <section class="page-hero">
        <div class="container">
            <p class="hero-eyebrow">Service catalog</p>
            <h1>IMEI lookup services</h1>
            <p class="lede">
                Different checks return different information.
                Pick the one that matches what you need to know about a device.
            </p>
        </div>
    </section>

    <section class="services-list">
        <div class="container">
            <div class="service-grid">
                <?php foreach ($services as $s): ?>
                    <a class="service-card" href="/service.php?slug=<?= urlencode($s['slug']) ?>">
                        <div class="service-card-head">
                            <span class="service-icon"><?= icon((string) $s['icon'], 22) ?></span>
                            <?php if ($s['free']): ?>
                                <span class="pill pill-free">Free</span>
                            <?php else: ?>
                                <span class="pill pill-paid">Premium</span>
                            <?php endif; ?>
                        </div>
                        <h3><?= $s['name'] ?></h3>
                        <p><?= htmlspecialchars($s['tagline'], ENT_QUOTES, 'UTF-8') ?></p>
                        <span class="service-link">Run check <?= icon('arrow-right', 14) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php layout_foot(); ?>
