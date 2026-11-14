<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');

// Always use HTTPS for sitemap URLs
$base = 'https://imeihub.net';

$brands   = require __DIR__ . '/data/brands.php';
$services = require __DIR__ . '/data/services.php';
$articles = require __DIR__ . '/data/articles.php';
$now      = date('c');

$urls = [
    ['loc' => $base . '/',            'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => $base . '/services.php','priority' => '0.9', 'changefreq' => 'weekly'],
    ['loc' => $base . '/brands.php',  'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => $base . '/articles.php','priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => $base . '/about.php',   'priority' => '0.4', 'changefreq' => 'yearly'],
    ['loc' => $base . '/contact.php', 'priority' => '0.4', 'changefreq' => 'yearly'],
    ['loc' => $base . '/privacy.php', 'priority' => '0.3', 'changefreq' => 'yearly'],
];
foreach ($services as $s) {
    $urls[] = [
        'loc'        => $base . '/service.php?slug=' . urlencode($s['slug']),
        'priority'   => '0.7',
        'changefreq' => 'monthly',
    ];
}
foreach ($brands as $b) {
    $urls[] = [
        'loc'        => $base . '/brand.php?slug=' . urlencode($b['slug']),
        'priority'   => '0.6',
        'changefreq' => 'monthly',
    ];
}
// Use pretty URLs for articles (/article/slug instead of /article.php?slug=)
foreach ($articles as $a) {
    $urls[] = [
        'loc'        => $base . '/article/' . urlencode($a['slug']),
        'priority'   => '0.7',
        'changefreq' => 'weekly',
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    echo "    <lastmod>$now</lastmod>\n";
    echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
    echo "    <priority>{$u['priority']}</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
