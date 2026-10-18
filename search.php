<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

// 1. Get and Sanitize Query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($query)) {
    header("Location: " . SITE_URL);
    exit;
}

$search_term = "%" . $query . "%";

// 2. Search Topics (Categories & Tags)
$matched_topics = [];

try {
    $stmt_cat = $pdo->prepare("SELECT id, name, slug, 'category' as type FROM categories WHERE name LIKE :query LIMIT 5");
    $stmt_cat->execute([':query' => $search_term]);
    $found_cats = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);

    $stmt_tag = $pdo->prepare("SELECT id, name, slug, 'tag' as type FROM tags WHERE name LIKE :query LIMIT 5");
    $stmt_tag->execute([':query' => $search_term]);
    $found_tags = $stmt_tag->fetchAll(PDO::FETCH_ASSOC);

    $matched_topics = array_merge($found_cats, $found_tags);

} catch (PDOException $e) {
    error_log("Search topics error: " . $e->getMessage());
}

// 3. Pagination Setup
$posts_per_page = POSTS_PER_PAGE;
$page           = max(1, (int)($_GET['page'] ?? 1));
$offset         = ($page - 1) * $posts_per_page;

// 4. Count Total Results
$count_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM posts
    WHERE status = 'published'
    AND (title LIKE :query1 OR content LIKE :query2)
");
$count_stmt->execute([':query1' => $search_term, ':query2' => $search_term]);
$total_posts = $count_stmt->fetchColumn();
$total_pages  = ceil($total_posts / $posts_per_page);

// 5. Fetch Posts
$stmt = $pdo->prepare("
    SELECT p.*,
           a.name AS author_name, a.slug AS author_slug,
           m.file_path AS banner_image, m.alt_text AS banner_alt
    FROM posts p
    JOIN authors a ON p.author_id = a.id
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.status = 'published'
    AND (p.title LIKE :query1 OR p.content LIKE :query2)
    ORDER BY p.date DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':query1', $search_term, PDO::PARAM_STR);
$stmt->bindValue(':query2', $search_term, PDO::PARAM_STR);
$stmt->bindValue(':limit',  $posts_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SEO ARRAY
$seo = [
    'title'       => 'Search Results for "' . $query . '" | ' . SITE_NAME,
    'description' => 'Search results for "' . $query . '" on ' . SITE_NAME . '. Browse latest updates and articles.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => 'noindex, follow',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
    
<?php require_once ROOT_PATH . '/components/head_script.php'; ?>
 
   
        <style>
        /* Topics Section Styles */
        .search-topics-container { margin-bottom: 10px; padding: 20px; background: #f9f9f9; border-radius: 8px; border: 1px solid #eee; }
        .topics-label { font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: #666; margin-bottom: 15px; display: block; letter-spacing: 0.5px; }
        .topics-list { display: flex; flex-wrap: wrap; gap: 10px; }
        .topic-badge { 
            display: inline-flex; align-items: center; padding: 3px 12px; 
            border-radius: 50px; text-decoration: none; font-weight: 500; font-size: 0.85rem; 
            transition: all 0.2s ease; border: 1px solid transparent;
        }
        
        /* Category Badge Style */
        .topic-badge.category { background-color: #e3f2fd; color: #0d47a1; border-color: #bbdefb; }
        .topic-badge.category:hover { background-color: #bbdefb; }
        
        /* Tag Badge Style */
        .topic-badge.tag { background-color: #f3e5f5; color: #7b1fa2; border-color: #e1bee7; }
        .topic-badge.tag:hover { background-color: #e1bee7; }

        .topic-icon { margin-right: 6px; font-size: 0.8em; }
    </style>

    <?php if ($page > 1): ?>
<link rel="prev" href="<?= SITE_URL ?>/search.php?q=<?= urlencode($query) ?>&page=<?= $page-1 ?>">
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<link rel="next" href="<?= SITE_URL ?>/search.php?q=<?= urlencode($query) ?>&page=<?= $page+1 ?>">
<?php endif; ?>


<!-- Breadcrumbs Schema -->
    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "<?= htmlspecialchars(SITE_URL) ?>" },
    { "@type": "ListItem", "position": 2, "name": "Search" }
  ]
}
</script>



    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SearchResultsPage",
  "name": "<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>",
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
                <li aria-current="page">Search</li>
            </ol>
        </nav>
    
        <div class="container">
        
            <h1 class="page-title">Search Results for: <em><?= htmlspecialchars($query) ?></em></h1>
               <p style="padding:5px;color:#606060; margin-bottom: 10px;">
                Found <?= $total_posts ?> article<?= $total_posts != 1 ? 's' : '' ?> 
                <?php if(!empty($matched_topics)) echo " and " . count($matched_topics) . " related topic" . (count($matched_topics) != 1 ? 's' : ''); ?>
            </p>
            
                <?php if (!empty($matched_topics)): ?>
            <div class="search-topics-container">
                <span class="topics-label">Related Topics & Categories</span>
                <div class="topics-list">
                    <?php foreach ($matched_topics as $topic): ?>
                        <?php 
                            if ($topic['type'] === 'category') {
                                $url = '/categories/' . $topic['slug'];
                                $icon = '&#128193;'; 
                            } else {
                                $url = tagUrl($topic['slug'], $topic['id']);
                                $icon = '#';
                            }
                        ?>
                        <a href="<?= $url ?>" class="topic-badge <?= $topic['type'] ?>">
                            <span class="topic-icon"><?= $icon ?></span> 
                            <?= htmlspecialchars($topic['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            	
           
            
            <?php if (empty($posts)): ?>
                <div class="no-results" style="text-align:center; padding: 50px 0;">
                    <p style="font-size:1.2rem; margin-bottom:15px;">We couldn't find anything matching "<strong><?= htmlspecialchars($query) ?></strong>".</p>
                    <p>Try different keywords or check the spelling.</p>
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
                    // Helper to generate search pagination link
                    function getSearchPageUrl($pageNum, $q) {
                        return '?q=' . urlencode($q) . '&page=' . $pageNum;
                    }
                ?>
                <nav class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?= getSearchPageUrl(1, $query) ?>">&laquo; First</a>
                        <a href="<?= getSearchPageUrl($page - 1, $query) ?>">&lsaquo; Prev</a>
                    <?php else: ?>
                        <span class="disabled">&laquo; First</span>
                        <span class="disabled">&lsaquo; Prev</span>
                    <?php endif; ?>
                    
                    <?php
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end = min($total_pages, $page + $range);
                    
                    if ($start > 1): ?>
                        <a href="<?= getSearchPageUrl(1, $query) ?>">1</a>
                        <?php if ($start > 2): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= getSearchPageUrl($i, $query) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1): ?>
                            <span>...</span>
                        <?php endif; ?>
                        <a href="<?= getSearchPageUrl($total_pages, $query) ?>"><?= $total_pages ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= getSearchPageUrl($page + 1, $query) ?>">Next &rsaquo;</a>
                        <a href="<?= getSearchPageUrl($total_pages, $query) ?>">Last &raquo;</a>
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
