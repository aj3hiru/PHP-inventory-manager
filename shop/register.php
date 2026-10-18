<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!empty($_SESSION['customer_id'])) { header('Location: /shop/account.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $pdo->prepare("SELECT id FROM ecom_customers WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with this email already exists. Please login instead.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO ecom_customers (name, email, phone, password, customer_type, status) VALUES (?,?,?,?,'online','active')")
                ->execute([$name, $email, $phone ?: null, $hash]);
            $new_id = $pdo->lastInsertId();

            // Same session id used while browsing as a guest — carry over
            // anything added to the cart before creating this account.
            $guest_session_id = session_id();

            $_SESSION['customer_id']   = $new_id;
            $_SESSION['customer_name'] = $name;

            require_once __DIR__ . '/includes/cart-helpers.php';
            cartMergeGuestIntoAccount($pdo, (int)$new_id, $guest_session_id);

            header('Location: /account?welcome=1');
            exit;
        }
    }
}

$page_title = 'Create Account';
include __DIR__ . '/includes/shop-header.php';
?>

<div class="shop-container" style="max-width:480px;">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-1">Create Your Account</h4>
            <p class="text-muted mb-4">Sign up to track orders, save your wishlist, and checkout faster.</p>

            <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
                <button type="submit" class="btn btn-shop-primary w-100">Create Account</button>
            </form>

            <p class="text-center mt-3 mb-0">Already have an account? <a href="/shop/login.php">Login</a></p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
