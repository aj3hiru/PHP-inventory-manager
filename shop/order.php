<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: /shop/login.php?redirect=' . urlencode('/shop/order.php?id=' . (int)($_GET['id'] ?? 0)));
    exit;
}

$page_title = 'Order Details';
include __DIR__ . '/includes/shop-header.php';

$order_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM ecom_orders WHERE id = ? AND customer_id = ?");
$stmt->execute([$order_id, $shop_customer['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) { http_response_code(404); exit('Order not found.'); }

$items = $pdo->prepare("SELECT * FROM ecom_order_items WHERE order_id = ?");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$statuses = ['Pending', 'In Progress', 'Delivered', 'Canceled'];
$current_step = array_search($order['order_status'], $statuses);
?>

<div class="shop-container" style="max-width:800px;">

    <?php if (isset($_GET['placed'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Your order has been placed successfully!</div>
    <?php endif; ?>

    <h2 class="section-title">Order <?= htmlspecialchars($order['order_number']) ?></h2>

    <?php if ($order['order_status'] !== 'Canceled'): ?>
    <div class="d-flex justify-content-between mb-4 text-center">
        <?php foreach (['Pending', 'In Progress', 'Delivered'] as $i => $s): ?>
        <div style="flex:1;">
            <div style="width:36px;height:36px;border-radius:50%;margin:0 auto 0.4rem;display:flex;align-items:center;justify-content:center;
                background:<?= $i <= $current_step ? 'var(--primary)' : '#e5e7eb' ?>; color:#fff;">
                <i class="fas fa-check"></i>
            </div>
            <div class="small"><?= $s ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="alert alert-danger">This order was canceled.</div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>GST</th><th class="text-end">Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['product_name']) ?></td>
                        <td><?= (int)$it['qty'] ?></td>
                        <td>₹<?= number_format((float)$it['price'], 2) ?></td>
                        <td><?= number_format((float)($it['gst_rate'] ?? 0), 2) ?>%</td>
                        <td class="text-end">₹<?= number_format((float)$it['price'] * (int)$it['qty'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php if ((float)($order['discount_amount'] ?? 0) > 0): ?>
                        <tr><td colspan="4" class="text-end">Discount</td><td class="text-end">-₹<?= number_format((float)$order['discount_amount'], 2) ?></td></tr>
                        <?php endif; ?>
                        <?php if ((float)($order['gst_amount'] ?? 0) > 0): ?>
                        <tr><td colspan="4" class="text-end">GST</td><td class="text-end">+₹<?= number_format((float)$order['gst_amount'], 2) ?></td></tr>
                        <?php endif; ?>
                        <tr><td colspan="4" class="text-end fw-bold">Total</td><td class="text-end fw-bold">₹<?= number_format((float)$order['total_amount'], 2) ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <p class="mb-1"><strong>Payment Status:</strong> <span class="badge <?= $order['payment_status'] === 'Paid' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($order['payment_status']) ?></span></p>
            <p class="mb-1"><strong>Order Date:</strong> <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></p>
            <p class="mb-0"><strong>Delivery Address:</strong> <?= nl2br(htmlspecialchars($shop_customer['address'] ?? '—')) ?></p>
        </div>
    </div>

    <a href="/shop/account.php#orders" class="btn btn-outline-shop mt-3"><i class="fas fa-arrow-left"></i> Back to My Orders</a>
</div>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
