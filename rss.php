<?php
declare(strict_types=1);

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600, s-maxage=86400');

$base     = 'https://imeihub.net';
$articles = require __DIR__ . '/data/articles.php';

// Sort newest first by date, preserving original order on ties.
usort($articles, static function (array $a, array $b): int {
    return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
});

$latest = array_slice($articles, 0, 50);

$lastBuildTs = time();
if (!empty($latest[0]['date'])) {
    $parsed = strtotime((string) $latest[0]['date'] . ' 12:00:00 UTC');
    if ($parsed !== false) {
        $lastBuildTs = $parsed;
    }
}

$xml = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>imeihub Articles &amp; IMEI Verification Guides</title>
    <link><?= $xml($base . '/articles.php') ?></link>
    <description>Practical IMEI lookup, carrier lock, iCloud activation, blacklist, and mobile device verification guides from the imeihub Editorial Team.</description>
    <language>en-us</language>
    <lastBuildDate><?= gmdate(DATE_RSS, $lastBuildTs) ?></lastBuildDate>
    <atom:link href="<?= $xml($base . '/rss.xml') ?>" rel="self" type="application/rss+xml"/>
<?php foreach ($latest as $a):
    $url = $base . '/article/' . rawurlencode((string) $a['slug']);
    $ts  = !empty($a['date']) ? strtotime((string) $a['date'] . ' 12:00:00 UTC') : false;
    $pub = $ts !== false ? gmdate(DATE_RSS, $ts) : gmdate(DATE_RSS, $lastBuildTs);
?>
    <item>
      <title><?= $xml((string) ($a['title'] ?? '')) ?></title>
      <link><?= $xml($url) ?></link>
      <guid isPermaLink="true"><?= $xml($url) ?></guid>
      <pubDate><?= $pub ?></pubDate>
<?php if (!empty($a['tag'])): ?>
      <category><?= $xml((string) $a['tag']) ?></category>
<?php endif; ?>
      <description><?= $xml((string) ($a['excerpt'] ?? '')) ?></description>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
