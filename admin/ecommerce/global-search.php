<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode([]); exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$results = [];

// ── Orders: match by order number ────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, order_number, customer_name, total_amount, order_status
    FROM ecom_orders
    WHERE order_number LIKE ?
    ORDER BY created_at DESC LIMIT 5
");
$stmt->execute(["%$q%"]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $results[] = [
        'type'  => 'order',
        'id'    => $o['id'],
        'title' => $o['order_number'],
        'sub'   => ($o['customer_name'] ?: 'Walk-in') . ' · ₹' . number_format((float)$o['total_amount'], 2) . ' · ' . $o['order_status'],
        'url'   => '/admin/ecommerce/order-view.php?id=' . $o['id'],
    ];
}

// ── Customers: match by name, phone, or numeric ID ───────────────────────────
$stmt = $pdo->prepare("
    SELECT id, name, phone, email, customer_type,
           (SELECT COALESCE(SUM(amount - amount_paid), 0) FROM ecom_credits WHERE customer_id = ecom_customers.id AND status = 'pending') AS due_amount
    FROM ecom_customers
    WHERE name LIKE ? OR phone LIKE ? OR (? REGEXP '^[0-9]+$' AND id = ?)
    ORDER BY created_at DESC LIMIT 5
");
$like = "%$q%";
$stmt->execute([$like, $like, $q, (int)$q]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $due_note = (float)$c['due_amount'] > 0 ? ' · Due ₹' . number_format((float)$c['due_amount'], 2) : '';
    $results[] = [
        'type'  => 'customer',
        'id'    => $c['id'],
        'title' => $c['name'] . ' (#' . $c['id'] . ')',
        'sub'   => ($c['phone'] ?: $c['email'] ?: '') . ' · ' . ucfirst($c['customer_type']) . $due_note,
        'url'   => '/admin/ecommerce/customer-profile.php?id=' . $c['id'],
    ];
}

// ── Payment receipts: match by receipt number ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT cp.receipt_number, cp.amount, cp.created_at, c.customer_name
    FROM ecom_credit_payments cp
    JOIN ecom_credits c ON cp.credit_id = c.id
    WHERE cp.receipt_number LIKE ?
    ORDER BY cp.created_at DESC LIMIT 5
");
$stmt->execute(["%$q%"]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $results[] = [
        'type'  => 'receipt',
        'id'    => $r['receipt_number'],
        'title' => $r['receipt_number'],
        'sub'   => $r['customer_name'] . ' · ₹' . number_format((float)$r['amount'], 2) . ' · ' . date('d M Y', strtotime($r['created_at'])),
        'url'   => '/admin/ecommerce/payment-receipt.php?receipt=' . urlencode($r['receipt_number']) . '&return_to=' . urlencode('/admin/dashboard.php'),
    ];
}

echo json_encode($results);
