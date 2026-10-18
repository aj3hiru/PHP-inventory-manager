<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$tag_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tag_id <= 0) {
    header("Location: " . SITE_URL);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ?");
$stmt->execute([$tag_id]);
$tag = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tag) {
    http_response_code(404);
    include 'templates/page_not_found.php';
    exit;
}

// Increment View Count (Tracking)
$pdo->prepare("UPDATE tags SET views = views + 1 WHERE id = ?")->execute([$tag_id]);


$posts_per_page = POSTS_PER_PAGE;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $posts_per_page;


$count_sql = "
    SELECT COUNT(*) 
    FROM posts p
    JOIN post_tag pt ON p.id = pt.post_id
    WHERE pt.tag_id = :tag_id 
    AND p.status = 'published'
";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute([':tag_id' => $tag_id]);
$total_posts = $count_stmt->fetchColumn();
$total_pages = ceil($total_posts / $posts_per_page);


$sql = "
    SELECT 
        p.*, 
        a.name AS author_name,
        a.slug AS author_slug,
        m.file_path AS banner_image,
        m.alt_text AS banner_alt
    FROM posts p
    JOIN post_tag pt ON p.id = pt.post_id
    JOIN authors a ON p.author_id = a.id
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE pt.tag_id = :tag_id 
    AND p.status = 'published'
    ORDER BY p.date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':tag_id', $tag_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $posts_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);


$seo = [
    'title'       => $tag['name'] . ($page > 1 ? " - Page $page" : '') . ' | ' . SITE_NAME,
    'description' => 'Explore all articles and resources related to ' . $tag['name'] . ' on ' . SITE_NAME . '. Updated regularly.',
    'image'       => !empty($posts[0]['banner_image'])
                        ? SITE_URL . '/' . $posts[0]['banner_image']
                        : SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => $page > 1 ? 'noindex, follow' : SEO_DEFAULT_ROBOTS,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>

<?php require_once ROOT_PATH . '/components/head_script.php'; ?>
     	
<style id="tp-inline">.no-results{text-align:center;padding:48px 16px}
.no-results-text{font-size:1.1rem;font-weight:500;color:#444;margin-bottom:16px}
.no-results-btn{display:inline-block;padding:10px 20px;font-size:.95rem;font-weight:600;color:#fff;background:#2563eb;border-radius:8px;text-decoration:none;transition:background .2s ease}
.no-results-btn:hover{background:#1e40af}</style>


    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "<?= htmlspecialchars(SITE_URL) ?>" },
    { "@type": "ListItem", "position": 2, "name": "<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?>" }
  ]
}
</script>



    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  "name": "<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "mainEntity": {
    "@type": "ItemList",
    "itemListElement": [
      <?php foreach ($posts as $index => $post): ?>
      {
        "@type": "ListItem",
        "position": <?= $index + 1 ?>,
        "url": "<?= htmlspecialchars(SITE_URL . postUrl($post['slug'], $post['id'])) ?>",
        "name": "<?= htmlspecialchars($post['title']) ?>"
      }<?= $index < count($posts) - 1 ? ',' : '' ?>
      <?php endforeach; ?>
    ]
  }
}
</script>

</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page"><?= $tag_name_safe ?></li>
            </ol>
        </nav>

        <div class="container">
                <h1 class="page-title"><?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            
            <?php if (empty($posts)): ?>
                <div class="no-results">
    <p class="no-results-text">No posts found for this tag.</p>
    <a href="/" class="no-results-btn">Back to Home</a>
</div>
            <?php else: ?>
                <div class="post-grid">
                    <?php foreach ($posts as $post): ?>
                        <article class="post-card">
                            <a href="<?= postUrl($post['slug'], $post['id']) ?>" class="post-card-link">
                                <div class="post-banner">
                                    <?php if ($post['banner_image']): ?>
                                        <img src="/<?= htmlspecialchars($post['banner_image']) ?>" 
                                             alt="<?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?>" 
                                             loading="lazy" width="1200" height="675">
                                    <?php else: ?>
                                        <div class="post-banner-placeholder">
                                            <?= strtoupper(substr($post['title'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="post-card-content">
                                    <h2 class="post-card-title"><?= htmlspecialchars($post['title']) ?></h2>
                                    <p class="post-card-author">
                                        <span><?= htmlspecialchars($post['author_name']) ?></span>
                                        <span> / <?= date('F j, Y', strtotime($post['date'])) ?></span>
                                    </p>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>



                <?php if ($total_pages > 1): 
                    function getTagPageUrl($pageNum, $tid) {
                        return 'tag.php?id=' . $tid . '&page=' . $pageNum;
                    }
                ?>
                <nav class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= getTagPageUrl(1, $tag_id) ?>">&laquo; First</a>
                        <a href="<?= getTagPageUrl($page - 1, $tag_id) ?>">&lsaquo; Prev</a>
                    <?php else: ?>
                        <span class="disabled">&laquo; First</span>
                        <span class="disabled">&lsaquo; Prev</span>
                    <?php endif; ?>
                    
                    <?php
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end = min($total_pages, $page + $range);
                    
                    if ($start > 1): ?>
                        <a href="<?= getTagPageUrl(1, $tag_id) ?>">1</a>
                        <?php if ($start > 2): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= getTagPageUrl($i, $tag_id) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1): ?>
                            <span>...</span>
                        <?php endif; ?>
                        <a href="<?= getTagPageUrl($total_pages, $tag_id) ?>"><?= $total_pages ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= getTagPageUrl($page + 1, $tag_id) ?>">Next &rsaquo;</a>
                        <a href="<?= getTagPageUrl($total_pages, $tag_id) ?>">Last &raquo;</a>
                    <?php else: ?>
                        <span class="disabled">Next &rsaquo;</span>
                        <span class="disabled">Last &raquo;</span>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
                	
                
                
            <?php endif; ?>
        </div>
    </main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php ob_end_flush(); ?>