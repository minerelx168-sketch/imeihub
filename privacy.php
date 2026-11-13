<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
layout_head('Privacy policy · imeihub', 'How imeihub handles IMEI lookups, caching and personal data.');
?>
    <section class="page-hero">
        <div class="container">
            <h1>Privacy policy</h1>
            <p class="lede">Last updated <?= date('F j, Y', strtotime('-30 days')) ?></p>
        </div>
    </section>

    <section class="prose">
        <div class="container container--prose">
            <h2>What we collect</h2>
            <ul>
                <li>The IMEI you submit, and the result returned by our lookup provider.</li>
                <li>Your IP address &mdash; only to enforce rate limits.</li>
                <li>Standard webserver logs (request path, status code, user agent).</li>
            </ul>

            <h2>What we do not collect</h2>
            <ul>
                <li>Names, emails, or phone numbers (unless you contact us).</li>
                <li>Location data, beyond what is implied by your IP address.</li>
                <li>Cookies for tracking or advertising.</li>
            </ul>

            <h2>Caching</h2>
            <p>
                IMEIs you look up are cached for up to 24 hours so that repeat
                queries are fast and cheap. After that window the cache entry
                is overwritten or evicted.
            </p>

            <h2>Third-party providers</h2>
            <p>
                IMEI lookups are powered by external partners (e.g.
                <a href="https://imei.info">imei.info</a>). Your submitted IMEI
                is forwarded to the partner only when no cached result is
                available. We do not send your IP address or browser
                information to the partner.
            </p>

            <h2>Contact</h2>
            <p>
                Privacy questions? Reach us via the <a href="/contact.php">contact
                form</a>.
            </p>
        </div>
    </section>
<?php layout_foot(); ?>
