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
if (!$user || $user['status'] !== 'active' || empty($permissions['dashboard_access'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

function scalar($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetchColumn() ?: 0);
}

// ── Date range filter ────────────────────────────────────────────────────────
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
        // Guard against a reversed range
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
// Inclusive end-of-day boundary for created_at comparisons
$range_start = $date_from . ' 00:00:00';
$range_end   = $date_to . ' 23:59:59';

// ── Online Platform ──────────────────────────────────────────────────────────
$on_total     = scalar($pdo, "SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$on_pending   = scalar($pdo, "SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='Pending' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$on_progress  = scalar($pdo, "SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='In Progress' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$on_delivered = scalar($pdo, "SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='Delivered' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$on_canceled  = scalar($pdo, "SELECT COUNT(*) FROM ecom_orders WHERE order_type='online' AND order_status='Canceled' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$on_customers = scalar($pdo, "SELECT COUNT(*) FROM ecom_customers WHERE customer_type='online' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);

// ── Offline Customers (standalone, no order-status breakdown needed) ────────
$off_customers = scalar($pdo, "SELECT COUNT(*) FROM ecom_customers WHERE customer_type='offline' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);

// ── Earnings — follows the selected date range ──────────────────────────────
$period_earning = scalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE payment_status='Paid' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);

// ── Payment method breakdown for the selected period ─────────────────────────
$pm_cash = scalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE payment_method='Cash' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$pm_upi  = scalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE payment_method='UPI' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$pm_card = scalar($pdo, "SELECT COALESCE(SUM(total_amount),0) FROM ecom_orders WHERE payment_method='Card' AND created_at BETWEEN ? AND ?", [$range_start, $range_end]);

// ── Due ──────────────────────────────────────────────────────────────────────
$period_new_due    = scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM ecom_credits WHERE created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$period_due_collection = scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM ecom_credit_payments WHERE created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$period_due_promise = scalar($pdo, "SELECT COALESCE(SUM(amount - amount_paid),0) FROM ecom_credits WHERE status='pending' AND promised_date BETWEEN ? AND ?", [$date_from, $date_to]);

// ── Catalog (always live — never filtered by date) ──────────────────────────
$total_products    = scalar($pdo, "SELECT COUNT(*) FROM ecom_products");
$total_categories  = scalar($pdo, "SELECT COUNT(*) FROM ecom_categories");
$total_brands      = scalar($pdo, "SELECT COUNT(*) FROM ecom_brands");
$out_of_stock      = scalar($pdo, "SELECT COUNT(*) FROM ecom_products WHERE product_type='physical' AND (stock_qty IS NULL OR stock_qty<=0)");

// ── Customers ────────────────────────────────────────────────────────────────
$period_customers     = scalar($pdo, "SELECT COUNT(*) FROM ecom_customers WHERE created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$period_new_customers = scalar($pdo, "SELECT COUNT(*) FROM ecom_customers WHERE created_at BETWEEN ? AND ?", [$range_start, $range_end]);

// ── Reviews ──────────────────────────────────────────────────────────────────
$period_reviews_today = scalar($pdo, "SELECT COUNT(*) FROM ecom_product_reviews WHERE created_at BETWEEN ? AND ?", [$range_start, $range_end]);
$period_reviews_total = scalar($pdo, "SELECT COUNT(*) FROM ecom_product_reviews WHERE created_at BETWEEN ? AND ?", [$range_start, $range_end]);

// ── Coupons (always live — never filtered by date) ──────────────────────────
$active_coupons = scalar($pdo, "SELECT COUNT(*) FROM ecom_coupons WHERE status='active'");

// ── Recent orders within the selected period ────────────────────────────────
$recent_orders = $pdo->prepare("
    SELECT o.*,
           (SELECT GROUP_CONCAT(CONCAT(oi.qty, 'x ', oi.product_name) SEPARATOR ', ')
              FROM ecom_order_items oi WHERE oi.order_id = o.id) AS items_summary
    FROM ecom_orders o
    WHERE o.created_at BETWEEN ? AND ? ORDER BY o.created_at DESC LIMIT 8
");
$recent_orders->execute([$range_start, $range_end]);
$recent_orders = $recent_orders->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'E-commerce Dashboard';
$page_subtitle = 'A live overview of your store';

function statCard($color, $icon, $label, $value, $widgetKey = null, $url = null) {
    $colors = [
        'green'  => '#1cc88a',
        'blue'   => '#4361ee',
        'red'    => '#e74a5b',
        'cyan'   => '#36b9cc',
        'orange' => '#f6c23e',
    ];
    $bg = $colors[$color] ?? '#4361ee';
    $widgetAttr = $widgetKey ? ' data-widget="' . htmlspecialchars($widgetKey) . '"' : '';
    $tag = $url ? 'a' : 'div';
    $hrefAttr = $url ? ' href="' . htmlspecialchars($url) . '"' : '';
    $clickableClass = $url ? ' stat-card-e-clickable' : '';
    echo '<div class="col-sm-6 col-lg-3 mb-3"' . $widgetAttr . '>
        <' . $tag . $hrefAttr . ' class="stat-card-e' . $clickableClass . '">
            <div class="stat-card-e-corner" style="background:' . $bg . '"></div>
            <div class="stat-icon-e" style="background:' . $bg . '"><i class="fas ' . $icon . '"></i></div>
            <div>
                <div class="stat-label-e">' . htmlspecialchars($label) . '</div>
                <div class="stat-value-e">' . $value . '</div>
            </div>
        </' . $tag . '>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include DROOT_PATH . '/admin/ecommerce/components/ecom-head.php'; ?>
<style>
.stat-card-e { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 0.5rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,.1); padding: 1.25rem; display: flex; align-items: center; gap: 1rem; height: 100%; position: relative; overflow: hidden; }
.stat-card-e-corner { position: absolute; top: -30px; right: -30px; width: 100px; height: 100px; border-radius: 50%; opacity: 0.08; pointer-events: none; }
.stat-icon-e { width: 52px; height: 52px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.25rem; flex-shrink: 0; }
.stat-label-e { font-size: 0.875rem; color: var(--gray-500); margin-bottom: 0.2rem; }
.stat-value-e { font-size: 1.375rem; font-weight: 700; color: var(--gray-900); }
.section-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-400); margin: 1.5rem 0 0.75rem; }

/* Date range filter bar */
.range-bar { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; }
.range-bar .btn-group .btn { font-size: 0.8125rem; }
.range-bar-custom { display: flex; align-items: center; gap: 0.4rem; margin-left: auto; }
.range-bar-label { font-size: 0.8125rem; color: var(--gray-500); margin-right: auto; }

/* Display Options panel (same pattern as the Blog dashboard) */
.db-display-wrap { position: relative; }
.db-display-panel {
    position: absolute; top: calc(100% + 0.5rem); right: 0; z-index: 20;
    min-width: 240px;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 0.5rem;
    padding: 0.75rem;
    box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,.15);
    display: none;
    flex-direction: column;
    gap: 0.125rem;
    max-height: 70vh;
    overflow-y: auto;
}
.db-display-panel.open { display: flex; }
.db-display-check {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.5rem 0.625rem; border-radius: 7px;
    font-size: 0.8438rem; color: var(--gray-700);
    cursor: pointer; user-select: none; transition: background 0.15s;
}
.db-display-check:hover { background: var(--gray-50); }
.db-display-check input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }
.db-display-divider { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; color: var(--gray-400); padding: 0.5rem 0.625rem 0.2rem; }
.stat-card-e-clickable { text-decoration: none; cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; }
.stat-card-e-clickable:hover { transform: translateY(-2px); box-shadow: 0 0.5rem 1.5rem rgba(58,59,69,.15); }
.db-display-check { position: relative; }
.db-subtoggle { margin-left: auto; background: none; border: none; color: var(--gray-400); padding: 0.2rem; cursor: pointer; transition: transform 0.15s; }
.db-subtoggle.open { transform: rotate(180deg); }
.db-display-sublist { display: none; flex-direction: column; align-items: center; text-align: center; padding: 0.25rem 0 0.4rem; background: var(--gray-50); border-radius: 7px; margin: 0 0.3rem 0.3rem; }
.db-display-sublist.open { display: flex; }
.db-display-check.db-sub { justify-content: center; padding-left: 1.5rem; }
[data-widget].db-card-hidden { display: none !important; }
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
                <?php include DROOT_PATH . '/admin/components/global-search.php'; ?>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <!-- DATE RANGE FILTER -->
            <div class="range-bar">
                <span class="range-bar-label">Showing: <strong><?= htmlspecialchars($range_label) ?></strong></span>
                <div class="btn-group">
                    <a href="?range=today" class="btn btn-sm <?= $range === 'today' ? 'btn-primary' : 'btn-outline-secondary' ?>">Today</a>
                    <a href="?range=yesterday" class="btn btn-sm <?= $range === 'yesterday' ? 'btn-primary' : 'btn-outline-secondary' ?>">Yesterday</a>
                    <a href="?range=7days" class="btn btn-sm <?= $range === '7days' ? 'btn-primary' : 'btn-outline-secondary' ?>">7 Days</a>
                    <a href="?range=this_month" class="btn btn-sm <?= $range === 'this_month' ? 'btn-primary' : 'btn-outline-secondary' ?>">This Month</a>
                    <a href="?range=prev_month" class="btn btn-sm <?= $range === 'prev_month' ? 'btn-primary' : 'btn-outline-secondary' ?>">Previous Month</a>
                </div>
                <form method="GET" class="range-bar-custom">
                    <input type="hidden" name="range" value="custom">
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>" style="width:150px;">
                    <span class="text-muted small">to</span>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>" style="width:150px;">
                    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
                </form>

                <div class="db-display-wrap">
                    <button class="btn btn-secondary btn-sm" id="displayOptionsToggle" type="button">
                        <i class="fas fa-sliders-h"></i> Display Options <i class="fas fa-chevron-down" id="doArrow"></i>
                    </button>
                    <div class="db-display-panel" id="displayOptionsPanel">
                        <div class="db-display-divider">Sections</div>

                        <label class="db-display-check">
                            <input type="checkbox" data-widget="online" checked> Online Platform
                            <button type="button" class="db-subtoggle" data-target="sub-online"><i class="fas fa-chevron-down"></i></button>
                        </label>
                        <div class="db-display-sublist" data-sublist="sub-online">
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-total" checked> Total Orders</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-pending" checked> Pending Orders</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-progress" checked> In Progress</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-delivered" checked> Delivered Orders</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-canceled" checked> Canceled Orders</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-custonline" checked> Total Online Customers</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-on-custoffline" checked> Total Offline Customers</label>
                        </div>

                        <label class="db-display-check">
                            <input type="checkbox" data-widget="earnings" checked> Earnings &amp; Due
                            <button type="button" class="db-subtoggle" data-target="sub-earnings"><i class="fas fa-chevron-down"></i></button>
                        </label>
                        <div class="db-display-sublist" data-sublist="sub-earnings">
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-earning" checked> Earning</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-newdue" checked> New Due</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-duecollection" checked> Due Collection</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-duepromise" checked> Due Promise</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-cash" checked> Cash</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-upi" checked> UPI</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-card" checked> Card</label>
                        </div>

                        <label class="db-display-check">
                            <input type="checkbox" data-widget="overview" checked> Store Overview
                            <button type="button" class="db-subtoggle" data-target="sub-overview"><i class="fas fa-chevron-down"></i></button>
                        </label>
                        <div class="db-display-sublist" data-sublist="sub-overview">
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-products" checked> Total Products</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-outofstock" checked> Out of Stock</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-categories" checked> Total Categories</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-brands" checked> Total Brands</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-customers" checked> Customers</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-newcustomers" checked> New Customers</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-reviewstoday" checked> Reviews (Period)</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-reviewstotal" checked> Total Reviews</label>
                            <label class="db-display-check db-sub"><input type="checkbox" data-widget="card-coupons" checked> Active Coupons</label>
                        </div>

                        <label class="db-display-check"><input type="checkbox" data-widget="recentorders" checked> Recent Orders</label>
                    </div>
                </div>
            </div>

            <!-- ONLINE PLATFORM -->
            <div data-widget="online">
                <div class="section-label"><i class="fas fa-globe"></i> Online Platform</div>
                <div class="row">
                    <?php
                    statCard('green', 'fa-shopping-cart', 'Total Orders', number_format($on_total), 'card-on-total', 'ecommerce/orders.php');
                    statCard('green', 'fa-hourglass-half', 'Pending Orders', number_format($on_pending), 'card-on-pending', 'ecommerce/orders.php?type=Pending');
                    statCard('green', 'fa-truck-loading', 'In Progress', number_format($on_progress), 'card-on-progress', 'ecommerce/orders.php?type=In Progress');
                    statCard('green', 'fa-check-circle', 'Delivered Orders', number_format($on_delivered), 'card-on-delivered', 'ecommerce/orders.php?type=Delivered');
                    statCard('red', 'fa-ban', 'Canceled Orders', number_format($on_canceled), 'card-on-canceled', 'ecommerce/orders.php?type=Canceled');
                    statCard('cyan', 'fa-user-plus', 'Total Online Customers', number_format($on_customers), 'card-on-custonline', 'ecommerce/customers.php');
                    statCard('orange', 'fa-user-plus', 'Total Offline Customers', number_format($off_customers), 'card-on-custoffline', 'ecommerce/customers.php');
                    ?>
                </div>
            </div>

            <!-- EARNINGS & DUE -->
            <div data-widget="earnings">
                <div class="section-label"><i class="fas fa-hand-holding-usd"></i> Earnings &amp; Due (<?= htmlspecialchars($range_label) ?>)</div>
                <div class="row">
                    <?php
                    statCard('red', 'fa-rupee-sign', "Earning ({$range_label})", '₹' . number_format($period_earning, 2), 'card-earning', 'ecommerce/sales-history.php');
                    statCard('orange', 'fa-plus-circle', 'New Due', '₹' . number_format($period_new_due, 2), 'card-newdue', 'ecommerce/due.php');
                    statCard('cyan', 'fa-cash-register', 'Due Collection', '₹' . number_format($period_due_collection, 2), 'card-duecollection', 'ecommerce/due.php');
                    statCard('orange', 'fa-calendar-check', 'Due Promise', '₹' . number_format($period_due_promise, 2), 'card-duepromise', 'ecommerce/due.php');
                    ?>
                </div>

                <div class="section-label">Payment Method (<?= htmlspecialchars($range_label) ?>)</div>
                <div class="row">
                    <?php
                    statCard('green', 'fa-money-bill-wave', 'Cash', '₹' . number_format($pm_cash, 2), 'card-cash', 'ecommerce/sales-history.php');
                    statCard('blue', 'fa-mobile-alt', 'UPI', '₹' . number_format($pm_upi, 2), 'card-upi', 'ecommerce/sales-history.php');
                    statCard('cyan', 'fa-credit-card', 'Card', '₹' . number_format($pm_card, 2), 'card-card', 'ecommerce/sales-history.php');
                    ?>
                </div>
            </div>

            <!-- STORE OVERVIEW (catalog, customers, reviews, coupons combined) -->
            <div data-widget="overview">
                <div class="section-label">Store Overview</div>
                <div class="row">
                    <?php
                    statCard('blue', 'fa-boxes', 'Total Products', number_format($total_products), 'card-products', 'ecommerce/products.php');
                    statCard('red', 'fa-box-open', 'Out of Stock', number_format($out_of_stock), 'card-outofstock', 'ecommerce/stock-out-products.php');
                    statCard('blue', 'fa-list', 'Total Categories', number_format($total_categories), 'card-categories', 'ecommerce/categories.php');
                    statCard('blue', 'fa-copyright', 'Total Brands', number_format($total_brands), 'card-brands', 'ecommerce/brands.php');
                    statCard('cyan', 'fa-users', 'Customers', number_format($period_customers), 'card-customers', 'ecommerce/customers.php');
                    statCard('cyan', 'fa-user-plus', 'New Customers', number_format($period_new_customers), 'card-newcustomers', 'ecommerce/customers.php');
                    statCard('cyan', 'fa-star-half-alt', "Reviews ({$range_label})", number_format($period_reviews_today), 'card-reviewstoday', 'ecommerce/product-reviews.php');
                    statCard('cyan', 'fa-star', 'Total Reviews', number_format($period_reviews_total), 'card-reviewstotal', 'ecommerce/product-reviews.php');
                    statCard('orange', 'fa-percentage', 'Active Coupons', number_format($active_coupons), 'card-coupons', 'ecommerce/coupons.php');
                    ?>
                </div>
            </div>

            <div class="gd-card mt-3" data-widget="recentorders">
                <div class="gd-card-body">
                    <h5 class="mb-3"><i class="fas fa-clock text-primary"></i> Recent Orders <small class="text-muted">(<?= htmlspecialchars($range_label) ?>)</small></h5>
                    <?php if (empty($recent_orders)): ?>
                        <p class="text-muted mb-0">No orders in this period.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr><th>Order #</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_orders as $o): ?>
                            <tr>
                                <td><a href="/admin/ecommerce/order-view.php?id=<?= $o['id'] ?>"><?= htmlspecialchars($o['order_number']) ?></a></td>
                                <td><?= htmlspecialchars($o['customer_name'] ?: '—') ?></td>
                                <td>
                                    <?php $items_txt = $o['items_summary'] ?: '—'; ?>
                                    <span title="<?= htmlspecialchars($items_txt) ?>" style="display:inline-block; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom;">
                                        <?= htmlspecialchars($items_txt) ?>
                                    </span>
                                </td>
                                <td>₹<?= number_format((float)$o['total_amount'], 2) ?></td>
                                <td><span class="badge <?= $o['payment_status'] === 'Paid' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($o['payment_status']) ?></span></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($o['order_status']) ?></span></td>
                                <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
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

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include DROOT_PATH . '/admin/ecommerce/components/ecom-scripts.php'; ?>
<script>
/* ── Display Options (show/hide dashboard sections, remembered per browser) ── */
(function () {
    const PREF_KEY = 'ecom_dashboard_widgets';
    const toggle = document.getElementById('displayOptionsToggle');
    const panel  = document.getElementById('displayOptionsPanel');
    const arrow  = document.getElementById('doArrow');
    if (!toggle || !panel) return;

    function loadPrefs() {
        try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}'); } catch { return {}; }
    }
    function savePrefs(prefs) {
        localStorage.setItem(PREF_KEY, JSON.stringify(prefs));
    }
    function applyVisibility(key, visible) {
        document.querySelectorAll('[data-widget="' + key + '"]').forEach(el => {
            el.classList.toggle('db-card-hidden', !visible);
        });
    }

    const prefs = loadPrefs();
    panel.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        const key = cb.dataset.widget;
        const visible = prefs[key] !== false;
        cb.checked = visible;
        applyVisibility(key, visible);
        cb.addEventListener('change', function () {
            const p = loadPrefs();
            p[key] = this.checked;
            savePrefs(p);
            applyVisibility(key, this.checked);
        });
    });

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = panel.classList.toggle('open');
        arrow.style.transform = isOpen ? 'rotate(180deg)' : '';
    });
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            panel.classList.remove('open');
            arrow.style.transform = '';
        }
    });

    panel.querySelectorAll('.db-subtoggle').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const sublist = panel.querySelector('[data-sublist="' + this.dataset.target + '"]');
            if (sublist) sublist.classList.toggle('open');
            this.classList.toggle('open');
        });
    });
})();
</script>
</body>
</html>
