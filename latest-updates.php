<?php
$cf = "cache/latest-updates.html";
if (file_exists($cf) && time() - filemtime($cf) < 3600) { readfile($cf); exit; }
ob_start();

require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';


// SEO SECTION ARRAY
$seo = [
    'title'       => 'Latest Updates 2026 – New Vacancy, Results & Admit Cards | EduMint24',

    'description' => 'All latest government job updates 2026 in one place. New vacancies, admit cards, results, and answer keys — updated daily on EduMint24.',

    'image'     => SEO_DEFAULT_IMAGE,
    'type'      => SEO_DEFAULT_TYPE,
    'robots'    => SEO_DEFAULT_ROBOTS
];


// FAQ SECTION ARRAY
$faqs = [

    [
        'question'    => 'How often are new updates added on EduMint24?',
        'answer_html' => 'We add new government job updates every day. This page always shows the 50 most recent posts across all categories - new vacancies, admit cards, results, and answer keys — so bookmark it and check daily.',
    ],

    [
        'question'    => 'What types of updates are listed on this page?',
        'answer_html' => 'This page covers all types of <strong>sarkari updates 2026</strong> - new vacancy notifications, admit card releases, exam results, and answer keys from SSC, Railway, UPSC, Banking, State PSC, and other recruitment boards.',
    ],

    [
        'question'    => 'How do I spot a newly added post?',
        'answer_html' => 'Posts published within the last 3 days show a red <strong>New</strong> badge next to the title. You can spot them instantly when you scroll through the list.',
    ],

    [
        'question'    => 'Can I apply directly from this page?',
        'answer_html' => 'No. Click the <strong>View</strong> button on any post to open its detail page. There you\'ll find the official apply online link, important dates, eligibility, and the full notification details.',
    ],

    [
        'question'    => 'How do I find updates for a specific category?',
        'answer_html' => 'Use our <a href="/categories">Categories</a> page to filter by SSC, Railway, Banking, Results, Admit Cards, and more. You can also use the Search to find any post by keyword.',
    ],

];


function getRecentPosts(PDO $pdo, int $limit = 50): array {
    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.slug, p.date
        FROM posts p
        WHERE p.status = 'published'
        ORDER BY p.date DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isNew(string $dateStr): bool {
    return (time() - strtotime($dateStr)) < (3 * 86400);
}

$recentPosts = getRecentPosts($pdo, 50);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>

<style id="lu-sls">

/* ── Table Card ────────────────────────────────── */
.lu-section {
  padding: var(--space-4) 0;
}

.ht-card {
  background: var(--bg-card);
  border-radius: var(--border-radius-sm);
  border: var(--border-width) solid var(--border-light);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.ht-card.recent { --ht-accent: var(--color-primary); }

/* ── Card Header ───────────────────────────────── */
.ht-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-3) var(--space-4);
  border-bottom: var(--border-width) solid var(--border-light);
  border-left: 3px solid var(--ht-accent);
  background: var(--bg-subtle);
}

.ht-card-title {
  font-family: var(--font-heading);
  font-size: var(--text-md);
  font-weight: var(--weight-bold);
  color: var(--text-primary);
}

/* ── Table ─────────────────────────────────────── */
.ht-table {
  width: 100%;
  border-collapse: collapse;
}

.ht-table thead tr {
  background: var(--bg-subtle);
}

.ht-table th {
  padding: var(--space-2) var(--space-3);
  text-align: left;
  font-size: 11px;
  font-weight: var(--weight-semibold);
  color: var(--text-muted);
  border-bottom: var(--border-width) solid var(--border-light);
  text-transform: uppercase;
  letter-spacing: var(--letter-wide);
  white-space: nowrap;
}

.ht-table tbody tr {
  border-bottom: var(--border-width) solid var(--border-light);
  transition: background var(--ease-fast);
}
.ht-table tbody tr:last-child { border-bottom: none; }
.ht-table tbody tr:hover { background: var(--color-primary-soft); }

.ht-table td {
  padding: var(--space-2) var(--space-3);
  vertical-align: middle;
}

/* ── Column: # ─────────────────────────────────── */
.col-num {
  width: 36px;
  text-align: center;
  color: var(--text-faint);
  font-size: 11px;
  white-space: nowrap;
}

/* ── Column: Date ──────────────────────────────── */
.col-date {
  width: 52px;
  white-space: nowrap;
  color: var(--text-muted);
  font-size: 11px;
  line-height: var(--leading-tight);
}

/* ── Column: Title ─────────────────────────────── */
.col-title { max-width: 0; }

.col-title-inner {
  display: flex;
  align-items: center;
  overflow: hidden;
}

.col-title-inner a {
  display: block;
  overflow: hidden;
  color: var(--text-body);
  font-size: var(--text-xs);
  text-decoration: none;
  min-width: 0;
  transition: color var(--ease-fast);
}
.col-title-inner a:hover { color: var(--color-primary); }

/* ── Column: Action ────────────────────────────── */
.col-action {
  width: 44px;
  text-align: center;
}

/* ── View Button ───────────────────────────────── */
.ht-btn {
  display: inline-block;
  background: var(--ht-accent);
  color: var(--text-white);
  font-size: 10px;
  font-weight: var(--weight-semibold);
  padding: 2px var(--space-2);
  border-radius: var(--border-radius-full);
  text-decoration: none;
  transition: opacity var(--ease-fast);
}
.ht-btn:hover { opacity: 0.82; }

/* ── New Badge ─────────────────────────────────── */
.ht-new {
  display: inline-block;
  background: #ef4444;
  color: var(--text-white);
  font-size: 9px;
  font-weight: var(--weight-bold);
  padding: 1px var(--space-1);
  border-radius: var(--border-radius-xs);
  margin-right: var(--space-1);
  line-height: 1.4;
  flex-shrink: 0;
}

</style>


    <!-- Breadcrumb Schema -->
    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": <?= json_encode(SITE_URL) ?>
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Latest Updates",
      "item": <?= json_encode(SITE_URL . '/latest-updates') ?>
    }
  ]
}
</script>

    <!-- ItemList Schema -->
    <script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',

    'name' =>
        'Latest Government Job Updates 2026 - ' . SITE_NAME,

    'url' =>
        SITE_URL . '/latest-updates',

    'numberOfItems' =>
        count($recentPosts),

    'itemListElement' => array_map(
        static function ($post, $index) {
            return [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'url'      => SITE_URL . postUrl($post['slug'], $post['id']),
                'item'     => [
                    '@type'            => 'BlogPosting',
                    'headline'         => $post['title'],
                    'datePublished'    => date('c', strtotime($post['date'])),
                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',
                        '@id'   => SITE_URL . postUrl($post['slug'], $post['id']),
                    ],
                ],
            ];
        },
        $recentPosts,
        array_keys($recentPosts)
    ),

], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

</head>
<body>
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<main>
	<nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page">Latest Updates</li>
            </ol>
        </nav>  
        
    <div class="container">
    <h1>Latest Updates 2026 - Recent Government Jobs, Results & Admit Cards</h1>

        <section class="lu-section">
            <div class="ht-card recent">

                <div class="ht-card-header">
                    <span class="ht-card-title">Recent Updates</span>
                    <a href="/categories" class="ht-view-all">All Categories »</a>
                </div>

                <table class="ht-table">
                    <thead>
                        <tr>
                            <th class="col-num">#</th>
                            <th class="col-date">Date</th>
                            <th class="col-title">Post Title</th>
                            <th class="col-action">Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPosts as $i => $post): ?>
                        <tr>
                            <td class="col-num"><?= $i + 1 ?></td>
                            <td class="col-date"><?= date('d M', strtotime($post['date'])) ?></td>
                            <td class="col-title">
                                <div class="col-title-inner">
                                    <?php if (isNew($post['date'])): ?>
                                        <span class="ht-new">New</span>
                                    <?php endif; ?>
                                    <a href="<?= postUrl($post['slug'], $post['id']) ?>">
                                        <?= htmlspecialchars($post['title']) ?>
                                    </a>
                                </div>
                            </td>
                            <td class="col-action">
                                <a href="<?= postUrl($post['slug'], $post['id']) ?>" class="ht-btn">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            </div>
        </section>

    </div><!-- /container -->

    <?php require_once ROOT_PATH . '/components/faq.php'; ?>

</main>
<?php require_once ROOT_PATH . '/components/footer.php'; ?>
</body>
</html>
<?php file_put_contents($cf, $minified = minifyHTML(ob_get_clean())) or error_log("Failed to write cache: $cf"); echo $minified; ?>
