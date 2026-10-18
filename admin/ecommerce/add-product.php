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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_products'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$page_title = 'Add Product';
$page_subtitle = 'Choose the type of product you want to add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.type-card { display: block; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 0.5rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,.1); text-decoration: none; color: inherit; transition: transform 0.15s, box-shadow 0.15s; }
.type-card:hover { transform: translateY(-3px); box-shadow: 0 0.5rem 2rem 0 rgba(58,59,69,.18); color: inherit; }
.type-card-body { text-align: center; padding: 2.5rem 1.5rem; }
.type-icon { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; font-size: 1.75rem; color: #fff; }
.type-icon.physical { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
.type-icon.digital { background: linear-gradient(135deg, var(--info), #2563eb); }
.type-icon.license { background: linear-gradient(135deg, var(--success), #059669); }
.type-icon.affiliate { background: linear-gradient(135deg, var(--warning), #d97706); }
.type-card h2 { font-size: 1.125rem; font-weight: 700; margin: 0; }
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
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Add Product</b></h3>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-sm-6 col-md-6">
                    <a href="add-product-form.php?type=physical" class="type-card">
                        <div class="type-card-body">
                            <div class="type-icon physical"><i class="fas fa-box"></i></div>
                            <h2>Add Physical Product</h2>
                        </div>
                    </a>
                </div>
                <div class="col-sm-6 col-md-6">
                    <a href="add-product-form.php?type=digital" class="type-card">
                        <div class="type-card-body">
                            <div class="type-icon digital"><i class="fas fa-cloud-download-alt"></i></div>
                            <h2>Add Digital Product</h2>
                        </div>
                    </a>
                </div>
                <div class="col-sm-6 col-md-6">
                    <a href="add-product-form.php?type=license" class="type-card">
                        <div class="type-card-body">
                            <div class="type-icon license"><i class="far fa-copyright"></i></div>
                            <h2>Add License Product</h2>
                        </div>
                    </a>
                </div>
                <div class="col-sm-6 col-md-6">
                    <a href="add-product-form.php?type=affiliate" class="type-card">
                        <div class="type-card-body">
                            <div class="type-icon affiliate"><i class="fas fa-link"></i></div>
                            <h2>Add Affiliate Product</h2>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
</body>
</html>
