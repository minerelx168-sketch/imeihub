<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');

// Always use HTTPS for sitemap URLs
$base = 'https://imeihub.net';

$brands   = require __DIR__ . '/data/brands.php';
$services = require __DIR__ . '/data/services.php';
$articles = require __DIR__ . '/data/articles.php';
$siteMod  = '2026-11-22';

$urls = [
    ['loc' => $base . '/',              'priority' => '1.0', 'changefreq' => 'daily',   'lastmod' => $siteMod],
    ['loc' => $base . '/services.php',  'priority' => '0.9', 'changefreq' => 'weekly',  'lastmod' => $siteMod],
    ['loc' => $base . '/brands.php',    'priority' => '0.8', 'changefreq' => 'weekly',  'lastmod' => $siteMod],
    ['loc' => $base . '/articles.php',    'priority' => '0.8', 'changefreq' => 'weekly',  'lastmod' => $siteMod],
    ['loc' => $base . '/vs-ifreeicloud',  'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/vs-imei-info',    'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/api-docs',        'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/widget',          'priority' => '0.8', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/refund-policy',   'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/about.php',     'priority' => '0.5', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/contact.php',   'priority' => '0.5', 'changefreq' => 'monthly', 'lastmod' => $siteMod],
    ['loc' => $base . '/privacy.php',   'priority' => '0.3', 'changefreq' => 'yearly',  'lastmod' => $siteMod],
];
foreach ($services as $s) {
    $urls[] = [
        'loc'        => $base . '/service.php?slug=' . urlencode($s['slug']),
        'priority'   => '0.8',
        'changefreq' => 'monthly',
        'lastmod'    => $siteMod,
    ];
}
foreach ($brands as $b) {
    $urls[] = [
        'loc'        => $base . '/brand.php?slug=' . urlencode($b['slug']),
        'priority'   => '0.7',
        'changefreq' => 'monthly',
        'lastmod'    => $siteMod,
    ];
}
// Use pretty URLs and actual publication dates for articles
foreach ($articles as $a) {
    $urls[] = [
        'loc'        => $base . '/article/' . urlencode($a['slug']),
        'priority'   => '0.7',
        'changefreq' => 'monthly',
        'lastmod'    => !empty($a['date']) ? (string) $a['date'] : $siteMod,
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . "</lastmod>\n";
    echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
    echo "    <priority>{$u['priority']}</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
