<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || (empty($permissions['ecommerce']['manage_credits']) && empty($permissions['ecommerce']['manage_customers']) && empty($permissions['ecommerce']['manage_billing']))) {
    exit('Access Denied');
}

$credit_ids      = $_POST['credit_ids'] ?? [];      // array of credit ids being paid
$amounts         = $_POST['amounts'] ?? [];         // matching array of amounts (same index)
$payment_method  = trim($_POST['payment_method'] ?? 'Cash');
$combine_receipt = !empty($_POST['combine_receipt']); // one receipt for all, or one each
$return_to       = $_POST['return_to'] ?? '/admin/ecommerce/due.php';

if (empty($credit_ids) || count($credit_ids) !== count($amounts)) {
    header('Location: ' . $return_to . '?error=' . urlencode('No payment selected.'));
    exit;
}

try {
    $pdo->beginTransaction();

    $shared_receipt = $combine_receipt ? generateReceiptNumber($pdo) : null;
    $created_receipts = [];

    foreach ($credit_ids as $idx => $cid) {
        $cid = (int)$cid;
        $amount = (float)($amounts[$idx] ?? 0);
        if ($cid <= 0 || $amount <= 0) continue;

        $c = $pdo->prepare("SELECT * FROM ecom_credits WHERE id = ?");
        $c->execute([$cid]);
        $credit = $c->fetch(PDO::FETCH_ASSOC);
        if (!$credit) continue;

        $balance = (float)$credit['amount'] - (float)$credit['amount_paid'];
        $amount = min($amount, $balance); // never overpay
        if ($amount <= 0) continue;

        $new_paid = (float)$credit['amount_paid'] + $amount;
        $new_status = $new_paid >= (float)$credit['amount'] - 0.004 ? 'paid' : 'pending';
        $pdo->prepare("UPDATE ecom_credits SET amount_paid = ?, status = ? WHERE id = ?")
            ->execute([$new_paid, $new_status, $cid]);

        $receipt_number = $shared_receipt ?? generateReceiptNumber($pdo);
        $pdo->prepare("INSERT INTO ecom_credit_payments (credit_id, receipt_number, amount, payment_method, created_by) VALUES (?,?,?,?,?)")
            ->execute([$cid, $receipt_number, $amount, $payment_method, $_SESSION['user_id']]);

        $created_receipts[] = $receipt_number;

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_credit_payment', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Recorded due payment: ₹" . number_format($amount, 2) . " from " . $credit['customer_name'] . " (Receipt: $receipt_number)", $log_ip, $log_ua]);
    }

    $pdo->commit();

    if (empty($created_receipts)) {
        header('Location: ' . $return_to . '?error=' . urlencode('Nothing was recorded — check the amounts entered.'));
        exit;
    }

    $unique_receipts = array_values(array_unique($created_receipts));
    if (count($unique_receipts) === 1) {
        header('Location: /admin/ecommerce/payment-receipt.php?receipt=' . urlencode($unique_receipts[0]) . '&return_to=' . urlencode($return_to));
    } else {
        header('Location: ' . $return_to . '?success=payment&receipts=' . urlencode(implode(',', $unique_receipts)));
    }
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: ' . $return_to . '?error=' . urlencode('Could not save payment: ' . $e->getMessage()));
    exit;
}
