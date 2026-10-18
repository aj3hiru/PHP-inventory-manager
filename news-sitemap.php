<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/functions.php';

// Set correct content type
header('Content-Type: application/xml; charset=utf-8');

// --- GOOGLE NEWS SETTINGS ---
// IMPORTANT: Update these to match your Google News Publisher Center settings
$publication_name = "EduMint24"; // e.g., "The Daily News"
$publication_language = "en"; // e.g., "en" for English, "hi" for Hindi

// Get site URL 
$site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$base_url = rtrim($site_url, '/');

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
    
<?php
// Google News Sitemaps should ONLY contain articles published in the last 48 hours.
// We use 'p.date >= DATE_SUB(NOW(), INTERVAL 2 DAY)' to filter older posts.
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.slug,
        p.title,
        p.date
    FROM posts p 
    WHERE p.status = 'published'
    AND p.date >= DATE_SUB(NOW(), INTERVAL 2 DAY)
    ORDER BY p.date DESC
");
$stmt->execute();

while ($post = $stmt->fetch(PDO::FETCH_ASSOC)):
    $post_url = $base_url . postUrl($post['slug'], $post['id']);
    // Google News requires W3C Datetime format, usually based on the original publish date
    $pub_date = gmdate('c', strtotime($post['date']));
?>
    <url>
        <loc><?= htmlspecialchars($post_url) ?></loc>
        <news:news>
            <news:publication>
                <news:name><?= htmlspecialchars($publication_name) ?></news:name>
                <news:language><?= htmlspecialchars($publication_language) ?></news:language>
            </news:publication>
            <news:publication_date><?= $pub_date ?></news:publication_date>
            <news:title><?= htmlspecialchars($post['title']) ?></news:title>
        </news:news>
    </url>
<?php endwhile; ?>

</urlset>
