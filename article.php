<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/markdown.php';

$articles = require __DIR__ . '/data/articles.php';
$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]+/i', '', (string) $_GET['slug']) : '';

$article = null;
foreach ($articles as $a) {
    if ($a['slug'] === $slug) { $article = $a; break; }
}

if (!$article) {
    http_response_code(404);
    layout_head('Article not found · imeihub');
    ?>
    <section class="page-hero">
        <div class="container">
            <h1>Article not found</h1>
            <p class="lede">
                <a href="/articles.php">Browse all articles &rarr;</a>
            </p>
        </div>
    </section>
    <?php
    layout_foot();
    return;
}

// Build canonical URL
$canonicalUrl = 'https://imeihub.net/article/' . urlencode($article['slug']);

// Build extra head tags: canonical + Open Graph + JSON-LD
$titleEsc = htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8');
$descEsc  = htmlspecialchars($article['excerpt'], ENT_QUOTES, 'UTF-8');
$dateEsc  = htmlspecialchars($article['date'], ENT_QUOTES, 'UTF-8');

$extraHead = <<<HTML
<link rel="canonical" href="{$canonicalUrl}">
<meta property="og:type" content="article">
<meta property="og:title" content="{$titleEsc}">
<meta property="og:description" content="{$descEsc}">
<meta property="og:url" content="{$canonicalUrl}">
<meta property="og:site_name" content="imeihub">
<meta property="article:published_time" content="{$dateEsc}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{$titleEsc}">
<meta name="twitter:description" content="{$descEsc}">
HTML;

// JSON-LD Structured Data (Article schema)
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $article['title'],
    'description' => $article['excerpt'],
    'datePublished' => $article['date'],
    'dateModified' => $article['date'],
    'author' => [
        '@type' => 'Organization',
        'name' => 'imeihub',
        'url' => 'https://imeihub.net',
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'imeihub',
        'url' => 'https://imeihub.net',
    ],
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id' => $canonicalUrl,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$extraHead .= "\n<script type=\"application/ld+json\">{$jsonLd}</script>";

layout_head(
    htmlspecialchars($article['meta_title'] ?? ($article['title'] . ' · imeihub'), ENT_QUOTES, 'UTF-8'),
    $article['excerpt'],
    $extraHead
);
?>
    <article class="article-page">
        <header class="page-hero">
            <div class="container">
                <p class="breadcrumbs">
                    <a href="/articles.php">Articles</a> &rsaquo;
                    <?= htmlspecialchars($article['tag'], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <h1><?= htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="lede"><?= htmlspecialchars($article['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="article-date">
                    Published <?= htmlspecialchars($article['date'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        </header>

        <section class="article-body">
            <div class="container container--prose">
                <?= md_render((string) $article['body']) ?>

                <hr>
                <p class="article-cta">
                    Ready to try it? <a href="/">Run a free IMEI check &rarr;</a>
                </p>
            </div>
        </section>
    </article>
<?php layout_foot(); ?>
