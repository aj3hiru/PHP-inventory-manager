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

// ── Change status ────────────────────────────────────────────────────────────
if (isset($_GET['set_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $valid = ['pending', 'approved', 'rejected'];
    $new_status = in_array($_GET['set_status'], $valid, true) ? $_GET['set_status'] : 'pending';
    $pdo->prepare("UPDATE ecom_product_reviews SET status=? WHERE id=?")->execute([$new_status, (int)$_GET['id']]);
    header("Location: /admin/ecommerce/product-reviews.php");
    exit;
}

// ── Delete ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    $pdo->prepare("DELETE FROM ecom_product_reviews WHERE id=?")->execute([$del_id]);
    header("Location: /admin/ecommerce/product-reviews.php?success=deleted");
    exit;
}

if (isset($_GET['success']) && $_GET['success'] === 'deleted') {
    $notifications[] = ['type' => 'success', 'message' => 'Review deleted successfully!'];
}

$reviews = $pdo->query("
    SELECT r.*, p.name AS product_name
    FROM ecom_product_reviews r
    LEFT JOIN ecom_products p ON r.product_id = p.id
    ORDER BY r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_pending = count(array_filter($reviews, fn($r) => $r['status'] === 'pending'));

$page_title = 'Product Reviews';
$page_subtitle = 'Moderate customer reviews left on your products';
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

            <div class="stat-mini-grid">
                <div class="stat-mini"><div class="val"><?= number_format(count($reviews)) ?></div><div class="lbl">Total Reviews</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--warning);"><?= number_format($total_pending) ?></div><div class="lbl">Pending</div></div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Product Reviews</b></h3>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <?php if (empty($reviews)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-star-half-alt fa-3x mb-3"></i>
                        <p>No reviews yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Product</th>
                                    <th>Rating</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reviews as $r): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($r['customer_name']) ?>
                                    <?php if (!empty($r['review_text'])): ?>
                                        <div class="text-muted small mt-1" style="max-width:280px;"><?= htmlspecialchars(mb_strimwidth($r['review_text'], 0, 100, '…')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['product_name'] ?? '—') ?></td>
                                <td class="star-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?= $i <= (int)$r['rating'] ? 'fas' : 'far' ?> fa-star"></i>
                                    <?php endfor; ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <?php
                                        $statusClasses = ['pending' => 'btn-warning-status', 'approved' => 'btn-success', 'rejected' => 'btn-danger-status'];
                                        $statusClass = $statusClasses[$r['status']] ?? 'btn-secondary-status';
                                        ?>
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $statusClass ?>" type="button" data-bs-toggle="dropdown">
                                            <?= ucfirst($r['status']) ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?set_status=approved&id=<?= $r['id'] ?>">Approve</a></li>
                                            <li><a class="dropdown-item" href="?set_status=pending&id=<?= $r['id'] ?>">Pending</a></li>
                                            <li><a class="dropdown-item" href="?set_status=rejected&id=<?= $r['id'] ?>">Reject</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['customer_name'])) ?>')"><i class="fas fa-trash-alt"></i></button>
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

<div class="modal fade" id="confirm-delete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete?</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Delete the review from "<strong id="deleteReviewName"></strong>"? This cannot be undone.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteReviewId">
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
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [2, 3, 4] }] });
});
function openDeleteModal(id, name) {
    document.getElementById('deleteReviewId').value = id;
    document.getElementById('deleteReviewName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}
</script>
</body>
</html>
