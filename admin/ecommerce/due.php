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
if (!$user || $user['status'] !== 'active' || (empty($permissions['ecommerce']['manage_credits']) && empty($permissions['ecommerce']['manage_billing']))) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

// ── Lightweight AJAX: payment history for one credit (used by Sales History) ──
if (isset($_GET['ajax_history'])) {
    header('Content-Type: application/json');
    $credit_id = (int)($_GET['credit_id'] ?? 0);
    $hp = $pdo->prepare("SELECT receipt_number, amount, payment_method, created_at FROM ecom_credit_payments WHERE credit_id = ? ORDER BY created_at ASC");
    $hp->execute([$credit_id]);
    $rows = $hp->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['created_at'] = date('d M Y, h:i A', strtotime($r['created_at'])); }
    echo json_encode(['payments' => $rows]);
    exit;
}

$notifications = [];

// ── Update promised date ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_date') {
    $credit_id = (int)($_POST['credit_id'] ?? 0);
    $date = $_POST['promised_date'] ?: null;
    $pdo->prepare("UPDATE ecom_credits SET promised_date = ? WHERE id = ?")->execute([$date, $credit_id]);
    header("Location: /admin/ecommerce/due.php?success=date");
    exit;
}

if (isset($_GET['success'])) {
    $map = ['payment' => 'Payment recorded!', 'date' => 'Promised date updated!'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
    if ($_GET['success'] === 'payment' && !empty($_GET['receipts'])) {
        $notifications[count($notifications) - 1]['message'] .= ' Receipts: ' . htmlspecialchars($_GET['receipts']);
    }
}
if (isset($_GET['error'])) {
    $notifications[] = ['type' => 'error', 'message' => $_GET['error']];
}

$filter = $_GET['filter'] ?? 'pending';

$where = "WHERE status = 'pending'";
if ($filter === 'due') {
    $where = "WHERE status = 'pending' AND promised_date IS NOT NULL AND promised_date <= CURDATE()";
} elseif ($filter === 'paid') {
    $where = "WHERE status = 'paid'";
} elseif ($filter === 'all') {
    $where = "";
}

$credits = $pdo->query("SELECT c.*, o.order_number FROM ecom_credits c LEFT JOIN ecom_orders o ON c.order_id = o.id $where ORDER BY (c.promised_date IS NULL), c.promised_date ASC, c.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Preload payment history for every visible credit, grouped by credit_id
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

$total_outstanding   = (float) $pdo->query("SELECT COALESCE(SUM(amount - amount_paid),0) FROM ecom_credits WHERE status='pending'")->fetchColumn();
$total_due_today     = (float) $pdo->query("SELECT COALESCE(SUM(amount - amount_paid),0) FROM ecom_credits WHERE status='pending' AND promised_date IS NOT NULL AND promised_date <= CURDATE()")->fetchColumn();
$total_people        = (int) $pdo->query("SELECT COUNT(DISTINCT customer_name) FROM ecom_credits WHERE status='pending'")->fetchColumn();
$total_new_today     = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ecom_credits WHERE DATE(created_at) = CURDATE()")->fetchColumn();

$page_title = 'Due';
$page_subtitle = 'Track customer dues, promised payment dates, and collections';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.overdue-row { background: #fef2f2 !important; }
.pay-history-row td { background: var(--gray-50); padding: 0.75rem 1rem !important; }
.pay-history-list { font-size: 0.85rem; }
.pay-history-list .item { display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px dashed var(--gray-200); }
.pay-history-list .item:last-child { border-bottom: none; }
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
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
                <?= $n['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="stat-mini-grid">
                <div class="stat-mini"><div class="val" style="color:var(--danger);">₹<?= number_format($total_outstanding, 2) ?></div><div class="lbl">Total Due</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--warning);">₹<?= number_format($total_due_today, 2) ?></div><div class="lbl">Due Today / Overdue</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--info);"><?= $total_people ?></div><div class="lbl">People with Dues</div></div>
                <div class="stat-mini"><div class="val">₹<?= number_format($total_new_today, 2) ?></div><div class="lbl">Today Due</div></div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row flex-wrap gap-2">
                        <h3 class="mb-0"><b>Due</b></h3>
                        <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
                            <div class="position-relative">
                                <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.8rem;"></i>
                                <input type="text" id="dueLiveSearch" class="form-control form-control-sm" style="padding-left:2rem;width:260px;" placeholder="Search receipt no., name, or phone…">
                            </div>
                            <div class="btn-group">
                                <a href="?filter=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary' : 'btn-outline-secondary' ?>">Pending</a>
                                <a href="?filter=due" class="btn btn-sm <?= $filter === 'due' ? 'btn-primary' : 'btn-outline-secondary' ?>">Due / Overdue</a>
                                <a href="?filter=paid" class="btn btn-sm <?= $filter === 'paid' ? 'btn-primary' : 'btn-outline-secondary' ?>">Paid</a>
                                <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body table-card-body">
                    <?php if (empty($credits)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-hand-holding-usd fa-3x mb-3"></i>
                        <p>No records here.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>From Order</th>
                                    <th>Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Promised Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($credits as $c):
                                $balance = (float)$c['amount'] - (float)$c['amount_paid'];
                                $is_overdue = $c['status'] === 'pending' && !empty($c['promised_date']) && strtotime($c['promised_date']) <= strtotime(date('Y-m-d'));
                                $history = $payments_by_credit[$c['id']] ?? [];
                                $receipt_numbers = implode(' ', array_column($history, 'receipt_number'));
                            ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td>
                                    <?= htmlspecialchars($c['customer_name']) ?>
                                    <?php if ($receipt_numbers): ?><span style="display:none;"><?= htmlspecialchars($receipt_numbers) ?></span><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($c['customer_phone'] ?: '—') ?></td>
                                <td><?php if ($c['order_number']): ?><a href="invoice.php?id=<?= $c['order_id'] ?>" target="_blank"><?= htmlspecialchars($c['order_number']) ?></a><?php else: ?>—<?php endif; ?></td>
                                <td>₹<?= number_format((float)$c['amount'], 2) ?></td>
                                <td>
                                    ₹<?= number_format((float)$c['amount_paid'], 2) ?>
                                    <?php if (!empty($history)): ?>
                                    <br><button type="button" class="btn btn-link btn-sm p-0" onclick="openHistoryModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['customer_name'])) ?>')">History (<?= count($history) ?>)</button>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">₹<?= number_format($balance, 2) ?></td>
                                <td>
                                    <?php if ($c['status'] === 'pending'): ?>
                                    <form method="POST" class="d-flex gap-1">
                                        <input type="hidden" name="action" value="set_date">
                                        <input type="hidden" name="credit_id" value="<?= $c['id'] ?>">
                                        <input type="date" name="promised_date" value="<?= htmlspecialchars($c['promised_date'] ?? '') ?>" class="form-control form-control-sm" onchange="this.form.submit()">
                                    </form>
                                    <?php if ($is_overdue): ?><span class="badge bg-danger mt-1">Overdue</span><?php endif; ?>
                                    <?php else: ?>
                                        <?= $c['promised_date'] ? date('d M Y', strtotime($c['promised_date'])) : '—' ?>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $c['status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= ucfirst($c['status']) ?></span></td>
                                <td>
                                    <?php if ($c['status'] === 'pending'): ?>
                                    <button class="btn btn-primary btn-sm" onclick="openPayModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['customer_name'])) ?>', <?= $balance ?>)">
                                        <i class="fas fa-hand-holding-usd"></i> Record Payment
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Hidden templates for the History modal (kept out of the table so DataTables doesn't treat them as data rows) -->
                    <?php foreach ($credits as $c): $history = $payments_by_credit[$c['id']] ?? []; if (empty($history)) continue; ?>
                    <div id="history-content-<?= $c['id'] ?>" style="display:none;">
                        <?php foreach ($history as $h): ?>
                        <div class="item">
                            <span><?= date('d M Y, h:i A', strtotime($h['created_at'])) ?> · <?= htmlspecialchars($h['payment_method']) ?></span>
                            <span>₹<?= number_format((float)$h['amount'], 2) ?> — <a href="payment-receipt.php?receipt=<?= urlencode($h['receipt_number']) ?>&return_to=<?= urlencode('/admin/ecommerce/due.php?filter=' . $filter) ?>" target="_blank">Receipt <?= htmlspecialchars($h['receipt_number']) ?></a></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

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
                    <input type="hidden" name="return_to" value="/admin/ecommerce/due.php?filter=<?= htmlspecialchars($filter) ?>">
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

<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment History — <span id="histCustName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pay-history-list" id="historyModalBody"></div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
function openPayModal(id, name, balance) {
    document.getElementById('payCreditId').value = id;
    document.getElementById('payCustName').textContent = name;
    document.getElementById('payAmount').value = balance.toFixed(2);
    document.getElementById('payAmount').max = balance;
    document.getElementById('payBalanceText').textContent = balance.toFixed(2);
    new bootstrap.Modal(document.getElementById('payModal')).show();
}
function openHistoryModal(id, name) {
    document.getElementById('histCustName').textContent = name;
    document.getElementById('historyModalBody').innerHTML = document.getElementById('history-content-' + id).innerHTML;
    new bootstrap.Modal(document.getElementById('historyModal')).show();
}

$(document).ready(function () {
    const table = $('#admin-table').DataTable({
        order: [],
        columnDefs: [{ orderable: false, targets: [8] }],
        dom: 't<"d-flex justify-content-between align-items-center mt-2"ip>', // hide default search box, keep table/info/pagination
        pageLength: 25,
    });

    // Wire our own styled search input to DataTables' live filtering
    $('#dueLiveSearch').on('input', function () {
        table.search(this.value).draw();
    });
});
</script>
</body>
</html>
