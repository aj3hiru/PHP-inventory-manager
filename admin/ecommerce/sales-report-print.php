<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
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

// ── Resolve the same date range logic as Sales History ──────────────────────
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

$sale_type = $_GET['sale_type'] ?? 'all';
if (!in_array($sale_type, ['all', 'offline', 'online'], true)) $sale_type = 'all';

$type_condition = "((o.order_type = 'offline') OR (o.order_type = 'online' AND o.order_status = 'Delivered'))";
if ($sale_type === 'offline') $type_condition = "o.order_type = 'offline'";
if ($sale_type === 'online')  $type_condition = "(o.order_type = 'online' AND o.order_status = 'Delivered')";
$sale_type_label = ['all' => 'All Sales', 'offline' => 'Store Sales Only', 'online' => 'Online Sales Only'][$sale_type];

// ── 1. Sales in this period ──────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT o.*,
           (SELECT GROUP_CONCAT(CONCAT(oi.qty, 'x ', oi.product_name) SEPARATOR ', ')
              FROM ecom_order_items oi WHERE oi.order_id = o.id) AS items_summary,
           c.amount AS credit_amount, c.amount_paid AS credit_paid
    FROM ecom_orders o
    LEFT JOIN ecom_credits c ON c.order_id = o.id
    WHERE $type_condition AND o.created_at BETWEEN ? AND ?
    ORDER BY o.created_at ASC
");
$stmt->execute([$range_start, $range_end]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sales_total = 0; $sales_paid = 0; $sales_due = 0;
$by_method_report = ['Cash' => 0, 'UPI' => 0, 'Card' => 0, 'Other' => 0];
foreach ($sales as &$s) {
    if ($s['credit_amount'] !== null) {
        $paid_at_sale = (float)$s['total_amount'] - (float)$s['credit_amount'];
        $s['live_paid'] = min((float)$s['total_amount'], $paid_at_sale + (float)$s['credit_paid']);
    } else {
        $s['live_paid'] = $s['paid_amount'] !== null ? (float)$s['paid_amount'] : (float)$s['total_amount'];
    }
    $s['live_due'] = max(0, (float)$s['total_amount'] - $s['live_paid']);
    $sales_total += (float)$s['total_amount'];
    $sales_paid  += $s['live_paid'];
    $sales_due   += $s['live_due'];
    $m = in_array($s['payment_method'], ['Cash', 'UPI', 'Card'], true) ? $s['payment_method'] : 'Other';
    $by_method_report[$m] += (float)$s['total_amount'];
}
unset($s);

// ── 2. New dues created in this period ───────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*, o.order_number
    FROM ecom_credits c
    LEFT JOIN ecom_orders o ON c.order_id = o.id
    WHERE c.created_at BETWEEN ? AND ?
    ORDER BY c.created_at ASC
");
$stmt->execute([$range_start, $range_end]);
$new_dues = $stmt->fetchAll(PDO::FETCH_ASSOC);
$new_dues_total = array_sum(array_column($new_dues, 'amount'));

// ── 3. Due collections (payments) in this period ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT cp.*, c.customer_name, c.order_id, o.order_number
    FROM ecom_credit_payments cp
    JOIN ecom_credits c ON cp.credit_id = c.id
    LEFT JOIN ecom_orders o ON c.order_id = o.id
    WHERE cp.created_at BETWEEN ? AND ?
    ORDER BY cp.created_at ASC
");
$stmt->execute([$range_start, $range_end]);
$collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
$collections_total = array_sum(array_column($collections, 'amount'));

// ── 4. Online orders breakdown for this period (all statuses) ───────────────
$stmt = $pdo->prepare("SELECT * FROM ecom_orders WHERE order_type = 'online' AND created_at BETWEEN ? AND ? ORDER BY created_at ASC");
$stmt->execute([$range_start, $range_end]);
$online_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$online_counts = ['Pending' => 0, 'In Progress' => 0, 'Delivered' => 0, 'Canceled' => 0];
foreach ($online_orders as $oo) {
    if (isset($online_counts[$oo['order_status']])) $online_counts[$oo['order_status']]++;
}

$biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$biz_numbers = !empty($biz['contact_numbers']) ? (json_decode($biz['contact_numbers'], true) ?: []) : (!empty($biz['phone']) ? [$biz['phone']] : []);
$show_gstin = (!isset($biz['show_gstin_on_invoice']) || (int)$biz['show_gstin_on_invoice'] === 1) && !empty($biz['gstin']);
$show_pan   = (!isset($biz['show_pan_on_invoice']) || (int)$biz['show_pan_on_invoice'] === 1) && !empty($biz['pan_number']);
$show_fssai = (!isset($biz['show_fssai_on_invoice']) || (int)$biz['show_fssai_on_invoice'] === 1) && !empty($biz['fssai_number']);
$show_addr  = (!isset($biz['show_address_on_invoice']) || (int)$biz['show_address_on_invoice'] === 1) && !empty($biz['address']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Report — <?= htmlspecialchars($range_label) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@page { size: A4; margin: 15mm; }
body { font-family: 'Inter', Arial, sans-serif; background: #f3f4f6; color: #1f2937; }
.report-page { max-width: 900px; margin: 1.5rem auto; background: #fff; padding: 2.5rem; border-radius: 0.5rem; box-shadow: 0 0.15rem 1.75rem rgba(58,59,69,.1); }
.report-head { text-align: center; border-bottom: 2px solid #1f2937; padding-bottom: 1rem; margin-bottom: 1.5rem; }
.report-head h1 { font-size: 1.5rem; font-weight: 800; color: #7c3aed; margin-bottom: 0.2rem; }
.report-meta { font-size: 0.85rem; color: #6b7280; }
.summary-box-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 2rem; }
.summary-box { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.85rem; text-align: center; }
.summary-box .num { font-size: 1.25rem; font-weight: 800; }
.summary-box .lbl { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; letter-spacing: 0.02em; margin-top: 0.2rem; }
.section-title { font-size: 1.05rem; font-weight: 700; margin: 2rem 0 0.75rem; padding-bottom: 0.4rem; border-bottom: 2px solid #e5e7eb; display: flex; align-items: center; gap: 0.5rem; }
table.report-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; margin-bottom: 0.5rem; }
table.report-table th { background: #f9fafb; text-align: left; padding: 0.5rem 0.6rem; border: 1px solid #e5e7eb; font-size: 0.7rem; text-transform: uppercase; color: #6b7280; }
table.report-table td { padding: 0.5rem 0.6rem; border: 1px solid #f3f4f6; }
table.report-table tfoot td { font-weight: 700; background: #f9fafb; }
.text-end { text-align: right; }
.empty-note { color: #9ca3af; font-style: italic; padding: 0.75rem 0; }
.print-bar { max-width: 900px; margin: 0 auto; display: flex; justify-content: flex-end; padding-top: 1rem; }
@media print { .print-bar { display: none; } body { background: #fff; } .report-page { box-shadow: none; margin: 0; max-width: 100%; } }
</style>
</head>
<body>

<div class="print-bar">
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
</div>

<div class="report-page">
    <div class="report-head">
        <?php if (!empty($biz['logo'])): ?><img src="/<?= htmlspecialchars(ltrim($biz['logo'], '/')) ?>" style="max-height:60px; margin-bottom:0.5rem;"><?php endif; ?>
        <h1><?= htmlspecialchars($biz['business_name'] ?: $site_name) ?></h1>
        <?php if (!empty($biz['tagline'])): ?><div class="report-meta"><?= htmlspecialchars($biz['tagline']) ?></div><?php endif; ?>
        <?php if ($show_addr): ?><div class="report-meta"><?= nl2br(htmlspecialchars($biz['address'])) ?></div><?php endif; ?>
        <div class="report-meta">
            <?php if (!empty($biz_numbers)): ?>Tel: <?= htmlspecialchars(implode(', ', array_filter($biz_numbers))) ?><?php endif; ?>
            <?php if (!empty($biz['email'])): ?> · <?= htmlspecialchars($biz['email']) ?><?php endif; ?>
            <?php if (!empty($biz['website_url'])): ?> · <?= htmlspecialchars($biz['website_url']) ?><?php endif; ?>
        </div>
        <div class="report-meta">
            <?php if ($show_gstin): ?>GSTIN: <?= htmlspecialchars($biz['gstin']) ?><?php endif; ?>
            <?php if ($show_pan): ?> · PAN: <?= htmlspecialchars($biz['pan_number']) ?><?php endif; ?>
            <?php if ($show_fssai): ?> · FSSAI: <?= htmlspecialchars($biz['fssai_number']) ?><?php endif; ?>
        </div>
        <hr style="margin:0.75rem 0;">
        <div class="fw-bold" style="font-size:1.1rem;">Sales Report</div>
        <div class="report-meta">
            Period: <?= htmlspecialchars($range_label) ?> (<?= date('d M Y', strtotime($date_from)) ?> – <?= date('d M Y', strtotime($date_to)) ?>)
            · <?= htmlspecialchars($sale_type_label) ?>
            <br>Generated: <?= date('d M Y, h:i A') ?>
        </div>
    </div>

    <div class="summary-box-grid">
        <div class="summary-box"><div class="num">₹<?= number_format($sales_total, 2) ?></div><div class="lbl">Total Sales</div></div>
        <div class="summary-box"><div class="num" style="color:#059669;">₹<?= number_format($sales_paid, 2) ?></div><div class="lbl">Total Collected</div></div>
        <div class="summary-box"><div class="num" style="color:#d97706;">₹<?= number_format($new_dues_total, 2) ?></div><div class="lbl">New Due Created</div></div>
        <div class="summary-box"><div class="num" style="color:#059669;">₹<?= number_format($collections_total, 2) ?></div><div class="lbl">Due Collected</div></div>
    </div>

    <!-- SECTION 1: SALES -->
    <div class="section-title"><i class="fas fa-receipt"></i> Sales (<?= count($sales) ?>)</div>
    <?php if (empty($sales)): ?>
    <p class="empty-note">No sales recorded in this period.</p>
    <?php else: ?>
    <table class="report-table">
        <thead>
            <tr>
                <th>Date</th><th>Order #</th><th>Customer</th><th>Type</th><th>Items</th><th>Payment</th>
                <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sales as $s): ?>
        <tr>
            <td><?= date('d/m/Y h:i A', strtotime($s['created_at'])) ?></td>
            <td><?= htmlspecialchars($s['order_number']) ?></td>
            <td><?= htmlspecialchars($s['customer_name'] ?: 'Walk-in') ?></td>
            <td><?= ucfirst($s['order_type']) ?></td>
            <td><?= htmlspecialchars($s['items_summary'] ?: '—') ?></td>
            <td><?= htmlspecialchars($s['payment_method'] ?: '—') ?></td>
            <td class="text-end">₹<?= number_format((float)$s['total_amount'], 2) ?></td>
            <td class="text-end">₹<?= number_format($s['live_paid'], 2) ?></td>
            <td class="text-end"><?= $s['live_due'] > 0.004 ? '₹' . number_format($s['live_due'], 2) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">Totals</td>
                <td class="text-end">₹<?= number_format($sales_total, 2) ?></td>
                <td class="text-end">₹<?= number_format($sales_paid, 2) ?></td>
                <td class="text-end">₹<?= number_format($sales_due, 2) ?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- SECTION: ONLINE ORDERS OVERVIEW -->
    <div class="section-title"><i class="fas fa-globe"></i> Online Orders Overview (<?= count($online_orders) ?>)</div>
    <div class="summary-box-grid" style="grid-template-columns: repeat(5, 1fr);">
        <div class="summary-box"><div class="num"><?= count($online_orders) ?></div><div class="lbl">Total Online</div></div>
        <div class="summary-box"><div class="num" style="color:#d97706;"><?= $online_counts['Pending'] ?></div><div class="lbl">Pending</div></div>
        <div class="summary-box"><div class="num" style="color:#2563eb;"><?= $online_counts['In Progress'] ?></div><div class="lbl">In Progress</div></div>
        <div class="summary-box"><div class="num" style="color:#059669;"><?= $online_counts['Delivered'] ?></div><div class="lbl">Delivered</div></div>
        <div class="summary-box"><div class="num" style="color:#dc2626;"><?= $online_counts['Canceled'] ?></div><div class="lbl">Canceled</div></div>
    </div>
    <?php if (empty($online_orders)): ?>
    <p class="empty-note">No online orders were placed in this period.</p>
    <?php else: ?>
    <table class="report-table">
        <thead>
            <tr><th>Date</th><th>Order #</th><th>Customer</th><th>Status</th><th>Payment</th><th class="text-end">Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($online_orders as $oo): ?>
        <tr>
            <td><?= date('d/m/Y h:i A', strtotime($oo['created_at'])) ?></td>
            <td><?= htmlspecialchars($oo['order_number']) ?></td>
            <td><?= htmlspecialchars($oo['customer_name'] ?: '—') ?></td>
            <td><?= htmlspecialchars($oo['order_status']) ?></td>
            <td><?= htmlspecialchars($oo['payment_status']) ?></td>
            <td class="text-end">₹<?= number_format((float)$oo['total_amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="5">Total</td><td class="text-end">₹<?= number_format(array_sum(array_column($online_orders, 'total_amount')), 2) ?></td></tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- SECTION 2: NEW DUE CREATED -->
    <div class="section-title"><i class="fas fa-hand-holding-usd"></i> New Due Created (<?= count($new_dues) ?>)</div>
    <?php if (empty($new_dues)): ?>
    <p class="empty-note">No new due was created in this period.</p>
    <?php else: ?>
    <table class="report-table">
        <thead>
            <tr><th>Date</th><th>Customer</th><th>From Order</th><th class="text-end">Amount</th><th>Promised Date</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($new_dues as $d): ?>
        <tr>
            <td><?= date('d/m/Y h:i A', strtotime($d['created_at'])) ?></td>
            <td><?= htmlspecialchars($d['customer_name']) ?></td>
            <td><?= htmlspecialchars($d['order_number'] ?: '—') ?></td>
            <td class="text-end">₹<?= number_format((float)$d['amount'], 2) ?></td>
            <td><?= $d['promised_date'] ? date('d/m/Y', strtotime($d['promised_date'])) : '—' ?></td>
            <td><?= ucfirst($d['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="3">Total</td><td class="text-end">₹<?= number_format($new_dues_total, 2) ?></td><td colspan="2"></td></tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- SECTION 3: DUE COLLECTED (PAYMENTS) -->
    <div class="section-title"><i class="fas fa-cash-register"></i> Due Collected (<?= count($collections) ?>)</div>
    <?php if (empty($collections)): ?>
    <p class="empty-note">No due payments were collected in this period.</p>
    <?php else: ?>
    <table class="report-table">
        <thead>
            <tr><th>Date</th><th>Receipt #</th><th>Customer</th><th>Against Order</th><th>Method</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($collections as $c): ?>
        <tr>
            <td><?= date('d/m/Y h:i A', strtotime($c['created_at'])) ?></td>
            <td><?= htmlspecialchars($c['receipt_number']) ?></td>
            <td><?= htmlspecialchars($c['customer_name']) ?></td>
            <td><?= htmlspecialchars($c['order_number'] ?: '—') ?></td>
            <td><?= htmlspecialchars($c['payment_method']) ?></td>
            <td class="text-end">₹<?= number_format((float)$c['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="5">Total</td><td class="text-end">₹<?= number_format($collections_total, 2) ?></td></tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- PAYMENT METHOD SUMMARY -->
    <div class="section-title"><i class="fas fa-wallet"></i> Payment Method Summary</div>
    <table class="report-table">
        <thead><tr><th>Method</th><th class="text-end">Total Amount</th></tr></thead>
        <tbody>
            <tr><td>Cash</td><td class="text-end">₹<?= number_format($by_method_report['Cash'], 2) ?></td></tr>
            <tr><td>UPI</td><td class="text-end">₹<?= number_format($by_method_report['UPI'], 2) ?></td></tr>
            <tr><td>Card</td><td class="text-end">₹<?= number_format($by_method_report['Card'], 2) ?></td></tr>
            <?php if ($by_method_report['Other'] > 0.004): ?>
            <tr><td>Other</td><td class="text-end">₹<?= number_format($by_method_report['Other'], 2) ?></td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr><td>Total</td><td class="text-end">₹<?= number_format($sales_total, 2) ?></td></tr>
        </tfoot>
    </table>

    <p class="text-muted text-center mt-4 mb-0" style="font-size:0.8rem;">— End of Report —</p>
</div>

</body>
</html>
