<?php
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

if ($id <= 0) {
    http_response_code(400);
    include 'templates/post_not_found.php';
    die();
}

// Cache settings (Start/Stop toggle, TTL, Never-Cache list) are managed from
// /admin/cache-manager.php and stored in includes/cache_settings.json.
$__cs_file = __DIR__ . '/includes/cache_settings.json';
$__cs = ['disabled' => false, 'ttl_post' => 21600, 'exclude_urls' => ''];
if (file_exists($__cs_file)) {
    $__loaded = json_decode((string)@file_get_contents($__cs_file), true);
    if (is_array($__loaded)) { $__cs = array_merge($__cs, $__loaded); }
}
$__cache_excluded = false;
if (trim((string)$__cs['exclude_urls']) !== '') {
    $__req_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    foreach (preg_split('/\r\n|\r|\n/', $__cs['exclude_urls']) as $__line) {
        $__line = trim($__line);
        if ($__line !== '' && strpos($__req_path, $__line) === 0) { $__cache_excluded = true; break; }
    }
}

// Cache check
$cf = "cache/post_$id.html";
if (!$__cs['disabled'] && !$__cache_excluded && file_exists($cf) && time() - filemtime($cf) < (int)$__cs['ttl_post']) {
    readfile($cf);
    exit;
}

ob_start();

require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';

try {
    // Fetch post with author, category, media, meta
    $stmt = $pdo->prepare("
        SELECT p.*,
               a.name AS author_name, a.slug AS author_slug, a.bio AS author_bio,
               a.profile_image AS author_image,
               a.instagram AS author_instagram, a.threads AS author_threads,
               a.linkedin AS author_linkedin, a.facebook AS author_facebook,
               a.twitter AS author_twitter,
               c.name AS cat_name, c.slug AS cat_slug,
               m.file_path AS banner_path, m.alt_text AS banner_alt,
               m.responsive_set AS banner_responsive,
               pm.meta_value AS meta_description
        FROM posts p
        JOIN authors a   ON p.author_id = a.id
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN media m ON p.featured_image_id = m.id
        LEFT JOIN post_meta pm ON p.id = pm.post_id AND pm.meta_key = 'description'
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) {
        http_response_code(404);
        include 'templates/post_not_found.php';
        die();
    }

    // Fetch comments
    [$commentTree, $commentCount] = getCommentTree($pdo, $id);

    // Fetch tags
    $stmt = $pdo->prepare("
        SELECT t.id, t.slug, t.name
        FROM tags t
        JOIN post_tag pt ON t.id = pt.tag_id
        WHERE pt.post_id = ?
    ");
    $stmt->execute([$id]);
    $post_tags = $stmt->fetchAll();

    // Fetch related posts
    $stmt = $pdo->prepare("
        SELECT p.id, p.title, p.slug, p.date,
               a.name AS author_name,
               m.file_path AS banner_path, m.alt_text AS banner_alt
        FROM posts p
        JOIN authors a ON p.author_id = a.id
        LEFT JOIN media m ON p.featured_image_id = m.id
        WHERE p.category_id = ? AND p.id != ? AND p.status = 'published'
        ORDER BY p.date DESC
        LIMIT 5
    ");
    $stmt->execute([$post['category_id'], $id]);
    $related_posts = $stmt->fetchAll();

    // Generate TOC
    $tocData = generateTOC($post['content']);
    $toc     = $tocData['toc'];
    $content = $tocData['content'];

    // SEO ARRAY
    $seo = [
        'title'       => $post['title'] . ' | ' . SITE_NAME,
        'description' => !empty($post['meta_description'])
                            ? $post['meta_description']
                            : mb_substr(strip_tags(html_entity_decode($post['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 160),
        'image'       => !empty($post['banner_path'])
                            ? rtrim(SITE_URL, '/') . '/' . ltrim($post['banner_path'], '/')
                            : SEO_DEFAULT_IMAGE,
        'type'        => 'article',
        'robots'      => SEO_DEFAULT_ROBOTS,
    ];

} catch (PDOException $e) {
    error_log("DB Error post.php ID:$id — " . $e->getMessage());
    http_response_code(500);
    include 'templates/error.php';
    die();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
    
 <meta name="author" content="<?= htmlspecialchars($post['author_name']) ?>">
 	
<!-- Article-specific Open Graph Tags -->
<meta property="article:published_time" content="<?= htmlspecialchars(date('c', strtotime($post['date']))) ?>">
	
<?php if (!empty($post['updated_at'])): ?>
<meta property="article:modified_time" content="<?= htmlspecialchars(date('c', strtotime($post['updated_at']))) ?>">
<?php endif; ?>
	
<meta property="article:section" content="<?= htmlspecialchars($post['cat_name']) ?>">   
       
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>
    
<style id="sp-css">
/* ── Single Post Wrapper ───────────────────────── */
.single-post {
  margin: var(--space-3) 0;
}


/* ── Post Hero Card ────────────────────────────── */
.post-hero-card {
  display: flex;
  background: var(--bg-card);
  border-radius: var(--border-radius-md);
  overflow: hidden;
  box-shadow: var(--shadow-md);
  margin-bottom: var(--space-6);
  min-height: 260px;
}

.post-hero-banner {
  width: 50%;
  flex-shrink: 0;
  overflow: hidden;
  background: var(--bg-subtle);
}

.post-banner-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.post-banner-placeholder {
  width: 100%;
  height: 100%;
  min-height: 260px;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 48px;
}

.post-hero-info {
  flex: 1;
  padding: var(--space-6);
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: var(--space-4);
  min-width: 0;
}


/* ── Title ─────────────────────────────────────── */
.single-post h1 {
  font-family: var(--font-heading);
  font-size: var(--text-xl);
  font-weight: var(--weight-bold);
  color: var(--text-primary);
  line-height: var(--leading-tight);
  margin: 0;
}


/* ── Meta Wrap ─────────────────────────────────── */
.meta-wrap {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-family: var(--font-ui);
  font-size: var(--text-sm);
  color: var(--color-primary);
  font-weight: var(--weight-medium);
  flex-wrap: wrap;
  gap: var(--space-2);
}

.meta {
  display: flex;
  gap: var(--space-1);
  flex-wrap: wrap;
  align-items: center;
}

.meta a { color: var(--color-primary); }
.meta a:hover { text-decoration: underline; }

.share-btn {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  background: var(--color-primary-soft);
  border: var(--border-width) solid var(--border-light);
  color: var(--color-primary);
  cursor: pointer;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--border-radius-sm);
  font-size: var(--text-xs);
  font-weight: var(--weight-semibold);
  font-family: var(--font-ui);
  transition: background var(--ease-fast), color var(--ease-fast);
}
.share-btn:hover {
  background: var(--color-primary-light);
  color: var(--color-primary-hover);
}


/* ── Post Layout (70/30) ───────────────────────── */
.post-layout {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: var(--space-6);
  align-items: start;
}

.post-main { min-width: 0; }

.post-sidebar {
  position: sticky;
  top: calc(var(--header-height) + var(--space-4));
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}


/* ── TOC ───────────────────────────────────────── */
.toc {
  background: var(--bg-card);
  border: var(--border-width) solid var(--border-light);
  padding: var(--space-3) var(--space-4);
  margin: 0 0 var(--space-5) 0;
  border-radius: var(--border-radius-sm);
  box-shadow: var(--shadow-sm);
}

.toc h2 {
  font-weight: var(--weight-semibold);
  font-size: var(--text-md);
  line-height: var(--leading-tight);
  font-family: var(--font-ui);
  color: var(--text-primary);
}

.toc a {
  color: var(--text-body);
  font-size: var(--text-sm);
  transition: color var(--ease-fast);
}
.toc a:hover { color: var(--color-primary); text-decoration: underline; }

.toc li { 
color: var(--text-secondary); 
font-size: var(--text-sm); 
margin: var(--space-1) 0; 
}

.toc > ol {
  margin: 0 !important;
  padding-left: 1.2em !important;
  overflow: hidden;
  transition: height .32s cubic-bezier(.4, 0, .2, 1), opacity .32s ease;
}
.toc.collapsed > ol { height: 0 !important; opacity: 0; }

.toc button {
  all: unset;
  cursor: pointer;
  margin-left: var(--space-2);
  font-size: 0.9rem;
  color: var(--color-primary);
  font-weight: var(--weight-medium);
  transition: opacity var(--ease-fast);
}
.toc button:hover { opacity: 0.7; }


/* ── Article Content ───────────────────────────── */
.single-post .content {
  line-height: var(--leading-loose);
  color: var(--text-primary);
  margin-bottom: var(--space-6);
}

.single-post h2 {
  font-size: var(--text-xl);
  font-weight: var(--weight-bold);
  margin-bottom: var(--space-2);
  color: var(--text-primary);
}

.single-post h3 {
  font-size: var(--text-lg);
  font-weight: var(--weight-semibold);
  margin-bottom: var(--space-2);
}

.single-post .content img {
  width: 100%;
  height: auto;
  max-width: 500px;
  border-radius: var(--border-radius-sm);
}

.single-post ul,
.single-post ol {
  margin: var(--space-2) 0 var(--space-2) var(--space-5);
  padding-left: var(--space-5);
}

.single-post li { margin: var(--space-1) 0; }
.single-post ol li[data-list="bullet"] { list-style-type: disc !important; }
.single-post a:hover { text-decoration: underline; }

.single-post blockquote {
  margin: var(--space-4) 0;
  padding: var(--space-3) var(--space-4);
  border-left: 3px solid var(--color-primary);
  background: var(--bg-subtle);
  font-style: italic;
  color: var(--text-secondary);
  border-radius: var(--border-radius-xs);
}

.single-post hr {
  margin: var(--space-3) 0;
  border: none;
  border-top: var(--border-width) solid var(--border-light);
}


/* ── Content Tables (scoped) ───────────────────── */
.single-post .content table {
  border-collapse: collapse;
  width: 100%;
  margin: var(--space-4) 0;
  font-size: var(--text-sm);
}

.single-post .content th,
.single-post .content td {
  border: var(--border-width) solid var(--border-default);
  padding: var(--space-2) var(--space-3);
  text-align: left;
  vertical-align: top;
  word-break: break-word;
}

.single-post .content th {
  background: var(--bg-subtle);
  font-weight: var(--weight-semibold);
  color: var(--text-primary);
}

.single-post .content td { background: var(--bg-card); }


/* ── Quill Classes ─────────────────────────────── */
.ql-align-left    { text-align: left; }
.ql-align-center  { text-align: center; }
.ql-align-right   { text-align: right; }
.ql-align-justify { text-align: justify; }
.ql-table-temporary { display: none !important; }
.single-post .ql-indent-1 { margin-left: 2rem; }
.single-post .ql-indent-2 { margin-left: 4rem; }


/* ── Post FAQ ──────────────────────────────────── */
.pst-faq-cont {
  max-width: 800px;
  margin: var(--space-5) auto;
}

.pst-faq-h {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  text-align: center;
  margin-bottom: var(--space-4);
  color: var(--text-primary);
}

.w {
  background: var(--color-primary-soft);
  border-radius: var(--border-radius-md);
  margin-bottom: var(--space-3);
  overflow: hidden;
  transition: box-shadow var(--ease-normal);
}

.q {
  width: 100%;
  padding: var(--space-2) var(--space-3);
  border: none;
  background: none;
  text-align: left;
  font-weight: var(--weight-medium);
  font-size: var(--text-base);
  color: var(--text-body);
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  outline: none;
  transition: color var(--ease-fast);
}

.q:hover { color: var(--color-primary); }

.a {
  max-height: 0;
  overflow: hidden;
  background: var(--bg-subtle);
  transition: max-height 0.3s ease-out;
  font-size: var(--text-sm);
  color: var(--text-secondary);
  line-height: var(--leading-base);
  padding: 0 var(--space-6);
}

.a p { padding: var(--space-4) 0; }

.sp {
  width: 20px;
  height: 20px;
  min-width: 20px;
  flex-shrink: 0;
  margin-left: var(--space-4);
  transition: transform var(--ease-normal);
  opacity: 0.5;
  stroke: var(--color-primary);
}

/* open state — toggle via JS: add .open to .w */
.w.open .sp { transform: rotate(180deg); opacity: 1; }
.w.open .q  { color: var(--color-primary); font-weight: var(--weight-semibold); }


/* ── Post Tags ─────────────────────────────────── */
.post-tags {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
  margin-top: var(--space-5);
}

.tags-label {
  font-size: var(--text-xs);
  font-weight: var(--weight-semibold);
  color: var(--text-muted);
}

.tag-link {
  font-size: var(--text-xs);
  font-weight: var(--weight-medium);
  color: var(--color-primary);
  background: var(--color-primary-soft);
  border: var(--border-width) solid var(--color-primary-light);
  padding: var(--tag-padding);
  border-radius: var(--border-radius-full);
  transition: background var(--ease-fast);
}
.tag-link:hover { background: var(--color-primary-light); }


/* ── Responsive ────────────────────────────────── */
@media (max-width: 900px) {
  .post-layout { grid-template-columns: 1fr; }
  .post-sidebar { position: static; }
}

@media (max-width: 768px) {
  .post-hero-card { flex-direction: column; min-height: unset; }
  .post-hero-banner { width: 100%; max-height: 220px; }
  .post-banner-img { max-height: 220px; }
  .post-hero-info { padding: var(--space-4); }
  .single-post h1 { font-size: var(--text-lg); }
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
      "name": "<?= htmlspecialchars($post['cat_name']) ?>",
      "item": "<?= htmlspecialchars(SITE_URL . '/categories/' . $post['cat_slug']) ?>"
    },
    {
      "@type": "ListItem",
      "position": 4,
      "name": "<?= htmlspecialchars($post['title']) ?>",
      "item": "<?= SITE_URL . postUrl($post['slug'], $post['id']) ?>"
    }
  ]
}
</script>


<!-- Article + Comments Schema -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BlogPosting",
  "headline": "<?= htmlspecialchars($post['title']) ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "articleSection": "<?= htmlspecialchars($post['cat_name']) ?>",
  "image": {
    "@type": "ImageObject",
    "url": "<?= htmlspecialchars($seo['image'], ENT_QUOTES, 'UTF-8') ?>",
    "width": 1280,
    "height": 720
  },
  "author": {
    "@type": "Person",
    "name": "<?= htmlspecialchars($post['author_name']) ?>",
      "url": "<?= SITE_URL . '/author/' . htmlspecialchars($post['author_slug']) ?>"
  },
  "publisher": {
    "@type": "Organization",
    "name": "<?= SITE_NAME ?>",
    "logo": {
      "@type": "ImageObject",
      "url": "<?= SITE_LOGO ?>"
    }
  },
  "datePublished": "<?= htmlspecialchars(date('c', strtotime($post['date']))) ?>",
  <?php if (!empty($post['updated_at'])): ?>
"dateModified": "<?= htmlspecialchars(date('c', strtotime($post['updated_at']))) ?>",
<?php endif; ?>
  "mainEntityOfPage": {
    "@type": "WebPage",
    "@id": "<?= SITE_URL . postUrl($post['slug'], $post['id']) ?>"
  },
  "url": "<?= SITE_URL . postUrl($post['slug'], $post['id']) ?>",
  "wordCount": <?= str_word_count(strip_tags($post['content'])) ?>,
  "inLanguage": "en-IN",
  "isFamilyFriendly": true
  
    <?php if (!empty($commentTree)): ?>
 , "commentCount": <?= (int)$commentCount ?>,
  "comment": <?php
    $allComments = [];
    $iterator = function($comments) use(&$iterator, &$allComments) {
        foreach ($comments as $c) {
            $allComments[] = [
                "@type" => "Comment",
                "author" => [
                    "@type" => "Person",
                    "name" => htmlspecialchars($c['name'])
                ],
                "datePublished" => date('c', strtotime($c['date'])),
                "text" => htmlspecialchars($c['content'])
            ];
            if (!empty($c['children'])) $iterator($c['children']);
        }
    };
    $iterator($commentTree);
    echo json_encode($allComments, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  ?>
  <?php endif; ?>
}
</script>

<!-- RELATED POSTS SCHEMA -->
<?php if (!empty($related_posts)): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "name": "More from <?= htmlspecialchars($post['cat_name']) ?>",
  "itemListElement": [
    <?php foreach ($related_posts as $i => $rpost): ?>
    {
      "@type": "ListItem",
      "position": <?= $i + 1 ?>,
      "url": "<?= SITE_URL . postUrl($rpost['slug'], $rpost['id']) ?>"
    }<?= $i + 1 < count($related_posts) ? ',' : '' ?>
    <?php endforeach; ?>
  ]
}
</script>
<?php endif; ?>
	
	
</head>
<body>
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
<main>
    	<!-- Breadcrumbs -->
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li><a href="/categories">Categories</a></li>
                <li><a href="/categories/<?= $post['cat_slug'] ?>"><?= htmlspecialchars($post['cat_name']) ?></a></li>
                <li aria-current="page"><?= htmlspecialchars($post['title']) ?></li>
            </ol>
        </nav>
        
        
        <div class="container">  
      
            <article class="single-post">

  <!-- ① Hero Card: Banner + Title/Meta -->
  <div class="post-hero-card">

    <div class="post-hero-banner">
      <?php if (!empty($post['banner_path'])): 
        $r = json_decode($post['banner_responsive'] ?? '[]', true);
        $sizes = ['sm'=>480,'md'=>768,'lg'=>1024,'xl'=>1280];
        $srcset = [];
        foreach ($sizes as $k => $w)
          if (!empty($r[$k])) $srcset[] = "/{$r[$k]} {$w}w";
      ?>
        <img src="/<?= htmlspecialchars($post['banner_path']) ?>"
             alt="<?= htmlspecialchars($post['banner_alt'] ?? $post['title']) ?>"
             class="post-banner-img"
             width="1280" height="720"
             fetchpriority="high" decoding="async"
             <?= !empty($srcset) ? 'srcset="'.htmlspecialchars(implode(', ',$srcset)).'" sizes="(max-width:480px)480px,(max-width:768px)768px,(max-width:1024px)1024px,100vw"' : '' ?>>
      <?php else: ?>
        <div class="post-banner-placeholder">📄</div>
      <?php endif; ?>
    </div>

    <div class="post-hero-info">
      <h1><?= htmlspecialchars($post['title']) ?></h1>
      <div class="meta-wrap">
        <div class="meta">
          <span>By <a href="author/<?= $post['author_slug'] ?>"><?= htmlspecialchars($post['author_name']) ?></a></span>
          <span>/ <?= date('F j, Y', strtotime($post['updated_at'])) ?></span>
        </div>
        <button class="share-btn" id="shareBtn" title="Share this post">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5 15.4 17.5M15.4 6.5 8.6 10.5"/></svg>
          Share
        </button>
      </div>
    </div>

  </div>
  <!-- /Hero Card -->


  <!-- ② Post Layout: 70/30 -->
  <div class="post-layout">

    <!-- Left: Main Content -->
    <div class="post-main">

      <?php if ($toc): ?>
        <?= $toc ?>
      <?php endif; ?>

      <div class="content">
        <?= $content ?>

        <?php
          $d = !empty($post['faq_json']) ? json_decode($post['faq_json'], 1) : [];
          if ($d):
            $s = ["@context"=>"https://schema.org","@type"=>"FAQPage","mainEntity"=>[]];
        ?>
        <div class="pst-faq-cont">
          <h2 class="pst-faq-h">Frequently Asked Questions</h2>
          <?php foreach ($d as $i):
            $s['mainEntity'][] = ["@type"=>"Question","name"=>$i['q'],"acceptedAnswer"=>["@type"=>"Answer","text"=>$i['a']]];
          ?>
            <div class="w">
              <h3 class="q">
                <?= htmlspecialchars($i['q']) ?>
                <svg class="sp" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
              </h3>
              <div class="a"><p><?= nl2br(htmlspecialchars($i['a'])) ?></p></div>
            </div>
          <?php endforeach; ?>
        </div>
        <script type="application/ld+json"><?= json_encode($s) ?></script>
        <?php endif; ?>

      </div><!-- /content -->

      <?php if (!empty($post_tags)): ?>
      <div class="post-tags">
        <span class="tags-label">Tagged in:</span>
        <?php foreach ($post_tags as $tag): ?>
          <a href="<?= tagUrl($tag['slug'], $tag['id']) ?>" class="tag-link">
            <?= htmlspecialchars($tag['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div><!-- /post-main -->


    <!-- Right: Sidebar (add your widgets here) -->
    <aside class="post-sidebar">
      <!-- Author Bio -->
<div class="author-bio">
    <img src="<?= !empty($post['author_image']) ? '/' . htmlspecialchars($post['author_image']) : '/assets/img/user.png' ?>"
         alt="<?= htmlspecialchars($post['author_name']) ?>"
         width="64" height="64"
         style="width:64px;height:64px;border-radius:50%;object-fit:cover;"
         onerror="this.src='/assets/img/user.png'">
    <h2>About <?= htmlspecialchars($post['author_name']) ?></h2>
    <p><?= nl2br(htmlspecialchars($post['author_bio'])) ?><br>
    <a href="author/<?= $post['author_slug'] ?>">Read more about <?= htmlspecialchars($post['author_name']) ?></a></p>
    <div class="author-follow-links">
    	<h3>Follow author on:</h3>
        <?php if (!empty($post['author_instagram'])): ?><a href="<?= htmlspecialchars($post['author_instagram']) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
        <?php if (!empty($post['author_threads'])): ?><a href="<?= htmlspecialchars($post['author_threads']) ?>" target="_blank" rel="noopener">Threads</a><?php endif; ?>
        <?php if (!empty($post['author_linkedin'])): ?><a href="<?= htmlspecialchars($post['author_linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
        <?php if (!empty($post['author_facebook'])): ?><a href="<?= htmlspecialchars($post['author_facebook']) ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
        <?php if (!empty($post['author_twitter'])): ?><a href="<?= htmlspecialchars($post['author_twitter']) ?>" target="_blank" rel="noopener">X</a><?php endif; ?>
    </div>
</div>
                
            
<!-- Related Posts from Same Category -->
<section class="related-posts">
    <h2>More from <?= htmlspecialchars($post['cat_name']) ?></h2>
    <?php if (empty($related_posts)): ?>
        <p>No related posts found.</p>
    <?php else: foreach ($related_posts as $rpost): ?>
        <a href="<?= postUrl($rpost['slug'],$rpost['id']) ?>" class="related-post">
            <?php if ($rpost['banner_path']): ?>
                <img src="/<?= htmlspecialchars($rpost['banner_path']) ?>" alt="<?= htmlspecialchars($rpost['banner_alt'] ?? $rpost['title']) ?>" loading="lazy" width="400" height="250">
            <?php endif; ?>
            <div class="related-post-info">
                <div class="related-post-title"><?= htmlspecialchars($rpost['title']) ?></div>
                <div class="meta">
                    <span><?= htmlspecialchars($rpost['author_name']) ?></span>
                    <span>/ <?= date('F j, Y',strtotime($rpost['date'])) ?></span>
                </div>
            </div>
        </a>
    <?php endforeach; endif; ?>
</section>

<!-- Comment Form -->
<section class="blog-comment-form-container">
    <h2>Leave a Comment</h2>
    
    <div id="blog-comment-alert-container" class="blog-comment-alert-container"></div>
    
    <div id="blog-comment-replying-to" class="blog-comment-replying-to">
        <span>Replying to <strong id="blog-comment-reply-to-name"></strong></span>
        <button type="button" class="blog-comment-cancel-reply">&#10005; Cancel</button>
    </div>
    
    <form id="blog-comment-form" data-post-id="<?= $id ?>">
    	<input type="hidden" name="cTkn" value="" id="cTkn-inpt">
        <div class="blog-comment-form-group">
            <label for="blog-comment-name">Name *</label>
            <input type="text" 
                   id="blog-comment-name" 
                   name="name" 
                   value="" 
                   required>
        </div>
        
        <div class="blog-comment-form-group">
            <label for="blog-comment-email">Email *</label>
            <input type="email" 
                   id="blog-comment-email" 
                   name="email" 
                   value="" 
                   required>
        </div>
        
        <div class="blog-comment-form-group">
            <label for="blog-comment-content">Comment *</label>
            <textarea id="blog-comment-content" 
                      name="content" 
                      required 
                      placeholder="Share your thoughts..."></textarea>
        </div>
        
        <div class="blog-comment-remember">
            <input type="checkbox" id="blog-comment-remember" name="remember" checked>
            <label for="blog-comment-remember">Remember me for next time</label>
        </div>
        
        <input type="hidden" id="blog-comment-parent-id" name="parent_id" value="">
        <input type="hidden" name="post_id" value="<?= $id ?>">
        <input type="hidden" name="ajax" value="1">
        
        <button type="submit" class="blog-comment-submit-btn" id="blog-comment-submit-btn">
            Post Comment
            <span class="blog-comment-loading-spinner" id="blog-comment-loading-spinner"></span>
        </button>
    </form>
</section>



<!-- Comments Section -->
<section class="blog-comments-section">
    <h2>Comments (<?= $commentCount ?>)</h2>
    <div id="blog-comments-container" class="blog-comments-container">
        <?php if (empty($commentTree)): ?>
        <p>No comments yet. Be the first to comment!</p>
        <?php else: ?>
     <?php renderComments($commentTree); ?>
        <?php endif; ?>
    </div>
 
 <?php if ($commentCount > 5): ?>
<div class="load-more-btn-cont">
    <button id="load-more-comments" class="load-more-cmt-btn" data-offset="5">Show more &#65291;</button>
</div>
<?php endif; ?>
</section>

    </aside>

  </div><!-- /post-layout -->

</article>
            

        </div>
    </main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>

<script id="post" src="/assets/js/post.min.js?v=22052026" defer></script>
</body>
</html>
<?php
$minified = minifyHTML(ob_get_clean());
if (!$__cs['disabled'] && !$__cache_excluded) {
    file_put_contents($cf, $minified) or error_log("Failed to write cache: $cf");
}
echo $minified;
?>