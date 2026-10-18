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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_categories'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

define('ECOM_UPLOAD_URL', 'uploads/ecommerce/categories/');
define('ECOM_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/categories/');

function uniqueCatSlug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = $base; $i = 1;
    while (true) {
        $q = $pdo->prepare("SELECT id FROM ecom_categories WHERE slug = ? AND id != ?");
        $q->execute([$slug, $excludeId]);
        if (!$q->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

$notifications = [];
$editing = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM ecom_categories WHERE id=?");
    $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch(PDO::FETCH_ASSOC);
}

// ── Handle POST (create / update) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action        = $_POST['action'] ?? '';
    $edit_id       = (int)($_POST['edit_id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $slug_raw      = trim($_POST['slug'] ?? '');
    $meta_keywords = trim($_POST['meta_keywords'] ?? '');
    $meta_desc     = trim($_POST['meta_description'] ?? '');
    $serial        = (int)($_POST['serial'] ?? 0);
    $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if (empty($name)) {
        $notifications[] = ['type' => 'error', 'message' => 'Category name is required.'];
    } else {
        $base_slug = $slug_raw !== '' ? generateSlug($slug_raw) : generateSlug($name);
        $slug      = uniqueCatSlug($pdo, $base_slug, $edit_id);

        $image_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            if (!file_exists(ECOM_UPLOAD_ABS)) mkdir(ECOM_UPLOAD_ABS, 0777, true);
            $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $filename = $slug . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], ECOM_UPLOAD_ABS . $filename)) {
                $image_path = ECOM_UPLOAD_URL . $filename;
            }
        }

        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO ecom_categories (name, slug, image, meta_keywords, meta_description, serial, status) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$name, $slug, $image_path, $meta_keywords ?: null, $meta_desc ?: null, $serial, $status]);

                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_category_create', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Created Category: $name (ID: $new_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/categories.php?success=created");
                exit;

            } elseif ($action === 'update' && $edit_id > 0) {
                if ($image_path) {
                    $old = $pdo->prepare("SELECT image FROM ecom_categories WHERE id=?");
                    $old->execute([$edit_id]);
                    if ($old_row = $old->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($old_row['image']) && file_exists(DROOT_PATH . '/' . $old_row['image'])) {
                            @unlink(DROOT_PATH . '/' . $old_row['image']);
                        }
                    }
                    $pdo->prepare("UPDATE ecom_categories SET name=?, slug=?, image=?, meta_keywords=?, meta_description=?, serial=?, status=? WHERE id=?")
                        ->execute([$name, $slug, $image_path, $meta_keywords ?: null, $meta_desc ?: null, $serial, $status, $edit_id]);
                } else {
                    $pdo->prepare("UPDATE ecom_categories SET name=?, slug=?, meta_keywords=?, meta_description=?, serial=?, status=? WHERE id=?")
                        ->execute([$name, $slug, $meta_keywords ?: null, $meta_desc ?: null, $serial, $status, $edit_id]);
                }

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_category_update', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Updated Category: $name (ID: $edit_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/categories.php?success=updated");
                exit;
            }
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}

$page_title = $editing ? 'Update Category' : 'Add Category';
$page_subtitle = $editing ? 'Edit category details and SEO' : 'Create a new top-level category';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.admin-img-preview { width: 60px; height: 60px; object-fit: cover; border-radius: 0.25rem; border: 1px solid var(--gray-200); }
.file-upload-label { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 1rem; background: var(--gray-100); color: var(--gray-700); border-radius: var(--radius); font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.file-upload-label:hover { background: var(--gray-200); }
.file-upload-label input[type="file"] { display: none; }
/* Thin, subtle focus state (Bootstrap's default glow is too heavy) */
.form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: none; }
.btn-check:focus + .btn, .btn:focus { box-shadow: none; }
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
                <a href="categories.php" class="btn btn-primary btn-sm"><i class="fas fa-chevron-left"></i> Back</a>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                                <input type="hidden" name="edit_id" value="<?= $editing['id'] ?? 0 ?>">

                                <?php if ($editing && !empty($editing['image'])): ?>
                                <div class="mb-3">
                                    <label class="form-label d-block">Current Image *</label>
                                    <img class="admin-img-preview" src="/<?= htmlspecialchars($editing['image']) ?>" alt="No Image Found">
                                    <div class="form-text">Image Size Should Be 60 x 60.</div>
                                </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="file-upload-label" for="photo">
                                        <i class="fas fa-upload"></i> Upload Image…
                                        <input type="file" accept="image/*" name="photo" id="photo">
                                    </label>
                                    <span id="photoFileName" class="text-muted small ms-2"></span>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="name">Name *</label>
                                    <input type="text" name="name" class="form-control" id="catName" placeholder="Enter Name" value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="slug">Slug *</label>
                                    <input type="text" name="slug" class="form-control" id="catSlug" placeholder="Enter Slug" value="<?= htmlspecialchars($editing['slug'] ?? '') ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="meta_keywords">Meta Keywords</label>
                                    <input type="text" name="meta_keywords" class="form-control" id="meta_keywords" placeholder="Enter Meta Keywords, comma separated" value="<?= htmlspecialchars($editing['meta_keywords'] ?? '') ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="meta_description">Meta Description</label>
                                    <textarea name="meta_description" id="meta_description" class="form-control" rows="5" placeholder="Enter Meta Description"><?= htmlspecialchars($editing['meta_description'] ?? '') ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" for="serial">Serial</label>
                                    <input type="number" name="serial" class="form-control" id="serial" placeholder="Enter Serial Number" value="<?= htmlspecialchars($editing['serial'] ?? 0) ?>">
                                    <div class="form-text">Lower numbers appear first when sorted by serial.</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label d-block">Status</label>
                                    <div class="btn-group" role="group">
                                        <input type="radio" class="btn-check" name="status" id="statusActive" value="active" <?= (($editing['status'] ?? 'active') === 'active') ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-success btn-sm" for="statusActive">Active</label>
                                        <input type="radio" class="btn-check" name="status" id="statusInactive" value="inactive" <?= (($editing['status'] ?? '') === 'inactive') ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-secondary btn-sm" for="statusInactive">Inactive</label>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-<?= $editing ? 'save' : 'plus-circle' ?>"></i>
                                    Submit
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
(function () {
    const nameEl = document.getElementById('catName');
    const slugEl = document.getElementById('catSlug');
    let userEditedSlug = slugEl.value !== '';
    function toSlug(str) {
        return str.toLowerCase().trim().replace(/[^a-z0-9\s-]/g, '').replace(/[\s-]+/g, '-').replace(/^-+|-+$/g, '');
    }
    nameEl.addEventListener('input', function () { if (!userEditedSlug) slugEl.value = toSlug(this.value); });
    slugEl.addEventListener('input', function () { userEditedSlug = slugEl.value !== ''; });
})();

document.getElementById('photo').addEventListener('change', function (e) {
    document.getElementById('photoFileName').textContent = e.target.files[0] ? e.target.files[0].name : '';
});
</script>
</body>
</html>
