<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || $user['status'] !== 'active') { exit('Access Denied'); }

$permissions = json_decode($user['permissions'] ?? '{}', true);
$username = $_SESSION['username'];

$notifications = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = trim($_POST['username'] ?? '');
    $new_email    = trim($_POST['email'] ?? '');
    $new_password = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';

    if (empty($new_username) || empty($new_email)) {
        $notifications[] = ['type' => 'error', 'message' => 'Username and email are required.'];
    } elseif ($new_password !== '' && $new_password !== $confirm) {
        $notifications[] = ['type' => 'error', 'message' => 'New password and confirmation do not match.'];
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $notifications[] = ['type' => 'error', 'message' => 'New password must be at least 6 characters.'];
    } else {
        try {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET username=?, email=?, password_hash=? WHERE id=?")
                    ->execute([$new_username, $new_email, $hash, $user['id']]);
            } else {
                $pdo->prepare("UPDATE users SET username=?, email=? WHERE id=?")
                    ->execute([$new_username, $new_email, $user['id']]);
            }

            $_SESSION['username'] = $new_username;
            $username = $new_username;

            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'profile_update', 'Updated own profile', ?, ?)")
                ->execute([$user['id'], $log_ip, $log_ua]);

            $notifications[] = ['type' => 'success', 'message' => 'Profile updated successfully!'];
            $user['username'] = $new_username;
            $user['email'] = $new_email;
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: that username or email may already be in use.'];
        }
    }
}

$page_title = 'My Profile';
$page_subtitle = 'Update your account details and password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include DROOT_PATH . '/admin/ecommerce/components/ecom-head.php'; ?>
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
                <a href="/admin/dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <div class="text-center mb-4">
                                <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.75rem;margin:0 auto 0.75rem;">
                                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                </div>
                                <div class="fw-bold" style="font-size:1.1rem;"><?= htmlspecialchars($user['username']) ?></div>
                                <div class="text-muted small"><?= ucfirst(htmlspecialchars($user['role'] ?? '')) ?></div>
                            </div>

                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                                </div>
                                <hr>
                                <p class="text-muted small mb-2">Leave the password fields blank to keep your current password.</p>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <input type="password" name="password" class="form-control" autocomplete="new-password" minlength="6">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" minlength="6">
                                </div>
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Save Changes</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include DROOT_PATH . '/admin/ecommerce/components/ecom-scripts.php'; ?>
</body>
</html>
