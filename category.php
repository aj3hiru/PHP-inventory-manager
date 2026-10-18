<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$slug = $_GET['slug'] ?? null;

// Get category by slug
$stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = ?");
$stmt->execute([$slug]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cat) die('Category not found');
$category_id = $cat['id'];

// Increase category views by 1
$update = $pdo->prepare("UPDATE categories SET views = views + 1 WHERE id = ?");
$update->execute([$category_id]);

// Pagination setup
$posts_per_page = POSTS_PER_PAGE;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $posts_per_page;

// Get total post count for this category using apcu if available cache for 1 hr
$cache_key = "total_published_posts_cat_$category_id";
$total_posts = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $total_posts = apcu_fetch($cache_key, $success);
    if (!$success) $total_posts = false;
}

if ($total_posts === false) {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM posts p
        JOIN post_categories pc ON p.id = pc.post_id
        WHERE pc.category_id = ? AND p.status = 'published'
    ");
    $count_stmt->execute([$category_id]);
    $total_posts = (int) $count_stmt->fetchColumn();
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $total_posts, 3600);
    }
}

$total_pages = ceil($total_posts / $posts_per_page);

// Get posts for this category using post_categories junction
$sql = "
    SELECT 
        p.*, 
        a.name AS author_name,
        a.slug AS author_slug,
        m.file_path AS banner_image,
        m.alt_text AS banner_alt
    FROM posts p
    JOIN post_categories pc ON p.id = pc.post_id
    JOIN authors a ON p.author_id = a.id
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE pc.category_id = :category_id 
      AND p.status = 'published'
    ORDER BY p.date DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $posts_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SEO SECTION ARRAY
$page_suffix = $page > 1 ? " - Page $page" : "";

$seo = [
    'title'       => ($cat['meta_title'] ?: $cat['name']) . $page_suffix . " | " . SITE_NAME,
    'description' => $cat['meta_description'] ?: "Explore all posts in {$cat['name']} on " . SITE_NAME,
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>
    
<?php if ($page > 1): ?>
<link rel="prev" href="<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>?page=<?= $page-1 ?>">
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<link rel="next" href="<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>?page=<?= $page+1 ?>">
<?php endif; ?>
    
 <!-- Breadcrumb Schema for SEO -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "<?= htmlspecialchars(SITE_URL) ?>"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Categories",
      "item": "<?= htmlspecialchars(SITE_URL) ?>/categories"
    },
    {
      "@type": "ListItem",
      "position": 3,
      "name": "<?= htmlspecialchars($cat['name']) ?>"
    }
  ]
}
</script>

<!-- Category Page Schema for SEO -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": ["CollectionPage", "Blog"],
  "name": "<?= htmlspecialchars($cat['name']) ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "url": "<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>",
  "mainEntity": {
    "@type": "Blog",
    "name": "<?= SITE_NAME ?>",
    "url": "<?= htmlspecialchars(SITE_URL) ?>"
  },
  "hasPart": [
    <?php foreach ($posts as $index => $post): ?>
    {
      "@type": "BlogPosting",
      "headline": "<?= htmlspecialchars($post['title']) ?>",
      "url": "<?= htmlspecialchars(SITE_URL . postUrl($post['slug'], $post['id'])) ?>",
      "datePublished": "<?= date('c', strtotime($post['date'])) ?>",
      <?php if (!empty($post['updated_at'])): ?>
      "dateModified": "<?= date('c', strtotime($post['updated_at'])) ?>",
      <?php endif; ?>
      "author": {
        "@type": "Person",
        "name": "<?= htmlspecialchars($post['author_name']) ?>",
          "url": "<?= SITE_URL . '/author/' . $post['author_slug'] ?>"
      },
      "publisher": {
        "@type": "Organization",
        "name": "<?= SITE_NAME ?>",
        "logo": {
          "@type": "ImageObject",
          "url": "<?= htmlspecialchars(SITE_LOGO) ?>"
        }
      },
      <?php if (!empty($post['banner_image'])): ?>
      "image": "<?= htmlspecialchars(SITE_URL . '/' . $post['banner_image']) ?>",
      <?php endif; ?>
      "mainEntityOfPage": {
        "@type": "WebPage",
        "@id": "<?= htmlspecialchars(SITE_URL . postUrl($post['slug'], $post['id'])) ?>"
      }
    }<?= $index < count($posts) - 1 ? ',' : '' ?>
    <?php endforeach; ?>
  ]
}
</script>

</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
    	<!-- Breadcrumbs -->
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li><a href="/categories">Categories</a></li>
                <li aria-current="page"><?= htmlspecialchars($cat['name']) ?></li>
            </ol>
        </nav>
        <div class="container">
            <h1 class="page-title"><?= htmlspecialchars($cat['name']) ?> - <?= htmlspecialchars($cat['meta_title']) ?></h1>
      
            <?php if (empty($posts)): ?>
           <p>No posts found in this category.</p>
            <?php else: ?>
                <div class="post-grid">
                    <?php foreach ($posts as $post): ?>
                        <article class="post-card">
                            <a href="<?= postUrl($post['slug'], $post['id']) ?>" class="post-card-link">
                                <div class="post-banner">
                                    <?php if ($post['banner_image']): ?>
                                        <img src="/<?= htmlspecialchars($post['banner_image']) ?>" 
                                             alt="<?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?> - <?= htmlspecialchars($cat['meta_title']) ?>" loading="lazy" width="1200"
                     height="675">
                                    <?php else: ?>
                                        <div class="post-banner-placeholder">
                                            <?= strtoupper(substr($post['title'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="post-card-content">
                                    <h2 class="post-card-title"><?= htmlspecialchars($post['title']) ?></h2>
                                    <p class="post-card-author">
                                        <span><?= htmlspecialchars($post['author_name']) ?></span><span> / <?= date('F j, Y', strtotime($post['date'])) ?></span>
                                    </p>
                                </div>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
                
                
                
   <?php if ($total_pages > 1): ?>
    <nav class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1">&laquo; First</a>
            <a href="?page=<?= $page - 1 ?>">&lsaquo; Prev</a>
        <?php else: ?>
 <span class="disabled">&laquo; First</span>
<span class="disabled">&lsaquo; Prev</span>
        <?php endif; ?>
        
        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end = min($total_pages, $page + $range);
        
        if ($start > 1): ?>
            <a href="?page=1">1</a>
            <?php if ($start > 2): ?>
                <span>...</span>
            <?php endif; ?>
        <?php endif; ?>
        
      <?php for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i == $page): ?>
          <span class="current"><?= $i ?></span>
            <?php else: ?>
        <a href="?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?>
                <span>...</span>
            <?php endif; ?>
            <a href="?page=<?= $total_pages ?>"><?= $total_pages ?></a>
        <?php endif; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?>">Next &rsaquo;</a>
            <a href="?page=<?= $total_pages ?>">Last &raquo;</a>
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