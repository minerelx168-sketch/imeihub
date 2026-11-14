<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
layout_head('About · imeihub', 'About imeihub — a free tool for looking up the brand, model and specifications of any mobile phone by its IMEI number.');
?>
    <section class="page-hero">
        <div class="container">
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
                also offer premium checks for blacklist status, carrier lock,
                iCloud activation lock and warranty information.
            </p>

            <h2>Why it exists</h2>
            <p>
                The second-hand phone market is huge &mdash; and so is the
                market for stolen and blacklisted devices. A 15-second IMEI
                check before you hand over money can save you from buying a
                brick. We built imeihub so that this check takes one
                paste and one click.
            </p>

            <h2>How accurate is the data?</h2>
            <p>
                For brand and model lookups, accuracy is essentially 100%
                &mdash; the first eight digits of an IMEI are issued by the
                GSMA and tie directly to a specific certified device. For
                blacklist and carrier checks, accuracy depends on the partner
                database; we work with established providers but cannot
                guarantee real-time coverage of every carrier worldwide.
            </p>

            <h2>Privacy</h2>
            <p>
                We do not store personal information. IMEIs you look up are
                hashed and cached briefly so that repeat queries are fast and
                cheap to serve. See <a href="/privacy.php">our privacy
                policy</a> for details.
            </p>

            <p style="margin-top:32px">
                Questions or feedback? <a href="/contact.php">Get in touch</a>.
            </p>
        </div>
    </section>
<?php layout_foot(); ?>
