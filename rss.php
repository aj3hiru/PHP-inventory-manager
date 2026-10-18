<?php
// rss.php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/functions.php';
header('Content-Type: application/rss+xml; charset=utf-8');

$base_url = rtrim($site_url, '/');
$site_title = $site_name;
$site_desc  = $seo_description;
$now = gmdate('D, d M Y H:i:s') . ' GMT';  // RFC-822

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\">
<channel>
<title>{$site_title}</title>
<link>{$base_url}</link>
<description>{$site_desc}</description>
<language>en-us</language>
<generator>PHP</generator>
<lastBuildDate>{$now}</lastBuildDate>
<atom:link href=\"{$base_url}/rss.xml\" rel=\"self\" type=\"application/rss+xml\"/>";

$stmt = $pdo->prepare("
    SELECT p.id, p.slug, p.title, p.content, p.date, p.updated_at,
           m.file_path AS img, m.alt_text AS alt
    FROM posts p
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.status = 'published'
    ORDER BY p.date DESC LIMIT 20
");
$stmt->execute();

while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $url = $base_url . postUrl($p['slug'], $p['id']);
    $pub = gmdate('D, d M Y H:i:s', strtotime($p['date'])) . ' GMT';
    $mod = $p['updated_at'] ? gmdate('c', strtotime($p['updated_at'])) : $pub;

    $text = strip_tags($p['content']);
    $excerpt = mb_substr($text, 0, 160);
    if (mb_strlen($text) > 160) $excerpt .= '...';

    $title = htmlspecialchars($p['title'], ENT_XML1, 'UTF-8');
    $excerpt = htmlspecialchars($excerpt, ENT_XML1, 'UTF-8');
    $content = htmlspecialchars($text, ENT_XML1, 'UTF-8');

    $encl = $p['img'] ? '<enclosure url="'.htmlspecialchars($base_url.'/'.ltrim($p['img'],'/'),ENT_XML1).'" length="0" type="image/jpeg"/>' : '';

    echo "\n<item>
<title>{$title}</title>
<link>{$url}</link>
<guid isPermaLink=\"true\">{$url}</guid>
<pubDate>{$pub}</pubDate>
<description>{$excerpt}</description>
{$encl}
<content:encoded><![CDATA[{$content}]]></content:encoded>
</item>";
}

echo "\n</channel>\n</rss>";