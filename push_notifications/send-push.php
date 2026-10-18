<?php
// send-push.php

require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method Not Allowed']));
}

$token = $_SERVER['HTTP_X_PUSH_TOKEN'] ?? '';
if (!hash_equals($config['push_secret'], $token)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Invalid token']));
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$title  = trim($input['title']  ?? '');
$body   = trim($input['body']   ?? '');
$image  = trim($input['image']  ?? '');
$url    = trim($input['url']    ?? 'https://edumint24.com');
$post_id = !empty($input['post_id']) ? (int)$input['post_id'] : null;

if (empty($title) || empty($url)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Title and URL are required']));
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO push_campaigns 
        (post_id, title, body, image, url, status, total_subscribers) 
        VALUES (?, ?, ?, ?, ?, 'pending', 
            (SELECT COUNT(*) FROM push_subscriptions)
        )
    ");
    $stmt->execute([$post_id, $title, $body, $image, $url]);
    $campaign_id = $pdo->lastInsertId();

    $pdo->exec("
        INSERT INTO push_queue (campaign_id, subscription_id, status)
        SELECT $campaign_id, id, 'pending'
        FROM push_subscriptions
    ");

    $pdo->commit();

    http_response_code(202);
    echo json_encode([
        'success' => true,
        'message' => 'Push campaign queued successfully',
        'campaign_id' => $campaign_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to queue campaign: ' . $e->getMessage()
    ]);
}