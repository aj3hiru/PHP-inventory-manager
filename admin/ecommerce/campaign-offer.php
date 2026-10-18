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

$notifications = [];

// ── Add product to campaign ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_campaign') {
    $pid = (int)($_POST['product_id'] ?? 0);
    $campaign_price = (float)($_POST['campaign_price'] ?? 0);
    if ($pid > 0 && $campaign_price > 0) {
        $pdo->prepare("UPDATE ecom_products SET is_campaign=1, campaign_price=? WHERE id=?")->execute([$campaign_price, $pid]);
        header("Location: /admin/ecommerce/campaign-offer.php?success=added");
        exit;
    }
    $notifications[] = ['type' => 'error', 'message' => 'Please select a product and enter a valid campaign price.'];
}

// ── Remove from campaign ─────────────────────────────────────────────────────
if (isset($_GET['remove']) && is_numeric($_GET['remove'])) {
    $pdo->prepare("UPDATE ecom_products SET is_campaign=0, campaign_price=NULL, show_on_home=0 WHERE id=?")->execute([(int)$_GET['remove']]);
    header("Location: /admin/ecommerce/campaign-offer.php?success=removed");
    exit;
}

// ── Toggle Show on Home Page ─────────────────────────────────────────────────
if (isset($_GET['toggle_home']) && is_numeric($_GET['toggle_home'])) {
    $pdo->prepare("UPDATE ecom_products SET show_on_home = 1 - show_on_home WHERE id = ?")->execute([(int)$_GET['toggle_home']]);
    header("Location: /admin/ecommerce/campaign-offer.php");
    exit;
}

if (isset($_GET['success'])) {
    $map = ['added' => 'Product added to campaign!', 'removed' => 'Product removed from campaign.'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
}

$campaign_products = $pdo->query("SELECT * FROM ecom_products WHERE is_campaign = 1 ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$available_products = $pdo->query("SELECT id, name, price FROM ecom_products WHERE is_campaign = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Campaign Offer';
$page_subtitle = 'Products currently discounted as part of a campaign';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
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

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Campaign Offer</b></h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCampaignModal">
                            <i class="fas fa-plus"></i> Add Product to Campaign
                        </button>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <h5 class="mb-3">Product Added for Campaign</h5>
                    <?php if (empty($campaign_products)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-percent fa-3x mb-3"></i>
                        <p>No products in the current campaign yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th width="40%">Name</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Show Home Page</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($campaign_products as $p): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($p['image'])): ?>
                                        <img src="/<?= htmlspecialchars($p['image']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="dt-thumb-placeholder"><i class="fas fa-image"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td>
                                    <span class="text-decoration-line-through text-muted">₹<?= number_format((float)$p['price'], 2) ?></span>
                                    <strong class="text-danger">₹<?= number_format((float)$p['campaign_price'], 2) ?></strong>
                                </td>
                                <td><span class="badge <?= $p['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst($p['status']) ?></span></td>
                                <td>
                                    <a href="?toggle_home=<?= $p['id'] ?>" class="popular-toggle-link <?= $p['show_on_home'] ? 'popular-yes' : 'popular-no' ?>">
                                        <i class="fas fa-home"></i> <?= $p['show_on_home'] ? 'Yes' : 'No' ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <a class="btn btn-primary btn-sm" href="add-product-form.php?edit=<?= $p['id'] ?>"><i class="fas fa-edit"></i></a>
                                        <a class="btn btn-danger btn-sm" href="?remove=<?= $p['id'] ?>" onclick="return confirm('Remove this product from the campaign?');"><i class="fas fa-times"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Add to Campaign Modal -->
<div class="modal fade" id="addCampaignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Product to Campaign</h5>
                    <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_to_campaign">
                    <div class="mb-3">
                        <label class="form-label">Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Select product…</option>
                            <?php foreach ($available_products as $ap): ?>
                            <option value="<?= $ap['id'] ?>"><?= htmlspecialchars($ap['name']) ?> (₹<?= number_format((float)$ap['price'], 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Campaign Price (₹) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" name="campaign_price" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add to Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
$(document).ready(function () {
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [0, 4, 5] }] });
});
</script>
</body>
</html>
