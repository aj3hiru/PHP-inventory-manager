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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_orders'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$order_id = (int)($_GET['id'] ?? 0);
$notifications = [];

function fetchOrder(PDO $pdo, int $id) {
    $s = $pdo->prepare("SELECT * FROM ecom_orders WHERE id = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function isLocked(array $order): bool {
    return $order['order_status'] === 'Delivered';
}
function recalcOrderTotals(PDO $pdo, int $order_id) {
    $items = $pdo->prepare("SELECT * FROM ecom_order_items WHERE order_id = ?");
    $items->execute([$order_id]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);

    $subtotal = 0.0;
    $total_gst = 0.0;
    foreach ($items as $it) {
        $line_total = (float)$it['price'] * (float)$it['qty'];
        $gst_rate = (float)($it['gst_rate'] ?? 0);
        $line_gst = round($line_total * $gst_rate / 100, 2);
        $subtotal += $line_total;
        $total_gst += $line_gst;
        $pdo->prepare("UPDATE ecom_order_items SET gst_amount = ? WHERE id = ?")->execute([$line_gst, $it['id']]);
    }

    $discount_stmt = $pdo->prepare("SELECT COALESCE(discount_amount,0) FROM ecom_orders WHERE id = ?");
    $discount_stmt->execute([$order_id]);
    $discount = (float) $discount_stmt->fetchColumn();

    $grand_total = max(0, $subtotal - $discount + $total_gst);

    $pdo->prepare("UPDATE ecom_orders SET subtotal_amount = ?, gst_amount = ?, total_amount = ? WHERE id = ?")
        ->execute([$subtotal, $total_gst, $grand_total, $order_id]);
}

$order = fetchOrder($pdo, $order_id);
if (!$order) { exit('Order not found.'); }

$customer_phone = null;
$customer_email_live = null;
$customer_address_live = null;
if (!empty($order['customer_id'])) {
    $cph = $pdo->prepare("SELECT phone, email, address FROM ecom_customers WHERE id = ?");
    $cph->execute([$order['customer_id']]);
    $cust_row = $cph->fetch(PDO::FETCH_ASSOC);
    if ($cust_row) {
        $customer_phone = $cust_row['phone'];
        $customer_email_live = $cust_row['email'];
        $customer_address_live = $cust_row['address'];
    }
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isLocked($order)) {
        $notifications[] = ['type' => 'error', 'message' => 'This order is completed (Delivered) and can no longer be edited.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $new_status = $_POST['order_status'] ?? $order['order_status'];
            if (in_array($new_status, ['Pending', 'In Progress', 'Delivered', 'Canceled'], true)) {
                $pdo->prepare("UPDATE ecom_orders SET order_status = ? WHERE id = ?")->execute([$new_status, $order_id]);
                $notifications[] = ['type' => 'success', 'message' => 'Order status updated to ' . $new_status . '.'];
            }
        } elseif ($action === 'update_payment') {
            $new_payment = $_POST['payment_status'] ?? $order['payment_status'];
            if (in_array($new_payment, ['Paid', 'Unpaid'], true)) {
                $pdo->prepare("UPDATE ecom_orders SET payment_status = ?, paid_amount = ? WHERE id = ?")
                    ->execute([$new_payment, $new_payment === 'Paid' ? $order['total_amount'] : 0, $order_id]);
                $notifications[] = ['type' => 'success', 'message' => 'Payment status updated to ' . $new_payment . '.'];
            }
        } elseif ($action === 'update_qty') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $qty = max(1, (float)($_POST['qty'] ?? 1));
            $pdo->prepare("UPDATE ecom_order_items SET qty = ? WHERE id = ? AND order_id = ?")->execute([$qty, $item_id, $order_id]);
            recalcOrderTotals($pdo, $order_id);
            $notifications[] = ['type' => 'success', 'message' => 'Quantity updated.'];
        } elseif ($action === 'remove_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $pdo->prepare("DELETE FROM ecom_order_items WHERE id = ? AND order_id = ?")->execute([$item_id, $order_id]);
            recalcOrderTotals($pdo, $order_id);
            $notifications[] = ['type' => 'success', 'message' => 'Item removed from order.'];
        } elseif ($action === 'add_item') {
            $product_id = (int)($_POST['product_id'] ?? 0);
            $qty = max(1, (float)($_POST['add_qty'] ?? 1));
            $p = $pdo->prepare("SELECT * FROM ecom_products WHERE id = ?");
            $p->execute([$product_id]);
            $p = $p->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $price = (!empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price']) ? (float)$p['sale_price'] : (float)$p['price'];
                // If this product is already a line on the order, just bump its qty instead of duplicating
                $existing = $pdo->prepare("SELECT id, qty FROM ecom_order_items WHERE order_id = ? AND product_id = ?");
                $existing->execute([$order_id, $product_id]);
                $existing = $existing->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $pdo->prepare("UPDATE ecom_order_items SET qty = qty + ? WHERE id = ?")->execute([$qty, $existing['id']]);
                } else {
                    $pdo->prepare("INSERT INTO ecom_order_items (order_id, product_id, product_name, hsn_code, qty, price, gst_rate, gst_amount) VALUES (?,?,?,?,?,?,?,0)")
                        ->execute([$order_id, $product_id, $p['name'], $p['hsn_code'] ?? null, $qty, $price, $p['gst_rate'] ?? 0]);
                }
                recalcOrderTotals($pdo, $order_id);
                $notifications[] = ['type' => 'success', 'message' => 'Product added to order.'];
            } else {
                $notifications[] = ['type' => 'error', 'message' => 'Product not found.'];
            }
        }
    }

    $order = fetchOrder($pdo, $order_id); // reload with fresh values
}

$items = $pdo->prepare("SELECT * FROM ecom_order_items WHERE order_id = ?");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$locked = isLocked($order);

$linked_credit = null;
$due_payments = [];
$cq = $pdo->prepare("SELECT * FROM ecom_credits WHERE order_id = ?");
$cq->execute([$order_id]);
$linked_credit = $cq->fetch(PDO::FETCH_ASSOC);
if ($linked_credit) {
    $hp = $pdo->prepare("SELECT * FROM ecom_credit_payments WHERE credit_id = ? ORDER BY created_at ASC");
    $hp->execute([$linked_credit['id']]);
    $due_payments = $hp->fetchAll(PDO::FETCH_ASSOC);
}
$due_balance = $linked_credit ? max(0, (float)$linked_credit['amount'] - (float)$linked_credit['amount_paid']) : 0;

$all_products = [];
if (!$locked) {
    $all_products = $pdo->query("SELECT id, name, sku, price, sale_price, stock_qty FROM ecom_products WHERE status='active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Order ' . $order['order_number'];
$page_subtitle = 'Order details and items';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.summary-head { text-align: center; padding-bottom: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--gray-100); }
.summary-order-num { font-size: 1.05rem; font-weight: 700; color: var(--gray-900); letter-spacing: 0.02em; }
.summary-total { font-size: 1.75rem; font-weight: 800; color: var(--primary); margin-top: 0.2rem; }
.summary-badges { display: flex; justify-content: center; gap: 0.4rem; margin-bottom: 1.25rem; }
.summary-badge { font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 9999px; }
.summary-badge.badge-danger { background: #fee2e2; color: #991b1b; }
.summary-badge.badge-success { background: #d1fae5; color: #065f46; }
.summary-badge.badge-secondary { background: var(--gray-100); color: var(--gray-500); }
.summary-badge.badge-info { background: #dbeafe; color: #1e40af; }
.summary-rows { border-top: 1px solid var(--gray-100); padding-top: 0.75rem; }
.summary-row { display: flex; align-items: flex-start; gap: 0.65rem; padding: 0.5rem 0; font-size: 0.875rem; color: var(--gray-700); }
.summary-row i { width: 16px; color: var(--gray-400); margin-top: 0.2rem; flex-shrink: 0; }
.customer-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    background: var(--primary-lighter); color: var(--primary);
    padding: 0.3rem 0.85rem; border-radius: 9999px;
    font-weight: 700; font-size: 0.875rem; text-decoration: none;
    transition: all 0.15s;
}
.customer-btn:hover { background: var(--primary); color: #fff; }
.customer-btn i { width: auto; color: inherit; margin-top: 0; font-size: 0.75rem; }
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
                <a href="orders.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Orders</a>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>


            <div class="row">
                <div class="col-md-9">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <h5 class="mb-3"><i class="fas fa-box text-primary"></i> Items</h5>
                            <?php if (empty($items)): ?>
                                <p class="text-muted">No line items recorded for this order.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Qty</th>
                                            <th>Price</th>
                                            <th>Due</th>
                                            <th>Subtotal</th>
                                            <th>Invoice</th>
                                            <?php if (!$locked): ?><th>Actions</th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php $item_count = count($items); ?>
                                    <?php foreach ($items as $i => $it): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['product_name']) ?></td>
                                        <td>
                                            <?php if ($locked): ?>
                                                <?= (float)$it['qty'] ?>
                                            <?php else: ?>
                                            <form method="POST" class="d-flex gap-1">
                                                <input type="hidden" name="action" value="update_qty">
                                                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                                <input type="number" name="qty" value="<?= (float)$it['qty'] ?>" min="1" step="1" class="form-control form-control-sm" style="width:70px;">
                                                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-sync-alt"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                        <td>₹<?= number_format((float)$it['price'], 2) ?></td>
                                        <?php if ($i === 0): ?>
                                        <td rowspan="<?= $item_count ?>">
                                            <?php if ($due_balance > 0.004): ?>
                                                <span class="badge bg-danger">Due: ₹<?= number_format($due_balance, 2) ?></span>
                                                <br><button type="button" class="btn btn-primary btn-sm mt-1" onclick="openPayModal(<?= $linked_credit['id'] ?>, '<?= htmlspecialchars(addslashes($order['customer_name'] ?: 'Customer')) ?>', <?= $due_balance ?>)">Pay Due</button>
                                            <?php elseif ($linked_credit): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle"></i> Paid: ₹<?= number_format((float)$order['total_amount'], 2) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td>₹<?= number_format((float)$it['price'] * (float)$it['qty'], 2) ?></td>
                                        <?php if ($i === 0): ?>
                                        <td rowspan="<?= $item_count ?>">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="openInvoiceList()">
                                                <i class="fas fa-file-invoice"></i><?= count($due_payments) > 0 ? ' ×' . (count($due_payments) + 1) : '' ?>
                                            </button>
                                        </td>
                                        <?php endif; ?>
                                        <?php if (!$locked): ?>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Remove this item from the order?');">
                                                <input type="hidden" name="action" value="remove_item">
                                                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (!$locked): ?>
                            <hr>
                            <h6 class="mb-2">Add a Product to this Order</h6>
                            <form method="POST" class="row g-2 align-items-end">
                                <input type="hidden" name="action" value="add_item">
                                <div class="col-md-7 position-relative">
                                    <input type="text" id="productSearchInput" class="form-control" placeholder="Search product by name or SKU…" autocomplete="off">
                                    <input type="hidden" name="product_id" id="addProductId">
                                    <div id="productSearchResults" class="list-group position-absolute w-100" style="z-index:50; max-height:220px; overflow-y:auto; display:none;"></div>
                                </div>
                                <div class="col-md-3">
                                    <input type="number" name="add_qty" value="1" min="1" step="1" class="form-control" placeholder="Qty">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary w-100" id="addItemBtn" disabled>Add</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="gd-card summary-card">
                        <div class="gd-card-body">
                            <div class="summary-head">
                                <div class="summary-order-num"><?= htmlspecialchars($order['order_number']) ?></div>
                                <div class="summary-total">₹<?= number_format((float)$order['total_amount'], 2) ?></div>
                            </div>

                            <div class="summary-badges">
                                <?php if ($linked_credit && $due_balance > 0.004): ?>
                                    <span class="summary-badge badge-danger">Due: ₹<?= number_format($due_balance, 2) ?></span>
                                <?php elseif ($locked || $order['payment_status'] === 'Paid'): ?>
                                    <span class="summary-badge badge-success"><i class="fas fa-check-circle"></i> Paid: ₹<?= number_format((float)$order['total_amount'], 2) ?></span>
                                <?php else: ?>
                                    <span class="summary-badge badge-secondary"><?= htmlspecialchars($order['payment_status']) ?></span>
                                <?php endif; ?>

                                <?php if ($locked): ?>
                                    <span class="summary-badge badge-success">Completed</span>
                                <?php else: ?>
                                    <span class="summary-badge badge-info"><?= htmlspecialchars($order['order_status']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="summary-rows">
                                <div class="summary-row">
                                    <i class="fas fa-user"></i>
                                    <?php if (!empty($order['customer_id'])): ?>
                                        <a href="customer-profile.php?id=<?= $order['customer_id'] ?>" class="customer-btn"><?= htmlspecialchars($order['customer_name'] ?: 'View Profile') ?> <i class="fas fa-arrow-right"></i></a>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($order['customer_name'] ?: '—') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="summary-row"><i class="fas fa-phone"></i> <span><?= htmlspecialchars($customer_phone ?: '—') ?></span></div>
                                <div class="summary-row"><i class="fas fa-envelope"></i> <span><?= htmlspecialchars($order['customer_email'] ?: $customer_email_live ?: '—') ?></span></div>
                                <div class="summary-row"><i class="fas fa-map-marker-alt"></i> <span><?= nl2br(htmlspecialchars($order['shipping_address'] ?: $customer_address_live ?: '')) ?: '—' ?></span></div>
                                <div class="summary-row"><i class="fas fa-credit-card"></i> <span><?= htmlspecialchars($order['payment_method'] ?? '—') ?></span></div>
                            </div>

                            <?php if (!$locked): ?>
                            <hr>
                            <?php if (!($linked_credit && $due_balance > 0.004)): ?>
                            <label class="form-label mb-1 small">Update Payment Status</label>
                            <form method="POST" class="d-flex gap-2 mb-3">
                                <input type="hidden" name="action" value="update_payment">
                                <select name="payment_status" class="form-select form-select-sm">
                                    <option value="Unpaid" <?= $order['payment_status'] === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                    <option value="Paid" <?= $order['payment_status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                            <?php endif; ?>

                            <label class="form-label mb-1 small">Update Order Status</label>
                            <form method="POST" class="d-flex gap-2 mb-3">
                                <input type="hidden" name="action" value="update_status">
                                <select name="order_status" class="form-select form-select-sm">
                                    <?php foreach (['Pending', 'In Progress', 'Delivered', 'Canceled'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $order['order_status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                            <?php endif; ?>

                            <p class="mb-0 text-muted small"><strong>Placed:</strong> <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></p>
                        </div>
                    </div>
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
                    <input type="hidden" name="return_to" value="/admin/ecommerce/order-view.php?id=<?= $order_id ?>">
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
                <h5 class="modal-title">Invoices &amp; Receipts</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="list-group">
                    <a href="invoice.php?id=<?= $order_id ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-file-invoice"></i> Main Invoice</span>
                        <small class="text-muted"><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></small>
                    </a>
                    <?php foreach ($due_payments as $i => $p): ?>
                    <a href="payment-receipt.php?receipt=<?= urlencode($p['receipt_number']) ?>&return_to=<?= urlencode('/admin/ecommerce/order-view.php?id=' . $order_id) ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-receipt"></i> Payment Receipt <?= $i + 1 ?> — ₹<?= number_format((float)$p['amount'], 2) ?></span>
                        <small class="text-muted"><?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
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

function openInvoiceList() {
    const receiptCount = <?= count($due_payments) ?>;
    if (receiptCount === 0) {
        window.open('invoice.php?id=<?= $order_id ?>', '_blank');
        return;
    }
    new bootstrap.Modal(document.getElementById('invoiceListModal')).show();
}
</script>
<?php if (!$locked): ?>
<script>
const ALL_PRODUCTS = <?= json_encode($all_products) ?>;
const searchInput = document.getElementById('productSearchInput');
const searchResults = document.getElementById('productSearchResults');
const addProductId = document.getElementById('addProductId');
const addItemBtn = document.getElementById('addItemBtn');

function effectivePrice(p) {
    const sale = parseFloat(p.sale_price);
    const price = parseFloat(p.price);
    return (sale > 0 && sale < price) ? sale : price;
}

searchInput.addEventListener('input', function () {
    addProductId.value = '';
    addItemBtn.disabled = true;
    const q = this.value.trim().toLowerCase();
    if (q.length < 2) { searchResults.style.display = 'none'; return; }

    const matches = ALL_PRODUCTS.filter(p =>
        p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q)
    ).slice(0, 8);

    if (matches.length === 0) { searchResults.style.display = 'none'; return; }
    searchResults.innerHTML = matches.map(p =>
        `<a href="#" class="list-group-item list-group-item-action" data-id="${p.id}" data-name="${p.name.replace(/"/g,'&quot;')}">${p.name} <small class="text-muted">(₹${effectivePrice(p).toFixed(2)}${p.stock_qty !== null ? ' · Stock: ' + p.stock_qty : ''})</small></a>`
    ).join('');
    searchResults.style.display = 'block';
});

searchResults.addEventListener('click', function (e) {
    e.preventDefault();
    const item = e.target.closest('.list-group-item');
    if (!item) return;
    addProductId.value = item.dataset.id;
    searchInput.value = item.dataset.name;
    addItemBtn.disabled = false;
    searchResults.style.display = 'none';
});

document.addEventListener('click', function (e) {
    if (!e.target.closest('#productSearchInput') && !e.target.closest('#productSearchResults')) searchResults.style.display = 'none';
});
</script>
<?php endif; ?>
</body>
</html>
