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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_billing'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

// ── Date range filter (same pattern as the main Dashboard) ──────────────────
$range = $_GET['range'] ?? 'today';
$today = date('Y-m-d');

switch ($range) {
    case 'yesterday':
        $date_from = $date_to = date('Y-m-d', strtotime('-1 day'));
        $range_label = 'Yesterday';
        break;
    case '7days':
        $date_from = date('Y-m-d', strtotime('-6 days'));
        $date_to   = $today;
        $range_label = 'Last 7 Days';
        break;
    case 'this_month':
        $date_from = date('Y-m-01');
        $date_to   = $today;
        $range_label = 'This Month';
        break;
    case 'prev_month':
        $date_from = date('Y-m-01', strtotime('first day of last month'));
        $date_to   = date('Y-m-t', strtotime('last day of last month'));
        $range_label = 'Previous Month';
        break;
    case 'custom':
        $date_from = $_GET['from'] ?? $today;
        $date_to   = $_GET['to'] ?? $today;
        if (strtotime($date_to) < strtotime($date_from)) { $tmp = $date_from; $date_from = $date_to; $date_to = $tmp; }
        $range_label = date('d M Y', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to));
        break;
    case 'today':
    default:
        $range = 'today';
        $date_from = $date_to = $today;
        $range_label = 'Today';
        break;
}
$range_start = $date_from . ' 00:00:00';
$range_end   = $date_to . ' 23:59:59';

// ── Sale-type filter: Store (offline) / Online / All ────────────────────────
$sale_type = $_GET['sale_type'] ?? 'all';
if (!in_array($sale_type, ['all', 'offline', 'online'], true)) $sale_type = 'all';

// A "sale" here means: every offline (POS) order, and every ONLINE order that
// has actually been completed (Delivered) — pending online orders belong on
// the Orders page, not in the sales ledger.
$type_condition = "((o.order_type = 'offline') OR (o.order_type = 'online' AND o.order_status = 'Delivered'))";
if ($sale_type === 'offline') $type_condition = "o.order_type = 'offline'";
if ($sale_type === 'online')  $type_condition = "(o.order_type = 'online' AND o.order_status = 'Delivered')";

$stmt = $pdo->prepare("
    SELECT o.*,
           (SELECT GROUP_CONCAT(CONCAT(oi.qty, 'x ', oi.product_name) SEPARATOR ', ')
              FROM ecom_order_items oi WHERE oi.order_id = o.id) AS items_summary,
           (SELECT COUNT(*) FROM ecom_order_items oi WHERE oi.order_id = o.id) AS item_count,
           c.id AS linked_credit_id, c.amount AS credit_amount, c.amount_paid AS credit_paid, c.status AS credit_status
    FROM ecom_orders o
    LEFT JOIN ecom_credits c ON c.order_id = o.id
    WHERE $type_condition AND o.created_at BETWEEN ? AND ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$range_start, $range_end]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Payment method breakdown for the currently filtered sales ───────────────
$by_method = ['Cash' => 0, 'UPI' => 0, 'Card' => 0, 'Other' => 0];
foreach ($sales as $s) {
    $m = in_array($s['payment_method'], ['Cash', 'UPI', 'Card'], true) ? $s['payment_method'] : 'Other';
    $by_method[$m] += (float)$s['total_amount'];
}

// Payment-receipt counts per linked credit, for the "×N" invoice badge
$credit_ids = array_filter(array_column($sales, 'linked_credit_id'));
$receipt_counts = [];
if (!empty($credit_ids)) {
    $in = implode(',', array_fill(0, count($credit_ids), '?'));
    $rc = $pdo->prepare("SELECT credit_id, COUNT(*) AS cnt FROM ecom_credit_payments WHERE credit_id IN ($in) GROUP BY credit_id");
    $rc->execute(array_values($credit_ids));
    foreach ($rc->fetchAll(PDO::FETCH_ASSOC) as $row) $receipt_counts[$row['credit_id']] = (int)$row['cnt'];
}

$total_sales_count = count($sales);
$total_sales_amount = array_sum(array_column($sales, 'total_amount'));
$total_paid_amount = array_sum(array_map(function ($s) {
    if ($s['linked_credit_id']) {
        $paid_at_sale = (float)$s['total_amount'] - (float)$s['credit_amount'];
        return min((float)$s['total_amount'], $paid_at_sale + (float)$s['credit_paid']);
    }
    return $s['paid_amount'] !== null ? (float)$s['paid_amount'] : (float)$s['total_amount'];
}, $sales));
$total_due_amount = max(0, $total_sales_amount - $total_paid_amount);

// ── Fixed reference stat cards (independent of the table's own filter) ─────
function scalarSH($pdo, $sql, $params = []) { $s = $pdo->prepare($sql); $s->execute($params); return (float)($s->fetchColumn() ?: 0); }
$sale_cond = "((order_type = 'offline') OR (order_type = 'online' AND order_status = 'Delivered'))";
$sale_today     = scalarSH($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE $sale_cond AND DATE(created_at)=CURDATE()");
$sale_yesterday = scalarSH($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE $sale_cond AND DATE(created_at)=CURDATE() - INTERVAL 1 DAY");
$sale_week      = scalarSH($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE $sale_cond AND created_at >= CURDATE() - INTERVAL 6 DAY");
$sale_month     = scalarSH($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE $sale_cond AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$today_due            = scalarSH($pdo, "SELECT COALESCE(SUM(amount),0) FROM ecom_credits WHERE DATE(created_at)=CURDATE()");
$today_due_collection = scalarSH($pdo, "SELECT COALESCE(SUM(amount),0) FROM ecom_credit_payments WHERE DATE(created_at)=CURDATE()");
$all_time_due = scalarSH($pdo, "SELECT COALESCE(SUM(amount - amount_paid),0) FROM ecom_credits WHERE status='pending'");

$page_title = 'Sales History';
$page_subtitle = 'Every completed sale — in-store and online — in one place';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.range-bar { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; }
.range-bar .btn-group .btn { font-size: 0.8125rem; }
.range-bar-custom { display: flex; align-items: center; gap: 0.4rem; }
.db-display-wrap { position: relative; }
.db-display-panel {
    position: absolute; top: calc(100% + 0.5rem); right: 0; z-index: 20;
    min-width: 240px; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.5rem;
    padding: 0.75rem; box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,.15);
    display: none; flex-direction: column; gap: 0.125rem; max-height: 70vh; overflow-y: auto;
}
.db-display-panel.open { display: flex; }
.db-display-check { display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.625rem; border-radius: 7px; font-size: 0.8438rem; color: var(--gray-700); cursor: pointer; user-select: none; }
.db-display-check:hover { background: var(--gray-50); }
.db-display-check input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }
.db-display-divider { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--gray-400); padding: 0.5rem 0.625rem 0.2rem; }
[data-widget].db-card-hidden { display: none !important; }
.db-display-check { position: relative; }
.db-subtoggle { margin-left: auto; background: none; border: none; color: var(--gray-400); padding: 0.2rem; cursor: pointer; transition: transform 0.15s; }
.db-subtoggle.open { transform: rotate(180deg); }
.db-display-sublist { display: none; flex-direction: column; align-items: center; text-align: center; padding: 0.25rem 0 0.4rem; background: var(--gray-50); border-radius: 7px; margin: 0 0.3rem 0.3rem; }
.db-display-sublist.open { display: flex; }
.db-display-check.db-sub { justify-content: center; padding-left: 1.5rem; }
[data-col].col-hidden { display: none !important; }
.due-pay-link { color: var(--danger); font-weight: 700; text-decoration: underline; cursor: pointer; background: none; border: none; padding: 0; }
.due-clear-badge { color: var(--success); font-weight: 700; font-size: 0.8rem; }
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
                <div class="db-display-wrap">
                    <button class="btn btn-secondary btn-sm" id="displayOptionsToggle" type="button">
                        <i class="fas fa-sliders-h"></i> Display Options <i class="fas fa-chevron-down" id="doArrow"></i>
                    </button>
                    <div class="db-display-panel" id="displayOptionsPanel">
                        <div class="db-display-divider">Summary Cards</div>
                        <label class="db-display-check">
                            <input type="checkbox" data-widget="cards" checked> Show Summary Cards
                            <button type="button" class="db-subtoggle" id="cardsSubToggle" title="Show/hide individual cards"><i class="fas fa-chevron-down"></i></button>
                        </label>
                        <div class="db-display-sublist" id="cardsSubList">
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-totalsale" checked> Total Sale</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-today" checked> Today's Sale</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-yesterday" checked> Yesterday's Sale</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-week" checked> This Week's Sale</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-month" checked> This Month's Sale</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-todaydue" checked> Today's Due</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-duecollection" checked> Today's Due Collection</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-alltimedue" checked> All Time Due</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-cash" checked> Cash</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-upi" checked> UPI</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-card" checked> Card</label>
                        </div>
                        <div class="db-display-divider">Table Columns</div>
                        <label class="db-display-check"><input type="checkbox" data-col="time" checked> Time</label>
                        <label class="db-display-check"><input type="checkbox" data-col="orderid" checked> Order ID</label>
                        <label class="db-display-check"><input type="checkbox" data-col="customer" checked> Customer</label>
                        <label class="db-display-check"><input type="checkbox" data-col="items" checked> Items</label>
                        <label class="db-display-check"><input type="checkbox" data-col="payment" checked> Payment</label>
                        <label class="db-display-check"><input type="checkbox" data-col="total" checked> Total</label>
                        <label class="db-display-check"><input type="checkbox" data-col="paid" checked> Paid</label>
                        <label class="db-display-check"><input type="checkbox" data-col="invoice" checked> Invoice</label>
                    </div>
                </div>

                <a href="sales-report-print.php?<?= http_build_query(['range' => $range, 'sale_type' => $sale_type, 'from' => $date_from, 'to' => $date_to]) ?>" target="_blank" class="btn btn-secondary btn-sm">
                    <i class="fas fa-file-export"></i> Export
                </a>

                <a href="billing.php" class="btn btn-primary btn-sm"><i class="fas fa-cash-register"></i> New Sale</a>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <!-- FILTER BAR -->
            <div class="range-bar">
                <div class="btn-group">
                    <a href="?range=today&sale_type=<?= $sale_type ?>" class="btn btn-sm <?= $range === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>">Today</a>
                    <a href="?range=yesterday&sale_type=<?= $sale_type ?>" class="btn btn-sm <?= $range === 'yesterday' ? 'btn-primary' : 'btn-outline-secondary' ?>">Yesterday</a>
                    <a href="?range=7days&sale_type=<?= $sale_type ?>" class="btn btn-sm <?= $range === '7days' ? 'btn-primary' : 'btn-outline-secondary' ?>">7 Days</a>
                    <a href="?range=this_month&sale_type=<?= $sale_type ?>" class="btn btn-sm <?= $range === 'this_month' ? 'btn-primary' : 'btn-outline-secondary' ?>">This Month</a>
                    <a href="?range=prev_month&sale_type=<?= $sale_type ?>" class="btn btn-sm <?= $range === 'prev_month' ? 'btn-primary' : 'btn-outline-secondary' ?>">Previous Month</a>
                </div>
                <form method="GET" class="range-bar-custom">
                    <input type="hidden" name="range" value="custom">
                    <input type="hidden" name="sale_type" value="<?= htmlspecialchars($sale_type) ?>">
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>" style="width:150px;">
                    <span class="text-muted small">to</span>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>" style="width:150px;">
                    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
                </form>

                <div class="btn-group">
                    <a href="?range=<?= $range ?>&sale_type=all<?= $range === 'custom' ? '&from=' . $date_from . '&to=' . $date_to : '' ?>" class="btn btn-sm <?= $sale_type === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">All Sales</a>
                    <a href="?range=<?= $range ?>&sale_type=offline<?= $range === 'custom' ? '&from=' . $date_from . '&to=' . $date_to : '' ?>" class="btn btn-sm <?= $sale_type === 'offline' ? 'btn-primary' : 'btn-outline-secondary' ?>">Store Sales</a>
                    <a href="?range=<?= $range ?>&sale_type=online<?= $range === 'custom' ? '&from=' . $date_from . '&to=' . $date_to : '' ?>" class="btn btn-sm <?= $sale_type === 'online' ? 'btn-primary' : 'btn-outline-secondary' ?>">Online Sales</a>
                </div>
            </div>

            <!-- SUMMARY CARDS -->
            <div data-widget="cards">
                <div class="stat-mini-grid">
                    <div class="stat-mini" data-widget="card-totalsale"><div class="val" style="color:var(--primary);">₹<?= number_format($total_sales_amount, 2) ?></div><div class="lbl">Total Sale (<?= htmlspecialchars($range_label) ?>)</div></div>
                    <div class="stat-mini" data-widget="card-today"><div class="val">₹<?= number_format($sale_today, 2) ?></div><div class="lbl">Today's Sale</div></div>
                    <div class="stat-mini" data-widget="card-yesterday"><div class="val">₹<?= number_format($sale_yesterday, 2) ?></div><div class="lbl">Yesterday's Sale</div></div>
                    <div class="stat-mini" data-widget="card-week"><div class="val">₹<?= number_format($sale_week, 2) ?></div><div class="lbl">This Week's Sale</div></div>
                    <div class="stat-mini" data-widget="card-month"><div class="val">₹<?= number_format($sale_month, 2) ?></div><div class="lbl">This Month's Sale</div></div>
                    <div class="stat-mini" data-widget="card-todaydue"><div class="val" style="color:var(--warning);">₹<?= number_format($today_due, 2) ?></div><div class="lbl">Today's Due</div></div>
                    <div class="stat-mini" data-widget="card-duecollection"><div class="val" style="color:var(--success);">₹<?= number_format($today_due_collection, 2) ?></div><div class="lbl">Today's Due Collection</div></div>
                    <div class="stat-mini" data-widget="card-alltimedue"><div class="val" style="color:var(--danger);">₹<?= number_format($all_time_due, 2) ?></div><div class="lbl">All Time Due</div></div>
                    <div class="stat-mini" data-widget="card-cash"><div class="val">₹<?= number_format($by_method['Cash'], 2) ?></div><div class="lbl">Cash</div></div>
                    <div class="stat-mini" data-widget="card-upi"><div class="val">₹<?= number_format($by_method['UPI'], 2) ?></div><div class="lbl">UPI</div></div>
                    <div class="stat-mini" data-widget="card-card"><div class="val">₹<?= number_format($by_method['Card'], 2) ?></div><div class="lbl">Card</div></div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body table-card-body">
                    <?php if (empty($sales)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-receipt fa-3x mb-3"></i>
                        <p>No sales recorded in this date range.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th data-col="time">Time</th>
                                    <th data-col="orderid">Order ID</th>
                                    <th data-col="customer">Customer</th>
                                    <th data-col="items">Items</th>
                                    <th data-col="payment">Payment</th>
                                    <th data-col="total">Total</th>
                                    <th data-col="paid">Paid</th>
                                    <th data-col="invoice">Invoice</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sales as $s):
                                if ($s['linked_credit_id']) {
                                    $paid_at_sale = (float)$s['total_amount'] - (float)$s['credit_amount'];
                                    $paid = min((float)$s['total_amount'], $paid_at_sale + (float)$s['credit_paid']);
                                } else {
                                    $paid = $s['paid_amount'] !== null ? (float)$s['paid_amount'] : (float)$s['total_amount'];
                                }
                                $due = max(0, (float)$s['total_amount'] - $paid);
                                $receipt_count = $s['linked_credit_id'] ? ($receipt_counts[$s['linked_credit_id']] ?? 0) : 0;
                            ?>
                            <tr>
                                <td data-col="time"><?= date('h:i A', strtotime($s['created_at'])) ?><br><small class="text-muted"><?= date('d M Y', strtotime($s['created_at'])) ?></small></td>
                                <td data-col="orderid"><a href="order-view.php?id=<?= $s['id'] ?>"><?= htmlspecialchars($s['order_number']) ?></a></td>
                                <td data-col="customer">
                                    <?php if (!empty($s['customer_id'])): ?>
                                        <a href="customer-profile.php?id=<?= $s['customer_id'] ?>"><?= htmlspecialchars($s['customer_name'] ?: 'Customer') ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($s['customer_name'] ?: 'Walk-in') ?>
                                    <?php endif; ?>
                                    <?php if ($s['order_type'] === 'online'): ?><br><i class="fas fa-globe text-info" style="font-size:0.8rem;" title="Online order"></i><?php endif; ?>
                                </td>
                                <td data-col="items">
                                    <span title="<?= htmlspecialchars($s['items_summary'] ?: '') ?>" style="display:inline-block;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                        <?= (int)$s['item_count'] ?> item<?= $s['item_count'] == 1 ? '' : 's' ?> — <?= htmlspecialchars($s['items_summary'] ?: '—') ?>
                                    </span>
                                </td>
                                <td data-col="payment"><?= htmlspecialchars($s['payment_method'] ?: '—') ?></td>
                                <td data-col="total">₹<?= number_format((float)$s['total_amount'], 2) ?></td>
                                <td data-col="paid">
                                    ₹<?= number_format($paid, 2) ?>
                                    <?php if ($due > 0.004 && $s['linked_credit_id']): ?>
                                        <br><button type="button" class="due-pay-link" onclick="openPayModal(<?= $s['linked_credit_id'] ?>, '<?= htmlspecialchars(addslashes($s['customer_name'] ?: 'Customer')) ?>', <?= $due ?>)">Due ₹<?= number_format($due, 2) ?></button>
                                    <?php elseif ($due <= 0.004 && $receipt_count > 0): ?>
                                        <br><span class="due-clear-badge">Due Clear</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col="invoice">
                                    <a href="#" class="btn btn-secondary btn-sm" onclick="openInvoiceList(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['order_number'])) ?>', <?= (int)$s['linked_credit_id'] ?: 0 ?>); return false;">
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
                    <input type="hidden" name="return_to" value="/admin/ecommerce/sales-history.php?range=<?= htmlspecialchars($range) ?>&sale_type=<?= htmlspecialchars($sale_type) ?><?= $range === 'custom' ? '&from=' . $date_from . '&to=' . $date_to : '' ?>">
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
                    html += `<a href="payment-receipt.php?receipt=${encodeURIComponent(p.receipt_number)}&return_to=${encodeURIComponent('/admin/ecommerce/sales-history.php')}" target="_blank" class="list-group-item list-group-item-action"><i class="fas fa-receipt"></i> Payment Receipt ${i + 1} — ₹${parseFloat(p.amount).toFixed(2)} (${p.created_at})</a>`;
                });
                body.innerHTML = html;
            })
            .catch(() => { body.innerHTML = html; });
    } else {
        body.innerHTML = html;
    }
    new bootstrap.Modal(document.getElementById('invoiceListModal')).show();
}

/* ── Display Options (columns + summary cards) ── */
(function () {
    const PREF_KEY = 'sales_history_display';
    const toggle = document.getElementById('displayOptionsToggle');
    const panel  = document.getElementById('displayOptionsPanel');
    const arrow  = document.getElementById('doArrow');

    function loadPrefs() { try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}'); } catch { return {}; } }
    function savePrefs(p) { localStorage.setItem(PREF_KEY, JSON.stringify(p)); }

    function applyWidget(key, visible) {
        document.querySelectorAll('[data-widget="' + key + '"]').forEach(el => el.classList.toggle('db-card-hidden', !visible));
    }
    function applyColumn(key, visible) {
        document.querySelectorAll('[data-col="' + key + '"]').forEach(el => el.classList.toggle('col-hidden', !visible));
    }

    const prefs = loadPrefs();
    panel.querySelectorAll('input[data-widget]').forEach(cb => {
        const key = cb.dataset.widget;
        const visible = prefs['w_' + key] !== false;
        cb.checked = visible;
        applyWidget(key, visible);
        cb.addEventListener('change', function () { const p = loadPrefs(); p['w_' + key] = this.checked; savePrefs(p); applyWidget(key, this.checked); });
    });
    panel.querySelectorAll('input[data-col]').forEach(cb => {
        const key = cb.dataset.col;
        const visible = prefs['c_' + key] !== false;
        cb.checked = visible;
        applyColumn(key, visible);
        cb.addEventListener('change', function () { const p = loadPrefs(); p['c_' + key] = this.checked; savePrefs(p); applyColumn(key, this.checked); });
    });

    const subToggle = document.getElementById('cardsSubToggle');
    const subList = document.getElementById('cardsSubList');
    if (subToggle && subList) {
        subToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            subList.classList.toggle('open');
            subToggle.classList.toggle('open');
        });
    }

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
    $('#admin-table').DataTable({
        order: [],
        pageLength: 20,
        lengthMenu: [[20, 50, 100, 150, 200, -1], [20, 50, 100, 150, 200, 'View All']],
        columnDefs: [{ orderable: false, targets: [3, 6, 7] }]
    });
});
</script>
</body>
</html>
