<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

$slug = $_GET['slug'] ?? null;
$stmt = $pdo->prepare("SELECT * FROM authors WHERE slug = ?");
$stmt->execute([$slug]);
$author = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$author) die('Author not found');

// Pagination setup
$posts_per_page = POSTS_PER_PAGE;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $posts_per_page;

// Get total post count for this author using apcu if available 
$cache_key = 'author_post_count_' . $author['id'];
$total_posts = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $total_posts = apcu_fetch($cache_key, $success);
    if (!$success) $total_posts = false;
}

if ($total_posts === false) {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE author_id = ? AND status = 'published'");
    $count_stmt->execute([$author['id']]);
    $total_posts = (int) $count_stmt->fetchColumn();
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $total_posts, 21600);
    }
}

$total_pages = ceil($total_posts / $posts_per_page);


// Get posts with pagination
$sql = "
    SELECT 
        p.*, 
        a.name as author_name,
        m.file_path as banner_image,
        m.alt_text as banner_alt
    FROM posts p 
    JOIN authors a ON p.author_id = a.id 
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.author_id = " . (int)$author['id'] . " AND p.status = 'published'
    ORDER BY p.date DESC
    LIMIT " . (int)$posts_per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->query($sql);
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SameAs Data for schema markup 
$sameAs = array_values(array_filter([
    $author['instagram'] ?? null,
    $author['threads']   ?? null,
    $author['linkedin']  ?? null,
    $author['facebook']  ?? null,
    $author['twitter']   ?? null,
]));

//SEO SECTION ARRAY
$seo = [
    'title'       => 'Articles by ' . $author['name'] . ' | ' . SITE_NAME,
    'description' => 'Browse all articles and updates written by ' . $author['name'] . ' on ' . SITE_NAME . '.',
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
<link rel="prev" href="<?= htmlspecialchars(SITE_URL . '/author/' . $slug . '?page=' . ($page - 1)) ?>">
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<link rel="next" href="<?= htmlspecialchars(SITE_URL . '/author/' . $slug . '?page=' . ($page + 1)) ?>">
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
      "name": "Author - <?= htmlspecialchars($author['name']) ?>"
    }
  ]
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": ["CollectionPage", "Blog"],
  "name": "Posts by <?= htmlspecialchars($author['name']) ?>",
  "description": "Read blog posts written by <?= htmlspecialchars($author['name']) ?> on <?= SITE_NAME ?>.",
  "url": "<?= htmlspecialchars(SITE_URL) ?>/author/<?= htmlspecialchars($author['slug']) ?>",
  "author": {
    "@type": "Person",
    "name": "<?= htmlspecialchars($author['name']) ?>",
    "description": "<?= htmlspecialchars($author['bio'] ?? '') ?>",
    <?php if (!empty($sameAs)): ?>
    "sameAs": <?= json_encode($sameAs, JSON_UNESCAPED_SLASHES) ?>
    <?php endif; ?>
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
          "url": "<?= SITE_URL . '/author/' . htmlspecialchars($author['slug']) ?>"
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
                <li aria-current="page">Author - <?= htmlspecialchars($author['name']) ?></li>
            </ol>
        </nav>
        
        <div class="container">             
        	<!-- Author Bio -->
                <div class="author-bio">
    <img src="<?= !empty($author['profile_image']) ? '/' . htmlspecialchars($author['profile_image']) : '/assets/img/user.png' ?>"
         alt="<?= htmlspecialchars($author['name']) ?>"
         width="80" height="80"
         style="width:80px;height:80px;border-radius:50%;object-fit:cover;"
         onerror="this.src='/assets/img/user.png'">
    <h1 class="page-title">About <?= htmlspecialchars($author['name']) ?></h1>
    <p><?= nl2br(htmlspecialchars($author['bio'])) ?></p>
    <div class="author-follow-links">
    	<h2>Follow author on:</h2>
        <?php if (!empty($author['instagram'])): ?><a href="<?= htmlspecialchars($author['instagram']) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
        <?php if (!empty($author['threads'])): ?><a href="<?= htmlspecialchars($author['threads']) ?>" target="_blank" rel="noopener">Threads</a><?php endif; ?>
        <?php if (!empty($author['linkedin'])): ?><a href="<?= htmlspecialchars($author['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
        <?php if (!empty($author['facebook'])): ?><a href="<?= htmlspecialchars($author['facebook']) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
        <?php if (!empty($author['twitter'])): ?><a href="<?= htmlspecialchars($author['twitter']) ?>" target="_blank" rel="noopener">X</a><?php endif; ?>
    </div>
</div>
                
                
                
            <h2 class="page-title-2">Posts by <?= htmlspecialchars($author['name']) ?></h2>
            
            <?php if (empty($posts)): ?>
                <p>No posts found by this author.</p>
            <?php else: ?>
                <div class="post-grid">
             <?php foreach ($posts as $post): ?>
                        <article class="post-card">
                            <a href="<?= postUrl($post['slug'], $post['id']) ?>" class="post-card-link">
                                <div class="post-banner">
                                    <?php if ($post['banner_image']): ?>
                                        <img src="/<?= htmlspecialchars($post['banner_image']) ?>" 
                                             alt="<?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?>" loading="lazy" width="1200"
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
            <a href="/author/<?= urlencode($slug) ?>/">&laquo; First</a>
            <a href="/author/<?= urlencode($slug) ?>/?page=<?= $page - 1 ?>">&lsaquo; Prev</a>
        <?php else: ?>
            <span class="disabled">&laquo; First</span>
            <span class="disabled">&lsaquo; Prev</span>
        <?php endif; ?>

        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end = min($total_pages, $page + $range);

        if ($start > 1): ?>
            <a href="/author/<?= urlencode($slug) ?>/">1</a>
            <?php if ($start > 2): ?>
                <span>...</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="/author/<?= urlencode($slug) ?>/?page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1): ?>
                <span>...</span>
            <?php endif; ?>
            <a href="/author/<?= urlencode($slug) ?>/?page=<?= $total_pages ?>"><?= $total_pages ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
            <a href="/author/<?= urlencode($slug) ?>/?page=<?= $page + 1 ?>">Next &rsaquo;</a>
            <a href="/author/<?= urlencode($slug) ?>/?page=<?= $total_pages ?>">Last &raquo;</a>
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