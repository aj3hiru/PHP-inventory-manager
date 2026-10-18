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

$name = trim($_POST['name'] ?? '');
if ($name === '') { echo json_encode(['success' => false, 'message' => 'Brand name is required.']); exit; }

$slug = generateSlug($name);
$base_slug = $slug;
$i = 1;
while (true) {
    $chk = $pdo->prepare("SELECT id FROM ecom_brands WHERE slug = ?");
    $chk->execute([$slug]);
    if (!$chk->fetch()) break;
    $slug = $base_slug . '-' . (++$i);
}

define('BRAND_UPLOAD_URL', 'uploads/ecommerce/brands/');
define('BRAND_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/brands/');

$logo_path = null;
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    if (!file_exists(BRAND_UPLOAD_ABS)) mkdir(BRAND_UPLOAD_ABS, 0777, true);
    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    $filename = 'brand-' . uniqid() . '.' . $ext;
    if (move_uploaded_file($_FILES['logo']['tmp_name'], BRAND_UPLOAD_ABS . $filename)) {
        $logo_path = BRAND_UPLOAD_URL . $filename;
    }
}

try {
    $pdo->prepare("INSERT INTO ecom_brands (name, slug, logo, status, is_popular) VALUES (?,?,?,?,?)")
        ->execute([$name, $slug, $logo_path, 'active', 0]);
    $new_id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_brand_create', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], "Created Brand (via Add Product): $name (ID: $new_id)", $log_ip, $log_ua]);

    echo json_encode(['success' => true, 'id' => $new_id, 'name' => $name]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'A brand with this name may already exist.']);
}
