<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$stmt_all = $pdo->query("SELECT name, slug FROM categories ORDER BY views DESC, name ASC");
$all_cats = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

// Pagination setup
$categories_per_page = 3;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $categories_per_page;

// Get total category count using apcu if available cache for 24 hr
$cache_key = 'total_categories_count';
$total_categories = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $total_categories = apcu_fetch($cache_key, $success);
    if (!$success) $total_categories = false;
}

if ($total_categories === false) {
    $total_categories = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $total_categories, 86400);
    }
}

$total_pages = ceil($total_categories / $categories_per_page);

// Get categories list (cached for 24 hr) with pagination
$cache_key = "categories_page_" . $page;
if (!function_exists('apcu_fetch') || !apcu_enabled() || ($categories = apcu_fetch($cache_key)) === false) {
    $sql = "SELECT id, name, slug, meta_title, meta_description FROM categories ORDER BY name LIMIT " . (int)$categories_per_page . " OFFSET " . (int)$offset;
    $stmt = $pdo->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('apcu_store')) apcu_store($cache_key, $categories, 86400);
}

// Fetch recent posts (up to 3 per category) for all categories in one query
$category_ids = array_column($categories, 'id');
$grouped_posts = [];

if ($category_ids) {
    $in = str_repeat('?,', count($category_ids) - 1) . '?';
    $posts_stmt = $pdo->prepare("
        SELECT 
            p.*, 
            a.name AS author_name,
            a.slug AS author_slug,
            m.file_path AS banner_image,
            m.alt_text AS banner_alt
        FROM posts p
        JOIN authors a ON p.author_id = a.id 
        LEFT JOIN media m ON p.featured_image_id = m.id
        WHERE p.category_id IN ($in) AND p.status = 'published'
        ORDER BY p.category_id, p.date DESC
    ");
    $posts_stmt->execute($category_ids);
    $all_posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group posts by category ID (limit 3 per category)
    foreach ($all_posts as $post) {
        $cat_id = $post['category_id'];
        if (!isset($grouped_posts[$cat_id])) $grouped_posts[$cat_id] = [];
        if (count($grouped_posts[$cat_id]) < 3) {
            $grouped_posts[$cat_id][] = $post;
        }
    }
}


// SEO SECTION ARRAY
$page_suffix = $page > 1 ? " - Page $page" : "";

$seo = [
    'title'       => 'All Categories' . $page_suffix . ' | ' . SITE_NAME,
    'description' => 'Browse all categories on ' . SITE_NAME . '. Find the latest updates, notifications, and alerts across all topics in one place.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];


// FAQ SECTION ARRAY
$faqs = [

    [
        'question'    => 'What is the upcoming govt job vacancy 2026 list?',
        'answer_html' => 'The upcoming govt job vacancy 2026 list includes all India central and state government recruitment notifications across SSC, Railway, Banking, UPSC, Defence, Police, PSU and other departments. This page provides a complete and regularly updated vacancy list with direct apply online links.',
    ],

    [
        'question'    => 'Where can I check all India government job vacancy 2026?',
        'answer_html' => 'You can check the complete all India government job vacancy 2026 list on this page. It covers central government jobs, state recruitment notifications, qualification-wise vacancies, and latest job updates in one place.',
    ],

    [
        'question'    => 'How to apply for government job notification 2026?',
        'answer_html' => 'To apply for a government job notification 2026, select the desired vacancy, read the official notification carefully, verify eligibility criteria, and use the direct apply online link provided with each listing.',
    ],

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
<link rel="prev" href="<?= htmlspecialchars(SITE_URL . '/categories?page=' . ($page - 1)) ?>">
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<link rel="next" href="<?= htmlspecialchars(SITE_URL . '/categories?page=' . ($page + 1)) ?>">
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
      "item": <?= json_encode(SITE_URL) ?>
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Categories"
    }
  ]
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  "name": "<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>",
"description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "url": "<?= htmlspecialchars(SITE_URL) ?>/categories",
  "publisher": {
    "@type": "Organization",
    "name": "<?= SITE_NAME ?>",
    "logo": {
      "@type": "ImageObject",
      "url": "<?= htmlspecialchars(SITE_LOGO) ?>"
    }
  },
  "hasPart": [
    <?php foreach ($categories as $cat_index => $cat): ?>
    {
      "@type": "CollectionPage",
      "name": "<?= htmlspecialchars($cat['name']) ?>",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/categories/<?= htmlspecialchars($cat['slug']) ?>",
      "description": "<?= htmlspecialchars($cat['meta_description']) ?>",
      "hasPart": [
        <?php
        $posts = $grouped_posts[$cat['id']] ?? [];
        foreach ($posts as $p_index => $post): ?>
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
        }<?= $p_index < count($posts) - 1 ? ',' : '' ?>
        <?php endforeach; ?>
      ]
    }<?= $cat_index < count($categories) - 1 ? ',' : '' ?>
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
                <li aria-current="page">Categories</li>
            </ol>
        </nav>
            
        <div class="container">
            <h1 class="page-title">Upcoming Govt Job Vacancy 2026 – All India Government Jobs Notification List</h1>
           
<nav class="ql-container" aria-label="Job Categories">
    <div id="qlBox" class="ql-mask">
        <ul class="ql-table">
            <?php foreach ($all_cats as $cat): ?>
                <li>
                    <a href="/categories/<?= htmlspecialchars($cat['slug']) ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <button id="qlBtn" class="ql-toggle-btn" onclick="toggleQL()">View More Categories &#65291;</button>
</nav>
            
            <?php if (empty($categories)): ?>
                <p class="no-posts-message">No categories found.</p>
            <?php else: ?>
 <?php foreach ($categories as $cat):
   $posts = $grouped_posts[$cat['id']] ?? []; ?>                   
    <div class="category-section">
           <div class="category-section-header">
                            <h2 class="category-section-title"><?= htmlspecialchars($cat['name']) ?></h2>
                      <?php if (count($posts) > 0): ?>
                                <a href="categories/<?= $cat['slug'] ?>" class="view-all-link">View All &raquo;</a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (empty($posts)): ?>
                            <p class="no-posts-message">No posts available in this category yet.</p>
                        <?php else: ?>
                            <div class="post-grid">
                                <?php foreach ($posts as $post): ?>
                                    <article class="post-card">
                                        <a href="<?= postUrl($post['slug'], $post['id']) ?>" class="post-card-link">
                                            <div class="post-banner">
                                                <?php if ($post['banner_image']): ?>
                                                    <img src="<?= htmlspecialchars($post['banner_image']) ?>" 
                                                         alt="<?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?> - Government Job Vacancy 2025" loading="lazy" width="1200"
                     height="675">
                                                <?php else: ?>
                                                    <div class="post-banner-placeholder">
                                                        <?= strtoupper(substr($post['title'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="post-card-content">
                                                <h3 class="post-card-title"><?= htmlspecialchars($post['title']) ?></h3>
                                                <p class="post-card-author">
                                                    <span><?= htmlspecialchars($post['author_name']) ?></span><span> / <?= date('F j, Y', strtotime($post['date'])) ?></span>
                                                </p>
                                            </div>
                                        </a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                	
                
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
        
<?php require_once ROOT_PATH . '/components/faq.php'; ?>
 
    </main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
</body>
</html>
<?php ob_end_flush(); ?>