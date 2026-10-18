<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

// Security headers — same hardening as the old dedicated admin login,
// since this page can now authenticate admin accounts too.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");

define('ADMIN_DASHBOARD', '/admin/dashboard.php');

// ── Already logged in — send to the right place ─────────────────────────────
if (isset($_SESSION['user_id'], $_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor', 'author'], true)) {
    header("Location: " . ADMIN_DASHBOARD);
    exit;
}
if (!empty($_SESSION['customer_id'])) {
    header('Location: /shop/account.php');
    exit;
}

$ip_address = $_SERVER['REMOTE_ADDR']     ?? 'UNKNOWN';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
$redirect   = $_GET['redirect'] ?? '';

// ── Brute-force lockout (session-based, shared across both account types) ──
const MAX_ATTEMPTS = 5;
const LOCKOUT_SECS = 900; // 15 min

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['lockout_until']))  $_SESSION['lockout_until']  = 0;

$locked    = $_SESSION['lockout_until'] > time();
$lock_left = $locked ? $_SESSION['lockout_until'] - time() : 0;

$error = '';

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {

    if (!csrfValid()) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';
        $redirect = $_POST['redirect'] ?? $redirect;

        if (empty($identity) || empty($password)) {
            $error = 'Please enter your email/username and password.';
        } else {
            $authenticated = false;

            // ── 1) Try an admin/editor/author account first (matched by username) ──
            $stmt = $pdo->prepare(
                "SELECT id, username, email, password_hash, role, status, permissions
                 FROM users WHERE username = ? AND role IN ('admin','editor','author') LIMIT 1"
            );
            $stmt->execute([$identity]);
            $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);

            $dummy_hash = '$2y$10$invaliddummyhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
            $admin_pass_ok = password_verify($password, $admin_user['password_hash'] ?? $dummy_hash);

            if ($admin_user && $admin_pass_ok) {
                if ($admin_user['status'] === 'suspended') {
                    $error = 'Your account has been suspended. Contact the administrator.';
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'login_blocked', ?, ?, ?)")
                        ->execute([$admin_user['id'], "Suspended account login attempt: {$admin_user['username']}", $ip_address, $user_agent]);
                } elseif ($admin_user['status'] === 'pending') {
                    $error = 'Your account is pending approval. Please wait for admin activation.';
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'login_blocked', ?, ?, ?)")
                        ->execute([$admin_user['id'], "Pending account login attempt: {$admin_user['username']}", $ip_address, $user_agent]);
                } else {
                    $permissions = json_decode($admin_user['permissions'] ?? '{}', true);
                    if (empty($permissions['dashboard_access'])) {
                        $error = 'You do not have permission to access the admin panel.';
                        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'login_denied', ?, ?, ?)")
                            ->execute([$admin_user['id'], "No dashboard_access: {$admin_user['username']}", $ip_address, $user_agent]);
                    } else {
                        session_regenerate_id(true); // prevent session fixation
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['lockout_until']  = 0;
                        $_SESSION['user_id']    = $admin_user['id'];
                        $_SESSION['username']   = $admin_user['username'];
                        $_SESSION['email']      = $admin_user['email'];
                        $_SESSION['role']       = $admin_user['role'];
                        $_SESSION['login_time'] = time();

                        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'login_success', ?, ?, ?)")
                            ->execute([$admin_user['id'], "Logged in as {$admin_user['role']}: {$admin_user['username']}", $ip_address, $user_agent]);

                        header("Location: " . ADMIN_DASHBOARD);
                        exit;
                    }
                }
                $authenticated = true; // an admin row matched (right or wrong password state handled above)
            }

            // ── 2) Not an admin match — try a customer account (matched by email) ──
            if (!$authenticated) {
                $cstmt = $pdo->prepare("SELECT * FROM ecom_customers WHERE email = ?");
                $cstmt->execute([$identity]);
                $customer = $cstmt->fetch(PDO::FETCH_ASSOC);

                $cust_pass_ok = password_verify($password, $customer['password'] ?? $dummy_hash);

                if ($customer && $cust_pass_ok) {
                    if ($customer['status'] !== 'active') {
                        $error = 'Your account has been suspended. Please contact support.';
                    } else {
                        // Capture the guest session id BEFORE regenerating it —
                        // that's where any items added to the cart before
                        // logging in are stored (see cart-helpers.php).
                        $guest_session_id = session_id();

                        session_regenerate_id(true);
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['lockout_until']  = 0;
                        $_SESSION['customer_id']   = $customer['id'];
                        $_SESSION['customer_name'] = $customer['name'];

                        // Move whatever was in the guest cart onto this
                        // customer's account cart (merging quantities with
                        // anything already there from another device), so
                        // logging in never loses items the person just added.
                        require_once __DIR__ . '/includes/cart-helpers.php';
                        cartMergeGuestIntoAccount($pdo, (int)$customer['id'], $guest_session_id);

                        header('Location: ' . ($redirect ?: '/shop/account.php'));
                        exit;
                    }
                    $authenticated = true;
                }
            }

            // ── Neither matched ───────────────────────────────────────────────────
            if (!$authenticated && $error === '') {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                    $_SESSION['lockout_until'] = time() + LOCKOUT_SECS;
                    $error = 'Too many failed attempts. Please try again in 15 minutes.';
                } else {
                    $error = 'Incorrect email/username or password.';
                }
            }
        }
    }
}

$page_title = 'Login';
include __DIR__ . '/includes/shop-header.php';
?>
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">

<div class="shop-container" style="max-width:420px;">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-1">Welcome Back</h4>
            <p class="text-muted mb-4">Login to your account to continue — customers and store staff both sign in here.</p>

            <?php if ($locked): ?>
            <div class="alert alert-danger">Too many failed attempts. Please try again in <?= ceil($lock_left / 60) ?> minute(s).</div>
            <?php elseif ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$locked): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                <div class="mb-3">
                    <label class="form-label">Email or Username</label>
                    <input type="text" name="identity" class="form-control" required autofocus autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-shop-primary w-100">Login</button>
            </form>
            <?php endif; ?>

            <p class="text-center mt-3 mb-0">New here? <a href="/shop/register.php">Create a customer account</a></p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
