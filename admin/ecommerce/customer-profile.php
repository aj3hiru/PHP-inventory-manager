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

$customer_id = (int)($_GET['id'] ?? 0);
$notifications = [];

// ── Handle update ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($_POST['name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $customer_type = ($_POST['customer_type'] ?? 'online') === 'offline' ? 'offline' : 'online';
    $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (empty($name) || empty($email)) {
        $notifications[] = ['type' => 'error', 'message' => 'Name and email are required.'];
    } else {
        try {
            $pdo->prepare("UPDATE ecom_customers SET name=?, email=?, phone=?, address=?, customer_type=?, status=? WHERE id=?")
                ->execute([$name, $email, $phone ?: null, $address ?: null, $customer_type, $status, $customer_id]);

            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_customer_update', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Updated Customer profile: $name (ID: $customer_id)", $log_ip, $log_ua]);

            header("Location: /admin/ecommerce/customer-profile.php?id=$customer_id&success=1");
            exit;
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: this email may already be in use.'];
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $notifications[] = ['type' => 'success', 'message' => 'Profile updated successfully!'];
}
if (isset($_GET['success']) && $_GET['success'] === 'payment') {
    $msg = 'Payment recorded!';
    if (!empty($_GET['receipts'])) $msg .= ' Receipts: ' . htmlspecialchars($_GET['receipts']);
    $notifications[] = ['type' => 'success', 'message' => $msg];
}
if (isset($_GET['error'])) {
    $notifications[] = ['type' => 'error', 'message' => htmlspecialchars($_GET['error'])];
}

$stmt = $pdo->prepare("SELECT * FROM ecom_customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) { exit('Customer not found.'); }

$orders = $pdo->prepare("SELECT * FROM ecom_orders WHERE customer_id = ? ORDER BY created_at DESC");
$orders->execute([$customer_id]);
$orders = $orders->fetchAll(PDO::FETCH_ASSOC);

$total_spent  = array_sum(array_column($orders, 'total_amount'));
$total_orders = count($orders);

$credits = $pdo->prepare("SELECT c.*, o.order_number FROM ecom_credits c LEFT JOIN ecom_orders o ON c.order_id = o.id WHERE c.customer_id = ? ORDER BY c.created_at DESC");
$credits->execute([$customer_id]);
$credits = $credits->fetchAll(PDO::FETCH_ASSOC);

$credit_ids_list = array_column($credits, 'id');
$payments_by_credit = [];
if (!empty($credit_ids_list)) {
    $in = implode(',', array_fill(0, count($credit_ids_list), '?'));
    $ph = $pdo->prepare("SELECT * FROM ecom_credit_payments WHERE credit_id IN ($in) ORDER BY created_at ASC");
    $ph->execute($credit_ids_list);
    foreach ($ph->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payments_by_credit[$row['credit_id']][] = $row;
    }
}

$credit_by_order = [];
foreach ($credits as $c) { if (!empty($c['order_id'])) $credit_by_order[$c['order_id']] = $c; }

$total_due_ever  = array_sum(array_column($credits, 'amount'));
$total_due_paid  = array_sum(array_column($credits, 'amount_paid'));
$total_due_left  = max(0, $total_due_ever - $total_due_paid);
$pending_credits = array_filter($credits, fn($c) => $c['status'] === 'pending');

$page_title = $customer['name'];
$page_subtitle = 'Customer profile and purchase history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
/* Display Options panel (matches Dashboard / Sales History) */
.db-display-panel {
    position: absolute; top: calc(100% + 0.5rem); right: 0; z-index: 20;
    min-width: 230px; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.5rem;
    padding: 0.75rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,.15);
    display: none; flex-direction: column; gap: 0.125rem;
}
.db-display-panel.open { display: flex; }
.db-display-check { display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.625rem; border-radius: 7px; font-size: 0.8438rem; color: var(--gray-700); cursor: pointer; user-select: none; }
.db-display-check:hover { background: var(--gray-50); }
.db-display-check input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }
[data-widget].db-card-hidden { display: none !important; }

/* Profile card */
.profile-card { position: relative; }
.profile-view { text-align: center; padding-top: 0.5rem; }
.profile-edit-btn {
    position: absolute; top: 1rem; right: 1rem; width: 32px; height: 32px; border-radius: 50%;
    border: 1px solid var(--gray-200); background: #fff; color: var(--gray-500);
    display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.15s;
}
.profile-edit-btn:hover { background: var(--primary-lighter); color: var(--primary); border-color: var(--primary); }
.profile-avatar {
    width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 0.75rem;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 1.75rem; font-weight: 700;
}
.profile-name { font-size: 1.15rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.5rem; }
.profile-pills { display: flex; justify-content: center; gap: 0.4rem; margin-bottom: 1.25rem; }
.profile-pill { font-size: 0.6875rem; font-weight: 700; padding: 0.2rem 0.65rem; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.02em; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-orange { background: #fef3c7; color: #92400e; }
.pill-green { background: #d1fae5; color: #065f46; }
.pill-gray { background: var(--gray-100); color: var(--gray-500); }
.profile-rows { text-align: left; border-top: 1px solid var(--gray-100); padding-top: 1rem; }
.profile-row { display: flex; align-items: flex-start; gap: 0.65rem; padding: 0.5rem 0; font-size: 0.875rem; color: var(--gray-700); }
.profile-row i { width: 16px; color: var(--gray-400); margin-top: 0.2rem; }
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
                <a href="billing.php?customer_id=<?= $customer_id ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Create Order</a>
                <?php endif; ?>
                <a href="customers.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Customers</a>
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

            <div class="db-display-wrap" style="display:flex; justify-content:flex-end; margin-bottom:0.5rem; position:relative;">
                <button class="btn btn-secondary btn-sm" id="displayOptionsToggle" type="button">
                    <i class="fas fa-sliders-h"></i> Display Options <i class="fas fa-chevron-down" id="doArrow"></i>
                </button>
                <div class="db-display-panel" id="displayOptionsPanel">
                    <label class="db-display-check"><input type="checkbox" data-widget="stat-orders" checked> Total Orders</label>
                    <label class="db-display-check"><input type="checkbox" data-widget="stat-spent" checked> Total Spent</label>
                    <label class="db-display-check"><input type="checkbox" data-widget="stat-type" checked> Customer Type</label>
                    <label class="db-display-check"><input type="checkbox" data-widget="stat-dueever" checked> Total Due (Ever)</label>
                    <label class="db-display-check"><input type="checkbox" data-widget="stat-duepaid" checked> Total Paid</label>
                    <label class="db-display-check"><input type="checkbox" data-widget="stat-outstanding" checked> Currently Outstanding</label>
                </div>
            </div>

            <div class="stat-mini-grid">
                <div class="stat-mini" data-widget="stat-orders"><div class="val"><?= number_format($total_orders) ?></div><div class="lbl">Total Orders</div></div>
                <div class="stat-mini" data-widget="stat-spent"><div class="val" style="color:var(--success);">₹<?= number_format((float)$total_spent, 2) ?></div><div class="lbl">Total Spent</div></div>
                <div class="stat-mini" data-widget="stat-type"><div class="val" style="color:var(--info);"><?= ucfirst($customer['customer_type']) ?></div><div class="lbl">Customer Type</div></div>
                <div class="stat-mini" data-widget="stat-dueever"><div class="val">₹<?= number_format($total_due_ever, 2) ?></div><div class="lbl">Total Due (Ever)</div></div>
                <div class="stat-mini" data-widget="stat-duepaid"><div class="val" style="color:var(--success);">₹<?= number_format($total_due_paid, 2) ?></div><div class="lbl">Total Paid</div></div>
                <div class="stat-mini" data-widget="stat-outstanding"><div class="val" style="color:var(--danger);">₹<?= number_format($total_due_left, 2) ?></div><div class="lbl">Currently Outstanding</div></div>
            </div>

            <div class="row">
                <div class="col-lg-4">
                    <div class="gd-card profile-card">
                        <div class="gd-card-body">
                            <div class="profile-view" id="profileView">
                                <button type="button" class="profile-edit-btn" onclick="toggleProfileEdit(true)" title="Edit profile">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <div class="profile-avatar"><?= strtoupper(substr($customer['name'], 0, 1)) ?></div>
                                <div class="profile-name"><?= htmlspecialchars($customer['name']) ?></div>
                                <div class="profile-pills">
                                    <span class="profile-pill <?= $customer['customer_type'] === 'online' ? 'pill-blue' : 'pill-orange' ?>"><?= ucfirst($customer['customer_type']) ?></span>
                                    <span class="profile-pill <?= $customer['status'] === 'active' ? 'pill-green' : 'pill-gray' ?>"><?= $customer['status'] === 'active' ? 'Active' : 'Suspended' ?></span>
                                </div>
                                <div class="profile-rows">
                                    <div class="profile-row"><i class="fas fa-phone"></i> <span><?= htmlspecialchars($customer['phone'] ?: 'No phone on file') ?></span></div>
                                    <div class="profile-row"><i class="fas fa-envelope"></i> <span><?= htmlspecialchars($customer['email'] ?: 'No email on file') ?></span></div>
                                    <div class="profile-row"><i class="fas fa-map-marker-alt"></i> <span><?= nl2br(htmlspecialchars($customer['address'] ?: 'No address on file')) ?></span></div>
                                </div>
                            </div>

                            <form method="POST" class="profile-edit-form" id="profileEditForm" style="display:none;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0"><i class="fas fa-user-edit text-primary"></i> Edit Profile</h5>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleProfileEdit(false)">Cancel</button>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Name</label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($customer['name']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label d-block">Customer Type</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="customer_type" id="pTypeOnline" value="online" <?= $customer['customer_type'] === 'online' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-primary btn-sm" for="pTypeOnline">Online</label>
                                        <input type="radio" class="btn-check" name="customer_type" id="pTypeOffline" value="offline" <?= $customer['customer_type'] === 'offline' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-warning btn-sm" for="pTypeOffline">Offline</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label d-block">Status</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="status" id="pStatusActive" value="active" <?= $customer['status'] === 'active' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-success btn-sm" for="pStatusActive">Active</label>
                                        <input type="radio" class="btn-check" name="status" id="pStatusInactive" value="inactive" <?= $customer['status'] === 'inactive' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-secondary btn-sm" for="pStatusInactive">Suspended</label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Save Changes</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <h5 class="mb-3"><i class="fas fa-receipt text-primary"></i> Purchase History</h5>
                            <?php if (empty($orders)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-box-open fa-3x mb-3"></i>
                                <p>No orders yet from this customer.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered" id="purchase-history-table" width="100%" cellspacing="0">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Date</th>
                                            <th>Total</th>
                                            <th>Payment</th>
                                            <th>Status</th>
                                            <th>Invoice</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($orders as $o):
                                        $oc = $credit_by_order[$o['id']] ?? null;
                                        $receipt_count = $oc ? count($payments_by_credit[$oc['id']] ?? []) : 0;
                                        $due = $oc ? max(0, (float)$oc['amount'] - (float)$oc['amount_paid']) : 0;
                                    ?>
                                    <tr>
                                        <td><a href="order-view.php?id=<?= $o['id'] ?>"><?= htmlspecialchars($o['order_number']) ?></a></td>
                                        <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                                        <td>₹<?= number_format((float)$o['total_amount'], 2) ?></td>
                                        <td>
                                            <?php if ($due > 0.004): ?>
                                                <span class="badge bg-danger">Due</span> ₹<?= number_format($due, 2) ?>
                                                <br><button type="button" class="btn btn-primary btn-sm mt-1" onclick="openPayModal(<?= $oc['id'] ?>, '<?= htmlspecialchars(addslashes($customer['name'])) ?>', <?= $due ?>)">Pay Due</button>
                                            <?php else: ?>
                                                <span class="badge <?= $o['payment_status'] === 'Paid' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($o['payment_status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-info"><?= htmlspecialchars($o['order_status']) ?></span></td>
                                        <td>
                                            <a href="#" class="btn btn-secondary btn-sm" onclick="openInvoiceList(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['order_number'])) ?>', <?= $oc ? $oc['id'] : 0 ?>); return false;">
                                                <i class="fas fa-file-invoice"></i><?= $receipt_count > 0 ? ' ×' . ($receipt_count + 1) : '' ?>
                                            </a>
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
            </div>


        </div>
    </main>
</div>

<!-- Record Due Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="record-due-payment.php">
                <div class="modal-header">
                    <h5 class="modal-title">Record Payment — <span id="payCustName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="credit_ids[]" id="payCreditId">
                    <input type="hidden" name="return_to" value="/admin/ecommerce/customer-profile.php?id=<?= $customer_id ?>">
                    <label class="form-label">Amount Received (₹)</label>
                    <input type="number" step="0.01" min="0.01" name="amounts[]" id="payAmount" class="form-control" required>
                    <div class="form-text mb-3">Outstanding balance: ₹<span id="payBalanceText"></span></div>
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="UPI">UPI</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save &amp; Print Receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Invoice List Modal -->
<div class="modal fade" id="invoiceListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invoices — <span id="invListOrderNum"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="list-group" id="invoiceListBody"></div>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
function openPayModal(creditId, name, balance) {
    document.getElementById('payCreditId').value = creditId;
    document.getElementById('payCustName').textContent = name;
    document.getElementById('payAmount').value = balance.toFixed(2);
    document.getElementById('payAmount').max = balance;
    document.getElementById('payBalanceText').textContent = balance.toFixed(2);
    new bootstrap.Modal(document.getElementById('payModal')).show();
}

function openInvoiceList(orderId, orderNumber, creditId) {
    document.getElementById('invListOrderNum').textContent = orderNumber;
    const body = document.getElementById('invoiceListBody');
    let html = `<a href="invoice.php?id=${orderId}" target="_blank" class="list-group-item list-group-item-action"><i class="fas fa-file-invoice"></i> Main Invoice (${orderNumber})</a>`;

    if (creditId) {
        fetch('due.php?ajax_history=1&credit_id=' + creditId)
            .then(r => r.json())
            .then(data => {
                (data.payments || []).forEach((p, i) => {
                    html += `<a href="payment-receipt.php?receipt=${encodeURIComponent(p.receipt_number)}&return_to=${encodeURIComponent('/admin/ecommerce/customer-profile.php?id=<?= $customer_id ?>')}" target="_blank" class="list-group-item list-group-item-action"><i class="fas fa-receipt"></i> Payment Receipt ${i + 1} — ₹${parseFloat(p.amount).toFixed(2)} (${p.created_at})</a>`;
                });
                body.innerHTML = html;
            })
            .catch(() => { body.innerHTML = html; });
    } else {
        body.innerHTML = html;
    }
    new bootstrap.Modal(document.getElementById('invoiceListModal')).show();
}

function toggleProfileEdit(showEdit) {
    document.getElementById('profileView').style.display = showEdit ? 'none' : 'block';
    document.getElementById('profileEditForm').style.display = showEdit ? 'block' : 'none';
}

/* ── Display Options (show/hide the top stat cards, remembered per browser) ── */
(function () {
    const PREF_KEY = 'customer_profile_display';
    const toggle = document.getElementById('displayOptionsToggle');
    const panel  = document.getElementById('displayOptionsPanel');
    const arrow  = document.getElementById('doArrow');
    if (!toggle || !panel) return;

    function loadPrefs() { try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}'); } catch { return {}; } }
    function savePrefs(p) { localStorage.setItem(PREF_KEY, JSON.stringify(p)); }
    function applyWidget(key, visible) {
        document.querySelectorAll('[data-widget="' + key + '"]').forEach(el => el.classList.toggle('db-card-hidden', !visible));
    }

    const prefs = loadPrefs();
    panel.querySelectorAll('input[data-widget]').forEach(cb => {
        const key = cb.dataset.widget;
        const visible = prefs[key] !== false;
        cb.checked = visible;
        applyWidget(key, visible);
        cb.addEventListener('change', function () { const p = loadPrefs(); p[key] = this.checked; savePrefs(p); applyWidget(key, this.checked); });
    });

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = panel.classList.toggle('open');
        arrow.style.transform = isOpen ? 'rotate(180deg)' : '';
    });
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !toggle.contains(e.target)) { panel.classList.remove('open'); arrow.style.transform = ''; }
    });
})();

$(document).ready(function () {
    if (document.getElementById('purchase-history-table')) {
        $('#purchase-history-table').DataTable({
            order: [],
            pageLength: 20,
            lengthMenu: [[20, 50, 100, 150, 200, -1], [20, 50, 100, 150, 200, 'View All']],
            columnDefs: [{ orderable: false, targets: [3, 5] }]
        });
    }
});
</script>
</body>
</html>
