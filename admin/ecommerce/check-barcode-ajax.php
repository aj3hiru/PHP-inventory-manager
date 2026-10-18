<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['exists' => false]); exit; }

$barcode = trim($_GET['barcode'] ?? '');
$exclude_id = (int)($_GET['exclude_id'] ?? 0);

if ($barcode === '') { echo json_encode(['exists' => false]); exit; }

$stmt = $pdo->prepare("SELECT id, name FROM ecom_products WHERE barcode = ? AND id != ? LIMIT 1");
$stmt->execute([$barcode, $exclude_id]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

if ($match) {
    echo json_encode(['exists' => true, 'id' => $match['id'], 'name' => $match['name']]);
} else {
    echo json_encode(['exists' => false]);
}
