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

// ── Create / update a tag ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create_tag', 'update_tag'], true)) {
    $tag_group = $_POST['tag_group'] === 'item_type' ? 'item_type' : 'badge';
    $label     = trim($_POST['label'] ?? '');
    $color     = trim($_POST['color'] ?? '') ?: null;
    $edit_id   = (int)($_POST['edit_id'] ?? 0);

    if ($label === '') {
        $notifications[] = ['type' => 'error', 'message' => 'Label is required.'];
    } else {
        $slug = generateSlug($label);
        try {
            if ($_POST['action'] === 'create_tag') {
                $pdo->prepare("INSERT INTO ecom_product_tags (tag_group, label, slug, color, status) VALUES (?,?,?,?,'active')")
                    ->execute([$tag_group, $label, $slug, $color]);
            } else {
                $pdo->prepare("UPDATE ecom_product_tags SET label=?, slug=?, color=? WHERE id=?")
                    ->execute([$label, $slug, $color, $edit_id]);
            }
            header("Location: /admin/ecommerce/product-tags.php?success=1");
            exit;
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'A ' . ($tag_group === 'badge' ? 'badge tag' : 'item type') . ' with that name already exists.'];
        }
    }
}

// ── Delete a tag (falls products back to the group's first/default option) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tag') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    $row = $pdo->prepare("SELECT tag_group, slug FROM ecom_product_tags WHERE id = ?");
    $row->execute([$del_id]);
    if ($tag = $row->fetch(PDO::FETCH_ASSOC)) {
        $fallback = $tag['tag_group'] === 'badge' ? 'none' : 'normal';
        $column = $tag['tag_group'] === 'badge' ? 'badge_tag' : 'item_type';
        $pdo->prepare("UPDATE ecom_products SET $column = ? WHERE $column = ?")->execute([$fallback, $tag['slug']]);
        $pdo->prepare("DELETE FROM ecom_product_tags WHERE id = ?")->execute([$del_id]);
    }
    header("Location: /admin/ecommerce/product-tags.php?success=1");
    exit;
}

if (isset($_GET['set_status']) && isset($_GET['id'])) {
    $new_status = $_GET['set_status'] === 'inactive' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE ecom_product_tags SET status=? WHERE id=?")->execute([$new_status, (int)$_GET['id']]);
    header("Location: /admin/ecommerce/product-tags.php");
    exit;
}

// ── Apply / remove a tag on a specific product (from the search tool) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_to_product') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $badge_tag  = trim($_POST['badge_tag'] ?? 'none');
    $item_type  = trim($_POST['item_type'] ?? 'normal');
    if ($product_id > 0) {
        $pdo->prepare("UPDATE ecom_products SET badge_tag = ?, item_type = ? WHERE id = ?")
            ->execute([$badge_tag, $item_type, $product_id]);
        $notifications[] = ['type' => 'success', 'message' => 'Product updated!'];
    }
}

if (isset($_GET['success'])) $notifications[] = ['type' => 'success', 'message' => 'Saved successfully!'];

$badge_tags = $pdo->query("SELECT * FROM ecom_product_tags WHERE tag_group='badge' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$item_types = $pdo->query("SELECT * FROM ecom_product_tags WHERE tag_group='item_type' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$all_products_for_search = $pdo->query("SELECT id, name, sku, badge_tag, item_type FROM ecom_products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Badge Tags & Item Types';
$page_subtitle = 'Manage the labels products can carry, and apply them directly to any product';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.tag-pill-preview { display: inline-block; padding: 0.2rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; color: #fff; }
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

            <div class="row">
                <!-- BADGE TAGS -->
                <div class="col-lg-6">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <div class="gd-heading-row">
                                <h5 class="mb-0"><i class="fas fa-tag text-primary"></i> Badge Tags</h5>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tagModal" onclick="openCreateTag('badge')">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                            <table class="table table-bordered mt-3 mb-0">
                                <thead><tr><th>Preview</th><th>Label</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($badge_tags as $t): ?>
                                <tr>
                                    <td><?php if ($t['slug'] !== 'none'): ?><span class="tag-pill-preview" style="background:<?= htmlspecialchars($t['color'] ?: '#6b7280') ?>;"><?= htmlspecialchars($t['label']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                    <td><?= htmlspecialchars($t['label']) ?></td>
                                    <td>
                                        <?php if ($t['slug'] === 'none'): ?>
                                            <span class="text-muted small">Default</span>
                                        <?php else: ?>
                                        <a href="?set_status=<?= $t['status'] === 'active' ? 'inactive' : 'active' ?>&id=<?= $t['id'] ?>" class="badge <?= $t['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>" style="text-decoration:none;">
                                            <?= $t['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['slug'] !== 'none'): ?>
                                        <div class="action-list">
                                            <button class="btn btn-primary btn-sm" onclick='openEditTag(<?= json_encode($t) ?>)'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm" onclick="openDeleteTag(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['label'])) ?>')"><i class="fas fa-trash-alt"></i></button>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ITEM TYPES -->
                <div class="col-lg-6">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <div class="gd-heading-row">
                                <h5 class="mb-0"><i class="fas fa-shapes text-primary"></i> Item Types</h5>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tagModal" onclick="openCreateTag('item_type')">
                                    <i class="fas fa-plus"></i> Add
                                </button>
                            </div>
                            <table class="table table-bordered mt-3 mb-0">
                                <thead><tr><th>Label</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($item_types as $t): ?>
                                <tr>
                                    <td><?= htmlspecialchars($t['label']) ?></td>
                                    <td>
                                        <?php if ($t['slug'] === 'normal'): ?>
                                            <span class="text-muted small">Default</span>
                                        <?php else: ?>
                                        <a href="?set_status=<?= $t['status'] === 'active' ? 'inactive' : 'active' ?>&id=<?= $t['id'] ?>" class="badge <?= $t['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>" style="text-decoration:none;">
                                            <?= $t['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['slug'] !== 'normal'): ?>
                                        <div class="action-list">
                                            <button class="btn btn-primary btn-sm" onclick='openEditTag(<?= json_encode($t) ?>)'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm" onclick="openDeleteTag(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['label'])) ?>')"><i class="fas fa-trash-alt"></i></button>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEARCH & APPLY TO A PRODUCT -->
            <div class="gd-card">
                <div class="gd-card-body">
                    <h5 class="mb-3"><i class="fas fa-search text-primary"></i> Apply Tags to a Product</h5>
                    <div class="mb-3 position-relative" style="max-width:420px;">
                        <input type="text" id="productSearchInput" class="form-control" placeholder="Search product by name or SKU…" autocomplete="off">
                        <div id="productSearchResults" class="list-group position-absolute w-100" style="z-index:50; max-height:240px; overflow-y:auto; display:none;"></div>
                    </div>

                    <form method="POST" id="applyTagForm" style="display:none;">
                        <input type="hidden" name="action" value="apply_to_product">
                        <input type="hidden" name="product_id" id="applyProductId">
                        <div class="alert alert-light border d-flex align-items-center justify-content-between">
                            <strong id="applyProductName"></strong>
                        </div>
                        <div class="row">
                            <div class="col-md-5 mb-3">
                                <label class="form-label">Badge Tag</label>
                                <select name="badge_tag" id="applyBadgeTag" class="form-select">
                                    <?php foreach ($badge_tags as $t): if ($t['status'] !== 'active' && $t['slug'] !== 'none') continue; ?>
                                    <option value="<?= htmlspecialchars($t['slug']) ?>"><?= htmlspecialchars($t['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5 mb-3">
                                <label class="form-label">Item Type</label>
                                <select name="item_type" id="applyItemType" class="form-select">
                                    <?php foreach ($item_types as $t): if ($t['status'] !== 'active' && $t['slug'] !== 'normal') continue; ?>
                                    <option value="<?= htmlspecialchars($t['slug']) ?>"><?= htmlspecialchars($t['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Add/Edit Tag Modal -->
<div class="modal fade" id="tagModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="tagModalLabel">New Tag</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="tagAction" value="create_tag">
                    <input type="hidden" name="tag_group" id="tagGroup" value="badge">
                    <input type="hidden" name="edit_id" id="tagEditId" value="0">
                    <div class="mb-3">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="tagLabel" class="form-control" placeholder="e.g. Clearance" required>
                    </div>
                    <div class="mb-1" id="tagColorRow">
                        <label class="form-label">Badge Color</label>
                        <input type="color" name="color" id="tagColor" class="form-control form-control-color" value="#7c3aed">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="tagSubmitBtn">Add</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="confirm-delete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete?</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">Delete "<strong id="deleteTagName"></strong>"? Any products using it will fall back to the default.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete_tag">
                    <input type="hidden" name="delete_id" id="deleteTagId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
const ALL_PRODUCTS_TAGS = <?= json_encode($all_products_for_search) ?>;

function openCreateTag(group) {
    document.getElementById('tagAction').value = 'create_tag';
    document.getElementById('tagGroup').value = group;
    document.getElementById('tagEditId').value = '0';
    document.getElementById('tagLabel').value = '';
    document.getElementById('tagColor').value = '#7c3aed';
    document.getElementById('tagModalLabel').textContent = 'New ' + (group === 'badge' ? 'Badge Tag' : 'Item Type');
    document.getElementById('tagSubmitBtn').textContent = 'Add';
    document.getElementById('tagColorRow').style.display = group === 'badge' ? 'block' : 'none';
}
function openEditTag(t) {
    document.getElementById('tagAction').value = 'update_tag';
    document.getElementById('tagGroup').value = t.tag_group;
    document.getElementById('tagEditId').value = t.id;
    document.getElementById('tagLabel').value = t.label;
    document.getElementById('tagColor').value = t.color || '#7c3aed';
    document.getElementById('tagModalLabel').textContent = 'Edit ' + (t.tag_group === 'badge' ? 'Badge Tag' : 'Item Type');
    document.getElementById('tagSubmitBtn').textContent = 'Update';
    document.getElementById('tagColorRow').style.display = t.tag_group === 'badge' ? 'block' : 'none';
    new bootstrap.Modal(document.getElementById('tagModal')).show();
}
function openDeleteTag(id, name) {
    document.getElementById('deleteTagId').value = id;
    document.getElementById('deleteTagName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}

// Product search + apply tool
const searchInput = document.getElementById('productSearchInput');
const searchResults = document.getElementById('productSearchResults');
searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    if (q.length < 2) { searchResults.style.display = 'none'; return; }
    const matches = ALL_PRODUCTS_TAGS.filter(p => p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q)).slice(0, 8);
    if (!matches.length) { searchResults.style.display = 'none'; return; }
    searchResults.innerHTML = matches.map(p => `<a href="#" class="list-group-item list-group-item-action" data-id="${p.id}" data-name="${p.name.replace(/"/g,'&quot;')}" data-badge="${p.badge_tag}" data-item="${p.item_type}">${p.name}${p.sku ? ' <small class=\"text-muted\">(' + p.sku + ')</small>' : ''}</a>`).join('');
    searchResults.style.display = 'block';
});
searchResults.addEventListener('click', function (e) {
    e.preventDefault();
    const item = e.target.closest('.list-group-item');
    if (!item) return;
    document.getElementById('applyProductId').value = item.dataset.id;
    document.getElementById('applyProductName').textContent = item.dataset.name;
    document.getElementById('applyBadgeTag').value = item.dataset.badge;
    document.getElementById('applyItemType').value = item.dataset.item;
    document.getElementById('applyTagForm').style.display = 'block';
    searchInput.value = item.dataset.name;
    searchResults.style.display = 'none';
});
document.addEventListener('click', function (e) {
    if (!e.target.closest('#productSearchInput') && !e.target.closest('#productSearchResults')) searchResults.style.display = 'none';
});
</script>
</body>
</html>
