-- Seed: service price catalog (USD)
--
-- ANCHORED on the upstream provider's live IMEI Check catalog
-- (60 services total: 43 ACTIVE + 17 PENDING activation). One row per
-- upstream service; the API name is copied VERBATIM into the `name`
-- column so the dropdown matches what the provider shows. Internal
-- `code` is our own opaque ID.
--
-- Pricing rule (active rows):
--   - If the operator's selling sheet (selling_price.xlsx) sets a price
--     for the matching service, use it directly (trailing -- sheet ...
--     comment marks these).
--   - Otherwise default to max(0.05, wholesale * 2) rounded to $0.01.
--     Comment shows the wholesale credit so you can re-derive at any
--     time with a different markup.
--
-- Pricing rule (pending rows):
--   - cost = 0.00 placeholder, active = 0 (hidden from dropdowns).
--   - Operator must (a) get the upstream Service ID, (b) decide
--     wholesale + retail, then update both this row's cost / active
--     AND data/service_provider_map.php's matching entry before the
--     service can be sold.
--
-- IMEI_BASIC stays at $0.00 / null provider for the always-free local
-- TAC lookup. The phantom codes that lived here pre-anchor (Apple
-- Mini / Pro / Ultimate / Universal Check / Apple iCloud S2 / Apple
-- GSMA Blacklist x2 / Itel / Acer / LG / Oppo / OnePlus / Kyocera)
-- have been removed - the upstream provider does not carry them.
-- ZTE was previously deactivated as phantom but is brought back below
-- as a pending row (the provider does carry it).
--
-- Idempotent: ON DUPLICATE KEY UPDATE - safe to re-run.

USE `imei_checker`;

INSERT INTO `service_prices` (`code`, `name`, `description`, `cost`, `active`) VALUES
    -- Free local TAC lookup (no provider call).
    ('IMEI_BASIC',                'Free IMEI Check',                                                                                'Brand, model and basic specs - resolved locally from the TAC.',                  0.00, 1),

    -- ----- Apple Featured -----
    ('APPLE_BASIC',               'Apple Basic Info',                                                                               'Apple basic info from upstream.',                                                0.10, 1), -- wholesale 0.03 - sheet "Apple Check Basic"
    ('APPLE_CARRIER_LITE',        'Apple Carrier (Lite)',                                                                           'Original carrier - lite tier.',                                                  0.08, 1), -- wholesale 0.04 (×2)
    ('APPLE_CARRIER_PRO',         'Apple Carrier (Pro)',                                                                            'Original carrier + extra detail.',                                               0.16, 1), -- wholesale 0.08 (×2)
    ('APPLE_CARRIER_PRO_PLUS',    'Apple Carrier (Pro Plus)',                                                                       'Carrier report with deepest detail.',                                            0.26, 1), -- wholesale 0.13 (×2)
    ('APPLE_MAX_INFO',            'Apple Max Info (Premium)',                                                                       'Premium combined report.',                                                       0.70, 1), -- wholesale 0.35 (×2)

    -- ----- Apple iCloud -----
    ('APPLE_ICLOUD_STATUS',       'Apple iCloud (ON / OFF)',                                                                        'Find My iPhone activation lock status.',                                         0.05, 1), -- wholesale 0.008 - min $0.05
    ('APPLE_ICLOUD_CLEAN',        'Apple iCloud (Clean / Lost)',                                                                    'iCloud Clean / Lost status.',                                                    0.05, 1), -- wholesale 0.02 - min $0.05
    ('APPLE_ICLOUD_CLEAN_SN',     'Apple iCloud (Clean / Lost) SN',                                                                 'Clean / Lost status by serial number.',                                          0.10, 1), -- wholesale 0.05 (×2)
    ('APPLE_ICLOUD_ID_HINT',      'Apple iCloud ID Hint',                                                                           'Masked Apple ID the device is signed in to.',                                    0.80, 1), -- wholesale 0.40 (×2)
    ('APPLE_MAC_ICLOUD_STATUS',   'Apple iCloud MACBOOK/iMAC (ON / OFF)',                                                           'Find My status for MacBook / iMac.',                                             0.30, 1), -- wholesale 0.10 - sheet
    ('APPLE_MAC_ICLOUD_CLEAN',    'Apple iCloud MACBOOK/iMAC (Clean / Lost)',                                                       'Lost-mode status for MacBook / iMac.',                                           0.40, 1), -- wholesale 0.20 - sheet

    -- ----- Apple MDM -----
    ('APPLE_MDM',                 'Apple MDM (ON / OFF)',                                                                           'MDM enrollment status.',                                                         0.35, 1), -- wholesale 0.15 - sheet
    ('APPLE_MDM_SN',              'Apple MDM (ON / OFF) SN',                                                                        'MDM status by serial number.',                                                   0.30, 1), -- wholesale 0.15 (×2)
    ('APPLE_MDM_FMI',             'Apple MDM + FMI (ON / OFF)',                                                                     'Combined MDM + Find My iPhone status.',                                          0.50, 1), -- wholesale 0.25 (×2)

    -- ----- Apple Device Info -----
    ('APPLE_WARRANTY',            'Apple Warranty (Activation Info)',                                                               'Activation date and remaining warranty.',                                        0.04, 1), -- wholesale 0.02 - sheet
    ('APPLE_WARRANTY_SN',         'Apple Warranty (Activation Info) SN',                                                            'Warranty / activation by serial number.',                                        0.05, 1), -- wholesale 0.008 (×2 capped at min 0.05)
    ('APPLE_PART_NUMBER',         'Apple Part Number / MPN',                                                                        'Apple Part Number / MPN.',                                                       0.14, 1), -- wholesale 0.07 (×2)
    ('APPLE_SIM_LOCK',            'Apple SIM-LOCK Status',                                                                          'SIM-lock status.',                                                               0.05, 1), -- wholesale 0.018 (×2 capped at min 0.05)
    ('APPLE_GSX_TETHER',          'Apple GSX Next Tether Policy',                                                                   'Apple GSX next tether policy.',                                                  0.20, 1), -- wholesale 0.10 (×2)

    -- ----- Apple GSX (Premium) -----
    ('APPLE_CASE_REPAIR_HISTORY', 'Apple Case History, Replacement',                                                              'Apple case and repair history from GSX.',                                        1.10, 1), -- wholesale 0.55 (×2) - API ID 201
    ('APPLE_SOLD_BY_INFO',        'Apple Sold By Info',                                                                             'Original retailer info from GSX.',                                               1.10, 1), -- wholesale 0.55 (×2) - API ID 928
    ('APPLE_SOLD_BY_COVERAGE',    'Apple Sold By, Coverage (Max Info)',                                                             'Original retailer + coverage status.',                                           2.90, 1), -- wholesale 1.20 (×2.4)
    ('APPLE_SOLD_BY_HISTORY',     'Apple Sold By, Case History, Activation Policy',                                                 'Sold-by + case history + activation policy.',                                    4.20, 1), -- wholesale 2.10 (×2)
    ('APPLE_GSX_LIGHT',           'Apple Sold By, Case History, Replacement, GSX Activation Policy',                                'Sold-by + case + replacement + activation policy.',                              1.80, 1), -- wholesale 0.75 (×2.4)
    ('APPLE_FULL_GSX',            'Apple Sold By, Case History, Replacement, Activation Policy [ICCID & MAC] (Full GSX)',           'Full GSX dataset including ICCID & MAC.',                                        3.50, 1), -- wholesale 1.45 (×2.4)
    ('APPLE_GSX_MAX',             'Apple Sold By, Case History, Replacement, Repair, GSX Activation Policy (Max Info)',             'GSX max-info: sold-by + case + replacement + repair + activation policy.',       2.60, 1), -- wholesale 1.30 (×2)

    -- ----- Worldwide Blacklist -----
    ('BLACKLIST_SIMPLE',          'WorldWide Blacklist Status (SIMPLE INFO)',                                                       'Quick yes/no GSMA blacklist status.',                                            0.05, 1), -- wholesale 0.008 (×2 capped at min 0.05) - sheet "GSMA Blacklist Status" set 0.01 below provider; using min
    ('BLACKLIST_FULL',            'WorldWide Blacklist Status (FULL INFO)',                                                         'Full GSMA blacklist report with reporting carrier and date.',                    0.10, 1), -- wholesale 0.04 - sheet "GSMA Blacklist Full Report"

    -- ----- Other Brands -----
    ('PIXEL_INFO',                'Google Pixel Info',                                                                              'Google Pixel: model + warranty + country.',                                      0.20, 1), -- wholesale 0.10 (×2)
    ('XIAOMI_STATUS',             'XIAOMI (ON / OFF)',                                                                              'Xiaomi: Mi Account / Find Device lock.',                                         0.10, 1), -- wholesale 0.01 - sheet
    ('HUAWEI_INFO',               'HUAWEI INFO',                                                                                    'Huawei: model + warranty + country.',                                            0.08, 1), -- wholesale 0.04 (×2)
    ('HONOR_INFO',                'HONOR INFO',                                                                                     'Honor: model + warranty + country.',                                             0.10, 1), -- wholesale 0.04 - sheet
    ('SAMSUNG_INFO',              'SAMSUNG INFO',                                                                                   'Samsung: model + warranty.',                                                     0.10, 1), -- wholesale 0.03 - sheet
    ('SAMSUNG_KNOX',              'SAMSUNG (Knox Guard Status, Samsung Lock - ON/OFF)',                                             'Samsung Knox Guard / Samsung Lock status.',                                      0.20, 1), -- wholesale 0.10 (×2)
    ('MOTOROLA_INFO',             'MOTOROLA INFO',                                                                                  'Motorola: model + warranty.',                                                    0.10, 1), -- wholesale 0.03 - sheet
    ('LENOVO_INFO',               'LENOVO INFO',                                                                                    'Lenovo: model + warranty.',                                                      0.10, 1), -- wholesale 0.04 - sheet

    -- ----- US Carriers -----
    ('TMOBILE_USA',               'T-Mobile - USA iPhone/Generic Status',                                                           'T-Mobile USA IMEI status.',                                                      0.10, 1), -- wholesale 0.01 - sheet
    ('TMOBILE_USA_PRO',           'T-Mobile - USA iPhone/Generic Status [PRO]',                                                     'T-Mobile USA status (Pro tier).',                                                0.06, 1), -- wholesale 0.03 (×2)
    ('VERIZON_USA_PRO',           'Verizon - USA iPhone/Generic Status [PRO]',                                                      'Verizon USA status (Pro tier).',                                                 0.06, 1), -- wholesale 0.03 (×2)

    -- ----- Phone number lookup -----
    ('YANDEX_ALICE',              'Yandex Alice Info',                                                                              'Phone owner info from Yandex Alice.',                                            0.60, 1), -- wholesale 0.30 (×2)
    ('HLR_LOOKUP',                'Home Location Register (HLR) Lookup',                                                            'HLR lookup for a phone number.',                                                 0.05, 1), -- wholesale 0.01 (×2 capped at min 0.05)
    ('NUMBER_TYPE',               'Number Type (NT) Lookup',                                                                        'Mobile / landline / VoIP classification.',                                       0.14, 1), -- wholesale 0.07 (×2)
    ('PING_SMS',                  'Ping-SMS',                                                                                       'Silent SMS ping.',                                                               0.20, 1), -- wholesale 0.10 (×2)
    ('PING_SMS_S2',               'Ping-SMS (Server 2)',                                                                            'Silent SMS ping via secondary route.',                                           0.70, 1), -- wholesale 0.35 (×2)

    -- ----- PENDING activation (awaiting provider Service ID + wholesale) -----
    -- All 17 rows below: active = 0, cost = 0.00 placeholder. They are
    -- anchored from a full catalog dump but the provider Service IDs
    -- haven't been wired yet, so they're hidden from the dropdown
    -- (check.php WHERE active=1) and credits_deduct() will throw
    -- "Unknown or inactive service" if anyone POSTs them directly.
    --
    -- To activate one: edit cost/active here AND add the matching ID
    -- in data/service_provider_map.php, then re-run this seed.

    -- Apple IMEI Check (2 pending)
    ('APPLE_OWNER_ID_INFO',       'Apple Owner ID Info - (Read Description)',                                                       'Apple Owner ID info lookup.',                                                    0.00, 0),
    ('APPLE_IMEI_SN_CONVERT',     'Apple IMEI <-> SN <-> IMEI2 CONVERT',                                                            'Convert between Apple IMEI / Serial Number / IMEI2.',                            0.00, 0),

    -- GSX Services (Instant) (2 pending)
    ('APPLE_GSX_CASE_REPLACE',    'Apple Case History, Replacement',                                                                'GSX case history + replacement details (instant).',                              0.00, 0),
    ('APPLE_GSX_SOLD_BY',         'Apple Sold By Info',                                                                             'GSX sold-by retailer info (instant).',                                           0.00, 0),

    -- GSX Services (server 2 / non-instant) (6 pending)
    ('APPLE_GSX_CASE_S2',         'Apple Case History',                                                                             'GSX case history (server 2).',                                                   0.00, 0),
    ('APPLE_GSX_REPAIRS_S2',      'Apple Repairs, Replacement Details',                                                             'GSX repair + replacement details (server 2).',                                   0.00, 0),
    ('APPLE_GSX_CASE_DIAG_S2',    'Apple Case History, Repairs, Replacements, Diagnostics',                                         'GSX case + repair + replacement + diagnostics (server 2).',                      0.00, 0),
    ('APPLE_GSX_SOLD_POLICY_S2',  'Apple Sold By, Activation Policy',                                                               'GSX sold-by + activation policy (server 2).',                                    0.00, 0),
    ('APPLE_GSX_FULL_LITE_S2',    'Apple Sold By, Case History, Replacement, Activation Policy',                                    'GSX sold-by + case + replacement + activation policy (server 2).',               0.00, 0),
    ('APPLE_FULL_GSX_S2',         'Apple Sold By, Case History, Replacement, Activation Policy [ICCID & MAC] (FULL GSX) S2',        'Full GSX dataset including ICCID & MAC (server 2).',                             0.00, 0),

    -- GSX Services (Picture) (5 pending)
    ('APPLE_GSX_CASE_PIC',        'Apple Case History (Picture)',                                                                   'GSX case history report with picture.',                                          0.00, 0),
    ('APPLE_GSX_CASE_REPAIR_PIC', 'Apple Case History, Repairs, Replacements (Picture)',                                            'GSX case + repair + replacement report with picture.',                           0.00, 0),
    ('APPLE_GSX_SOLD_REPLACE_PIC','Apple Sold By, Case History, Replacement (Picture)',                                             'GSX sold-by + case + replacement report with picture.',                          0.00, 0),
    ('APPLE_GSX_SOLD_DIAG_PIC',   'Apple Sold By, Case History, Repairs, Replacement, Diagnostics (Picture)',                       'GSX sold-by + case + repair + replacement + diagnostics with picture.',          0.00, 0),
    ('APPLE_FULL_GSX_PIC',        'Apple Full GSX (Picture)',                                                                       'Full GSX report with picture.',                                                  0.00, 0),

    -- Other IMEI Check (2 pending)
    ('ZTE_INFO',                  'ZTE INFO',                                                                                       'ZTE: model + warranty + country.',                                               0.00, 0),
    ('SAMSUNG_INFO_S2',           'SAMSUNG INFO (Server 2)',                                                                        'Samsung: model + warranty (server 2).',                                          0.00, 0)
ON DUPLICATE KEY UPDATE
    `name`        = VALUES(`name`),
    `description` = VALUES(`description`),
    `cost`        = VALUES(`cost`),
    `active`      = VALUES(`active`);

-- Hard-deactivate any phantom codes that lived here pre-anchor.
-- (Rows stay so historical service_usages refs remain valid.)
-- ZTE_INFO was previously in this list but is now a legit pending row
-- above (provider catalog confirms it exists), so it's been removed.
UPDATE `service_prices` SET `active` = 0 WHERE `code` IN (
    'APPLE_MINI','APPLE_PRO','APPLE_ULTIMATE','UNIVERSAL_CHECK',
    'APPLE_ICLOUD_CLEAN_S2','APPLE_BLACKLIST_SIMPLE','APPLE_BLACKLIST_FULL',
    'ITEL_INFO','ACER_INFO','LG_INFO','OPPO_INFO','ONEPLUS_INFO',
    'KYOCERA_INFO'
);
