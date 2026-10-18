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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_credits'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$notifications = [];

// ── Record a (partial) payment against a credit ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    $credit_id = (int)($_POST['credit_id'] ?? 0);
    $amount    = (float)($_POST['amount'] ?? 0);

    $c = $pdo->prepare("SELECT * FROM ecom_credits WHERE id = ?");
    $c->execute([$credit_id]);
    $credit = $c->fetch(PDO::FETCH_ASSOC);

    if ($credit && $amount > 0) {
        $new_paid = min((float)$credit['amount'], (float)$credit['amount_paid'] + $amount);
        $new_status = $new_paid >= (float)$credit['amount'] - 0.004 ? 'paid' : 'pending';
        $pdo->prepare("UPDATE ecom_credits SET amount_paid = ?, status = ? WHERE id = ?")
            ->execute([$new_paid, $new_status, $credit_id]);

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_credit_payment', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Recorded udhaar payment: ₹" . number_format($amount, 2) . " from " . $credit['customer_name'], $log_ip, $log_ua]);
    }
    header("Location: /admin/ecommerce/credits.php?success=payment");
    exit;
}

// ── Update promised date ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_date') {
    $credit_id = (int)($_POST['credit_id'] ?? 0);
    $date = $_POST['promised_date'] ?: null;
    $pdo->prepare("UPDATE ecom_credits SET promised_date = ? WHERE id = ?")->execute([$date, $credit_id]);
    header("Location: /admin/ecommerce/credits.php?success=date");
    exit;
}

if (isset($_GET['success'])) {
    $map = ['payment' => 'Payment recorded!', 'date' => 'Promised date updated!'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
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

$credits = $pdo->query("SELECT * FROM ecom_credits $where ORDER BY (promised_date IS NULL), promised_date ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$total_outstanding = (float) $pdo->query("SELECT COALESCE(SUM(amount - amount_paid),0) FROM ecom_credits WHERE status='pending'")->fetchColumn();
$total_due_today    = (float) $pdo->query("SELECT COALESCE(SUM(amount - amount_paid),0) FROM ecom_credits WHERE status='pending' AND promised_date IS NOT NULL AND promised_date <= CURDATE()")->fetchColumn();
$total_people        = (int) $pdo->query("SELECT COUNT(DISTINCT customer_name) FROM ecom_credits WHERE status='pending'")->fetchColumn();
$total_new_today      = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM ecom_credits WHERE DATE(created_at) = CURDATE()")->fetchColumn();

$page_title = 'Udhaar (Credit Ledger)';
$page_subtitle = 'Track customer dues, promised payment dates, and collections';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.overdue-row { background: #fef2f2 !important; }
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
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="stat-mini-grid">
                <div class="stat-mini"><div class="val" style="color:var(--danger);">₹<?= number_format($total_outstanding, 2) ?></div><div class="lbl">Total Outstanding</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--warning);">₹<?= number_format($total_due_today, 2) ?></div><div class="lbl">Due Today / Overdue</div></div>
                <div class="stat-mini"><div class="val" style="color:var(--info);"><?= $total_people ?></div><div class="lbl">People with Dues</div></div>
                <div class="stat-mini"><div class="val">₹<?= number_format($total_new_today, 2) ?></div><div class="lbl">New Udhaar Today</div></div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Udhaar Ledger</b></h3>
                        <div class="btn-group">
                            <a href="?filter=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary' : 'btn-outline-secondary' ?>">Pending</a>
                            <a href="?filter=due" class="btn btn-sm <?= $filter === 'due' ? 'btn-primary' : 'btn-outline-secondary' ?>">Due / Overdue</a>
                            <a href="?filter=paid" class="btn btn-sm <?= $filter === 'paid' ? 'btn-primary' : 'btn-outline-secondary' ?>">Paid</a>
                            <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
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
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Phone</th>
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
                            ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td><?= htmlspecialchars($c['customer_name']) ?></td>
                                <td><?= htmlspecialchars($c['customer_phone'] ?: '—') ?></td>
                                <td>₹<?= number_format((float)$c['amount'], 2) ?></td>
                                <td>₹<?= number_format((float)$c['amount_paid'], 2) ?></td>
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
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Record Payment — <span id="payCustName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="credit_id" id="payCreditId">
                    <label class="form-label">Amount Received (₹)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="payAmount" class="form-control" required>
                    <div class="form-text">Outstanding balance: ₹<span id="payBalanceText"></span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Payment</button>
                </div>
            </form>
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
</script>
</body>
</html>
