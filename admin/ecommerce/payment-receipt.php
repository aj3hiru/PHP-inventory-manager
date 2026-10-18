<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || (empty($permissions['ecommerce']['manage_credits']) && empty($permissions['ecommerce']['manage_customers']) && empty($permissions['ecommerce']['manage_billing']))) {
    exit('Access Denied');
}

$receipt_number = trim($_GET['receipt'] ?? '');
$return_to = $_GET['return_to'] ?? '/admin/ecommerce/due.php';

$stmt = $pdo->prepare("
    SELECT cp.*, c.customer_name, c.customer_phone, c.customer_id, c.order_id, c.amount AS credit_total, c.amount_paid AS credit_paid_total, o.order_number
    FROM ecom_credit_payments cp
    JOIN ecom_credits c ON cp.credit_id = c.id
    LEFT JOIN ecom_orders o ON c.order_id = o.id
    WHERE cp.receipt_number = ?
    ORDER BY cp.id ASC
");
$stmt->execute([$receipt_number]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($payments)) { exit('Receipt not found.'); }

$biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$customer_name = $payments[0]['customer_name'];
$customer_id   = $payments[0]['customer_id'];
$total_paid_this_receipt = array_sum(array_column($payments, 'amount'));
$paid_at = $payments[0]['created_at'];

// ── Customer's overall outstanding due, across every purchase — not just this receipt ──
$total_outstanding = 0.0;
if ($customer_id) {
    $ob = $pdo->prepare("SELECT COALESCE(SUM(amount - amount_paid), 0) FROM ecom_credits WHERE customer_id = ? AND status = 'pending'");
    $ob->execute([$customer_id]);
    $total_outstanding = (float) $ob->fetchColumn();
} else {
    // Fallback for walk-ins without a linked customer record: just this credit's balance
    $total_outstanding = max(0, (float)$payments[0]['credit_total'] - (float)$payments[0]['credit_paid_total']);
}
$all_clear = $total_outstanding <= 0.004;

// ── A4 or thermal (58/80mm), matching the invoice's printer format ─────────
$format = $_GET['format'] ?? ($biz['printer_format'] ?? 'a4');
if (!in_array($format, ['a4', 'thermal_58', 'thermal_80'], true)) $format = 'a4';
$is_thermal = strpos($format, 'thermal_') === 0;
$thermal_width = $format === 'thermal_58' ? '58mm' : '80mm';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt <?= htmlspecialchars($receipt_number) ?></title>
<?php if (!$is_thermal): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
<?php if (!$is_thermal): ?>
/* ── A4 / regular printer format ───────────────────────────────────────── */
body { font-family: 'Inter', sans-serif; background: #f3f4f6; }
.receipt-box { max-width: 560px; margin: 2rem auto; background: #fff; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 0.15rem 1.75rem rgba(58,59,69,.1); }
.receipt-head { text-align: center; border-bottom: 2px dashed #d1d5db; padding-bottom: 1.25rem; margin-bottom: 1.25rem; }
.receipt-head h1 { font-size: 1.4rem; font-weight: 800; color: #7c3aed; margin: 0 0 0.2rem; }
table.pay-table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
table.pay-table th { text-align: left; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; padding: 0.5rem 0; border-bottom: 1px solid #e5e7eb; }
table.pay-table td { padding: 0.5rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.9rem; }
.grand-row { font-weight: 800; font-size: 1.2rem; border-top: 2px solid #1f2937; padding-top: 0.6rem; margin-top: 0.5rem; display: flex; justify-content: space-between; }
.due-status { margin-top: 0.75rem; padding: 0.75rem 1rem; border-radius: 0.5rem; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
.due-status.clear { background: #d1fae5; color: #065f46; }
.due-status.pending { background: #fee2e2; color: #991b1b; }
.print-bar { max-width: 560px; margin: 0 auto 1rem; display: flex; justify-content: space-between; gap: 0.5rem; }
@media print { .print-bar { display: none; } body { background: #fff; } .receipt-box { box-shadow: none; margin: 0; } }
<?php else: ?>
/* ── Thermal receipt format ────────────────────────────────────────────── */
@page { size: <?= $thermal_width ?> auto; margin: 0; }
body { font-family: 'Courier New', monospace; font-size: 12px; color: #000; background: #ddd; margin: 0; }
.receipt-box { width: <?= $thermal_width ?>; margin: 10px auto; background: #fff; padding: 8px; }
.t-center { text-align: center; }
.t-biz-name { font-size: 15px; font-weight: 700; }
.t-line { border-top: 1px dashed #000; margin: 6px 0; }
table.pay-table { width: 100%; border-collapse: collapse; font-size: 11px; }
table.pay-table th { text-align: left; border-bottom: 1px dashed #000; padding: 2px 0; }
table.pay-table td { padding: 2px 0; vertical-align: top; }
.grand-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 13px; border-top: 1px dashed #000; margin-top: 4px; padding-top: 4px; }
.due-status { margin-top: 6px; font-weight: 700; text-align: center; font-size: 12px; }
.print-bar { text-align: center; margin: 10px; }
@media print { .print-bar { display: none; } body { background: #fff; } .receipt-box { margin: 0; } }
<?php endif; ?>
</style>
</head>
<body>

<div class="print-bar">
    <a href="<?= htmlspecialchars($return_to) ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
    <?php if (!$is_thermal): ?>
    <a href="?receipt=<?= urlencode($receipt_number) ?>&format=thermal_80&return_to=<?= urlencode($return_to) ?>" class="btn btn-secondary"><i class="fas fa-receipt"></i> Thermal View</a>
    <?php else: ?>
    <a href="?receipt=<?= urlencode($receipt_number) ?>&format=a4&return_to=<?= urlencode($return_to) ?>" class="btn btn-secondary">Full View</a>
    <?php endif; ?>
</div>

<?php if (!$is_thermal): ?>
<!-- ══════════════════ A4 / REGULAR RECEIPT ══════════════════ -->
<div class="receipt-box">
    <div class="receipt-head">
        <h1><?= htmlspecialchars($biz['business_name'] ?: $site_name) ?></h1>
        <?php if (!empty($biz['phone'])): ?><div class="text-muted small"><?= htmlspecialchars($biz['phone']) ?></div><?php endif; ?>
        <div class="mt-2 fw-bold">Payment Receipt</div>
        <div class="text-muted small">#<?= htmlspecialchars($receipt_number) ?> · <?= date('d M Y, h:i A', strtotime($paid_at)) ?></div>
    </div>

    <p class="mb-1"><strong>Received From:</strong> <?= htmlspecialchars($customer_name) ?></p>
    <?php if (!empty($payments[0]['customer_phone'])): ?><p class="mb-3"><strong>Phone:</strong> <?= htmlspecialchars($payments[0]['customer_phone']) ?></p><?php endif; ?>

    <table class="pay-table">
        <thead><tr><th>Order</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
            <td>
                <?php if ($p['order_id']): ?>
                    <a href="invoice.php?id=<?= $p['order_id'] ?>" target="_blank"><?= htmlspecialchars($p['order_number'] ?: ('Order #' . $p['order_id'])) ?></a>
                <?php else: ?>
                    General Due
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($p['payment_method']) ?></td>
            <td class="text-end">₹<?= number_format((float)$p['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="grand-row"><span>Total Received</span><span>₹<?= number_format($total_paid_this_receipt, 2) ?></span></div>

    <div class="due-status <?= $all_clear ? 'clear' : 'pending' ?>">
        <?php if ($all_clear): ?>
            <span><i class="fas fa-check-circle"></i> All Due Cleared!</span>
        <?php else: ?>
            <span>Total Due Remaining</span><span>₹<?= number_format($total_outstanding, 2) ?></span>
        <?php endif; ?>
    </div>

    <p class="text-muted text-center mt-4 mb-0" style="font-size:0.8125rem;">Thank you!</p>
</div>

<?php else: ?>
<!-- ══════════════════ THERMAL RECEIPT ══════════════════ -->
<div class="receipt-box">
    <div class="t-center">
        <div class="t-biz-name"><?= htmlspecialchars($biz['business_name'] ?: $site_name) ?></div>
        <?php if (!empty($biz['phone'])): ?><div><?= htmlspecialchars($biz['phone']) ?></div><?php endif; ?>
        <div class="t-line"></div>
        <div>Payment Receipt</div>
        <div>#<?= htmlspecialchars($receipt_number) ?></div>
        <div><?= date('d/m/y H:i', strtotime($paid_at)) ?></div>
    </div>
    <div class="t-line"></div>
    <div>From: <?= htmlspecialchars($customer_name) ?></div>
    <?php if (!empty($payments[0]['customer_phone'])): ?><div>Ph: <?= htmlspecialchars($payments[0]['customer_phone']) ?></div><?php endif; ?>
    <div class="t-line"></div>

    <table class="pay-table">
        <thead><tr><th>Order</th><th>Method</th><th class="text-end">Amt</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['order_number'] ?: 'General') ?></td>
            <td><?= htmlspecialchars($p['payment_method']) ?></td>
            <td class="text-end">₹<?= number_format((float)$p['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="t-line"></div>

    <div class="grand-row"><span>TOTAL RECEIVED</span><span>₹<?= number_format($total_paid_this_receipt, 2) ?></span></div>

    <div class="due-status">
        <?php if ($all_clear): ?>
            ✓ ALL DUE CLEARED!
        <?php else: ?>
            Due Remaining: ₹<?= number_format($total_outstanding, 2) ?>
        <?php endif; ?>
    </div>

    <div class="t-line"></div>
    <div class="t-center">Thank you!</div>
</div>
<?php endif; ?>

</body>
</html>
