<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);

if (
    !$user ||
    $user['status'] !== 'active' ||
    empty($permissions['ecommerce']['manage_products'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

define('BRAND_UPLOAD_URL', 'uploads/ecommerce/brands/');
define('BRAND_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/brands/');

function uniqueBrandSlug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = $base;
    $i = 1;
    while (true) {
        $q = $pdo->prepare("SELECT id FROM ecom_brands WHERE slug = ? AND id != ?");
        $q->execute([$slug, $excludeId]);
        if (!$q->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

$notifications = [];

// ── Handle POST (create / update) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {

    $action     = $_POST['action']    ?? '';
    $edit_id    = (int)($_POST['edit_id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $slug_raw   = trim($_POST['slug'] ?? '');
    $status     = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $is_popular = isset($_POST['is_popular']) ? 1 : 0;

    if (empty($name)) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Brand name is required.'];
    } else {
        $base_slug = $slug_raw !== '' ? generateSlug($slug_raw) : generateSlug($name);
        $slug      = uniqueBrandSlug($pdo, $base_slug, $edit_id);

        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            if (!file_exists(BRAND_UPLOAD_ABS)) mkdir(BRAND_UPLOAD_ABS, 0777, true);
            $ext      = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $filename = $slug . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], BRAND_UPLOAD_ABS . $filename)) {
                $logo_path = BRAND_UPLOAD_URL . $filename;
            }
        }

        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO ecom_brands (name, slug, logo, status, is_popular) VALUES (?,?,?,?,?)")
                    ->execute([$name, $slug, $logo_path, $status, $is_popular]);

                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_brand_create', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Created Brand: $name (ID: $new_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/brands.php?success=created");
                exit;

            } elseif ($action === 'update' && $edit_id > 0) {
                if ($logo_path) {
                    $old = $pdo->prepare("SELECT logo FROM ecom_brands WHERE id=?");
                    $old->execute([$edit_id]);
                    if ($old_row = $old->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($old_row['logo']) && file_exists(DROOT_PATH . '/' . $old_row['logo'])) {
                            @unlink(DROOT_PATH . '/' . $old_row['logo']);
                        }
                    }
                    $pdo->prepare("UPDATE ecom_brands SET name=?, slug=?, logo=?, status=?, is_popular=? WHERE id=?")
                        ->execute([$name, $slug, $logo_path, $status, $is_popular, $edit_id]);
                } else {
                    $pdo->prepare("UPDATE ecom_brands SET name=?, slug=?, status=?, is_popular=? WHERE id=?")
                        ->execute([$name, $slug, $status, $is_popular, $edit_id]);
                }

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_brand_update', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Updated Brand: $name (ID: $edit_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/brands.php?success=updated");
                exit;
            }
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}

// ── Handle DELETE ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['delete_id'] ?? 0);
    try {
        $row = $pdo->prepare("SELECT name, logo FROM ecom_brands WHERE id=?");
        $row->execute([$del_id]);
        $brand = $row->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("DELETE FROM ecom_brands WHERE id=?")->execute([$del_id]);

        if (!empty($brand['logo']) && file_exists(DROOT_PATH . '/' . $brand['logo'])) {
            @unlink(DROOT_PATH . '/' . $brand['logo']);
        }

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_brand_delete', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Deleted Brand: " . ($brand['name'] ?? 'Unknown') . " (ID: $del_id)", $log_ip, $log_ua]);

        header("Location: /admin/ecommerce/brands.php?success=deleted");
        exit;
    } catch (Exception $e) {
        header("Location: /admin/ecommerce/brands.php?error=1");
        exit;
    }
}

// ── Quick Enable/Disable ─────────────────────────────────────────────────────
if (isset($_GET['set_status']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $new_status = $_GET['set_status'] === 'inactive' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE ecom_brands SET status=? WHERE id=?")->execute([$new_status, (int)$_GET['id']]);
    header("Location: /admin/ecommerce/brands.php");
    exit;
}

// ── Quick toggle: Popular ───────────────────────────────────────────────────
if (isset($_GET['set_popular']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $val = $_GET['set_popular'] === '1' ? 1 : 0;
    $pdo->prepare("UPDATE ecom_brands SET is_popular = ? WHERE id = ?")->execute([$val, (int)$_GET['id']]);
    header("Location: /admin/ecommerce/brands.php");
    exit;
}

// ── Success / Error flash messages ─────────────────────────────────────────
if (isset($_GET['success'])) {
    $map = [
        'created' => 'Brand created successfully!',
        'updated' => 'Brand updated successfully!',
        'deleted' => 'Brand deleted successfully!',
    ];
    if (isset($map[$_GET['success']])) {
        $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => $map[$_GET['success']]];
    }
}
if (isset($_GET['error'])) {
    $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Delete failed. Please try again.'];
}

// ── Load brand for editing ──────────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $e_stmt = $pdo->prepare("SELECT * FROM ecom_brands WHERE id=?");
    $e_stmt->execute([(int)$_GET['edit']]);
    $editing = $e_stmt->fetch(PDO::FETCH_ASSOC);
}

$brands = $pdo->query("
    SELECT b.*, (SELECT COUNT(*) FROM ecom_products p WHERE p.brand_id = b.id) AS product_count
    FROM ecom_brands b
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>Brands - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
!function () {
    let e = localStorage.dm === "1", t = !1, n = !1,
        s = () => new Promise((e, r) => {
            let o = document.createElement("script");
            o.src = "/assets/js/darkreader.min.js";
            o.onload = () => { t = !0; e(); };
            o.onerror = r;
            document.head.appendChild(o);
        }),
        a = () => DarkReader.enable({ brightness: 100, contrast: 100, sepia: 10 }),
        d = () => DarkReader.disable(),
        i = () => {
            document.querySelectorAll(".dark-mode-toggle i").forEach(o => {
                o.classList.add("rotate");
                if (e) { o.classList.remove("fa-moon"); o.classList.add("fa-sun"); }
                else { o.classList.remove("fa-sun"); o.classList.add("fa-moon"); }
                setTimeout(() => o.classList.remove("rotate"), 400);
            });
        };
    e && (t ? a() : s().then(a));
    document.addEventListener("DOMContentLoaded", () => {
        i();
        document.querySelectorAll(".dark-mode-toggle").forEach(o => o.onclick = r);
    });
    async function r(o) {
        o.preventDefault();
        if (n) return;
        n = !0;
        let c = document.querySelectorAll(".dark-mode-toggle");
        c.forEach(e => e.classList.add("loading"));
        try {
            t || await s();
            e = !e;
            localStorage.dm = e ? "1" : "0";
            e ? a() : d();
            i();
        } finally {
            setTimeout(() => { c.forEach(e => e.classList.remove("loading")); n = !1; }, 600);
        }
    }
}();
</script>
<style>
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #3b82f6;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
    --sidebar-width: 280px;
}
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--gray-50); color: var(--gray-800); line-height: 1.5; }

.admin-container { display: grid; grid-template-columns: 1fr; min-height: 100vh; }
@media (min-width: 1024px) { .admin-container { grid-template-columns: var(--sidebar-width) 1fr; } }
.sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-width); background: white; border-right: 1px solid var(--gray-200); z-index: 1000; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow-y: auto; display: flex; flex-direction: column; }
.sidebar.open { transform: translateX(0); }
@media (min-width: 1024px) { .sidebar { position: sticky; transform: translateX(0); height: 100vh; top: 0; } }
.sidebar-header { padding: 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
.brand { display: flex; align-items: center; gap: 0.65rem; font-size: 1.15rem; font-weight: 800; color: var(--primary); text-decoration: none; }
.brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
.close-sidebar { width: 36px; height: 36px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--gray-600); transition: all 0.2s; }
.close-sidebar:hover { background: var(--gray-200); }
@media (min-width: 1024px) { .close-sidebar { display: none; } }
.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section { margin-bottom: 1.5rem; padding: 0 1rem; }
.nav-title { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gray-400); padding: 0 1rem; margin-bottom: 0.75rem; }
.nav-link { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem 1rem; border-radius: var(--radius); color: var(--gray-600); text-decoration: none; font-size: 0.9375rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.25rem; }
.nav-link:hover { background: var(--gray-50); color: var(--gray-900); }
.nav-link.active { background: var(--primary-lighter); color: var(--primary); font-weight: 600; }
.nav-link i { width: 24px; text-align: center; font-size: 1.125rem; }
.sidebar-footer { padding: 1rem; border-top: 1px solid var(--gray-100); }
.user-card { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem; background: var(--gray-50); border-radius: var(--radius-lg); }
.user-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; }
.user-info { flex: 1; min-width: 0; }
.user-name { font-weight: 600; color: var(--gray-900); font-size: 0.9375rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 0.75rem; color: var(--gray-500); }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; visibility: hidden; transition: all 0.3s; }
.sidebar-overlay.active { opacity: 1; visibility: visible; }
@media (min-width: 1024px) { .sidebar-overlay { display: none; } }
.main-content { min-width: 0; }
.top-nav { position: sticky; top: 0; background: white; border-bottom: 1px solid var(--gray-200); padding: 0.450rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; z-index: 100; }
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-700); cursor: pointer; transition: all 0.2s; }
.menu-toggle:hover { background: var(--gray-200); }
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }
.page-heading-mini { display: none; }
@media (min-width: 768px) { .page-heading-mini { display: block; } .sitename-mob { display: none; } .page-heading-mini h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); } .page-heading-mini p { font-size: 0.875rem; color: var(--gray-500); } }
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }
.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }
.alert-floating { border-radius: var(--radius-lg); margin-bottom: 1rem; }
.gd-card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 0.35rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,.1); margin-bottom: 1.5rem; }
.gd-card-body { padding: 1.25rem 1.5rem; }
.table-card-body { padding-bottom: 4rem; }
.gd-heading-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
.gd-heading-row h3 { font-weight: 700; color: var(--gray-800); margin: 0; }
table.dataTable { border-collapse: collapse !important; width: 100% !important; }
table.dataTable thead th { background: var(--gray-50); font-weight: 700; }
table.dataTable td, table.dataTable th { vertical-align: middle !important; }
table.dataTable img { width: 55px; height: 55px; object-fit: cover; border-radius: 0.25rem; }
.dt-thumb-placeholder { width: 55px; height: 55px; border-radius: 0.25rem; background: var(--gray-100); display: flex; align-items: center; justify-content: center; color: var(--gray-400); }
.status-btn { border: none; border-radius: 0; box-shadow: none !important; font-weight: 500; }
.status-btn.dropdown-toggle::after { vertical-align: 0.15em; }
.dropdown-menu { border-radius: 0; }
.status-btn.btn-success { background-color: #1cc88a; }
.status-btn.btn-secondary-status { background-color: #858796; color: #fff; }
.action-list { display: flex; gap: 0.4rem; }
.action-list .btn { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
.action-list .btn-primary { background-color: #4361ee; border-color: #4361ee; }
.action-list .btn-danger { background-color: var(--danger); border-color: var(--danger); }
.popular-toggle-link { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.7rem; border-radius: 9999px; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.popular-yes { background: #fef3c7; color: #92400e; }
.popular-yes:hover { background: #fde68a; }
.popular-no { background: var(--gray-100); color: var(--gray-400); }
.popular-no:hover { background: var(--gray-200); }
.modal-header { background: var(--gray-50); }
/* Thin, subtle focus state (Bootstrap's default glow is too heavy) */
.form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: none; }
.btn-check:focus + .btn, .btn:focus { box-shadow: none; }
.dataTables_length select { min-width: 75px; padding-right: 1.75rem !important; }
.dataTables_length, .dataTables_filter { margin-bottom: 1rem; }
</style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading-mini">
                    <h1>Brands</h1>
                    <p>Manage the brands products can be tagged with</p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-floating alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <i class="<?= $n['icon'] ?>"></i>
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Brands</b></h3>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#brandModal" onclick="openCreateModal()">
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
                                    <th>Name</th>
                                    <th>Logo</th>
                                    <th>Slug</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th>Popular</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($brands as $b): ?>
                            <tr>
                                <td><?= htmlspecialchars($b['name']) ?></td>
                                <td>
                                    <?php if (!empty($b['logo'])): ?>
                                        <img src="/<?= htmlspecialchars($b['logo']) ?>" alt="<?= htmlspecialchars($b['name']) ?>">
                                    <?php else: ?>
                                        <div class="dt-thumb-placeholder"><i class="fas fa-image"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($b['slug']) ?></td>
                                <td><?= (int)$b['product_count'] ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $b['status'] === 'active' ? 'btn-success' : 'btn-secondary-status' ?>" type="button" data-bs-toggle="dropdown">
                                            <?= $b['status'] === 'active' ? 'Enabled' : 'Disabled' ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?set_status=active&id=<?= $b['id'] ?>">Enable</a></li>
                                            <li><a class="dropdown-item" href="?set_status=inactive&id=<?= $b['id'] ?>">Disable</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm dropdown-toggle status-btn <?= $b['is_popular'] ? 'btn-success' : 'btn-secondary-status' ?>" type="button" data-bs-toggle="dropdown">
                                            <?= $b['is_popular'] ? 'Enabled' : 'Disabled' ?>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="?set_popular=1&id=<?= $b['id'] ?>">Enable</a></li>
                                            <li><a class="dropdown-item" href="?set_popular=0&id=<?= $b['id'] ?>">Disable</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-list">
                                        <button class="btn btn-primary btn-sm"
                                                onclick='openEditModal(<?= json_encode([
                                                    "id" => $b['id'],
                                                    "name" => $b['name'],
                                                    "slug" => $b['slug'],
                                                    "status" => $b['status'],
                                                    "logo" => $b['logo'],
                                                    "is_popular" => $b['is_popular'],
                                                ]) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm"
                                                onclick="openDeleteModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>')">
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
<div class="modal fade" id="brandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="brandForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="brandModalLabel">New Brand</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="brandAction" value="create">
                    <input type="hidden" name="edit_id" id="brandEditId" value="0">

                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="brandName" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Slug <small class="text-muted">(auto-generated)</small></label>
                        <input type="text" name="slug" id="brandSlug" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <div class="d-flex align-items-center gap-3">
                            <div id="imgPreviewBox" class="dt-thumb-placeholder" style="display:none;overflow:hidden;">
                                <img id="imgPreviewImg" style="width:100%;height:100%;object-fit:cover;">
                            </div>
                            <input type="file" name="logo" id="brandLogo" class="form-control" accept="image/*">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label d-block">Status</label>
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="status" id="brandStatusActive" value="active" checked>
                            <label class="btn btn-outline-success btn-sm" for="brandStatusActive">Active</label>
                            <input type="radio" class="btn-check" name="status" id="brandStatusInactive" value="inactive">
                            <label class="btn btn-outline-secondary btn-sm" for="brandStatusInactive">Inactive</label>
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_popular" id="brandPopular">
                        <label class="form-check-label" for="brandPopular">Show as Popular Brand</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="brandSubmitBtn">Create Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirm Delete Modal -->
<div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete?</h5>
                <button class="btn-close" type="button" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                You are going to delete "<strong id="deleteBrandName"></strong>". Do you want to delete it?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_id" id="deleteBrandId">
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<script>
const ADMIN_URL = "<?= ADMIN_URL ?>";

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
window.addEventListener('resize', function () {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});
let touchStartX = 0, touchEndX = 0;
const sidebarEl = document.getElementById('sidebar');
sidebarEl.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, false);
sidebarEl.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchStartX - touchEndX > 100) toggleSidebar();
}, false);

$(document).ready(function () {
    $('#admin-table').DataTable({
        order: [],
        columnDefs: [{ orderable: false, targets: [1, 4, 5, 6] }]
    });

    // Force status dropdowns to always open downward (no auto-flip to top)
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        new bootstrap.Dropdown(el, {
            popperConfig: function (defaultConfig) {
                return Object.assign({}, defaultConfig, {
                    strategy: 'fixed',
                    modifiers: (defaultConfig.modifiers || []).concat([{ name: 'flip', enabled: false }])
                });
            }
        });
    });
});

function resetBrandForm() {
    document.getElementById('brandForm').reset();
    document.getElementById('brandAction').value = 'create';
    document.getElementById('brandEditId').value = '0';
    document.getElementById('brandModalLabel').textContent = 'New Brand';
    document.getElementById('brandSubmitBtn').textContent = 'Create Brand';
    document.getElementById('imgPreviewBox').style.display = 'none';
    document.getElementById('brandStatusActive').checked = true;
}

function openCreateModal() {
    resetBrandForm();
}

function openEditModal(b) {
    resetBrandForm();
    document.getElementById('brandAction').value = 'update';
    document.getElementById('brandEditId').value = b.id;
    document.getElementById('brandModalLabel').textContent = 'Edit Brand';
    document.getElementById('brandSubmitBtn').textContent = 'Update Brand';
    document.getElementById('brandName').value = b.name;
    document.getElementById('brandSlug').value = b.slug;
    document.getElementById('brandPopular').checked = !!b.is_popular;
    if (b.status === 'inactive') {
        document.getElementById('brandStatusInactive').checked = true;
    } else {
        document.getElementById('brandStatusActive').checked = true;
    }
    if (b.logo) {
        document.getElementById('imgPreviewImg').src = '/' + b.logo;
        document.getElementById('imgPreviewBox').style.display = 'flex';
    }
    new bootstrap.Modal(document.getElementById('brandModal')).show();
}

document.getElementById('brandLogo').addEventListener('change', function (e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (ev) {
        document.getElementById('imgPreviewImg').src = ev.target.result;
        document.getElementById('imgPreviewBox').style.display = 'flex';
    };
    reader.readAsDataURL(file);
});

(function () {
    const nameEl = document.getElementById('brandName');
    const slugEl = document.getElementById('brandSlug');
    let userEditedSlug = false;
    function toSlug(str) {
        return str.toLowerCase().trim().replace(/[^a-z0-9\s-]/g, '').replace(/[\s-]+/g, '-').replace(/^-+|-+$/g, '');
    }
    nameEl.addEventListener('input', function () { if (!userEditedSlug) slugEl.value = toSlug(this.value); });
    slugEl.addEventListener('input', function () { userEditedSlug = slugEl.value !== ''; });
})();

function openDeleteModal(id, name) {
    document.getElementById('deleteBrandId').value = id;
    document.getElementById('deleteBrandName').textContent = name;
    new bootstrap.Modal(document.getElementById('confirm-delete')).show();
}

<?php if ($editing): ?>
document.addEventListener('DOMContentLoaded', function () {
    openEditModal(<?= json_encode([
        "id" => $editing['id'],
        "name" => $editing['name'],
        "slug" => $editing['slug'],
        "status" => $editing['status'],
        "logo" => $editing['logo'],
        "is_popular" => $editing['is_popular'],
    ]) ?>);
});
<?php endif; ?>
</script>
</body>
</html>