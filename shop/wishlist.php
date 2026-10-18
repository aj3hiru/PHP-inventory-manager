<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: /shop/login.php?redirect=' . urlencode('/shop/wishlist.php'));
    exit;
}

$page_title = 'My Wishlist';
include __DIR__ . '/includes/shop-header.php';
include __DIR__ . '/includes/product-card.php';

$stmt = $pdo->prepare("
    SELECT p.* FROM ecom_wishlist w
    JOIN ecom_products p ON w.product_id = p.id
    WHERE w.customer_id = ? ORDER BY w.created_at DESC
");
$stmt->execute([$shop_customer['id']]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$wishlisted_ids = array_column($products, 'id');
?>

<div class="shop-container">
    <h2 class="section-title">My Wishlist</h2>

    <?php if (empty($products)): ?>
        <div class="text-center py-5 text-muted">
            <i class="far fa-heart fa-3x mb-3"></i>
            <p>Your wishlist is empty.</p>
            <a href="/" class="btn btn-shop-primary">Browse Products</a>
        </div>
    <?php else: ?>
    <div class="product-grid">
        <?php foreach ($products as $p) { renderCmartProductCard($p); } ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
