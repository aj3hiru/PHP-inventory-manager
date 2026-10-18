<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';
require_once __DIR__ . '/includes/cart-helpers.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: /shop/login.php?redirect=' . urlencode('/shop/checkout.php'));
    exit;
}
if (empty(cartRawRows($pdo))) {
    header('Location: /shop/cart.php');
    exit;
}

$cstmt = $pdo->prepare("SELECT * FROM ecom_customers WHERE id = ?");
$cstmt->execute([$_SESSION['customer_id']]);
$customer = $cstmt->fetch(PDO::FETCH_ASSOC);

$error = '';

// ── Place order (runs BEFORE any HTML output, so redirects work) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address        = trim($_POST['address'] ?? '');
    $payment_method = $_POST['payment_method'] ?? '';
    $coupon_code    = strtoupper(trim($_POST['coupon_code'] ?? ''));

    if (empty($address)) {
        $error = 'Please enter a delivery address.';
    } elseif (empty($payment_method)) {
        $error = 'Please select a payment method.';
    } else {
        try {
            $pdo->beginTransaction();

            // Re-resolve the cart fresh (inside the transaction) so prices/stock
            // are current at the moment of purchase, not whatever was cached
            // when the cart page last loaded.
            $lines = getCartLines($pdo);

            $pstmt = $pdo->prepare("SELECT * FROM ecom_products WHERE id = ? AND status = 'active'");
            $line_items = [];
            $subtotal = 0;
            foreach ($lines as $line) {
                $pstmt->execute([$line['product_id']]);
                $product = $pstmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) continue;

                $qty = $line['qty'];
                if ($line['stock_qty'] !== null && $qty > (int)$line['stock_qty']) {
                    $qty = (int)$line['stock_qty'];
                }
                if ($qty <= 0) continue;

                $line_items[] = [
                    'product'       => $product,
                    'qty'           => $qty,
                    'unit_price'    => $line['unit_price'],
                    'line_total'    => $line['unit_price'] * $qty,
                    'variant_id'    => $line['variant_id'] ?: null,
                    'variant_label' => $line['variant_label'],
                ];
                $subtotal += $line['unit_price'] * $qty;
            }

            if (empty($line_items)) {
                throw new Exception('Your cart items are no longer available.');
            }

            $discount = 0;
            $coupon = null;
            if ($coupon_code !== '') {
                $cq = $pdo->prepare("SELECT * FROM ecom_coupons WHERE code = ? AND status = 'active'");
                $cq->execute([$coupon_code]);
                $coupon = $cq->fetch(PDO::FETCH_ASSOC);
                if ($coupon && (int)$coupon['used_count'] < (int)$coupon['number_of_times']) {
                    $eligible = 0;
                    foreach ($line_items as $li) {
                        $matches = $coupon['applies_to'] === 'all'
                            || ($coupon['applies_to'] === 'product' && $li['product']['id'] == $coupon['product_id'])
                            || ($coupon['applies_to'] === 'category' && $li['product']['category_id'] == $coupon['category_id'])
                            || ($coupon['applies_to'] === 'subcategory' && $li['product']['subcategory_id'] == $coupon['subcategory_id']);
                        if ($matches) $eligible += $li['line_total'];
                    }
                    if ($eligible > 0) {
                        $discount = $coupon['discount_type'] === 'percentage'
                            ? $eligible * ((float)$coupon['discount_value'] / 100)
                            : min((float)$coupon['discount_value'], $eligible);
                    }
                } else {
                    $coupon = null;
                }
            }

            $grand_total = max(0, $subtotal - $discount);

            // ── GST calculation (proportional to any discount applied) ──────────────
            $total_gst = 0;
            foreach ($line_items as $idx => $li) {
                $discount_share = $subtotal > 0 ? $discount * ($li['line_total'] / $subtotal) : 0;
                $taxable_value  = max(0, $li['line_total'] - $discount_share);
                $line_gst       = $taxable_value * ((float)$li['product']['gst_rate'] / 100);
                $line_items[$idx]['gst_amount'] = $line_gst;
                $total_gst += $line_gst;
            }
            $grand_total += $total_gst;

            $pdo->prepare("UPDATE ecom_customers SET address = ? WHERE id = ?")->execute([$address, $customer['id']]);

            $order_number = generateOrderNumber($pdo);
            $pdo->prepare("
                INSERT INTO ecom_orders (order_number, customer_id, customer_name, customer_email, shipping_address, total_amount, subtotal_amount, discount_amount, gst_amount, payment_status, payment_method, order_status, order_type)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'online')
            ")->execute([$order_number, $customer['id'], $customer['name'], $customer['email'], $address, $grand_total, $subtotal, $discount, $total_gst, 'Unpaid', $payment_method, 'Pending']);
            $order_id = (int)$pdo->lastInsertId();

            foreach ($line_items as $li) {
                // Variant label folded into product_name too, so any older
                // admin/invoice screen that only reads product_name still
                // shows the correct size without needing a join.
                $display_name = $li['product']['name'] . (!empty($li['variant_label']) ? ' (' . $li['variant_label'] . ')' : '');
                $pdo->prepare("INSERT INTO ecom_order_items (order_id, product_id, variant_id, product_name, variant_label, hsn_code, qty, price, gst_rate, gst_amount) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$order_id, $li['product']['id'], $li['variant_id'], $display_name, $li['variant_label'], $li['product']['hsn_code'], $li['qty'], $li['unit_price'], $li['product']['gst_rate'], $li['gst_amount']]);

                if ($li['variant_id']) {
                    $pdo->prepare("UPDATE ecom_product_variants SET stock_qty = GREATEST(stock_qty - ?, 0) WHERE id = ? AND stock_qty IS NOT NULL")
                        ->execute([$li['qty'], $li['variant_id']]);
                } elseif ($li['product']['product_type'] === 'physical' && $li['product']['stock_qty'] !== null) {
                    $pdo->prepare("UPDATE ecom_products SET stock_qty = GREATEST(stock_qty - ?, 0) WHERE id = ?")
                        ->execute([$li['qty'], $li['product']['id']]);
                }
                // Popularity tracking: feeds the "Popular Products" homepage
                // section's auto-fill (manual pins + top by purchases/views).
                $pdo->prepare("UPDATE ecom_products SET purchase_count = purchase_count + ? WHERE id = ?")
                    ->execute([$li['qty'], $li['product']['id']]);
            }

            if ($coupon) {
                $pdo->prepare("UPDATE ecom_coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$coupon['id']]);
            }

            $pdo->commit();
            cartClear($pdo);

            header('Location: /order/' . $order_id . '?placed=1');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not place order: ' . $e->getMessage();
        }
    }
}

// ── Build cart summary for display ──────────────────────────────────────────
$cart_lines = getCartLines($pdo);
$cart_items = [];
$subtotal = 0;
$estimated_gst = 0;
$gst_rates_by_product = [];
if (!empty($cart_lines)) {
    $product_ids = array_unique(array_column($cart_lines, 'product_id'));
    $in = implode(',', array_fill(0, count($product_ids), '?'));
    $gst_stmt = $pdo->prepare("SELECT id, gst_rate FROM ecom_products WHERE id IN ($in)");
    $gst_stmt->execute(array_values($product_ids));
    foreach ($gst_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $gst_rates_by_product[$row['id']] = (float)$row['gst_rate']; }
}
foreach ($cart_lines as $line) {
    $display_name = $line['name'] . (!empty($line['variant_label']) ? ' (' . $line['variant_label'] . ')' : '');
    $cart_items[] = ['name' => $display_name, 'qty' => $line['qty'], 'line_total' => $line['line_total']];
    $subtotal += $line['line_total'];
    $estimated_gst += $line['line_total'] * (($gst_rates_by_product[$line['product_id']] ?? 0) / 100);
}

$payment_methods = $pdo->query("SELECT method_key, name FROM ecom_payment_settings WHERE is_enabled = 1")->fetchAll(PDO::FETCH_ASSOC);

// ── Now safe to render the page ──────────────────────────────────────────────
$page_title = 'Checkout';
include __DIR__ . '/includes/shop-header.php';
?>

<div class="shop-container">
    <h2 class="section-title">Checkout</h2>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
        <div class="row">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Delivery Address</h5>
                        <textarea name="address" class="form-control" rows="3" placeholder="Full address for delivery" required><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Payment Method</h5>
                        <?php if (empty($payment_methods)): ?>
                            <p class="text-muted">No payment methods are currently available. Please contact us.</p>
                        <?php else: foreach ($payment_methods as $pm): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="payment_method" id="pm-<?= $pm['method_key'] ?>" value="<?= $pm['method_key'] ?>" required>
                            <label class="form-check-label" for="pm-<?= $pm['method_key'] ?>"><?= htmlspecialchars($pm['name']) ?></label>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Coupon Code</h5>
                        <input type="text" name="coupon_code" class="form-control text-uppercase" placeholder="Have a coupon? Enter it here">
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3">Order Summary</h5>
                        <?php foreach ($cart_items as $ci): ?>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span><?= htmlspecialchars($ci['name']) ?> × <?= $ci['qty'] ?></span>
                            <span>₹<?= number_format($ci['line_total'], 2) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold"><span>Subtotal</span><span>₹<?= number_format($subtotal, 2) ?></span></div>
                        <div class="d-flex justify-content-between small text-muted"><span>Estimated GST</span><span>+₹<?= number_format($estimated_gst, 2) ?></span></div>
                        <p class="text-muted small mt-2">Coupon discount (if any) is applied when you place the order.</p>
                        <button type="submit" class="btn btn-shop-primary w-100 mt-3">Place Order</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
