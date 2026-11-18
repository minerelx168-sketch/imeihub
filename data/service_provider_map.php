<?php
declare(strict_types=1);

/**
 * service_code  ->  upstream provider service descriptor
 *
 * Entry shapes:
 *   'CODE' => null            free local TAC lookup, no provider call
 *   'CODE' => 123             provider service id, PHP API (sync, <60s)
 *   'CODE' => ['id' => 123, 'type' => 'dhru']
 *                             provider service id, DHRU API (async, 1-5min)
 *                             credits_deduct() -> place order -> PROCESSING
 *                             A poll loop drives PROCESSING -> SUCCESS/FAILED.
 *
 * Use the DHRU shape for any service the provider documents as taking
 * more than ~60s (GSX Picture, manual fulfillment, etc.). The PHP API
 * does NOT serve those - calls return an "execution time exceeded"
 * error and the user's credit ends up refunded for nothing.
 *
 * 1:1 anchor on the upstream API catalog (60 IMEI Check services:
 * 43 ACTIVE wired below + 17 PENDING listed in seed.sql with active=0).
 * Source synced from the provider's imeiservicelist endpoint - see
 * scripts/fetch-provider-services.php to re-fetch.
 *
 * Codes that don't appear in this map will cause credits_deduct() to
 * throw "Unknown service code". That's fine for the 17 pending rows -
 * they're also active=0 in seed.sql so check.php's dropdown query
 * (WHERE active=1) hides them, and a direct POST is rejected before
 * any wallet mutation.
 *
 * To activate a pending row:
 *   1. Get the upstream Service ID from provider support, or run
 *      scripts/sync-pending-service-ids.php to auto-resolve IDs.
 *   2. Uncomment the matching line in the PENDING block below.
 *      Use the DHRU shape for any service flagged as 1-5 min / manual.
 *   3. In sql/seed.sql flip the row's active=0 -> 1 and set cost to
 *      max(0.05, wholesale * 2) (or the operator's selling-sheet
 *      price); re-run the seed.
 *
 * The phantom codes that used to live here (APPLE_MINI / APPLE_PRO /
 * APPLE_ULTIMATE / UNIVERSAL_CHECK / APPLE_ICLOUD_CLEAN_S2 /
 * APPLE_BLACKLIST_* / ITEL_INFO / ACER_INFO / LG_INFO / OPPO_INFO /
 * ONEPLUS_INFO / KYOCERA_INFO) have been removed because the provider
 * does not carry them. If a future provider does, add new codes here
 * AND a matching seed.sql row.
 */

return [
    // Free local lookup
    'IMEI_BASIC'                  => null,

    // Apple Featured
    'APPLE_BASIC'                 => 214,
    'APPLE_CARRIER_LITE'          => 444,
    'APPLE_CARRIER_PRO'           => ['id' => 445, 'type' => 'dhru'],
    'APPLE_CARRIER_PRO_PLUS'      => 448,
    'APPLE_MAX_INFO'              => 976,

    // Apple iCloud
    'APPLE_ICLOUD_STATUS'         => 10,
    'APPLE_ICLOUD_CLEAN'          => 11,
    'APPLE_ICLOUD_CLEAN_SN'       => ['id' => 847, 'type' => 'dhru'],
    'APPLE_ICLOUD_ID_HINT'        => 176,
    'APPLE_MAC_ICLOUD_STATUS'     => 503,
    'APPLE_MAC_ICLOUD_CLEAN'      => ['id' => 574, 'type' => 'dhru'],

    // Apple MDM
    'APPLE_MDM'                   => 845,
    'APPLE_MDM_SN'                => 457,
    'APPLE_MDM_FMI'               => ['id' => 299, 'type' => 'dhru'],

    // Apple Device Info
    'APPLE_WARRANTY'              => 806,
    'APPLE_WARRANTY_SN'           => 343,
    'APPLE_PART_NUMBER'           => 504,
    'APPLE_SIM_LOCK'              => 215,
    'APPLE_GSX_TETHER'            => 945,

    // Apple GSX (Premium)
    'APPLE_CASE_REPAIR_HISTORY'   => 201,  // was 691, updated to match current API
    'APPLE_SOLD_BY_INFO'          => 928,
    'APPLE_SOLD_BY_COVERAGE'      => 623,
    'APPLE_SOLD_BY_HISTORY'       => 707,
    'APPLE_GSX_LIGHT'             => 979,
    'APPLE_FULL_GSX'              => 348,
    'APPLE_GSX_MAX'               => 981,

    // Worldwide Blacklist
    'BLACKLIST_SIMPLE'            => 419,
    'BLACKLIST_FULL'              => 66,

    // Other Brands
    'PIXEL_INFO'                  => 584,
    'XIAOMI_STATUS'               => 439,
    'HUAWEI_INFO'                 => 130,
    'HONOR_INFO'                  => 936,
    'SAMSUNG_INFO'                => 9,
    'SAMSUNG_KNOX'                => 851,
    'MOTOROLA_INFO'               => 132,
    'LENOVO_INFO'                 => 969,

    // US Carriers
    'TMOBILE_USA'                 => 688,
    'TMOBILE_USA_PRO'             => 127,
    'VERIZON_USA_PRO'             => 423,

    // Phone number lookup
    'YANDEX_ALICE'                => 932,
    'HLR_LOOKUP'                  => 616,
    'NUMBER_TYPE'                 => 617,
    'PING_SMS'                    => 618,
    'PING_SMS_S2'                 => 619,  // Now active in API catalog

    // ----- PENDING (awaiting Service ID from provider) -----
    // Uncomment each row when its real Service ID arrives, then flip
    // the matching seed.sql row's active to 1 and set its cost.
    // Run scripts/sync-pending-service-ids.php to auto-fill IDs.
    //
    // Use plain int form for PHP-API services (sync, <60s).
    // Use ['id' => N, 'type' => 'dhru'] for DHRU services (async, 1-5+ min) -
    // the provider documents GSX Picture as DHRU-only; long-running
    // GSX S2 variants are also safer routed via DHRU.
    //
    // Apple IMEI Check (PHP)
    // 'APPLE_OWNER_ID_INFO'      => 0,
    // 'APPLE_IMEI_SN_CONVERT'    => 0,
    //
    // GSX Services (Instant) (PHP)
    // 'APPLE_GSX_CASE_REPLACE'   => 0,
    // 'APPLE_GSX_SOLD_BY'        => 0,
    //
    // GSX Services (Server 2 / non-instant) (DHRU)
    // 'APPLE_GSX_CASE_S2'        => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_REPAIRS_S2'     => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_CASE_DIAG_S2'   => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_SOLD_POLICY_S2' => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_FULL_LITE_S2'   => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_FULL_GSX_S2'        => ['id' => 0, 'type' => 'dhru'],
    //
    // GSX Services (Picture) - DHRU only (manual fulfillment, >60s)
    // 'APPLE_GSX_CASE_PIC'         => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_CASE_REPAIR_PIC'  => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_SOLD_REPLACE_PIC' => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_GSX_SOLD_DIAG_PIC'    => ['id' => 0, 'type' => 'dhru'],
    // 'APPLE_FULL_GSX_PIC'         => ['id' => 0, 'type' => 'dhru'],
    //
    // Other IMEI Check
    // 'ZTE_INFO'                 => 0,
    // 'SAMSUNG_INFO_S2'          => 0,
];
