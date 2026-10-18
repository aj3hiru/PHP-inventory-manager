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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_customers'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$notifications = [];

// ── Handle POST (create / update) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    $action        = $_POST['action'] ?? '';
    $edit_id       = (int)($_POST['edit_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $customer_type = ($_POST['customer_type'] ?? 'online') === 'offline' ? 'offline' : 'online';
    $address       = trim($_POST['address'] ?? '');
    $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (empty($name) || empty($email)) {
        $notifications[] = ['type' => 'error', 'message' => 'Name and email are required.'];
    } else {
        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO ecom_customers (name, email, phone, customer_type, address, status) VALUES (?,?,?,?,?,?)")
                    ->execute([$name, $email, $phone ?: null, $customer_type, $address ?: null, $status]);

                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_customer_create', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Created Customer: $name (ID: $new_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/customers.php?success=created");
                exit;
            } elseif ($action === 'update' && $edit_id > 0) {
                $pdo->prepare("UPDATE ecom_customers SET name=?, email=?, phone=?, customer_type=?, address=?, status=? WHERE id=?")
                    ->execute([$name, $email, $phone ?: null, $customer_type, $address ?: null, $status, $edit_id]);

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_customer_update', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Updated Customer: $name (ID: $edit_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/customers.php?success=updated");
                exit;
            }
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: this email may already be in use.'];
        }
    }
}

// ── Handle DELETE ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    $row = $pdo->prepare("SELECT name FROM ecom_customers WHERE id=?");
    $row->execute([$del_id]);
    $cust = $row->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare("DELETE FROM ecom_customers WHERE id=?")->execute([$del_id]);

    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_customer_delete', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], "Deleted Customer: " . ($cust['name'] ?? 'Unknown') . " (ID: $del_id)", $log_ip, $log_ua]);

    header("Location: /admin/ecommerce/customers.php?success=deleted");
    exit;
}

// ── Quick Enable/Disable ─────────────────────────────────────────────────────
if (isset($_GET['set_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $new_status = $_GET['set_status'] === 'inactive' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE ecom_customers SET status=? WHERE id=?")->execute([$new_status, (int)$_GET['id']]);
    $qs = [];
    if (!empty($_GET['filter']))    $qs[] = 'filter=' . urlencode($_GET['filter']);
    if (!empty($_GET['date_from'])) $qs[] = 'date_from=' . urlencode($_GET['date_from']);
    if (!empty($_GET['date_to']))   $qs[] = 'date_to=' . urlencode($_GET['date_to']);
    header("Location: /admin/ecommerce/customers.php" . ($qs ? '?' . implode('&', $qs) : ''));
    exit;
}

if (isset($_GET['success'])) {
    $map = ['created' => 'Customer added successfully!', 'updated' => 'Customer updated successfully!', 'deleted' => 'Customer deleted successfully!'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
}

// ── Filter by Online / Offline, and optionally by join date ────────────────
$filter    = $_GET['filter'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';

$conditions = [];
$params = [];

if (in_array($filter, ['online', 'offline'], true)) {
    $conditions[] = "c.customer_type = ?";
    $params[] = $filter;
}
if ($date_from !== '') {
    $conditions[] = "DATE(c.created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $conditions[] = "DATE(c.created_at) <= ?";
    $params[] = $date_to;
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stmt = $pdo->prepare("
    SELECT c.*, (SELECT COUNT(*) FROM ecom_orders o WHERE o.customer_id = c.id) AS order_count
    FROM ecom_customers c $where ORDER BY c.created_at DESC
");
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_all     = (int) $pdo->query("SELECT COUNT(*) FROM ecom_customers")->fetchColumn();
$total_online  = (int) $pdo->query("SELECT COUNT(*) FROM ecom_customers WHERE customer_type='online'")->fetchColumn();
$total_offline = (int) $pdo->query("SELECT COUNT(*) FROM ecom_customers WHERE customer_type='offline'")->fetchColumn();

$page_title = 'Customer List';
$page_subtitle = 'Everyone who has an account, or has purchased in-store';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.type-pill { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.8125rem; font-weight: 700; }
.type-online { color: #1e40af; }
.type-offline { color: #92400e; }
</style>
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
            <div class="alert alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="stat-mini-grid">
                <a href="customers.php" class="text-decoration-none"><div class="stat-mini"><div class="val"><?= number_format($total_all) ?></div><div class="lbl">All Customers</div></div></a>
                <a href="customers.php?filter=online" class="text-decoration-none"><div class="stat-mini"><div class="val" style="color:var(--info);"><?= number_format($total_online) ?></div><div class="lbl">Online</div></div></a>
                <a href="customers.php?filter=offline" class="text-decoration-none"><div class="stat-mini"><div class="val" style="color:var(--warning);"><?= number_format($total_offline) ?></div><div class="lbl">Offline (In-store)</div></div></a>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b><?= $filter === 'online' ? 'Online Customers' : ($filter === 'offline' ? 'Offline Customers' : 'Customer List') ?></b></h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>

                    <form method="GET" class="row g-2 align-items-end mt-1">
                        <?php if ($filter): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
                        <div class="col-auto">
                            <label class="form-label small mb-1">Joined From</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-auto">
                            <label class="form-label small mb-1">Joined To</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
                            <?php if ($date_from || $date_to): ?>
                            <a href="customers.php<?= $filter ? '?filter=' . urlencode($filter) : '' ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body table-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Type</th>
                                    <th>Joined</th>
                                    <th>Orders</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['name']) ?></td>
                                <td><?= htmlspecialchars($c['email']) ?></td>
                                <td><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
                                <td><span class="type-pill type-<?= $c['customer_type'] ?>"><?= ucfirst($c['customer_type']) ?></span></td>
                                <td><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                                <td><?= (int)$c['order_count'] ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $c['status'] === 'active' ? 'btn-success' : 'btn-secondary-status' ?>" type="button" data-bs-toggle="dropdown">
                                            <?= $c['status'] === 'active' ? 'Active' : 'Suspended' ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php $preserve = ($filter ? '&filter=' . urlencode($filter) : '') . ($date_from ? '&date_from=' . urlencode($date_from) : '') . ($date_to ? '&date_to=' . urlencode($date_to) : ''); ?>
                                            <li><a class="dropdown-item" href="?set_status=active&id=<?= $c['id'] ?><?= $preserve ?>">Activate</a></li>
                                            <li><a class="dropdown-item" href="?set_status=inactive&id=<?= $c['id'] ?><?= $preserve ?>">Suspend</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <a class="btn btn-secondary btn-sm" href="customer-profile.php?id=<?= $c['id'] ?>" title="View Profile"><i class="fas fa-eye"></i></a>
                                        <?php if (!empty($permissions['ecommerce']['manage_billing'])): ?>
                                        <a class="btn btn-success btn-sm" href="billing.php?customer_id=<?= $c['id'] ?>" title="Create Order"><i class="fas fa-cart-plus"></i></a>
                                        <?php endif; ?>
                                        <button class="btn btn-primary btn-sm"
                                                onclick='openEditModal(<?= json_encode([
                                                    "id" => $c['id'], "name" => $c['name'], "email" => $c['email'],
                                                    "phone" => $c['phone'], "customer_type" => $c['customer_type'],
                                                    "address" => $c['address'], "status" => $c['status'],
                                                ]) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
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

<!-- Add / Edit Modal -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="customerForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerModalLabel">New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="custAction" value="create">
                    <input type="hidden" name="edit_id" id="custEditId" value="0">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="custName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="custEmail" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="custPhone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" id="custAddress" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label d-block">Customer Type</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="customer_type" id="custTypeOnline" value="online" checked>
                                <label class="btn btn-outline-primary btn-sm" for="custTypeOnline">Online</label>
                                <input type="radio" class="btn-check" name="customer_type" id="custTypeOffline" value="offline">
                                <label class="btn btn-outline-warning btn-sm" for="custTypeOffline">Offline</label>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label d-block">Status</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="status" id="custStatusActive" value="active" checked>
                                <label class="btn btn-outline-success btn-sm" for="custStatusActive">Active</label>
                                <input type="radio" class="btn-check" name="status" id="custStatusInactive" value="inactive">
                                <label class="btn btn-outline-secondary btn-sm" for="custStatusInactive">Suspended</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="custSubmitBtn">Create Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="confirm-delete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete?</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Delete customer "<strong id="deleteCustName"></strong>"? This cannot be undone.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteCustId">
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
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [3, 5, 6, 7] }] });
});

function resetCustomerForm() {
    document.getElementById('customerForm').reset();
    document.getElementById('custAction').value = 'create';
    document.getElementById('custEditId').value = '0';
    document.getElementById('customerModalLabel').textContent = 'New Customer';
    document.getElementById('custSubmitBtn').textContent = 'Create Customer';
    document.getElementById('custStatusActive').checked = true;
    document.getElementById('custTypeOnline').checked = true;
}
function openCreateModal() { resetCustomerForm(); }
function openEditModal(c) {
    resetCustomerForm();
    document.getElementById('custAction').value = 'update';
    document.getElementById('custEditId').value = c.id;
    document.getElementById('customerModalLabel').textContent = 'Edit Customer';
    document.getElementById('custSubmitBtn').textContent = 'Update Customer';
    document.getElementById('custName').value = c.name;
    document.getElementById('custEmail').value = c.email;
    document.getElementById('custPhone').value = c.phone || '';
    document.getElementById('custAddress').value = c.address || '';
    if (c.customer_type === 'offline') document.getElementById('custTypeOffline').checked = true;
    else document.getElementById('custTypeOnline').checked = true;
    if (c.status === 'inactive') document.getElementById('custStatusInactive').checked = true;
    else document.getElementById('custStatusActive').checked = true;
    new bootstrap.Modal(document.getElementById('customerModal')).show();
}
function openDeleteModal(id, name) {
    document.getElementById('deleteCustId').value = id;
    document.getElementById('deleteCustName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}
</script>
</body>
</html>
