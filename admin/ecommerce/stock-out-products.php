<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_products'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$notifications = [];

if (isset($_GET['restock']) && is_numeric($_GET['restock']) && isset($_GET['qty'])) {
    $qty = max(0, (int)$_GET['qty']);
    $pdo->prepare("UPDATE ecom_products SET stock_qty=? WHERE id=?")->execute([$qty, (int)$_GET['restock']]);
    header("Location: /admin/ecommerce/stock-out-products.php?success=restocked");
    exit;
}

if (isset($_GET['success']) && $_GET['success'] === 'restocked') {
    $notifications[] = ['type' => 'success', 'message' => 'Stock updated successfully!'];
}

$products = $pdo->query("
    SELECT * FROM ecom_products
    WHERE product_type = 'physical' AND (stock_qty IS NULL OR stock_qty <= 0)
    ORDER BY updated_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Stock Out Products';
$page_subtitle = 'Physical products that are currently out of stock';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading-mini">
                    <h1><?= htmlspecialchars($page_title) ?></h1>
                    <p><?= htmlspecialchars($page_subtitle) ?></p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Stock Out Products</b> <span class="badge bg-danger"><?= count($products) ?></span></h3>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <?php if (empty($products)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-box-open fa-3x mb-3"></i>
                        <p>Nothing out of stock right now — nice!</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th width="30%">Name</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Stock</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($p['image'])): ?>
                                        <img src="/<?= htmlspecialchars($p['image']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="dt-thumb-placeholder"><i class="fas fa-image"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td>₹<?= number_format((!empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price']) ? (float)$p['sale_price'] : (float)$p['price'], 2) ?></td>
                                <td><span class="badge <?= $p['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst($p['status']) ?></span></td>
                                <td><span class="badge bg-danger">0 in stock</span></td>
                                <td>
                                    <div class="action-list">
                                        <button class="btn btn-success btn-sm" title="Restock" onclick="openRestockModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')"><i class="fas fa-boxes-packing"></i></button>
                                        <a class="btn btn-primary btn-sm" href="add-product-form.php?edit=<?= $p['id'] ?>"><i class="fas fa-edit"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Restock Modal -->
<div class="modal fade" id="restockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="GET">
                <div class="modal-header">
                    <h5 class="modal-title">Restock "<span id="restockName"></span>"</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="restock" id="restockId">
                    <label class="form-label">New Stock Quantity</label>
                    <input type="number" min="1" name="qty" class="form-control" value="10" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
$(document).ready(function () {
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [0, 5] }] });
});
function openRestockModal(id, name) {
    document.getElementById('restockId').value = id;
    document.getElementById('restockName').textContent = name;
    new bootstrap.Modal(document.getElementById('restockModal')).show();
}
</script>
</body>
</html>
