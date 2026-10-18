<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || (empty($permissions['ecommerce']['manage_orders']) && empty($permissions['ecommerce']['manage_customers']) && empty($permissions['ecommerce']['manage_billing']))) {
    exit('Access Denied');
}

$order_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM ecom_orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { exit('Order not found.'); }

$items = $pdo->prepare("SELECT * FROM ecom_order_items WHERE order_id = ?");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$customer = null;
if (!empty($order['customer_id'])) {
    $c = $pdo->prepare("SELECT * FROM ecom_customers WHERE id = ?");
    $c->execute([$order['customer_id']]);
    $customer = $c->fetch(PDO::FETCH_ASSOC);
}

$biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$show_gstin_on_invoice = !isset($biz['show_gstin_on_invoice']) || (int)$biz['show_gstin_on_invoice'] === 1;
$show_pan_on_invoice   = !isset($biz['show_pan_on_invoice']) || (int)$biz['show_pan_on_invoice'] === 1;
$has_gstin = !empty($biz['gstin']) && $show_gstin_on_invoice;
$has_pan   = !empty($biz['pan_number']) && $show_pan_on_invoice;
$show_fssai_on_invoice = !isset($biz['show_fssai_on_invoice']) || (int)$biz['show_fssai_on_invoice'] === 1;
$has_fssai = !empty($biz['fssai_number']) && $show_fssai_on_invoice;

$item_count = count($items);
$total_qty  = array_sum(array_map(fn($it) => (float)$it['qty'], $items));

$invoice_display = in_array($biz['invoice_display'] ?? 'both', ['logo','name','both'], true) ? $biz['invoice_display'] : 'both';
$invoice_numbers = !empty($biz['invoice_contact_numbers']) ? (json_decode($biz['invoice_contact_numbers'], true) ?: []) : (!empty($biz['phone']) ? [$biz['phone']] : []);
$show_address_on_invoice  = !isset($biz['show_address_on_invoice']) || (int)$biz['show_address_on_invoice'] === 1;
$show_location_on_invoice = !isset($biz['show_location_on_invoice']) || (int)$biz['show_location_on_invoice'] === 1;

// ── Force a specific format via ?format=a4|thermal_58|thermal_80, else use the business default
$format = $_GET['format'] ?? ($biz['printer_format'] ?? 'a4');
if (!in_array($format, ['a4', 'thermal_58', 'thermal_80'], true)) $format = 'a4';
$is_thermal = strpos($format, 'thermal_') === 0;
$thermal_width = $format === 'thermal_58' ? '58mm' : '80mm';

$computed_subtotal = 0;
foreach ($items as $it) { $computed_subtotal += (float)$it['price'] * (int)$it['qty']; }

$grand_total = (float)$order['total_amount'];
$subtotal    = (float)($order['subtotal_amount'] ?? 0) > 0 ? (float)$order['subtotal_amount'] : $computed_subtotal;
$discount    = (float)($order['discount_amount'] ?? 0) > 0 ? (float)$order['discount_amount'] : max(0, $subtotal - $grand_total);
$total_gst   = (float)($order['gst_amount'] ?? 0);
$cgst        = $total_gst / 2;
$sgst        = $total_gst / 2;

$paid_amount = $order['paid_amount'] !== null ? (float)$order['paid_amount'] : $grand_total;
$due_amount  = max(0, $grand_total - $paid_amount);

// ── Split payment breakdown (if this sale was paid across multiple methods) ──
$payment_breakdown = [];
try {
    $op = $pdo->prepare("SELECT payment_method, SUM(amount) AS total FROM ecom_order_payments WHERE order_id = ? GROUP BY payment_method ORDER BY payment_method ASC");
    $op->execute([$order_id]);
    $payment_breakdown = $op->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table not present yet (migration not run) — fall back to the single payment_method field below
}

// ── Live due status: reflect any payments made AFTER the sale too ──────────
$linked_credit = null;
$due_payment_history = [];
$cq = $pdo->prepare("SELECT * FROM ecom_credits WHERE order_id = ? LIMIT 1");
$cq->execute([$order_id]);
$linked_credit = $cq->fetch(PDO::FETCH_ASSOC);

if ($linked_credit) {
    // Total actually paid = what was paid at sale time + everything paid off the due since
    $paid_amount_at_sale = $paid_amount;
    $paid_amount = $paid_amount + (float)$linked_credit['amount_paid'];
    $due_amount  = max(0, $grand_total - $paid_amount);

    $hp = $pdo->prepare("SELECT * FROM ecom_credit_payments WHERE credit_id = ? ORDER BY created_at ASC");
    $hp->execute([$linked_credit['id']]);
    $due_payment_history = $hp->fetchAll(PDO::FETCH_ASSOC);

    // Work out the remaining balance immediately after each payment, so the
    // invoice can show "paid ₹X on this date, ₹Y still due after that"
    $running_paid = $paid_amount_at_sale;
    foreach ($due_payment_history as $idx => $h) {
        $running_paid += (float)$h['amount'];
        $due_payment_history[$idx]['balance_after'] = max(0, $grand_total - $running_paid);
    }
}
$is_fully_paid = $linked_credit ? ($linked_credit['status'] === 'paid') : ($due_amount <= 0.004);

$invoice_title = $biz['invoice_title'] ?? 'Tax Invoice';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($invoice_title) ?> <?= htmlspecialchars($order['order_number']) ?> - <?= htmlspecialchars($site_name) ?></title>
<?php if (!$is_thermal): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php else: ?>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
<?php if (!$is_thermal): ?>
/* ── A4 / regular printer format ───────────────────────────────────────── */
body { font-family: 'Inter', sans-serif; background: #f3f4f6; color: #1f2937; }
.invoice-box { max-width: 800px; margin: 2rem auto; background: #fff; padding: 2.5rem; border-radius: 0.5rem; box-shadow: 0 0.15rem 1.75rem rgba(58,59,69,.1); }
.invoice-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #7c3aed; padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
.invoice-head h1 { font-size: 1.6rem; font-weight: 800; color: #7c3aed; margin: 0; }
.invoice-head .biz-line { font-size: 0.8125rem; color: #6b7280; margin-top: 0.15rem; }
.invoice-head .meta { text-align: right; font-size: 0.875rem; color: #6b7280; }
.invoice-title-badge { display: inline-block; background: #ede9fe; color: #6d28d9; font-weight: 700; font-size: 0.8125rem; padding: 0.25rem 0.75rem; border-radius: 9999px; margin-bottom: 0.4rem; }
.invoice-parties { display: flex; justify-content: space-between; margin-bottom: 2rem; gap: 2rem; }
.invoice-parties .block h6 { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; margin-bottom: 0.4rem; }
table.inv-table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
table.inv-table th { background: #f9fafb; text-align: left; padding: 0.7rem 0.9rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
table.inv-table td { padding: 0.7rem 0.9rem; border-bottom: 1px solid #f3f4f6; font-size: 0.9rem; }
.inv-totals { max-width: 340px; margin-left: auto; }
.inv-totals div { display: flex; justify-content: space-between; padding: 0.35rem 0; font-size: 0.9rem; }
.inv-totals .grand { font-weight: 800; font-size: 1.125rem; border-top: 2px solid #1f2937; padding-top: 0.6rem; margin-top: 0.3rem; }
.inv-totals .due { color: #ef4444; font-weight: 700; }
.print-bar { max-width: 800px; margin: 0 auto 1rem; display: flex; justify-content: flex-end; gap: 0.5rem; }
@media print { .print-bar { display: none; } body { background: #fff; } .invoice-box { box-shadow: none; margin: 0; } }
<?php else: ?>
/* ── Thermal receipt format ────────────────────────────────────────────── */
@page { size: <?= $thermal_width ?> auto; margin: 0; }
body { font-family: 'Courier New', monospace; font-size: 12px; color: #000; background: #ddd; margin: 0; }
.invoice-box { width: <?= $thermal_width ?>; margin: 10px auto; background: #fff; padding: 8px; }
.t-center { text-align: center; }
.t-biz-name { font-size: 15px; font-weight: 700; }
.t-line { border-top: 1px dashed #000; margin: 6px 0; }
table.inv-table { width: 100%; border-collapse: collapse; font-size: 11px; table-layout: fixed; }
table.inv-table th { text-align: left; border-bottom: 1px dashed #000; padding: 2px 0; overflow: hidden; }
table.inv-table td { padding: 2px 0; vertical-align: top; overflow: hidden; word-break: break-word; }
table.inv-table th:nth-child(1), table.inv-table td:nth-child(1) { width: 40%; padding-right: 4px; }
table.inv-table th:nth-child(2), table.inv-table td:nth-child(2) { width: 20%; }
table.inv-table th:nth-child(3), table.inv-table td:nth-child(3) { width: 16%; }
table.inv-table th:nth-child(4), table.inv-table td:nth-child(4) { width: 24%; }
.text-end { text-align: right; }
.inv-totals div { display: flex; justify-content: space-between; font-size: 11px; padding: 1px 0; }
.inv-totals .grand { font-weight: 700; font-size: 13px; border-top: 1px dashed #000; margin-top: 4px; padding-top: 4px; }
.payment-info-box { border: 1px solid #000; padding: 4px 6px; margin: 6px 0; }
.payment-info-box .pi-title { font-weight: 700; font-size: 12px; margin-bottom: 2px; }
.payment-info-box .pi-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 12px; }
.barcode-box { text-align: center; margin-top: 8px; }
.print-bar { text-align: center; margin: 10px; }
@media print { .print-bar { display: none; } body { background: #fff; } .invoice-box { margin: 0; } }
<?php endif; ?>
</style>
</head>
<body>

<div class="print-bar">
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <?php if (!$is_thermal): ?>
    <a href="?id=<?= $order_id ?>&format=thermal_80" class="btn btn-secondary"><i class="fas fa-receipt"></i> Thermal View</a>
    <?php else: ?>
    <a href="?id=<?= $order_id ?>&format=a4" class="btn btn-secondary">Full Invoice View</a>
    <?php endif; ?>
</div>

<?php if (!$is_thermal): ?>
<!-- ══════════════════ A4 / REGULAR INVOICE ══════════════════ -->
<div class="invoice-box">
    <div class="invoice-head">
        <div>
            <?php if (in_array($invoice_display, ['logo','both'], true) && !empty($biz['logo'])): $inv_logo_w = min((int)($biz['logo_display_width'] ?? 150), 160); ?><img src="/<?= htmlspecialchars($biz['logo']) ?>" style="width:<?= $inv_logo_w ?>px;margin-bottom:0.4rem;"><?php endif; ?>
            <?php if (in_array($invoice_display, ['name','both'], true) || empty($biz['logo'])): ?><h1><?= htmlspecialchars($biz['business_name'] ?: $site_name) ?></h1><?php endif; ?>
            <?php if ($show_address_on_invoice && !empty($biz['address'])): ?><div class="biz-line"><?= nl2br(htmlspecialchars($biz['address'])) ?></div><?php endif; ?>
            <?php if ($show_location_on_invoice && !empty($biz['location'])): ?><div class="biz-line"><?= htmlspecialchars($biz['location']) ?></div><?php endif; ?>
            <?php if (!empty($invoice_numbers) || !empty($biz['email'])): ?><div class="biz-line"><?= htmlspecialchars(implode(', ', $invoice_numbers)) ?><?= (!empty($invoice_numbers) && !empty($biz['email'])) ? ' · ' : '' ?><?= htmlspecialchars($biz['email']) ?></div><?php endif; ?>
            <?php if ($has_gstin): ?><div class="biz-line"><strong>GSTIN:</strong> <?= htmlspecialchars($biz['gstin']) ?></div><?php endif; ?>
            <?php if ($has_pan): ?><div class="biz-line"><strong>PAN:</strong> <?= htmlspecialchars($biz['pan_number']) ?></div><?php endif; ?>
        </div>
        <div class="meta">
            <div class="invoice-title-badge"><?= htmlspecialchars($invoice_title) ?></div>
            <div><strong>Invoice #:</strong> <?= htmlspecialchars($order['order_number']) ?></div>
            <div><strong>Date:</strong> <?= date('d M Y', strtotime($order['created_at'])) ?></div>
            <div><strong>Type:</strong> <?= ucfirst($order['order_type'] ?? 'online') ?></div>
        </div>
    </div>

    <div class="invoice-parties">
        <div class="block">
            <h6>Billed To</h6>
            <div><strong><?= htmlspecialchars($order['customer_name'] ?: ($customer['name'] ?? 'Walk-in Customer')) ?></strong></div>
            <div><?= htmlspecialchars($order['customer_email'] ?: ($customer['email'] ?? '')) ?></div>
            <?php if (!empty($customer['phone'])): ?><div><?= htmlspecialchars($customer['phone']) ?></div><?php endif; ?>
            <?php if (!empty($customer['address'])): ?><div><?= nl2br(htmlspecialchars($customer['address'])) ?></div><?php endif; ?>
        </div>
        <div class="block">
            <h6>Payment</h6>
            <div>Status: <strong><?= htmlspecialchars($order['payment_status']) ?></strong></div>
            <div>Order Status: <strong><?= htmlspecialchars($order['order_status']) ?></strong></div>
            <?php if (count($payment_breakdown) > 1): ?>
                <?php foreach ($payment_breakdown as $pb): ?>
                <div><?= htmlspecialchars($pb['payment_method']) ?>: ₹<?= number_format((float)$pb['total'], 2) ?></div>
                <?php endforeach; ?>
            <?php elseif (count($payment_breakdown) === 1): ?>
                <div><?= htmlspecialchars($payment_breakdown[0]['payment_method']) ?> Paid: ₹<?= number_format((float)$payment_breakdown[0]['total'], 2) ?></div>
            <?php endif; ?>
            <?php if ($due_amount > 0): ?><div class="text-danger">Amount Due: <strong>₹<?= number_format($due_amount, 2) ?></strong></div><?php endif; ?>
        </div>
    </div>

    <table class="inv-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <?php if ($has_gstin): ?><th>HSN</th><?php endif; ?>
                <th>Qty</th>
                <th>Price</th>
                <th>GST</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="<?= $has_gstin ? 7 : 6 ?>" class="text-center text-muted">No line items recorded for this order.</td></tr>
        <?php else: foreach ($items as $i => $it): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($it['product_name']) ?></td>
                <?php if ($has_gstin): ?><td><?= htmlspecialchars($it['hsn_code'] ?? '') ?: '—' ?></td><?php endif; ?>
                <td><?= (int)$it['qty'] ?></td>
                <td>₹<?= number_format((float)$it['price'], 2) ?></td>
                <td><?= number_format((float)($it['gst_rate'] ?? 0), 2) ?>%</td>
                <td class="text-end">₹<?= number_format((float)$it['price'] * (int)$it['qty'], 2) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="inv-totals">
        <div><span>Subtotal</span><span>₹<?= number_format($subtotal, 2) ?></span></div>
        <?php if ($discount > 0): ?>
        <div><span>Discount</span><span>-₹<?= number_format($discount, 2) ?></span></div>
        <?php endif; ?>
        <?php if ($total_gst > 0 && $has_gstin): ?>
        <div><span>CGST</span><span>+₹<?= number_format($cgst, 2) ?></span></div>
        <div><span>SGST</span><span>+₹<?= number_format($sgst, 2) ?></span></div>
        <?php elseif ($total_gst > 0): ?>
        <div><span>GST</span><span>+₹<?= number_format($total_gst, 2) ?></span></div>
        <?php endif; ?>
        <div class="grand"><span>Grand Total</span><span>₹<?= number_format($grand_total, 2) ?></span></div>
        <div><span>Paid</span><span>₹<?= number_format($paid_amount, 2) ?></span></div>
        <?php if ($is_fully_paid): ?>
        <div style="color:#10b981;font-weight:700;"><span>Status</span><span>✓ Fully Paid</span></div>
        <?php else: ?>
        <div class="due"><span>Due</span><span>₹<?= number_format($due_amount, 2) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($due_payment_history)): ?>
    <div class="mt-3 pt-3" style="border-top:1px dashed #d1d5db;">
        <h6 style="font-size:0.8rem;text-transform:uppercase;letter-spacing:.03em;color:#6b7280;">Due Payment History</h6>
        <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:0.2rem 0;color:#6b7280;">
            <span><?= date('d M Y', strtotime($order['created_at'])) ?> — Due at time of sale</span>
            <span>₹<?= number_format((float)$linked_credit['amount'], 2) ?></span>
        </div>
        <?php foreach ($due_payment_history as $h): ?>
        <div style="display:flex;justify-content:space-between;font-size:0.85rem;padding:0.2rem 0;">
            <span><?= date('d M Y', strtotime($h['created_at'])) ?> · <?= htmlspecialchars($h['payment_method']) ?> — Paid ₹<?= number_format((float)$h['amount'], 2) ?></span>
            <span class="text-muted"><?= $h['balance_after'] > 0.004 ? 'Due after: ₹' . number_format($h['balance_after'], 2) : 'Fully Paid' ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($biz['invoice_footer_note'])): ?>
    <p class="text-muted text-center mt-4 mb-0" style="font-size:0.8125rem;"><?= nl2br(htmlspecialchars($biz['invoice_footer_note'])) ?></p>
    <?php endif; ?>
    <p class="text-muted text-center mt-2 mb-0" style="font-size:0.8125rem;">Thank you for shopping with <?= htmlspecialchars($biz['business_name'] ?: $site_name) ?>!</p>
</div>

<?php else: ?>
<!-- ══════════════════ THERMAL RECEIPT ══════════════════ -->
<div class="invoice-box">
    <div class="t-center">
        <?php if (in_array($invoice_display, ['logo','both'], true) && !empty($biz['logo'])): $t_logo_w = min((int)($biz['logo_display_width'] ?? 100), 120); ?><img src="/<?= htmlspecialchars($biz['logo']) ?>" style="width:<?= $t_logo_w ?>px;margin-bottom:4px;"><?php endif; ?>
        <?php if (in_array($invoice_display, ['name','both'], true) || empty($biz['logo'])): ?><div class="t-biz-name"><?= htmlspecialchars($biz['business_name'] ?: $site_name) ?></div><?php endif; ?>
    </div>
    <?php if ($show_address_on_invoice && !empty($biz['address'])): ?><div><?= nl2br(htmlspecialchars($biz['address'])) ?></div><?php endif; ?>
    <?php if ($show_location_on_invoice && !empty($biz['location'])): ?><div><?= htmlspecialchars($biz['location']) ?></div><?php endif; ?>
    <?php if (!empty($invoice_numbers)): ?><div>Tel. : <?= htmlspecialchars(implode(', ', $invoice_numbers)) ?></div><?php endif; ?>
    <?php if ($has_fssai): ?><div>FSSAI License No. : <?= htmlspecialchars($biz['fssai_number']) ?></div><?php endif; ?>
    <?php if ($has_gstin): ?><div>GSTIN : <?= htmlspecialchars($biz['gstin']) ?></div><?php endif; ?>
    <?php if ($has_pan): ?><div>PAN : <?= htmlspecialchars($biz['pan_number']) ?></div><?php endif; ?>
    <div class="t-line"></div>

    <div>Invoice No. : <?= htmlspecialchars($order['order_number']) ?></div>
    <div>Date : <?= date('d/m/Y  h:i A', strtotime($order['created_at'])) ?></div>
    <div class="t-line"></div>

    <table class="inv-table">
        <thead><tr><th>Item Name :</th><th class="text-end">Rate</th><th class="text-end">Qty.</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
            <td><?= htmlspecialchars($it['product_name']) ?></td>
            <td class="text-end"><?= number_format((float)$it['price'], 2) ?></td>
            <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.') ?></td>
            <td class="text-end"><?= number_format((float)$it['price'] * (float)$it['qty'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="t-line"></div>

    <div style="display:flex;justify-content:space-between;">
        <span>Items :  <?= number_format($item_count, 2) ?></span>
        <span>Qty. :  <?= rtrim(rtrim(number_format($total_qty, 2), '0'), '.') ?></span>
    </div>

    <div class="inv-totals">
        <div><span>Sub Total :</span><span><?= number_format($subtotal, 2) ?></span></div>
        <?php if ($discount > 0): ?><div><span>Discount :</span><span>-<?= number_format($discount, 2) ?></span></div><?php endif; ?>
        <?php if ($total_gst > 0 && $has_gstin): ?>
        <div><span>CGST :</span><span><?= number_format($cgst, 2) ?></span></div>
        <div><span>SGST :</span><span><?= number_format($sgst, 2) ?></span></div>
        <?php elseif ($total_gst > 0): ?>
        <div><span>GST :</span><span><?= number_format($total_gst, 2) ?></span></div>
        <?php endif; ?>
        <div class="grand"><span>Total :</span><span><?= number_format($grand_total, 2) ?></span></div>
        <?php if (count($payment_breakdown) > 1): ?>
            <?php foreach ($payment_breakdown as $pb): ?>
        <div><span><?= htmlspecialchars($pb['payment_method']) ?> Paid :</span><span>₹<?= number_format((float)$pb['total'], 2) ?></span></div>
            <?php endforeach; ?>
        <?php else: ?>
        <div><span><?= htmlspecialchars($order['payment_method'] ?: 'Cash') ?> Paid :</span><span>₹<?= number_format($paid_amount, 2) ?></span></div>
        <?php endif; ?>
        <?php if (!$is_fully_paid): ?><div><span>Due :</span><span>₹<?= number_format($due_amount, 2) ?></span></div><?php endif; ?>
    </div>

    <?php if (!empty($due_payment_history)): ?>
    <div class="t-line"></div>
    <div style="font-size:10px;">
        <div>Due History (orig <?= number_format((float)$linked_credit['amount'], 2) ?>):</div>
        <?php foreach ($due_payment_history as $h): ?>
        <div style="display:flex;justify-content:space-between;"><span><?= date('d/m/y', strtotime($h['created_at'])) ?> paid</span><span><?= number_format((float)$h['amount'], 2) ?> (bal <?= number_format($h['balance_after'], 2) ?>)</span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="barcode-box">
        <svg id="thermalBarcode"></svg>
    </div>

    <div class="t-line"></div>
    <div class="t-center"><?= htmlspecialchars($biz['invoice_footer_note'] ?: 'Thank you, visit again!') ?></div>
</div>
<script>
if (window.JsBarcode) {
    JsBarcode("#thermalBarcode", "<?= htmlspecialchars($order['order_number']) ?>", { format: "CODE128", width: 1.4, height: 34, fontSize: 10, margin: 2 });
}
</script>
<?php endif; ?>

</body>
</html>
