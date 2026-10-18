<?php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.slug, m.file_path AS banner_image
    FROM posts p
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.status = 'published'
    AND p.title LIKE ?
    ORDER BY p.date DESC
    LIMIT 50
");

$stmt->execute(['%' . $q . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];

foreach ($rows as $post) {
    $url = postUrl($post['slug'], $post['id']);
    if (strpos($url, 'http') !== 0) {
        $url = rtrim($site_url, '/') . '/' . ltrim($url, '/');
    }

    $img = $post['banner_image'] ? '/' . $post['banner_image'] : '/assets/img/seo_og_default.png';

    $data[] = [
        'id' => $post['id'],
        'title' => $post['title'],
        'image' => $img,
        'url' => $url
    ];
}

echo json_encode($data, JSON_UNESCAPED_SLASHES);