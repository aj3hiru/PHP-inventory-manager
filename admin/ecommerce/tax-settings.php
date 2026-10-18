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

// ── Handle POST (create / update a GST slab) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    $action  = $_POST['action'] ?? '';
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $label   = trim($_POST['label'] ?? '');
    $rate    = (float)($_POST['rate'] ?? 0);

    if (empty($label)) {
        $notifications[] = ['type' => 'error', 'message' => 'Label is required.'];
    } else {
        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO ecom_gst_rates (label, rate) VALUES (?, ?)")->execute([$label, $rate]);
                header("Location: /admin/ecommerce/tax-settings.php?success=created");
                exit;
            } elseif ($action === 'update' && $edit_id > 0) {
                $pdo->prepare("UPDATE ecom_gst_rates SET label=?, rate=? WHERE id=?")->execute([$label, $rate, $edit_id]);
                header("Location: /admin/ecommerce/tax-settings.php?success=updated");
                exit;
            }
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    $pdo->prepare("DELETE FROM ecom_gst_rates WHERE id=?")->execute([$del_id]);
    header("Location: /admin/ecommerce/tax-settings.php?success=deleted");
    exit;
}

if (isset($_GET['set_default']) && is_numeric($_GET['set_default'])) {
    $pdo->exec("UPDATE ecom_gst_rates SET is_default = 0");
    $pdo->prepare("UPDATE ecom_gst_rates SET is_default = 1 WHERE id = ?")->execute([(int)$_GET['set_default']]);
    header("Location: /admin/ecommerce/tax-settings.php?success=default");
    exit;
}

if (isset($_GET['success'])) {
    $map = ['created' => 'GST slab added!', 'updated' => 'GST slab updated!', 'deleted' => 'GST slab deleted!', 'default' => 'Default GST rate updated!'];
    if (isset($map[$_GET['success']])) $notifications[] = ['type' => 'success', 'message' => $map[$_GET['success']]];
}

$rates = $pdo->query("SELECT * FROM ecom_gst_rates ORDER BY rate ASC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'GST / Tax Settings';
$page_subtitle = 'Manage GST slabs used across products, billing, and checkout';
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

            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> The <strong>default</strong> GST rate is auto-selected whenever you add a new product — you can always change it per product on the Add/Edit Product page.
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>GST Slabs</b></h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#gstModal" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i> Add Slab
                        </button>
                    </div>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body table-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead><tr><th>Label</th><th>Rate</th><th>Default</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($rates as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['label']) ?></td>
                                <td><?= number_format((float)$r['rate'], 2) ?>%</td>
                                <td>
                                    <?php if ($r['is_default']): ?>
                                        <span class="badge bg-success">Default</span>
                                    <?php else: ?>
                                        <a href="?set_default=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Set Default</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <button class="btn btn-primary btn-sm"
                                                onclick='openEditModal(<?= json_encode(["id" => $r['id'], "label" => $r['label'], "rate" => $r['rate']]) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['label'])) ?>')">
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

<div class="modal fade" id="gstModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="gstModalLabel">New GST Slab</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="gstAction" value="create">
                    <input type="hidden" name="edit_id" id="gstEditId" value="0">
                    <div class="mb-3">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="gstLabel" class="form-control" placeholder="e.g. GST 18%" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Rate (%) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" max="100" name="rate" id="gstRate" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="gstSubmitBtn">Add Slab</button>
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
            <div class="modal-body">Delete GST slab "<strong id="deleteGstName"></strong>"?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteGstId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
function resetGstForm() {
    document.getElementById('gstAction').value = 'create';
    document.getElementById('gstEditId').value = '0';
    document.getElementById('gstModalLabel').textContent = 'New GST Slab';
    document.getElementById('gstSubmitBtn').textContent = 'Add Slab';
    document.getElementById('gstLabel').value = '';
    document.getElementById('gstRate').value = '';
}
function openCreateModal() { resetGstForm(); }
function openEditModal(r) {
    resetGstForm();
    document.getElementById('gstAction').value = 'update';
    document.getElementById('gstEditId').value = r.id;
    document.getElementById('gstModalLabel').textContent = 'Edit GST Slab';
    document.getElementById('gstSubmitBtn').textContent = 'Update Slab';
    document.getElementById('gstLabel').value = r.label;
    document.getElementById('gstRate').value = r.rate;
    new bootstrap.Modal(document.getElementById('gstModal')).show();
}
function openDeleteModal(id, name) {
    document.getElementById('deleteGstId').value = id;
    document.getElementById('deleteGstName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}
</script>
</body>
</html>
