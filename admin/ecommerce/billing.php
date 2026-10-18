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

$biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$pos_print_mode = $biz['pos_print_mode'] ?? 'both';
$shortcut_complete_sale = $biz['shortcut_complete_sale'] ?? 'F2';
$shortcut_print = $biz['shortcut_print'] ?? 'F3';
$shortcut_new_sale = $biz['shortcut_new_sale'] ?? 'F4';
$thermal_format_value = in_array($biz['printer_format'] ?? '', ['thermal_58', 'thermal_80'], true) ? $biz['printer_format'] : 'thermal_80';

// ── AJAX: checkout (create the sale) ────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'checkout') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    $items         = $input['items'] ?? [];
    $customer_id   = (int)($input['customer_id'] ?? 0);
    $customer_name = trim($input['customer_name'] ?? '');
    $customer_phone= trim($input['customer_phone'] ?? '');
    $is_guest      = !empty($input['is_guest']);
    $payments_input = is_array($input['payments'] ?? null) ? $input['payments'] : [];
    $promised_date = !empty($input['promised_date']) ? $input['promised_date'] : null;
    $coupon_code   = strtoupper(trim($input['coupon_code'] ?? ''));

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty.']); exit;
    }

    // ── Sanitize the payment rows (supports split payment across methods) ──────
    $valid_methods = ['Cash', 'Card', 'UPI', 'Other'];
    $payments = [];
    foreach ($payments_input as $p) {
        $method = in_array($p['method'] ?? '', $valid_methods, true) ? $p['method'] : 'Other';
        $amt = round((float)($p['amount'] ?? 0), 2);
        if ($amt > 0) $payments[] = ['method' => $method, 'amount' => $amt];
    }
    if (empty($payments)) $payments[] = ['method' => 'Cash', 'amount' => 0];

    try {
        $pdo->beginTransaction();

        // Re-fetch authoritative product data (never trust client-sent prices)
        $line_items = [];
        $subtotal = 0;
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = max(1, (int)($it['qty'] ?? 1));
            $p = $pdo->prepare("SELECT * FROM ecom_products WHERE id = ?");
            $p->execute([$pid]);
            $product = $p->fetch(PDO::FETCH_ASSOC);
            if (!$product) continue;

            $unit_price = (!empty($product['sale_price']) && (float)$product['sale_price'] > 0 && (float)$product['sale_price'] < (float)$product['price'])
                ? (float)$product['sale_price'] : (float)$product['price'];

            // Staff on this internal billing screen may manually override the price
            // (negotiated deals, damaged-item discount, etc.) — honored here since
            // this endpoint is only reachable by authenticated staff, unlike the
            // public storefront checkout which never trusts client-sent prices.
            if (isset($it['price_override']) && $it['price_override'] !== null && (float)$it['price_override'] >= 0) {
                $unit_price = (float)$it['price_override'];
            }

            $line_items[] = [
                'product'    => $product,
                'qty'        => $qty,
                'unit_price' => $unit_price,
                'line_total' => $unit_price * $qty,
            ];
            $subtotal += $unit_price * $qty;
        }

        if (empty($line_items)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'No valid products in cart.']); exit;
        }

        // ── Coupon validation & discount calculation (server-side, authoritative) ──
        $discount = 0;
        $coupon = null;
        if ($coupon_code !== '') {
            $cq = $pdo->prepare("SELECT * FROM ecom_coupons WHERE code = ? AND status = 'active'");
            $cq->execute([$coupon_code]);
            $coupon = $cq->fetch(PDO::FETCH_ASSOC);

            if ($coupon && (int)$coupon['used_count'] < (int)$coupon['number_of_times']) {
                $eligible_total = 0;
                foreach ($line_items as $li) {
                    $matches = false;
                    if ($coupon['applies_to'] === 'all') $matches = true;
                    elseif ($coupon['applies_to'] === 'product' && $li['product']['id'] == $coupon['product_id']) $matches = true;
                    elseif ($coupon['applies_to'] === 'category' && $li['product']['category_id'] == $coupon['category_id']) $matches = true;
                    elseif ($coupon['applies_to'] === 'subcategory' && $li['product']['subcategory_id'] == $coupon['subcategory_id']) $matches = true;

                    if ($matches) $eligible_total += $li['line_total'];
                }

                if ($eligible_total > 0) {
                    $discount = $coupon['discount_type'] === 'percentage'
                        ? $eligible_total * ((float)$coupon['discount_value'] / 100)
                        : min((float)$coupon['discount_value'], $eligible_total);
                }
            } else {
                $coupon = null; // invalid / exhausted — ignore silently, still complete the sale
            }
        }

        $grand_total = max(0, $subtotal - $discount);

        // ── GST calculation (proportional to any discount applied) ─────────────────
        $total_gst = 0;
        foreach ($line_items as $idx => $li) {
            $discount_share = $subtotal > 0 ? $discount * ($li['line_total'] / $subtotal) : 0;
            $taxable_value  = max(0, $li['line_total'] - $discount_share);
            $line_gst       = $taxable_value * ((float)$li['product']['gst_rate'] / 100);
            $line_items[$idx]['gst_amount'] = $line_gst;
            $total_gst += $line_gst;
        }
        $grand_total += $total_gst;

        // ── Resolve / create customer ────────────────────────────────────────────
        if ($is_guest) {
            $customer_id = 0;
            $customer_name = 'Guest';
            $customer_phone = '';
        } elseif ($customer_id > 0) {
            $cc = $pdo->prepare("SELECT id, name, phone FROM ecom_customers WHERE id = ?");
            $cc->execute([$customer_id]);
            $existing = $cc->fetch(PDO::FETCH_ASSOC);
            if ($existing) { $customer_name = $existing['name']; $customer_phone = $customer_phone ?: $existing['phone']; }
        } elseif ($customer_name !== '') {
            $pdo->prepare("INSERT INTO ecom_customers (name, phone, customer_type, status) VALUES (?,?,?,'active')")
                ->execute([$customer_name, $customer_phone ?: null, 'offline']);
            $customer_id = (int)$pdo->lastInsertId();
        } else {
            $customer_name = 'Walk-in Customer';
        }

        // ── Payment: how much was actually received right now? ──────────────────
        $paid_amount = array_sum(array_column($payments, 'amount'));
        $paid_amount = min($paid_amount, $grand_total); // never record more than the bill
        $due_amount  = round($grand_total - $paid_amount, 2);
        $payment_status = $due_amount > 0.004 ? 'Unpaid' : 'Paid';

        if ($is_guest && $due_amount > 0.004) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Guest bills must be paid in full. Turn off Guest Bill to record a due amount against a customer.']);
            exit;
        }

        $distinct_methods = array_unique(array_column($payments, 'method'));
        $payment_method = count($distinct_methods) === 1 ? $distinct_methods[0] : 'Split';

        // ── Create order ─────────────────────────────────────────────────────────
        $order_number = generateOrderNumber($pdo);
        $pdo->prepare("
            INSERT INTO ecom_orders (order_number, customer_id, customer_name, is_guest, total_amount, paid_amount, subtotal_amount, discount_amount, gst_amount, payment_status, payment_method, order_status, order_type)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'Delivered','offline')
        ")->execute([$order_number, $customer_id ?: null, $customer_name, $is_guest ? 1 : 0, $grand_total, $paid_amount, $subtotal, $discount, $total_gst, $payment_status, $payment_method]);
        $order_id = (int)$pdo->lastInsertId();

        // Record each payment method/amount pair for a clear, itemized receipt
        // (wrapped defensively — the sale must still complete even if this table
        // hasn't been created yet on a site where the migration wasn't run)
        try {
            foreach ($payments as $p) {
                if ($p['amount'] <= 0) continue;
                $pdo->prepare("INSERT INTO ecom_order_payments (order_id, payment_method, amount) VALUES (?,?,?)")
                    ->execute([$order_id, $p['method'], $p['amount']]);
            }
        } catch (Exception $e) {
            // ecom_order_payments table not present — the order itself is still saved correctly above
        }

        foreach ($line_items as $li) {
            $pdo->prepare("INSERT INTO ecom_order_items (order_id, product_id, product_name, hsn_code, qty, price, gst_rate, gst_amount) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$order_id, $li['product']['id'], $li['product']['name'], $li['product']['hsn_code'], $li['qty'], $li['unit_price'], $li['product']['gst_rate'], $li['gst_amount']]);

            if ($li['product']['product_type'] === 'physical' && $li['product']['stock_qty'] !== null) {
                $pdo->prepare("UPDATE ecom_products SET stock_qty = GREATEST(stock_qty - ?, 0) WHERE id = ?")
                    ->execute([$li['qty'], $li['product']['id']]);
            }
        }

        // ── Auto-create a Due (credit) record if the customer didn't pay in full ──
        if ($due_amount > 0.004) {
            $pdo->prepare("
                INSERT INTO ecom_credits (order_id, customer_id, customer_name, customer_phone, amount, promised_date, status)
                VALUES (?,?,?,?,?,?,'pending')
            ")->execute([$order_id, $customer_id ?: null, $customer_name, $customer_phone ?: null, $due_amount, $promised_date]);
        }

        if ($coupon) {
            $pdo->prepare("UPDATE ecom_coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$coupon['id']]);
        }

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_pos_sale', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "POS Sale: $order_number (₹" . number_format($grand_total, 2) . ($due_amount > 0 ? ", ₹" . number_format($due_amount, 2) . " due" : "") . ") via $payment_method", $log_ip, $log_ua]);

        $pdo->commit();
        echo json_encode(['success' => true, 'order_id' => $order_id, 'order_number' => $order_number, 'discount' => $discount, 'gst' => $total_gst, 'due' => $due_amount, 'grand_total' => $grand_total]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()]);
        exit;
    }
}

// ── Preload data for the client-side POS ────────────────────────────────────
$all_products = $pdo->query("
    SELECT id, name, sku, barcode, price, sale_price, gst_rate, stock_qty, product_type, category_id, subcategory_id
    FROM ecom_products WHERE status = 'active' ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$all_coupons = $pdo->query("SELECT code, discount_type, discount_value, applies_to FROM ecom_coupons WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$all_customers = $pdo->query("SELECT id, name, phone FROM ecom_customers WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Pre-select a customer when arriving from their profile / the customer list
// (e.g. billing.php?customer_id=42) — lets "Create Order" jump straight in.
$preselected_customer = null;
if (!empty($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
    $pc = $pdo->prepare("SELECT id, name, phone FROM ecom_customers WHERE id = ?");
    $pc->execute([(int)$_GET['customer_id']]);
    $preselected_customer = $pc->fetch(PDO::FETCH_ASSOC) ?: null;
}

$page_title = $preselected_customer ? 'New Order — ' . $preselected_customer['name'] : 'Billing / POS';
$page_subtitle = $preselected_customer ? 'Add products and complete the order for this customer' : 'Scan a barcode or search a product to start a sale';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.scan-box { font-size: 1.25rem; padding: 1rem 1.25rem; border: 2px solid var(--primary); border-radius: var(--radius-lg); }
.scan-box:focus { box-shadow: 0 0 0 3px var(--primary-lighter); }
.pos-cart-table th, .pos-cart-table td { vertical-align: middle; }
.qty-controls { display: flex; align-items: center; gap: 0.4rem; }
.qty-controls button { width: 28px; height: 28px; border: 1px solid var(--gray-200); background: var(--gray-50); border-radius: 4px; cursor: pointer; }
.qty-controls input { width: 50px; text-align: center; border: 1px solid var(--gray-200); border-radius: 4px; padding: 0.25rem; }
.pos-totals div { display: flex; justify-content: space-between; padding: 0.4rem 0; font-size: 0.95rem; }
.pos-totals .grand { font-size: 1.375rem; font-weight: 800; border-top: 2px solid var(--gray-800); padding-top: 0.6rem; margin-top: 0.3rem; }
.search-results-pos { position: absolute; z-index: 1060; background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius); box-shadow: var(--shadow-lg, 0 8px 20px rgba(0,0,0,.1)); max-height: 260px; overflow-y: auto; display: none; width: 100%; }
.search-results-pos .item { padding: 0.6rem 0.9rem; cursor: pointer; }
.search-results-pos .item:hover,
.search-results-pos .item.active { background: var(--gray-50); }
.search-results-pos .item small { color: var(--gray-400); }
.customer-search-wrap { position: relative; }

/* Premium thin toggle switch — Guest Bill */
.guest-toggle { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; user-select: none; }
.guest-toggle input { display: none; }
.guest-toggle-track { width: 34px; height: 18px; background: var(--gray-200); border-radius: 9999px; position: relative; transition: background 0.2s; flex-shrink: 0; }
.guest-toggle-thumb { position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; background: #fff; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,.25); transition: transform 0.2s; }
.guest-toggle input:checked + .guest-toggle-track { background: var(--primary); }
.guest-toggle input:checked + .guest-toggle-track .guest-toggle-thumb { transform: translateX(16px); }
.guest-toggle-label { font-size: 0.8rem; font-weight: 600; color: var(--gray-500); }
.guest-toggle input:checked ~ .guest-toggle-label { color: var(--primary); }
.field-dimmed { opacity: 0.4; pointer-events: none; }

/* Split payment rows */
.payment-row { align-items: center; }
.payment-row .pay-method { max-width: 110px; flex-shrink: 0; }
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

            <div class="row">
                <!-- LEFT: Scan + Cart -->
                <div class="col-lg-8">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <label class="form-label"><i class="fas fa-barcode"></i> Scan Barcode or Search Product</label>
                            <div class="position-relative">
                                <input type="text" id="scanInput" class="form-control scan-box" placeholder="Scan barcode or type product name / SKU…" autocomplete="off" autofocus>
                                <div class="search-results-pos" id="scanResults"></div>
                            </div>
                        </div>
                    </div>

                    <div class="gd-card">
                        <div class="gd-card-body">
                            <h5 class="mb-3"><i class="fas fa-shopping-basket text-primary"></i> Cart</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered pos-cart-table" id="cartTable">
                                    <thead>
                                        <tr><th>Product</th><th>Price</th><th width="140">Qty</th><th>Subtotal</th><th></th></tr>
                                    </thead>
                                    <tbody id="cartBody">
                                        <tr id="emptyCartRow"><td colspan="5" class="text-center text-muted py-4">Cart is empty — scan a product to begin.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Customer, Coupon, Totals -->
                <div class="col-lg-4">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="fas fa-user text-primary"></i> Customer</h5>
                                <label class="guest-toggle">
                                    <input type="checkbox" id="guestBillToggle" onchange="toggleGuestBill()">
                                    <span class="guest-toggle-track"><span class="guest-toggle-thumb"></span></span>
                                    <span class="guest-toggle-label">Guest Bill</span>
                                </label>
                            </div>
                            <input type="hidden" id="customerId">

                            <div class="mb-2 position-relative" id="customerPhoneWrap">
                                <label class="form-label small mb-1">Mobile Number</label>
                                <input type="text" id="customerPhone" class="form-control" placeholder="Enter mobile number" autocomplete="off" inputmode="numeric">
                                <div class="search-results-pos" id="customerResults"></div>
                            </div>
                            <div id="customerNameWrap">
                                <label class="form-label small mb-1">Customer Name</label>
                                <input type="text" id="customerName" class="form-control" placeholder="Name will appear automatically, or type it">
                            </div>
                            <div id="customerMatchNote" class="form-text" style="display:none;"></div>
                            <div id="guestNote" class="form-text text-muted" style="display:none;"><i class="fas fa-info-circle"></i> No customer details needed — this bill must be paid in full.</div>
                        </div>
                    </div>

                    <div class="gd-card">
                        <div class="gd-card-body">
                            <h5 class="mb-3"><i class="fas fa-tag text-primary"></i> Coupon</h5>
                            <div class="input-group">
                                <input type="text" id="couponCode" class="form-control text-uppercase" placeholder="Coupon code">
                                <button class="btn btn-secondary" type="button" onclick="applyCoupon()">Apply</button>
                            </div>
                            <div id="couponMsg" class="form-text"></div>
                        </div>
                    </div>

                    <div class="gd-card">
                        <div class="gd-card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="fas fa-money-bill-wave text-primary"></i> Payment</h5>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPaymentRow('Cash', '')"><i class="fas fa-plus"></i> Add</button>
                            </div>

                            <div id="paymentRows"></div>

                            <div class="pos-totals">
                                <div><span>Subtotal</span><span id="totSubtotal">₹0.00</span></div>
                                <div><span>Discount</span><span id="totDiscount">-₹0.00</span></div>
                                <div><span>GST</span><span id="totGst">+₹0.00</span></div>
                                <div class="grand"><span>Total</span><span id="totGrand">₹0.00</span></div>
                            </div>

                            <div class="d-flex justify-content-between small mt-3 mb-2">
                                <span class="text-muted">Amount Received</span>
                                <span id="paidTotalDisplay" class="fw-bold">₹0.00</span>
                            </div>

                            <div id="dueRow" class="alert alert-warning py-2 px-3 mb-3" style="display:none;">
                                <div class="d-flex justify-content-between fw-bold"><span>Due</span><span id="dueAmount">₹0.00</span></div>
                                <div class="mt-2">
                                    <label class="form-label small mb-1">Promise to pay by (optional)</label>
                                    <input type="date" id="promisedDate" class="form-control form-control-sm">
                                </div>
                            </div>

                            <button class="btn btn-primary w-100 btn-lg mt-1" onclick="completeSale()" id="completeSaleBtn">
                                <i class="fas fa-check-circle"></i> Complete Sale <small id="saleShortcutHint" class="opacity-75"></small>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>

<script>
const ALL_PRODUCTS  = <?= json_encode($all_products) ?>;
const ALL_COUPONS   = <?= json_encode($all_coupons) ?>;
const ALL_CUSTOMERS = <?= json_encode($all_customers) ?>;

let cart = [];              // [{product_id, name, unit_price, qty, stock_qty, product_type}]
let appliedCoupon = null;   // {code, discount_type, discount_value, applies_to}

function fmt(n) { return '₹' + Number(n).toFixed(2); }

function effectivePrice(p) {
    const sale = parseFloat(p.sale_price);
    const price = parseFloat(p.price);
    return (sale > 0 && sale < price) ? sale : price;
}

function addToCart(product) {
    const existing = cart.find(c => c.product_id === product.id);
    if (existing) {
        if (product.product_type === 'physical' && product.stock_qty !== null && existing.qty + 1 > product.stock_qty) {
            alert('Only ' + product.stock_qty + ' in stock for "' + product.name + '".');
            return;
        }
        existing.qty += 1;
    } else {
        cart.push({
            product_id: product.id,
            name: product.name,
            unit_price: effectivePrice(product),
            qty: 1,
            stock_qty: product.stock_qty,
            product_type: product.product_type,
            category_id: product.category_id,
            subcategory_id: product.subcategory_id,
            gst_rate: parseFloat(product.gst_rate) || 0
        });
    }
    renderCart();
}

function renderCart() {
    const body = document.getElementById('cartBody');
    if (cart.length === 0) {
        body.innerHTML = '<tr id="emptyCartRow"><td colspan="5" class="text-center text-muted py-4">Cart is empty — scan a product to begin.</td></tr>';
    } else {
        body.innerHTML = cart.map((c, idx) => `
            <tr>
                <td>${c.name}</td>
                <td>
                    <input type="number" step="0.01" min="0" value="${c.unit_price}" style="width:90px;" class="form-control form-control-sm" onchange="setPrice(${idx}, this.value)">
                </td>
                <td>
                    <div class="qty-controls">
                        <button type="button" onclick="changeQty(${idx}, -1)">-</button>
                        <input type="number" value="${c.qty}" min="1" onchange="setQty(${idx}, this.value)">
                        <button type="button" onclick="changeQty(${idx}, 1)">+</button>
                    </div>
                </td>
                <td>${fmt(c.unit_price * c.qty)}</td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeFromCart(${idx})"><i class="fas fa-trash-alt"></i></button></td>
            </tr>
        `).join('');
    }
    recalcTotals();
}

function changeQty(idx, delta) {
    const item = cart[idx];
    const newQty = item.qty + delta;
    if (newQty < 1) { removeFromCart(idx); return; }
    if (item.product_type === 'physical' && item.stock_qty !== null && newQty > item.stock_qty) {
        alert('Only ' + item.stock_qty + ' in stock.');
        return;
    }
    item.qty = newQty;
    renderCart();
}

function setQty(idx, val) {
    const qty = Math.max(1, parseInt(val) || 1);
    const item = cart[idx];
    if (item.product_type === 'physical' && item.stock_qty !== null && qty > item.stock_qty) {
        alert('Only ' + item.stock_qty + ' in stock.');
        item.qty = item.stock_qty;
    } else {
        item.qty = qty;
    }
    renderCart();
}

function setPrice(idx, val) {
    const price = Math.max(0, parseFloat(val) || 0);
    cart[idx].unit_price = price;
    cart[idx].price_overridden = true;
    renderCart();
}

function removeFromCart(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function recalcTotals() {
    const subtotal = cart.reduce((sum, c) => sum + c.unit_price * c.qty, 0);
    let discount = 0;

    if (appliedCoupon) {
        let eligible = 0;
        cart.forEach(c => {
            let matches = false;
            if (appliedCoupon.applies_to === 'all') matches = true;
            else if (appliedCoupon.applies_to === 'product' && c.product_id == appliedCoupon.product_id) matches = true;
            else if (appliedCoupon.applies_to === 'category' && c.category_id == appliedCoupon.category_id) matches = true;
            else if (appliedCoupon.applies_to === 'subcategory' && c.subcategory_id == appliedCoupon.subcategory_id) matches = true;
            if (matches) eligible += c.unit_price * c.qty;
        });
        if (eligible > 0) {
            discount = appliedCoupon.discount_type === 'percentage'
                ? eligible * (appliedCoupon.discount_value / 100)
                : Math.min(appliedCoupon.discount_value, eligible);
        }
    }

    let gst = 0;
    cart.forEach(c => {
        const lineTotal = c.unit_price * c.qty;
        const discountShare = subtotal > 0 ? discount * (lineTotal / subtotal) : 0;
        const taxable = Math.max(0, lineTotal - discountShare);
        gst += taxable * ((c.gst_rate || 0) / 100);
    });

    document.getElementById('totSubtotal').textContent = fmt(subtotal);
    document.getElementById('totDiscount').textContent = '-' + fmt(discount);
    document.getElementById('totGst').textContent = '+' + fmt(gst);
    document.getElementById('totGrand').textContent = fmt(Math.max(0, subtotal - discount) + gst);

    if (!userEditedPayments) {
        const rows = document.querySelectorAll('#paymentRows .payment-row');
        if (rows.length === 1) {
            rows[0].querySelector('.pay-amount').value = (Math.max(0, subtotal - discount) + gst).toFixed(2);
        }
    }
    updateDueDisplay();
}

let userEditedPayments = false;

function addPaymentRow(method, amount, silent) {
    const wrap = document.getElementById('paymentRows');
    const div = document.createElement('div');
    div.className = 'payment-row d-flex gap-2 mb-2';
    div.innerHTML = `
        <select class="form-select form-select-sm pay-method" onchange="onPaymentRowChange()">
            <option value="Cash">Cash</option>
            <option value="Card">Card</option>
            <option value="UPI">UPI</option>
            <option value="Other">Other</option>
        </select>
        <input type="number" step="0.01" min="0" class="form-control form-control-sm pay-amount" placeholder="Amount" oninput="onPaymentRowChange()">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removePaymentRow(this)"><i class="fas fa-times"></i></button>
    `;
    wrap.appendChild(div);
    if (method) div.querySelector('.pay-method').value = method;
    if (amount !== '' && amount !== undefined && amount !== null) div.querySelector('.pay-amount').value = amount;
    if (!silent) onPaymentRowChange();
}

function removePaymentRow(btn) {
    const rows = document.querySelectorAll('#paymentRows .payment-row');
    if (rows.length <= 1) return; // always keep at least one payment row
    btn.closest('.payment-row').remove();
    onPaymentRowChange();
}

function onPaymentRowChange() {
    userEditedPayments = true;
    updateDueDisplay();
}

function getPaidTotal() {
    let total = 0;
    document.querySelectorAll('#paymentRows .pay-amount').forEach(inp => total += parseFloat(inp.value) || 0);
    return total;
}

function getPaymentBreakdown() {
    const rows = [];
    document.querySelectorAll('#paymentRows .payment-row').forEach(row => {
        const method = row.querySelector('.pay-method').value;
        const amount = parseFloat(row.querySelector('.pay-amount').value) || 0;
        if (amount > 0) rows.push({ method, amount });
    });
    return rows;
}

function updateDueDisplay() {
    const grand = parseFloat(document.getElementById('totGrand').textContent.replace('₹', '')) || 0;
    const paid = getPaidTotal();
    const due = Math.max(0, grand - paid);
    document.getElementById('paidTotalDisplay').textContent = fmt(paid);
    const dueRow = document.getElementById('dueRow');
    if (due > 0.004) {
        dueRow.style.display = 'block';
        document.getElementById('dueAmount').textContent = fmt(due);
    } else {
        dueRow.style.display = 'none';
    }
}

// Seed with a single default Cash row (auto-filled to the bill total until edited) — runs once at page load
addPaymentRow('Cash', '0.00', true);

function applyCoupon() {
    const code = document.getElementById('couponCode').value.trim().toUpperCase();
    const msg = document.getElementById('couponMsg');
    if (!code) { appliedCoupon = null; msg.textContent = ''; recalcTotals(); return; }

    const found = ALL_COUPONS.find(c => c.code.toUpperCase() === code);
    if (!found) {
        appliedCoupon = null;
        msg.innerHTML = '<span class="text-danger">Invalid or inactive coupon code.</span>';
    } else {
        appliedCoupon = found;
        msg.innerHTML = '<span class="text-success">Coupon "' + code + '" applied!</span>';
    }
    recalcTotals();
}

// ── Barcode scan / product search ───────────────────────────────────────────
const scanInput = document.getElementById('scanInput');
const scanResults = document.getElementById('scanResults');

scanInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const val = this.value.trim();
        if (!val) return;

        // Exact match first (this is what a barcode scanner gun produces)
        const exact = ALL_PRODUCTS.find(p => p.barcode === val || p.sku === val || String(p.id) === val);
        if (exact) {
            addToCart(exact);
            this.value = '';
            scanResults.style.display = 'none';
            return;
        }

        // Otherwise treat it as a name search — add the first match
        const q = val.toLowerCase();
        const match = ALL_PRODUCTS.find(p => p.name.toLowerCase().includes(q));
        if (match) {
            addToCart(match);
            this.value = '';
            scanResults.style.display = 'none';
        } else {
            alert('No product found for "' + val + '".');
        }
    }
});

scanInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    if (q.length < 2) { scanResults.style.display = 'none'; return; }
    const matches = ALL_PRODUCTS.filter(p =>
        p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q) || (p.barcode || '').includes(q)
    ).slice(0, 8);

    if (matches.length === 0) { scanResults.style.display = 'none'; return; }
    scanResults.innerHTML = matches.map(p =>
        `<div class="item" data-id="${p.id}">${p.name} <small>(${fmt(effectivePrice(p))}${p.stock_qty !== null ? ' · Stock: ' + p.stock_qty : ''})</small></div>`
    ).join('');
    scanResults.style.display = 'block';
});

scanResults.addEventListener('click', function (e) {
    const item = e.target.closest('.item[data-id]');
    if (!item) return;
    const product = ALL_PRODUCTS.find(p => p.id == item.dataset.id);
    if (product) addToCart(product);
    scanInput.value = '';
    scanInput.focus();
    scanResults.style.display = 'none';
});

document.addEventListener('click', function (e) {
    if (!e.target.closest('.position-relative')) scanResults.style.display = 'none';
});

// ── Customer lookup: mobile number first, name auto-fills on match ─────────
const customerPhoneField = document.getElementById('customerPhone');
const customerNameField = document.getElementById('customerName');
const customerResults = document.getElementById('customerResults');
const customerIdField = document.getElementById('customerId');
const customerMatchNote = document.getElementById('customerMatchNote');

<?php if ($preselected_customer): ?>
customerIdField.value = '<?= (int)$preselected_customer['id'] ?>';
customerNameField.value = '<?= htmlspecialchars(addslashes($preselected_customer['name'])) ?>';
customerPhoneField.value = '<?= htmlspecialchars(addslashes($preselected_customer['phone'] ?? '')) ?>';
<?php endif; ?>

function clearCustomerMatch() {
    customerIdField.value = '';
    customerMatchNote.style.display = 'none';
}

let customerHighlightIndex = -1;

function highlightCustomerItem(index) {
    const items = customerResults.querySelectorAll('.item[data-id]');
    items.forEach(el => el.classList.remove('active'));
    if (index >= 0 && index < items.length) {
        items[index].classList.add('active');
        items[index].scrollIntoView({ block: 'nearest' });
    }
    customerHighlightIndex = index;
}

function selectCustomerItem(item) {
    customerIdField.value = item.dataset.id;
    customerNameField.value = item.dataset.name;
    customerPhoneField.value = item.dataset.phone;
    customerMatchNote.innerHTML = '<i class="fas fa-check-circle text-success"></i> Existing customer selected';
    customerMatchNote.style.display = 'block';
    customerResults.style.display = 'none';
    customerHighlightIndex = -1;
}

customerPhoneField.addEventListener('input', function () {
    const q = this.value.trim();
    clearCustomerMatch();
    customerResults.style.display = 'none';
    customerHighlightIndex = -1;
    if (q.length < 4) return;

    const matches = ALL_CUSTOMERS.filter(c => (c.phone || '').includes(q));

    // Exact phone match → auto-fill the name immediately, no click needed
    const exact = ALL_CUSTOMERS.find(c => (c.phone || '') === q);
    if (exact) {
        customerIdField.value = exact.id;
        customerNameField.value = exact.name;
        customerMatchNote.innerHTML = '<i class="fas fa-check-circle text-success"></i> Existing customer found';
        customerMatchNote.style.display = 'block';
        return;
    }

    // Otherwise show a pick-list (helpful while still typing digits) —
    // usable with the mouse, or with the keyboard (↑/↓ then Enter).
    if (matches.length > 0) {
        customerResults.innerHTML = matches.slice(0, 6).map(c =>
            `<div class="item" data-id="${c.id}" data-name="${c.name.replace(/"/g,'&quot;')}" data-phone="${c.phone || ''}">${c.name} <small>(${c.phone || ''})</small></div>`
        ).join('');
        customerResults.style.display = 'block';
    } else {
        customerNameField.placeholder = 'New customer — type their name';
    }
});

customerPhoneField.addEventListener('keydown', function (e) {
    const items = customerResults.querySelectorAll('.item[data-id]');
    if (customerResults.style.display === 'none' || items.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightCustomerItem(Math.min(customerHighlightIndex + 1, items.length - 1));
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightCustomerItem(Math.max(customerHighlightIndex - 1, 0));
    } else if (e.key === 'Enter') {
        if (customerHighlightIndex >= 0) {
            e.preventDefault();
            selectCustomerItem(items[customerHighlightIndex]);
        }
    } else if (e.key === 'Escape') {
        customerResults.style.display = 'none';
        customerHighlightIndex = -1;
    }
});

customerResults.addEventListener('click', function (e) {
    const item = e.target.closest('.item[data-id]');
    if (!item) return;
    selectCustomerItem(item);
});

// If they edit the name after an auto-match, keep the linked customer_id
// (they might just be fixing a typo) — but if they change the phone again,
// clearCustomerMatch() above already resets it correctly.

document.addEventListener('click', function (e) {
    if (!e.target.closest('#customerPhone') && !e.target.closest('#customerResults')) customerResults.style.display = 'none';
});

// ── Complete Sale ────────────────────────────────────────────────────────────
function completeSale() {
    if (cart.length === 0) { alert('Cart is empty.'); return; }

    const grand = parseFloat(document.getElementById('totGrand').textContent.replace('₹', '')) || 0;
    const payments = getPaymentBreakdown();
    const paidTotal = payments.reduce((s, p) => s + p.amount, 0);
    const due = Math.max(0, grand - paidTotal);
    const custName = document.getElementById('customerName').value.trim();
    const isGuest = document.getElementById('guestBillToggle').checked;

    if (isGuest && due > 0.004) {
        alert('Guest bills must be paid in full. Turn off Guest Bill to record a due amount against a customer.');
        return;
    }
    if (!isGuest && due > 0.004 && !customerIdField.value && !custName) {
        alert('This sale has a due amount — please select an existing customer or enter a walk-in customer name so it can be tracked.');
        return;
    }

    const payload = {
        items: cart.map(c => ({ product_id: c.product_id, qty: c.qty, price_override: c.price_overridden ? c.unit_price : null })),
        customer_id: isGuest ? 0 : (customerIdField.value || 0),
        customer_name: isGuest ? '' : custName,
        customer_phone: isGuest ? '' : document.getElementById('customerPhone').value.trim(),
        is_guest: isGuest,
        payments: payments,
        promised_date: document.getElementById('promisedDate').value || null,
        coupon_code: appliedCoupon ? appliedCoupon.code : ''
    };

    const btn = document.getElementById('completeSaleBtn');
    btn.disabled = true;

    fetch('billing.php?action=checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.success) { alert(data.message || 'Checkout failed.'); return; }

        printOrder(data.order_id);

        // Reset for next sale
        cart = [];
        appliedCoupon = null;
        userEditedPayments = false;
        document.getElementById('promisedDate').value = '';
        document.getElementById('couponCode').value = '';
        document.getElementById('couponMsg').textContent = '';
        document.getElementById('customerName').value = '';
        document.getElementById('customerPhone').value = '';
        customerIdField.value = '';
        customerMatchNote.style.display = 'none';
        document.getElementById('guestBillToggle').checked = false;
        toggleGuestBill();
        document.getElementById('paymentRows').innerHTML = '';
        addPaymentRow('Cash', '0.00', true);
        lastCompletedOrderId = data.order_id;
        renderCart();
        scanInput.focus();
    })
    .catch(() => { btn.disabled = false; alert('Checkout failed — please try again.'); });
}

// ── Guest Bill toggle ─────────────────────────────────────────────────────────
function toggleGuestBill() {
    const isGuest = document.getElementById('guestBillToggle').checked;
    document.getElementById('customerPhoneWrap').classList.toggle('field-dimmed', isGuest);
    document.getElementById('customerNameWrap').classList.toggle('field-dimmed', isGuest);
    document.getElementById('guestNote').style.display = isGuest ? 'block' : 'none';
    if (isGuest) {
        document.getElementById('customerPhone').value = '';
        document.getElementById('customerName').value = '';
        customerIdField.value = '';
        customerMatchNote.style.display = 'none';
    }
}

// ── Print handling — follows the POS Print Mode set in Business Settings ────
const POS_PRINT_MODE = <?= json_encode($pos_print_mode) ?>;
let lastCompletedOrderId = null;

function printOrder(orderId) {
    if (POS_PRINT_MODE === 'thermal') {
        window.open('invoice.php?id=' + orderId + '&format=<?= $thermal_format_value ?>', '_blank');
    } else if (POS_PRINT_MODE === 'a4') {
        window.open('invoice.php?id=' + orderId + '&format=a4', '_blank');
    } else {
        window.open('invoice.php?id=' + orderId, '_blank');
    }
}

// ── Configurable keyboard shortcuts (set in Business Settings → Print & Shortcuts) ──
const SHORTCUT_COMPLETE_SALE = <?= json_encode($shortcut_complete_sale) ?>;
const SHORTCUT_PRINT = <?= json_encode($shortcut_print) ?>;
const SHORTCUT_NEW_SALE = <?= json_encode($shortcut_new_sale) ?>;

document.getElementById('saleShortcutHint').textContent = '(' + SHORTCUT_COMPLETE_SALE + ')';

document.addEventListener('keydown', function (e) {
    if (e.key === SHORTCUT_COMPLETE_SALE) {
        e.preventDefault();
        completeSale();
    } else if (e.key === SHORTCUT_PRINT) {
        e.preventDefault();
        if (lastCompletedOrderId) printOrder(lastCompletedOrderId);
    } else if (e.key === SHORTCUT_NEW_SALE) {
        e.preventDefault();
        cart = [];
        renderCart();
        scanInput.focus();
    }
});
</script>
</body>
</html>
