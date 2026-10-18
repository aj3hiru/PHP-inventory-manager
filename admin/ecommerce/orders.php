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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_orders'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$notifications = [];
$valid_order_statuses = ['Pending', 'In Progress', 'Delivered', 'Canceled'];
$valid_payment_statuses = ['Unpaid', 'Paid'];

// ── Change order status ──────────────────────────────────────────────────────
if (isset($_GET['set_order_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    if (in_array($_GET['set_order_status'], $valid_order_statuses, true)) {
        $pdo->prepare("UPDATE ecom_orders SET order_status=? WHERE id=?")->execute([$_GET['set_order_status'], (int)$_GET['id']]);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    exit;
}

// ── Change payment status ────────────────────────────────────────────────────
if (isset($_GET['set_payment_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    if (in_array($_GET['set_payment_status'], $valid_payment_statuses, true)) {
        $pdo->prepare("UPDATE ecom_orders SET payment_status=? WHERE id=?")->execute([$_GET['set_payment_status'], (int)$_GET['id']]);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    exit;
}

// ── Delete ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    $pdo->prepare("DELETE FROM ecom_orders WHERE id=?")->execute([$del_id]);
    header("Location: /admin/ecommerce/orders.php?success=deleted");
    exit;
}

if (isset($_GET['success']) && $_GET['success'] === 'deleted') {
    $notifications[] = ['type' => 'success', 'message' => 'Order deleted successfully!'];
}

// ── Filter by type ────────────────────────────────────────────────────────────
$type = $_GET['type'] ?? '';
$where = "WHERE o.order_type = 'online'";
$params = [];
$heading = 'All Orders';
if (in_array($type, $valid_order_statuses, true)) {
    $where .= " AND o.order_status = ?";
    $params[] = $type;
    $heading = $type . ' Orders';
}

$stmt = $pdo->prepare("
    SELECT o.*,
           c.address AS customer_saved_address,
           (SELECT GROUP_CONCAT(CONCAT(oi.qty, 'x ', oi.product_name) SEPARATOR ', ')
              FROM ecom_order_items oi WHERE oi.order_id = o.id) AS items_summary
    FROM ecom_orders o
    LEFT JOIN ecom_customers c ON o.customer_id = c.id
    $where ORDER BY o.created_at DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_all       = (int) $pdo->query("SELECT COUNT(*) FROM ecom_orders WHERE order_type='online'")->fetchColumn();
$total_pending   = (int) $pdo->query("SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='Pending'")->fetchColumn();
$total_progress  = (int) $pdo->query("SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='In Progress'")->fetchColumn();
$total_delivered = (int) $pdo->query("SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='Delivered'")->fetchColumn();
$total_canceled  = (int) $pdo->query("SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='Canceled'")->fetchColumn();

$page_title = $heading;
$page_subtitle = 'Track and manage customer orders';
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
                <?php if (!empty($permissions['ecommerce']['manage_billing'])): ?>
                <a href="billing.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Create Order</a>
                <?php endif; ?>
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
                <a href="orders.php" class="text-decoration-none"><div class="stat-mini"><div class="val"><?= number_format($total_all) ?></div><div class="lbl">All Orders</div></div></a>
                <a href="orders.php?type=Pending" class="text-decoration-none"><div class="stat-mini"><div class="val" style="color:var(--warning);"><?= number_format($total_pending) ?></div><div class="lbl">Pending</div></div></a>
                <a href="orders.php?type=In+Progress" class="text-decoration-none"><div class="stat-mini"><div class="val" style="color:var(--info);"><?= number_format($total_progress) ?></div><div class="lbl">In Progress</div></div></a>
                <a href="orders.php?type=Delivered" class="text-decoration-none"><div class="stat-mini"><div class="val" style="color:var(--success);"><?= number_format($total_delivered) ?></div><div class="lbl">Delivered</div></div></a>
                <a href="orders.php?type=Canceled" class="text-decoration-none"><div class="stat-mini"><div class="val" style="color:var(--danger);"><?= number_format($total_canceled) ?></div><div class="lbl">Canceled</div></div></a>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b><?= htmlspecialchars($heading) ?></b></h3>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <?php if (empty($orders)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-receipt fa-3x mb-3"></i>
                        <p>No orders found<?= $type ? ' for this status' : '' ?>.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>User</th>
                                    <th>Items</th>
                                    <th>Address</th>
                                    <th>Total Amount</th>
                                    <th>Payment Status</th>
                                    <th>Order Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?= htmlspecialchars($o['order_number']) ?></td>
                                <td><?= htmlspecialchars($o['customer_name'] ?: $o['customer_email'] ?: '—') ?></td>
                                <td>
                                    <?php $items_txt = $o['items_summary'] ?: '—'; ?>
                                    <span title="<?= htmlspecialchars($items_txt) ?>" style="display:inline-block; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom;">
                                        <?= htmlspecialchars($items_txt) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php $addr_txt = $o['shipping_address'] ?: $o['customer_saved_address'] ?: '—'; ?>
                                    <span title="<?= htmlspecialchars($addr_txt) ?>" style="display:inline-block; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom;">
                                        <?= htmlspecialchars($addr_txt) ?>
                                    </span>
                                </td>
                                <td>₹<?= number_format((float)$o['total_amount'], 2) ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $o['payment_status'] === 'Paid' ? 'btn-success' : 'btn-secondary-status' ?>" type="button" data-bs-toggle="dropdown">
                                            <?= htmlspecialchars($o['payment_status']) ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?set_payment_status=Paid&id=<?= $o['id'] ?><?= $type ? '&type=' . urlencode($type) : '' ?>">Paid</a></li>
                                            <li><a class="dropdown-item" href="?set_payment_status=Unpaid&id=<?= $o['id'] ?><?= $type ? '&type=' . urlencode($type) : '' ?>">Unpaid</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $osClasses = ['Pending' => 'btn-warning-status', 'In Progress' => 'status-info-btn', 'Delivered' => 'btn-success', 'Canceled' => 'btn-danger-status'];
                                    $osClass = $osClasses[$o['order_status']] ?? 'btn-secondary-status';
                                    ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $osClass ?>" type="button" data-bs-toggle="dropdown">
                                            <?= htmlspecialchars($o['order_status']) ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php foreach ($valid_order_statuses as $vs): ?>
                                            <li><a class="dropdown-item" href="?set_order_status=<?= urlencode($vs) ?>&id=<?= $o['id'] ?><?= $type ? '&type=' . urlencode($type) : '' ?>"><?= $vs ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <a class="btn btn-secondary btn-sm" href="order-view.php?id=<?= $o['id'] ?>"><i class="fas fa-eye"></i></a>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['order_number'])) ?>')"><i class="fas fa-trash-alt"></i></button>
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
            <div class="modal-body">Delete order "<strong id="deleteOrderName"></strong>"? This cannot be undone.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteOrderId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<style>.status-info-btn { background-color: var(--info); color: #fff; border: none; }</style>
<script>
$(document).ready(function () {
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [7] }] });
});
function openDeleteModal(id, name) {
    document.getElementById('deleteOrderId').value = id;
    document.getElementById('deleteOrderName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}
</script>
</body>
</html>
