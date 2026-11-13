<?php
declare(strict_types=1);

/**
 * Offline demo / simulator provider.
 *
 * Returns realistic-looking IMEI lookup data based on the TAC (first 8
 * digits of the IMEI). Used when IMEI_API_PROVIDER=demo, so the site is
 * fully usable for previews and screenshots without burning external
 * API credits or needing internet access.
 *
 * Falls back to a generic "GSM Phone" record for unknown TACs.
 */

function imei_demo_lookup(string $imei, ?string $service = null): array
{
    $tac = substr($imei, 0, 8);
    $db  = imei_demo_tac_db();

    if (isset($db[$tac])) {
        $info = $db[$tac];
    } else {
        // Plausible-looking placeholder so unknown IMEIs still feel like a real result.
        $info = [
            'brand'   => 'Unknown',
            'model'   => 'GSM Phone',
            'release' => '—',
            'os'      => '—',
        ];
    }

    $svc = (string) $service;
    $details = imei_demo_details($imei, $info, $svc);

    return [
        'status' => 'success',
        'brand'  => $info['brand'],
        'model'  => $info['model'],
        'details'=> $details,
        'raw'    => json_encode(['demo' => true, 'tac' => $tac, 'info' => $info]),
        'error'  => null,
        'url'    => '(demo mode - no external API call)',
    ];
}

function imei_demo_tac_db(): array
{
    return [
        // Apple
        '35915206' => ['brand' => 'Apple', 'model' => 'iPhone 15 Pro Max', 'release' => '2023', 'os' => 'iOS 17',
                       'color' => 'Natural Titanium', 'storage' => '256 GB', 'model_no' => 'A2849'],
        '35373708' => ['brand' => 'Apple', 'model' => 'iPhone 14 Pro',      'release' => '2022', 'os' => 'iOS 16',
                       'color' => 'Deep Purple',      'storage' => '128 GB', 'model_no' => 'A2890'],
        '35690103' => ['brand' => 'Apple', 'model' => 'iPhone 13',          'release' => '2021', 'os' => 'iOS 15',
                       'color' => 'Midnight',         'storage' => '128 GB', 'model_no' => 'A2633'],
        '35328211' => ['brand' => 'Apple', 'model' => 'iPhone 12',          'release' => '2020', 'os' => 'iOS 14',
                       'color' => 'Pacific Blue',     'storage' => '64 GB',  'model_no' => 'A2403'],
        // Samsung
        '35316110' => ['brand' => 'Samsung', 'model' => 'Galaxy S24 Ultra', 'release' => '2024', 'os' => 'Android 14',
                       'color' => 'Titanium Black',   'storage' => '256 GB', 'model_no' => 'SM-S928B'],
        '35692611' => ['brand' => 'Samsung', 'model' => 'Galaxy S23',       'release' => '2023', 'os' => 'Android 13',
                       'color' => 'Phantom Black',    'storage' => '128 GB', 'model_no' => 'SM-S911B'],
        '35276011' => ['brand' => 'Samsung', 'model' => 'Galaxy A54 5G',    'release' => '2023', 'os' => 'Android 13',
                       'color' => 'Awesome Lime',     'storage' => '128 GB', 'model_no' => 'SM-A546B'],
        // Xiaomi
        '86891306' => ['brand' => 'Xiaomi', 'model' => 'Redmi Note 13 Pro', 'release' => '2024', 'os' => 'Android 13',
                       'color' => 'Midnight Black',   'storage' => '256 GB', 'model_no' => '23090RA98G'],
        // Google
        '35840911' => ['brand' => 'Google', 'model' => 'Pixel 8 Pro',       'release' => '2023', 'os' => 'Android 14',
                       'color' => 'Obsidian',         'storage' => '256 GB', 'model_no' => 'GE9DP'],
        // OnePlus
        '86432105' => ['brand' => 'OnePlus','model' => '12',                'release' => '2024', 'os' => 'Android 14',
                       'color' => 'Flowy Emerald',    'storage' => '256 GB', 'model_no' => 'CPH2581'],
    ];
}

function imei_demo_details(string $imei, array $info, string $service): array
{
    $details = [
        'Brand Name'    => $info['brand'],
        'Model Name'    => $info['model'],
        'Model Number'  => $info['model_no'] ?? '—',
        'IMEI'          => $imei,
        'TAC'           => substr($imei, 0, 8),
        'Serial Number' => substr($imei, 8, 6),
    ];

    if (!empty($info['release'])) $details['Release Year'] = $info['release'];
    if (!empty($info['os']))      $details['Operating System'] = $info['os'];
    if (!empty($info['color']))   $details['Color']   = $info['color'];
    if (!empty($info['storage'])) $details['Storage'] = $info['storage'];

    // Apple full-info style fields (demo). Statuses are pseudo-derived from
    // the IMEI so different inputs show a mix of green/red pills, the way a
    // real provider response would. Maps to status pills in renderResult().
    if (($info['brand'] ?? '') === 'Apple') {
        $seed = (int) substr($imei, -4);
        $details['Network']                     = 'Global';
        $details['IMEI2 Number']                = substr($imei, 0, 14) . (($seed + 7) % 10);
        $details['MEID Number']                 = substr($imei, 0, 14);
        $details['Estimated Purchase Date']     = date('j M Y', strtotime('-' . (2 + $seed % 3) . ' years'));
        $details['Warranty Status']             = 'Out Of Warranty';
        $details['Telephone Technical Support'] = 'Expired';
        $details['Repairs and Service Coverage'] = 'Expired';
        $details['Replaced by Apple']           = 'No';
        $details['Find My iPhone']              = ($seed % 2) ? 'On' : 'Off';
        $details['US Block Status']             = ($seed % 5 === 0) ? 'Blacklisted' : 'Clean';
        $details['SIM-Lock Status']             = ($seed % 3 === 0) ? 'Locked' : 'Unlocked';
    }

    // Per-service additions, so each service page shows different fields.
    switch ($service) {
        case '1': // Blacklist
            $details['Blacklist Status']  = 'Clean';
            $details['Last Reported']     = 'Never';
            $details['Carrier Reported']  = '—';
            break;
        case '2': // Carrier
            $details['Network']      = 'AT&T (Unlocked)';
            $details['SIM Lock']     = 'Unlocked';
            $details['Country']      = 'United States';
            $details['Carrier ID']   = '00001';
            break;
        case '3': // iCloud
            $details['iCloud Status']     = 'OFF (Clean)';
            $details['Find My iPhone']    = 'Disabled';
            $details['Activation Status'] = 'Activated';
            break;
        case '4': // Warranty
            $details['Warranty Status']  = 'Active';
            $details['Activation Date']  = date('Y-m-d', strtotime('-180 days'));
            $details['Warranty Until']   = date('Y-m-d', strtotime('+185 days'));
            $details['Coverage']         = 'Limited Warranty';
            break;
        case '5': // Full specs
            $details['Chipset']       = $info['brand'] === 'Apple' ? 'Apple A17 Pro' : 'Qualcomm Snapdragon 8 Gen 3';
            $details['RAM']           = '8 GB';
            $details['Battery']       = '4422 mAh';
            $details['Display']       = '6.7" OLED, 120 Hz';
            $details['Camera (main)'] = '48 MP';
            break;
    }

    return $details;
}
