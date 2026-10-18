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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_coupons'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$notifications = [];

// ── Handle POST (create / update) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {

    $action          = $_POST['action']  ?? '';
    $edit_id         = (int)($_POST['edit_id'] ?? 0);
    $title           = trim($_POST['title'] ?? '');
    $code            = strtoupper(trim($_POST['code'] ?? ''));
    $number_of_times = max(1, (int)($_POST['number_of_times'] ?? 1));
    $discount_type   = ($_POST['discount_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
    $discount_value  = (float)($_POST['discount_value'] ?? 0);
    $applies_to      = $_POST['applies_to'] ?? 'all';
    $applies_to      = in_array($applies_to, ['all', 'product', 'category', 'subcategory'], true) ? $applies_to : 'all';

    $product_id     = $applies_to === 'product'     ? (int)($_POST['product_id'] ?? 0) ?: null     : null;
    $category_id    = $applies_to === 'category'    ? (int)($_POST['category_id'] ?? 0) ?: null    : null;
    $subcategory_id = $applies_to === 'subcategory' ? (int)($_POST['subcategory_id'] ?? 0) ?: null : null;

    if (empty($title) || empty($code)) {
        $notifications[] = ['type' => 'error', 'message' => 'Title and Code are required.'];
    } elseif ($discount_value <= 0) {
        $notifications[] = ['type' => 'error', 'message' => 'Discount must be greater than 0.'];
    } elseif ($applies_to === 'product' && !$product_id) {
        $notifications[] = ['type' => 'error', 'message' => 'Please select a product for this coupon.'];
    } elseif ($applies_to === 'category' && !$category_id) {
        $notifications[] = ['type' => 'error', 'message' => 'Please select a category for this coupon.'];
    } elseif ($applies_to === 'subcategory' && !$subcategory_id) {
        $notifications[] = ['type' => 'error', 'message' => 'Please select a sub category for this coupon.'];
    } else {
        $dup = $pdo->prepare("SELECT id FROM ecom_coupons WHERE code = ? AND id != ?");
        $dup->execute([$code, $edit_id]);
        if ($dup->fetch()) {
            $notifications[] = ['type' => 'error', 'message' => 'This coupon code is already in use. Please choose another.'];
        } else {
            try {
                if ($action === 'create') {
                    $pdo->prepare("INSERT INTO ecom_coupons
                        (title, code, number_of_times, discount_type, discount_value, applies_to, product_id, category_id, subcategory_id, status)
                        VALUES (?,?,?,?,?,?,?,?,?,'active')")
                        ->execute([$title, $code, $number_of_times, $discount_type, $discount_value, $applies_to, $product_id, $category_id, $subcategory_id]);

                    $new_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_coupon_create', ?, ?, ?)")
                        ->execute([$_SESSION['user_id'], "Created Coupon: $title ($code) (ID: $new_id)", $log_ip, $log_ua]);

                    header("Location: /admin/ecommerce/coupons.php?success=created");
                    exit;

                } elseif ($action === 'update' && $edit_id > 0) {
                    $pdo->prepare("UPDATE ecom_coupons SET
                        title=?, code=?, number_of_times=?, discount_type=?, discount_value=?, applies_to=?, product_id=?, category_id=?, subcategory_id=?
                        WHERE id=?")
                        ->execute([$title, $code, $number_of_times, $discount_type, $discount_value, $applies_to, $product_id, $category_id, $subcategory_id, $edit_id]);

                    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_coupon_update', ?, ?, ?)")
                        ->execute([$_SESSION['user_id'], "Updated Coupon: $title ($code) (ID: $edit_id)", $log_ip, $log_ua]);

                    header("Location: /admin/ecommerce/coupons.php?success=updated");
                    exit;
                }
            } catch (Exception $e) {
                $notifications[] = ['type' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
            }
        }
    }
}

// ── Handle DELETE ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    $row = $pdo->prepare("SELECT title FROM ecom_coupons WHERE id=?");
    $row->execute([$del_id]);
    $coupon = $row->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare("DELETE FROM ecom_coupons WHERE id=?")->execute([$del_id]);

    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_coupon_delete', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], "Deleted Coupon: " . ($coupon['title'] ?? 'Unknown') . " (ID: $del_id)", $log_ip, $log_ua]);

    header("Location: /admin/ecommerce/coupons.php?success=deleted");
    exit;
}

// ── Quick Enable/Disable ─────────────────────────────────────────────────────
if (isset($_GET['set_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $new_status = $_GET['set_status'] === 'inactive' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE ecom_coupons SET status=? WHERE id=?")->execute([$new_status, (int)$_GET['id']]);
    header("Location: /admin/ecommerce/coupons.php");
    exit;
}

if (isset($_GET['success'])) {
    $map = ['created' => 'Coupon created successfully!', 'updated' => 'Coupon updated successfully!', 'deleted' => 'Coupon deleted successfully!'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
}

// ── Data for the form (products / categories / subcategories) ──────────────
$all_products = $pdo->query("SELECT id, name FROM ecom_products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_categories = $pdo->query("SELECT id, name FROM ecom_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_subcategories = $pdo->query("
    SELECT s.id, s.name, c.name AS category_name
    FROM ecom_subcategories s LEFT JOIN ecom_categories c ON s.category_id = c.id
    ORDER BY c.name ASC, s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── List ─────────────────────────────────────────────────────────────────────
$coupons = $pdo->query("
    SELECT co.*, p.name AS product_name, cat.name AS category_name, sub.name AS subcategory_name
    FROM ecom_coupons co
    LEFT JOIN ecom_products p ON co.product_id = p.id
    LEFT JOIN ecom_categories cat ON co.category_id = cat.id
    LEFT JOIN ecom_subcategories sub ON co.subcategory_id = sub.id
    ORDER BY co.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Coupons';
$page_subtitle = 'Discount codes for all products, or scoped to a product/category';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.applies-scope { background: var(--gray-50); border: 1px solid var(--gray-100); border-radius: var(--radius); padding: 0.9rem 1rem; margin-top: 0.75rem; }
.product-search-wrap { position: relative; }
.product-search-results { position: absolute; z-index: 1065; top: calc(100% + 2px); left: 0; right: 0; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius); box-shadow: 0 8px 20px rgba(0,0,0,.08); display: none; }
.product-search-results .item { padding: 0.55rem 0.85rem; cursor: pointer; font-size: 0.9rem; }
.product-search-results .item:hover { background: var(--gray-50); }
.product-search-results .item small { color: var(--gray-400); }
.applies-pill { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; font-weight: 600; color: var(--gray-600); background: var(--gray-100); padding: 0.2rem 0.6rem; border-radius: 9999px; margin-top: 0.25rem; }
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

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Coupons</b></h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#couponModal" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body table-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Code</th>
                                    <th>No. Of Times</th>
                                    <th>Discount</th>
                                    <th>Applies To</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($coupons as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['title']) ?></td>
                                <td><code><?= htmlspecialchars($c['code']) ?></code></td>
                                <td><?= (int)$c['number_of_times'] ?></td>
                                <td>
                                    <?= $c['discount_type'] === 'percentage'
                                        ? number_format((float)$c['discount_value'], 0) . ' %'
                                        : '₹' . number_format((float)$c['discount_value'], 2) ?>
                                </td>
                                <td>
                                    <?php if ($c['applies_to'] === 'all'): ?>
                                        <span class="applies-pill"><i class="fas fa-globe"></i> All Products</span>
                                    <?php elseif ($c['applies_to'] === 'product'): ?>
                                        <span class="applies-pill"><i class="fas fa-box"></i> <?= htmlspecialchars($c['product_name'] ?? 'Deleted product') ?></span>
                                    <?php elseif ($c['applies_to'] === 'category'): ?>
                                        <span class="applies-pill"><i class="fas fa-list"></i> <?= htmlspecialchars($c['category_name'] ?? 'Deleted category') ?></span>
                                    <?php elseif ($c['applies_to'] === 'subcategory'): ?>
                                        <span class="applies-pill"><i class="fas fa-list-ul"></i> <?= htmlspecialchars($c['subcategory_name'] ?? 'Deleted sub category') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $c['status'] === 'active' ? 'btn-success' : 'btn-secondary-status' ?>" type="button" data-bs-toggle="dropdown">
                                            <?= $c['status'] === 'active' ? 'Enabled' : 'Disabled' ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?set_status=active&id=<?= $c['id'] ?>">Enable</a></li>
                                            <li><a class="dropdown-item" href="?set_status=inactive&id=<?= $c['id'] ?>">Disable</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <button class="btn btn-primary btn-sm"
                                                onclick='openEditModal(<?= json_encode([
                                                    "id" => $c['id'],
                                                    "title" => $c['title'],
                                                    "code" => $c['code'],
                                                    "number_of_times" => $c['number_of_times'],
                                                    "discount_type" => $c['discount_type'],
                                                    "discount_value" => $c['discount_value'],
                                                    "applies_to" => $c['applies_to'],
                                                    "product_id" => $c['product_id'],
                                                    "product_name" => $c['product_name'],
                                                    "category_id" => $c['category_id'],
                                                    "subcategory_id" => $c['subcategory_id'],
                                                ]) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['title'])) ?>')">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="couponModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="couponForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="couponModalLabel">New Coupon</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="couponAction" value="create">
                    <input type="hidden" name="edit_id" id="couponEditId" value="0">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="couponTitle" class="form-control" placeholder="e.g. Flash Discount" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Code <span class="text-danger">*</span></label>
                            <input type="text" name="code" id="couponCode" class="form-control text-uppercase" placeholder="e.g. FLASH20" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Number Of Times <span class="text-danger">*</span></label>
                            <input type="number" name="number_of_times" id="couponTimes" class="form-control" min="1" value="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Discount Type <span class="text-danger">*</span></label>
                            <select name="discount_type" id="couponDiscountType" class="form-select">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (₹)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Discount Value <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="discount_value" id="couponDiscountValue" class="form-control" placeholder="e.g. 10" required>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label">Applies To <span class="text-danger">*</span></label>
                        <select name="applies_to" id="couponAppliesTo" class="form-select">
                            <option value="all">All Products</option>
                            <option value="product">Specific Product</option>
                            <option value="category">A Category</option>
                            <option value="subcategory">A Sub Category</option>
                        </select>

                        <!-- All Products: no extra field -->

                        <!-- Specific Product -->
                        <div class="applies-scope applies-field applies-field-product" style="display:none;">
                            <label class="form-label">Search Product <small class="text-muted">(by name or ID)</small></label>
                            <div class="product-search-wrap">
                                <input type="text" id="couponProductSearch" class="form-control" placeholder="Type a product name or ID…" autocomplete="off">
                                <input type="hidden" name="product_id" id="couponProductId">
                                <div class="product-search-results" id="couponProductResults"></div>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="applies-scope applies-field applies-field-category" style="display:none;">
                            <label class="form-label">Category</label>
                            <select name="category_id" id="couponCategorySelect" class="form-select">
                                <option value="">Select category…</option>
                                <?php foreach ($all_categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sub Category -->
                        <div class="applies-scope applies-field applies-field-subcategory" style="display:none;">
                            <label class="form-label">Sub Category</label>
                            <select name="subcategory_id" id="couponSubcategorySelect" class="form-select">
                                <option value="">Select sub category…</option>
                                <?php foreach ($all_subcategories as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['category_name'] . ' › ' . $sub['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="couponSubmitBtn">Create Coupon</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirm Delete Modal -->
<div class="modal fade" id="confirm-delete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete?</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Delete coupon "<strong id="deleteCouponName"></strong>"? This cannot be undone.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteCouponId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>

<script>
const ALL_PRODUCTS = <?= json_encode(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $all_products)) ?>;

$(document).ready(function () {
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [4, 5, 6] }] });
});

function resetCouponForm() {
    document.getElementById('couponForm').reset();
    document.getElementById('couponAction').value = 'create';
    document.getElementById('couponEditId').value = '0';
    document.getElementById('couponModalLabel').textContent = 'New Coupon';
    document.getElementById('couponSubmitBtn').textContent = 'Create Coupon';
    document.getElementById('couponProductSearch').value = '';
    document.getElementById('couponProductId').value = '';
    document.getElementById('couponProductResults').style.display = 'none';
    applyScopeFields('all');
}

function openCreateModal() {
    resetCouponForm();
}

function applyScopeFields(scope) {
    document.querySelectorAll('.applies-field').forEach(el => el.style.display = 'none');
    if (scope !== 'all') {
        const el = document.querySelector('.applies-field-' + scope);
        if (el) el.style.display = 'block';
    }
}

document.getElementById('couponAppliesTo').addEventListener('change', function () {
    applyScopeFields(this.value);
});

function openEditModal(c) {
    resetCouponForm();
    document.getElementById('couponAction').value = 'update';
    document.getElementById('couponEditId').value = c.id;
    document.getElementById('couponModalLabel').textContent = 'Edit Coupon';
    document.getElementById('couponSubmitBtn').textContent = 'Update Coupon';
    document.getElementById('couponTitle').value = c.title;
    document.getElementById('couponCode').value = c.code;
    document.getElementById('couponTimes').value = c.number_of_times;
    document.getElementById('couponDiscountType').value = c.discount_type;
    document.getElementById('couponDiscountValue').value = c.discount_value;
    document.getElementById('couponAppliesTo').value = c.applies_to;
    applyScopeFields(c.applies_to);

    if (c.applies_to === 'product' && c.product_id) {
        document.getElementById('couponProductId').value = c.product_id;
        document.getElementById('couponProductSearch').value = (c.product_name || '') + ' (ID: ' + c.product_id + ')';
    }
    if (c.applies_to === 'category' && c.category_id) {
        document.getElementById('couponCategorySelect').value = c.category_id;
    }
    if (c.applies_to === 'subcategory' && c.subcategory_id) {
        document.getElementById('couponSubcategorySelect').value = c.subcategory_id;
    }

    new bootstrap.Modal(document.getElementById('couponModal')).show();
}

// ── Product search-by-name-or-ID autocomplete ───────────────────────────────
(function () {
    const input = document.getElementById('couponProductSearch');
    const hidden = document.getElementById('couponProductId');
    const results = document.getElementById('couponProductResults');

    input.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        hidden.value = '';
        if (q === '') { results.style.display = 'none'; return; }

        const matches = ALL_PRODUCTS.filter(p =>
            p.name.toLowerCase().includes(q) || String(p.id).includes(q)
        ).slice(0, 8);

        if (matches.length === 0) {
            results.innerHTML = '<div class="item text-muted">No products found</div>';
        } else {
            results.innerHTML = matches.map(p =>
                `<div class="item" data-id="${p.id}" data-name="${p.name.replace(/"/g, '&quot;')}">${p.name} <small>(ID: ${p.id})</small></div>`
            ).join('');
        }
        results.style.display = 'block';
    });

    results.addEventListener('click', function (e) {
        const item = e.target.closest('.item[data-id]');
        if (!item) return;
        hidden.value = item.dataset.id;
        input.value = item.dataset.name + ' (ID: ' + item.dataset.id + ')';
        results.style.display = 'none';
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.product-search-wrap')) results.style.display = 'none';
    });
})();

// Auto-uppercase code as typed
document.getElementById('couponCode').addEventListener('input', function () {
    this.value = this.value.toUpperCase();
});

function openDeleteModal(id, name) {
    document.getElementById('deleteCouponId').value = id;
    document.getElementById('deleteCouponName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}
</script>
</body>
</html>
