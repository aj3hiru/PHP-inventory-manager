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

// ── Quick Publish/Unpublish ─────────────────────────────────────────────────
if (isset($_GET['set_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $new_status = $_GET['set_status'] === 'inactive' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE ecom_products SET status=? WHERE id=?")->execute([$new_status, (int)$_GET['id']]);
    header("Location: /admin/ecommerce/products.php");
    exit;
}

// ── Handle DELETE ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    try {
        $row = $pdo->prepare("SELECT name, image FROM ecom_products WHERE id=?");
        $row->execute([$del_id]);
        $p = $row->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("DELETE FROM ecom_products WHERE id=?")->execute([$del_id]);

        if (!empty($p['image']) && file_exists(DROOT_PATH . '/' . $p['image'])) {
            @unlink(DROOT_PATH . '/' . $p['image']);
        }

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_product_delete', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Deleted Product: " . ($p['name'] ?? 'Unknown') . " (ID: $del_id)", $log_ip, $log_ua]);

        header("Location: /admin/ecommerce/products.php?success=deleted");
        exit;
    } catch (Exception $e) {
        header("Location: /admin/ecommerce/products.php?error=1");
        exit;
    }
}

if (isset($_GET['success'])) {
    $map = ['created' => 'Product created successfully!', 'updated' => 'Product updated successfully!', 'deleted' => 'Product deleted successfully!'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
}
if (isset($_GET['error'])) $notifications[] = ['type' => 'error', 'message' => 'Delete failed. Please try again.'];

$products = $pdo->query("SELECT * FROM ecom_products ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$total_all      = count($products);
$total_active   = count(array_filter($products, fn($p) => $p['status'] === 'active'));
$total_outstock = count(array_filter($products, fn($p) => $p['product_type'] === 'physical' && (int)$p['stock_qty'] <= 0));

$page_title = 'All Products';
$page_subtitle = 'Manage everything you sell in your store';
$badge_tag_rows = $pdo->query("SELECT slug, label, color FROM ecom_product_tags WHERE tag_group='badge'")->fetchAll(PDO::FETCH_ASSOC);
$badge_labels = [];
$badge_colors = [];
foreach ($badge_tag_rows as $bt) { $badge_labels[$bt['slug']] = $bt['label']; $badge_colors[$bt['slug']] = $bt['color']; }
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
            <div class="alert alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="stat-mini-grid">
                <div class="stat-mini"><div class="val"><?= number_format($total_all) ?></div><div class="lbl">Total Products</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--success);"><?= number_format($total_active) ?></div><div class="lbl">Published</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--danger);"><?= number_format($total_outstock) ?></div><div class="lbl">Out of Stock</div></div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>All Products</b></h3>
                        <a href="add-product-form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add</a>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th width="25%">Name</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Item Type</th>
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
                                <td>
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ((int)$p['stock_qty'] <= 0 && $p['product_type'] === 'physical'): ?>
                                        <span class="badge bg-danger ms-1">Out of stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $has_sale = !empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price']; ?>
                                    ₹<?= number_format($has_sale ? (float)$p['sale_price'] : (float)$p['price'], 2) ?>
                                    <?php if ($has_sale): ?><br><small class="text-muted text-decoration-line-through">₹<?= number_format((float)$p['price'], 2) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $p['status'] === 'active' ? 'btn-success' : 'btn-secondary-status' ?>" type="button" data-bs-toggle="dropdown">
                                            <?= $p['status'] === 'active' ? 'Publish' : 'Unpublish' ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?set_status=active&id=<?= $p['id'] ?>">Publish</a></li>
                                            <li><a class="dropdown-item" href="?set_status=inactive&id=<?= $p['id'] ?>">Unpublish</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <?php $bcolor = $badge_colors[$p['badge_tag']] ?? null; ?>
                                    <?php if ($p['badge_tag'] === 'none' || !$bcolor): ?>
                                        <span class="text-muted small"><?= htmlspecialchars($badge_labels[$p['badge_tag']] ?? 'None') ?></span>
                                    <?php else: ?>
                                        <span class="badge-tag" style="background:<?= htmlspecialchars($bcolor) ?>;color:#fff;padding:0.2rem 0.65rem;border-radius:9999px;font-size:0.75rem;font-weight:700;"><?= htmlspecialchars($badge_labels[$p['badge_tag']] ?? $p['badge_tag']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= ucfirst($p['item_type']) ?></td>
                                <td>
                                    <div class="action-list">
                                        <a class="btn btn-secondary btn-sm" href="barcode-print.php?ids=<?= $p['id'] ?>" target="_blank" title="Print Barcode"><i class="fas fa-barcode"></i></a>
                                        <a class="btn btn-primary btn-sm" href="add-product-form.php?edit=<?= $p['id'] ?>"><i class="fas fa-edit"></i></a>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Confirm Delete Modal -->
<div class="modal fade" id="confirm-delete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete?</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">You are going to delete "<strong id="deleteProdName"></strong>". Do you want to delete it?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteProdId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
$(document).ready(function () {
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [0, 6] }] });
});
function openDeleteModal(id, name) {
    document.getElementById('deleteProdId').value = id;
    document.getElementById('deleteProdName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}
</script>
</body>
</html>
