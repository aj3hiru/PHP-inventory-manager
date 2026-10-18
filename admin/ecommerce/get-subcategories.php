<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode([]); exit; }

$cid = (int)($_GET['category_id'] ?? 0);
if ($cid <= 0) { echo json_encode([]); exit; }

$q = $pdo->prepare("SELECT id, name FROM ecom_subcategories WHERE category_id = ? ORDER BY name ASC");
$q->execute([$cid]);
echo json_encode($q->fetchAll(PDO::FETCH_ASSOC));
