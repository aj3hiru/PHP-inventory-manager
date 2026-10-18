<?php
// save-subscriptions.php
header('Content-Type: application/json');
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Handle subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subscription = json_decode(file_get_contents('php://input'), true);
    if ($subscription) {
        try {
            $stmt = $pdo->prepare('INSERT INTO push_subscriptions (subscription) VALUES (?)');
            $stmt->execute([json_encode($subscription)]);
            echo json_encode(['status' => 'success']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);