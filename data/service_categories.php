<?php
declare(strict_types=1);

/**
 * Groupings used by the unified dropdowns (homepage hero + /check page).
 *
 * One entry = one <optgroup>. Codes inside are looked up against
 * service_prices.cost at render time so prices stay in sync with the
 * source of truth. Inactive codes are silently skipped.
 *
 * Matches the unlock-service.net API catalog 1:1 (anchored 43 services
 * + IMEI_BASIC = 44 total). Reorder groups freely - the homepage
 * iterates this file in order.
 */

return [
    [
        'name'  => 'Free',
        'codes' => ['IMEI_BASIC'],
    ],
    [
        'name'  => 'Apple Featured',
        'codes' => [
            'APPLE_BASIC',
            'APPLE_CARRIER_LITE',
            'APPLE_CARRIER_PRO',
            'APPLE_CARRIER_PRO_PLUS',
            'APPLE_MAX_INFO',
        ],
    ],
    [
        'name'  => 'Apple iCloud',
        'codes' => [
            'APPLE_ICLOUD_STATUS',
            'APPLE_ICLOUD_CLEAN',
            'APPLE_ICLOUD_CLEAN_SN',
            'APPLE_ICLOUD_ID_HINT',
            'APPLE_MAC_ICLOUD_STATUS',
            'APPLE_MAC_ICLOUD_CLEAN',
        ],
    ],
    [
        'name'  => 'Apple MDM',
        'codes' => [
            'APPLE_MDM',
            'APPLE_MDM_SN',
            'APPLE_MDM_FMI',
        ],
    ],
    [
        'name'  => 'Apple Device Info',
        'codes' => [
            'APPLE_WARRANTY',
            'APPLE_WARRANTY_SN',
            'APPLE_PART_NUMBER',
            'APPLE_SIM_LOCK',
            'APPLE_GSX_TETHER',
        ],
    ],
    [
        'name'  => 'Apple GSX (Premium)',
        'codes' => [
            'APPLE_CASE_REPAIR_HISTORY',
            'APPLE_SOLD_BY_COVERAGE',
            'APPLE_SOLD_BY_HISTORY',
            'APPLE_GSX_LIGHT',
            'APPLE_FULL_GSX',
            'APPLE_GSX_MAX',
        ],
    ],
    [
        'name'  => 'Worldwide Blacklist',
        'codes' => ['BLACKLIST_SIMPLE', 'BLACKLIST_FULL'],
    ],
    [
        'name'  => 'Other Brands',
        'codes' => [
            'SAMSUNG_INFO',
            'SAMSUNG_KNOX',
            'XIAOMI_STATUS',
            'HUAWEI_INFO',
            'HONOR_INFO',
            'MOTOROLA_INFO',
            'LENOVO_INFO',
            'PIXEL_INFO',
        ],
    ],
    [
        'name'  => 'US Carriers',
        'codes' => ['TMOBILE_USA', 'TMOBILE_USA_PRO', 'VERIZON_USA_PRO'],
    ],
    [
        'name'  => 'Phone Number Lookup',
        'codes' => [
            'YANDEX_ALICE',
            'HLR_LOOKUP',
            'NUMBER_TYPE',
            'PING_SMS',
            'PING_SMS_S2',
        ],
    ],
];
