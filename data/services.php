<?php
declare(strict_types=1);

/**
 * Service landing pages.
 *
 * One entry = one /service-<slug> page. Each entry MUST set:
 *   - slug         URL key (lowercase, dash-separated)
 *   - code         service_prices.code (matches sql/seed.sql)
 *                  This is also the key into data/service_provider_map.php
 *   - name         heading rendered on the page (HTML allowed)
 *   - icon         icon name registered in includes/icons.php
 *   - tagline      short subtitle under the heading
 *   - description  longer body copy in the "About this check" section
 *   - free         true for the local IMEI_BASIC tier (no charge)
 *
 * We deliberately do NOT keep a separate slug -> code map here -
 * service.php just reads `code` straight off the matched entry.
 *
 * The full price catalog (~40 services) lives in sql/seed.sql; this
 * file is the curated short list we surface on the marketing nav.
 * All codes here must be active = 1 in service_prices.
 */

return [
    [
        'slug'        => 'free-imei-check',
        'code'        => 'IMEI_BASIC',
        'name'        => 'Free IMEI Check',
        'icon'        => 'info',
        'tagline'     => 'Brand, model and basic specs from any IMEI.',
        'description' =>
            'Identify any GSM mobile phone in under a second. We resolve the '
            . 'first 8 digits (TAC) against our local catalog and return the '
            . 'manufacturer, model and basic specs. No sign-in required and '
            . 'no credits are charged.',
        'free'        => true,
    ],
    [
        'slug'        => 'apple-basic',
        'code'        => 'APPLE_BASIC',
        'name'        => 'Apple Check Basic',
        'icon'        => 'phone',
        'tagline'     => 'iPhone / iPad model, colour and storage.',
        'description' =>
            'A targeted basic lookup for Apple devices. Returns the exact iPhone '
            . 'or iPad model, colour and storage tier. Works on every Apple '
            . 'device ever sold worldwide.',
        'free'        => false,
    ],
    [
        'slug'        => 'apple-icloud',
        'code'        => 'APPLE_ICLOUD_CLEAN',
        'name'        => 'iCloud Activation Lock (Clean / Lost)',
        'icon'        => 'cloud',
        'tagline'     => 'Find My iPhone status: Clean or Lost.',
        'description' =>
            'Returns the iCloud activation-lock status (Clean / Lost) as '
            . 'reported by Apple. A device flagged Lost has been reported '
            . 'missing and cannot be re-activated without the original Apple ID.',
        'free'        => false,
    ],
    [
        'slug'        => 'apple-icloud-status',
        'code'        => 'APPLE_ICLOUD_STATUS',
        'name'        => 'iCloud (ON / OFF)',
        'icon'        => 'cloud',
        'tagline'     => 'Quick check: is Find My iPhone enabled?',
        'description' =>
            'The cheapest iCloud check we offer. Just tells you whether Find My '
            . 'iPhone activation lock is ON or OFF, no Clean/Lost detail. Useful '
            . 'as a pre-purchase tripwire.',
        'free'        => false,
    ],
    [
        'slug'        => 'apple-warranty',
        'code'        => 'APPLE_WARRANTY',
        'name'        => 'Apple Warranty &amp; Activation',
        'icon'        => 'shield',
        'tagline'     => 'Activation date and remaining warranty coverage.',
        'description' =>
            'When was this iPhone first activated, and is it still under '
            . 'Apple\'s limited warranty? Activation Lock alone doesn\'t tell '
            . 'you that - this check does.',
        'free'        => false,
    ],
    [
        'slug'        => 'apple-sim-lock',
        'code'        => 'APPLE_SIM_LOCK',
        'name'        => 'Apple SIM-Lock Status',
        'icon'        => 'specs',
        'tagline'     => 'Quick check: is the SIM slot carrier-locked?',
        'description' =>
            'A lightweight check that returns just the SIM-lock status. Useful '
            . 'as a fast pre-purchase verification when you don\'t need the '
            . 'full carrier report.',
        'free'        => false,
    ],
    [
        'slug'        => 'apple-mdm',
        'code'        => 'APPLE_MDM',
        'name'        => 'Apple MDM (ON / OFF)',
        'icon'        => 'shield',
        'tagline'     => 'Is the device enrolled in Mobile Device Management?',
        'description' =>
            'Reveals whether the device is enrolled in an MDM (Mobile Device '
            . 'Management) profile - common on corporate / school devices. '
            . 'MDM-enrolled devices can be remotely wiped or restricted.',
        'free'        => false,
    ],
    [
        'slug'        => 'apple-full-gsx',
        'code'        => 'APPLE_FULL_GSX',
        'name'        => 'Apple Full GSX Report',
        'icon'        => 'report',
        'tagline'     => 'The full GSX dataset Apple gives to service providers.',
        'description' =>
            'The full GSX dataset Apple gives to authorised service providers. '
            . 'Includes original retailer, case + repair history, replacement '
            . 'flags, activation policy, ICCID and MAC address. Most '
            . 'comprehensive Apple check we offer.',
        'free'        => false,
    ],
    [
        'slug'        => 'blacklist',
        'code'        => 'BLACKLIST_FULL',
        'name'        => 'WorldWide Blacklist',
        'icon'        => 'ban',
        'tagline'     => 'Lost / stolen status across global carriers.',
        'description' =>
            'Cross-checks the IMEI against worldwide carrier blacklists. A '
            . 'blacklisted device usually cannot make calls or use data on '
            . 'the carrier that reported it, and resale is restricted in '
            . 'many countries.',
        'free'        => false,
    ],
    [
        'slug'        => 'samsung-info',
        'code'        => 'SAMSUNG_INFO',
        'name'        => 'Samsung Info + Knox Guard',
        'icon'        => 'phone',
        'tagline'     => 'Galaxy model, warranty, carrier &amp; Knox Guard status.',
        'description' =>
            'Combined Samsung Galaxy report: exact model, warranty, original '
            . 'carrier, country of purchase, and Knox Guard (the Samsung '
            . 'equivalent of iCloud lock) ON/OFF status.',
        'free'        => false,
    ],
    [
        'slug'        => 'huawei-info',
        'code'        => 'HUAWEI_INFO',
        'name'        => 'Huawei Info',
        'icon'        => 'phone',
        'tagline'     => 'Huawei brand, model, colour and base specs.',
        'description' =>
            'Identifies any Huawei device by IMEI: model, warranty and country '
            . 'of origin. Covers P, Mate, Nova and Y series.',
        'free'        => false,
    ],
    [
        'slug'        => 'xiaomi-status',
        'code'        => 'XIAOMI_STATUS',
        'name'        => 'Xiaomi Info + Mi ID',
        'icon'        => 'cloud',
        'tagline'     => 'Model, warranty, country and Mi Account lock status.',
        'description' =>
            'Returns the Xiaomi / Redmi / POCO model, warranty, country of '
            . 'origin, plus the Mi Account / Find Device status (ON / OFF). '
            . 'A locked device cannot be reset without the original Mi '
            . 'credentials.',
        'free'        => false,
    ],
    [
        'slug'        => 'pixel-info',
        'code'        => 'PIXEL_INFO',
        'name'        => 'Google Pixel Info',
        'icon'        => 'phone',
        'tagline'     => 'Pixel model, warranty and country of origin.',
        'description' =>
            'Identifies any Google Pixel device by IMEI: model, warranty and '
            . 'country of purchase. Covers Pixel 1 through the latest releases.',
        'free'        => false,
    ],
];
