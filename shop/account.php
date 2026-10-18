<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (empty($_SESSION['customer_id'])) {
    header('Location: /shop/login.php?redirect=' . urlencode('/shop/account.php'));
    exit;
}

$page_title = 'My Account';
include __DIR__ . '/includes/shop-header.php';

$notice = '';
if (isset($_GET['welcome'])) $notice = 'Welcome to ' . $site_name . '! Your account has been created.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!empty($name)) {
        $pdo->prepare("UPDATE ecom_customers SET name=?, phone=?, address=? WHERE id=?")
            ->execute([$name, $phone ?: null, $address ?: null, $shop_customer['id']]);
        $_SESSION['customer_name'] = $name;
        $notice = 'Profile updated successfully!';
    }
}

$full = $pdo->prepare("SELECT * FROM ecom_customers WHERE id = ?");
$full->execute([$shop_customer['id']]);
$full = $full->fetch(PDO::FETCH_ASSOC);

$orders = $pdo->prepare("SELECT * FROM ecom_orders WHERE customer_id = ? ORDER BY created_at DESC");
$orders->execute([$shop_customer['id']]);
$orders = $orders->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="shop-container">
    <h2 class="section-title">My Account</h2>

    <?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="fw-bold mb-3">Profile</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($full['name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?= htmlspecialchars($full['email'] ?? '') ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($full['phone'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($full['address'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-shop-primary w-100">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8" id="orders">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h5 class="fw-bold mb-3">My Orders</h5>
                    <?php if (empty($orders)): ?>
                        <p class="text-muted">You haven't placed any orders yet. <a href="/">Start shopping</a>.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Order #</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?= htmlspecialchars($o['order_number']) ?></td>
                                <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                                <td>₹<?= number_format((float)$o['total_amount'], 2) ?></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($o['order_status']) ?></span></td>
                                <td><a href="/order/<?= $o['id'] ?>" class="btn btn-sm btn-outline-shop">View</a></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
