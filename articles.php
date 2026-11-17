<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';
$articles = require __DIR__ . '/data/articles.php';

usort($articles, fn($a, $b) => strcmp($b['date'], $a['date']));

layout_head(
    'Articles · imeihub',
    'Guides and explainers about IMEI numbers, blacklist checks, and buying used phones safely.',
    '<meta name="keywords" content="IMEI guide, what is IMEI, how to check IMEI, blacklist check guide, buying used phone, phone safety, IMEI number explained">'
);
?>
    <section class="page-hero">
        <div class="container">
            <h1>Articles &amp; guides</h1>
            <p class="lede">
                Plain-English walkthroughs of what an IMEI is, how to check
                one safely, and how to avoid getting stuck with a stolen phone.
            </p>
        </div>
    </section>

    <section class="articles-list">
        <div class="container">
            <?php foreach ($articles as $a): ?>
                <a class="article-card" href="/article/<?= urlencode($a['slug']) ?>">
                    <div class="article-card-meta">
                        <span class="pill pill-tag"><?= htmlspecialchars($a['tag'], ENT_QUOTES, 'UTF-8') ?></span>
                        <time><?= htmlspecialchars($a['date'], ENT_QUOTES, 'UTF-8') ?></time>
                    </div>
                    <h2><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars($a['excerpt'], ENT_QUOTES, 'UTF-8') ?></p>
                    <span class="article-link">Read article &rarr;</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php layout_foot(); ?>
