<?php
declare(strict_types=1);

/**
 * Inline SVG icon library. Use icon('name') anywhere in a template.
 * All icons are 1.5-stroke lucide-style, sit on currentColor, and
 * accept an optional width/class so the caller controls sizing.
 */

if (!function_exists('icon')) {
function icon(string $name, int $size = 22, string $class = ''): string
{
    $icons = [
        // App / logo
        'logo' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true">'
                . '<defs><linearGradient id="lg" x1="0" y1="0" x2="1" y2="1">'
                . '<stop offset="0" stop-color="#4f46e5"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient></defs>'
                . '<rect x="2" y="2" width="28" height="28" rx="8" fill="url(#lg)"/>'
                . '<path d="M16 9.5v2.2M11 13.5h10M11 18h10M11 22.5h10" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
                . '<circle cx="16" cy="22.5" r="0" fill="#fff"/></svg>',

        // Generic
        'check'      => '<path d="M5 12.5l4.5 4.5L19 7"/>',
        'arrow-right'=> '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'search'     => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'phone'      => '<rect x="6" y="2.5" width="12" height="19" rx="2.5"/><circle cx="12" cy="18" r=".8" fill="currentColor" stroke="none"/>',

        // Services
        'info'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8.2v.1"/>',
        'ban'        => '<circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/>',
        'signal'     => '<path d="M3 16.5L21 16.5"/><path d="M5.5 16.5v-3M9 16.5v-5M12.5 16.5v-7M16 16.5v-9M19.5 16.5v-11"/>',
        'cloud'      => '<path d="M7 18.5h10.5a4 4 0 0 0 .5-7.95A6 6 0 0 0 6.5 9.6 4 4 0 0 0 7 18.5z"/>',
        'shield'     => '<path d="M12 3l8 3v5.5c0 4.6-3.4 8.6-8 9.5-4.6-.9-8-4.9-8-9.5V6l8-3z"/>'
                       . '<path d="M9 12.2l2.2 2.2L15.5 10"/>',
        'specs'      => '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M8 9h8M8 13h8M8 17h5"/>',

        // How it works
        'magnifier'  => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'paste'      => '<rect x="6" y="5" width="12" height="15" rx="2"/><rect x="9" y="3" width="6" height="4" rx="1.2" fill="currentColor" stroke="none"/>',
        'report'     => '<path d="M5 4h11l3 3v13H5z"/><path d="M9 11h6M9 15h6M9 7h3"/>',

        // Footer / external
        'mail'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    ];

    if ($name === 'logo') {
        $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        return preg_replace('/<svg /', '<svg width="' . $size . '" height="' . $size . '"' . $cls . ' ', $icons['logo'], 1);
    }

    if (!isset($icons[$name])) return '';
    $cls = ' icon' . ($class !== '' ? ' ' . $class : '');
    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 24 24" '
        . 'fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" '
        . 'class="%s" aria-hidden="true">%s</svg>',
        $size, $size, htmlspecialchars(trim($cls), ENT_QUOTES), $icons[$name]
    );
}
}

if (!function_exists('brand_initial')) {
function brand_initial(string $name): string
{
    // First non-space character, uppercased. Handles "OPPO", "vivo", "OnePlus", etc.
    $n = ltrim($name);
    return mb_strtoupper(mb_substr($n, 0, 1, 'UTF-8'), 'UTF-8');
}
}
