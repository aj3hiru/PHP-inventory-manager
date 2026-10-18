<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Access denied.']); exit; }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_products'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']); exit;
}

$label = trim($_POST['label'] ?? '');
if ($label === '') { echo json_encode(['success' => false, 'message' => 'Item type name is required.']); exit; }

$slug = generateSlug($label);
$base_slug = $slug;
$i = 1;
while (true) {
    $chk = $pdo->prepare("SELECT id FROM ecom_product_tags WHERE tag_group='item_type' AND slug = ?");
    $chk->execute([$slug]);
    if (!$chk->fetch()) break;
    $slug = $base_slug . '-' . (++$i);
}

try {
    $pdo->prepare("INSERT INTO ecom_product_tags (tag_group, label, slug, status) VALUES ('item_type', ?, ?, 'active')")
        ->execute([$label, $slug]);
    $new_id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_item_type_create', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], "Created Item Type (via Add Product): $label (ID: $new_id)", $log_ip, $log_ua]);

    echo json_encode(['success' => true, 'slug' => $slug, 'label' => $label]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An item type with this name may already exist.']);
}
