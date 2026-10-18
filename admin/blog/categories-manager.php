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
    empty($permissions['blogs']['manage_categories'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

// Auto-generate slug from name
function generateCatSlug(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
}

// Unique slug helper
function uniqueSlug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = $base;
    $i = 1;
    while (true) {
        $q = $pdo->prepare("SELECT id FROM categories WHERE slug = ? AND id != ?");
        $q->execute([$slug, $excludeId]);
        if (!$q->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

$notifications = [];
$form_data = [];
$edit_id = 0;

// ── Handle POST (create / update) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action   = $_POST['action']           ?? '';
    $edit_id  = (int)($_POST['edit_id']    ?? 0);
    $name     = trim($_POST['name']        ?? '');
    $slug_raw = trim($_POST['slug']        ?? '');
    $mt       = trim($_POST['meta_title']  ?? '');
    $md       = trim($_POST['meta_desc']   ?? '');
    $mk       = trim($_POST['meta_kw']     ?? '');

    if (empty($name)) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Category name is required.'];
    } else {
        $base_slug = $slug_raw !== '' ? generateCatSlug($slug_raw) : generateCatSlug($name);
        $slug      = uniqueSlug($pdo, $base_slug, $edit_id);

        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO categories (name, slug, meta_title, meta_description, meta_keywords) VALUES (?,?,?,?,?)")
                    ->execute([$name, $slug, $mt ?: null, $md ?: null, $mk ?: null]);

                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_create', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Created Category: $name (ID: $new_id)", $log_ip, $log_ua]);

                header("Location: /admin/categories-manager.php?success=created");
                exit;

            } elseif ($action === 'update' && $edit_id > 0) {
                $pdo->prepare("UPDATE categories SET name=?, slug=?, meta_title=?, meta_description=?, meta_keywords=? WHERE id=?")
                    ->execute([$name, $slug, $mt ?: null, $md ?: null, $mk ?: null, $edit_id]);

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_update', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Updated Category: $name (ID: $edit_id)", $log_ip, $log_ua]);

                header("Location: /admin/categories-manager.php?success=updated");
                exit;
            }
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}

// ── Handle DELETE ───────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    try {
        $row = $pdo->prepare("SELECT name FROM categories WHERE id=?");
        $row->execute([$del_id]);
        $cat = $row->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$del_id]);

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_delete', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Deleted Category: " . ($cat['name'] ?? 'Unknown') . " (ID: $del_id)", $log_ip, $log_ua]);

        header("Location: /admin/categories-manager.php?success=deleted");
        exit;
    } catch (Exception $e) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Delete failed: ' . $e->getMessage()];
    }
}

// ── Success / Error flash messages ─────────────────────────────────────────
if (isset($_GET['success'])) {
    $map = [
        'created' => 'Category created successfully!',
        'updated' => 'Category updated successfully!',
        'deleted' => 'Category deleted successfully!',
    ];
    if (isset($map[$_GET['success']])) {
        $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => $map[$_GET['success']]];
    }
}
if (isset($_GET['error'])) {
    $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Operation failed. Please try again.'];
}

// ── Load category for editing ───────────────────────────────────────────────
$editing = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $e_stmt = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $e_stmt->execute([(int)$_GET['edit']]);
    $editing = $e_stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Search & Pagination ─────────────────────────────────────────────────────
$per_page    = 15;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;
$search_q    = trim($_GET['search'] ?? '');

$where = $search_q !== '' ? "WHERE name LIKE :s OR slug LIKE :s" : "";

$total = $pdo->prepare("SELECT COUNT(*) FROM categories $where");
if ($search_q !== '') $total->bindValue(':s', "%$search_q%");
$total->execute();
$total_count = (int)$total->fetchColumn();
$total_pages = max(1, (int)ceil($total_count / $per_page));

$stmt = $pdo->prepare("SELECT * FROM categories $where ORDER BY name ASC LIMIT :lim OFFSET :off");
if ($search_q !== '') $stmt->bindValue(':s', "%$search_q%");
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>Category Manager - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
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
.page-heading { display: none; }
@media (min-width: 768px) {
    .page-heading { display: block; }
    .sitename-mob { display: none; }
    .page-heading h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); }
    .page-heading p { font-size: 0.875rem; color: var(--gray-500); }
}
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; position: relative; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }
.icon-btn .badge { position: absolute; top: 6px; right: 6px; width: 8px; height: 8px; background: var(--danger); border-radius: 50%; border: 2px solid white; }
.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

.alert { padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.875rem; font-size: 0.9375rem; border: 1px solid transparent; }
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.alert i { font-size: 1.125rem; }

/* Stats */
.stats-grid { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 0.5rem; margin-bottom: 1rem; scrollbar-width: none; -ms-overflow-style: none; }
.stat-card { flex: 0 0 auto; background: white; border-radius: var(--radius-lg); padding: 1rem; min-width: 140px; box-shadow: var(--shadow); border: 1px solid var(--gray-100); }
.stat-card.primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border: none; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.25rem; }
.stat-card.primary .stat-value { color: white; }
.stat-change { font-size: 0.75rem; opacity: 0.9; color: var(--gray-500); }
.stat-card.primary .stat-change { color: rgba(255,255,255,0.8); }

/* Two-column layout */
.layout-grid { display: grid; gap: 1.5rem; }
@media (min-width: 1024px) { .layout-grid { grid-template-columns: 400px 1fr; align-items: start; } }

/* Form Card */
.form-card { background: white; border-radius: var(--radius-xl); border: 1px solid var(--gray-100); box-shadow: var(--shadow-sm); overflow: hidden; position: sticky; top: 80px; }
.form-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; gap: 0.75rem; }
.form-card-header h2 { font-size: 1.0625rem; font-weight: 700; color: var(--gray-900); }
.form-card-header .header-icon { width: 36px; height: 36px; background: var(--primary-lighter); color: var(--primary); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1rem; }
.form-card-body { padding: 1.5rem; }

.form-group { margin-bottom: 1.125rem; }
.form-group label { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--gray-600); margin-bottom: 0.4rem; }
.form-group label span { font-weight: 400; color: var(--gray-400); font-size: 0.75rem; }
.form-control { width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.9375rem; background: white; color: var(--gray-800); transition: all 0.2s; font-family: inherit; }
.form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
textarea.form-control { resize: vertical; min-height: 80px; }

.form-actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.625rem 1.25rem; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-primary { background: var(--primary); color: white; flex: 1; }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25); }
.btn-secondary { background: var(--gray-100); color: var(--gray-700); }
.btn-secondary:hover { background: var(--gray-200); }
.btn-danger-outline { background: transparent; color: var(--danger); border: 1px solid #fecaca; }
.btn-danger-outline:hover { background: #fef2f2; }

/* Edit mode indicator */
.edit-mode-bar { background: var(--primary-lighter); border-bottom: 2px solid var(--primary-light); padding: 0.625rem 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; color: var(--primary); }

/* Table / List Card */
.list-card { background: white; border-radius: var(--radius-xl); border: 1px solid var(--gray-100); box-shadow: var(--shadow-sm); overflow: hidden; }
.list-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.list-card-header h2 { font-size: 1.0625rem; font-weight: 700; color: var(--gray-900); }

/* Search within list */
.filter-section { background: white; border-radius: var(--radius-xl); padding: 1.25rem; margin-bottom: 1rem; border: 1px solid var(--gray-100); box-shadow: var(--shadow-sm); }
.filter-row { display: grid; gap: 0.75rem; }
@media (min-width: 640px) { .filter-row { grid-template-columns: 1fr auto; align-items: end; } }
.filter-group { display: flex; flex-direction: column; gap: 0.5rem; }
.filter-group label { font-size: 0.8125rem; font-weight: 600; color: var(--gray-600); }
.filter-control { padding: 0.625rem 0.875rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.9375rem; background: white; color: var(--gray-800); transition: all 0.2s; width: 100%; }
.filter-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
.filter-actions { display: flex; gap: 0.75rem; }

/* Table */
.cat-table { width: 100%; border-collapse: collapse; }
.cat-table thead th { padding: 0.875rem 1.25rem; text-align: left; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); }
.cat-table tbody tr { border-bottom: 1px solid var(--gray-100); transition: background 0.15s; }
.cat-table tbody tr:last-child { border-bottom: none; }
.cat-table tbody tr:hover { background: var(--gray-50); }
.cat-table td { padding: 1rem 1.25rem; font-size: 0.9375rem; vertical-align: middle; }
.cat-name { font-weight: 600; color: var(--gray-900); }
.cat-slug { font-size: 0.8125rem; color: var(--gray-500); font-family: monospace; background: var(--gray-100); padding: 0.2rem 0.5rem; border-radius: 4px; display: inline-block; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cat-views { font-size: 0.875rem; color: var(--gray-600); font-weight: 500; }
.cat-seo-badge { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; font-weight: 600; padding: 0.25rem 0.625rem; border-radius: 9999px; }
.seo-ok { background: #d1fae5; color: #065f46; }
.seo-missing { background: var(--gray-100); color: var(--gray-500); }

.row-actions { display: flex; gap: 0.5rem; }
.btn-action { padding: 0.4rem 0.75rem; border-radius: var(--radius); font-size: 0.8125rem; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.35rem; }
.btn-edit { background: var(--gray-100); color: var(--gray-700); }
.btn-edit:hover { background: var(--gray-200); }
.btn-delete { background: #fee2e2; color: var(--danger); }
.btn-delete:hover { background: #fecaca; }

/* Empty State */
.empty-state { text-align: center; padding: 3rem 2rem; }
.empty-icon { width: 72px; height: 72px; background: var(--primary-lighter); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem; }
.empty-state h3 { font-size: 1.125rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.5rem; }
.empty-state p { color: var(--gray-500); font-size: 0.9375rem; }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 0.375rem; padding: 1.25rem; flex-wrap: wrap; }
.page-link { min-width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; transition: all 0.2s; border: 1px solid var(--gray-200); background: white; color: var(--gray-700); padding: 0 0.75rem; }
.page-link:hover { background: var(--gray-50); border-color: var(--gray-300); }
.page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
.page-link.disabled { color: var(--gray-300); cursor: not-allowed; pointer-events: none; }

/* Mobile table scroll */
.table-wrap { overflow-x: auto; }

@media (max-width: 640px) {
    .form-actions { flex-direction: column; }
    .btn-primary { width: 100%; }
}
@media (hover: none) { .btn-action:hover { transform: none; } }

.search-info { padding: 1rem 1.25rem; background: var(--primary-lighter); border-radius: var(--radius-lg); margin-bottom: 1rem; color: var(--primary-dark); font-size: 0.9375rem; font-weight: 500; }
.search-info strong { color: var(--primary); }
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
                <div class="page-heading">
                    <h1>Category Manager</h1>
                    <p>Create, edit, and organize categories</p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn">
                    <i class="fas fa-bell"></i>
                    <span class="badge"></span>
                </button>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] ?>">
                <i class="<?= $n['icon'] ?>"></i>
                <span><?= htmlspecialchars($n['message']) ?></span>
            </div>
            <?php endforeach; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-value"><?= number_format($total_count) ?></div>
                    <div class="stat-change">Total Categories</div>
                </div>
                <?php
                $seo_complete = $pdo->query("SELECT COUNT(*) FROM categories WHERE meta_title IS NOT NULL AND meta_title != ''")->fetchColumn();
                $total_views  = $pdo->query("SELECT SUM(views) FROM categories")->fetchColumn() ?: 0;
                ?>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--success);"><?= number_format($seo_complete) ?></div>
                    <div class="stat-change">SEO Complete</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--warning);"><?= number_format($total_count - $seo_complete) ?></div>
                    <div class="stat-change">Missing SEO</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--info);"><?= formatViews($total_views) ?></div>
                    <div class="stat-change">Total Views</div>
                </div>
            </div>

            <!-- Main Two-Column Layout -->
            <div class="layout-grid">

                <!-- ── LEFT: Form ── -->
                <div>
                    <div class="form-card">
                        <?php if ($editing): ?>
                        <div class="edit-mode-bar">
                            <i class="fas fa-pen"></i>
                            Editing: <?= htmlspecialchars($editing['name']) ?>
                        </div>
                        <?php endif; ?>

                        <div class="form-card-header">
                            <div class="header-icon">
                                <i class="fas fa-<?= $editing ? 'pen' : 'plus' ?>"></i>
                            </div>
                            <h2><?= $editing ? 'Edit Category' : 'New Category' ?></h2>
                        </div>

                        <div class="form-card-body">
                            <form method="POST" id="catForm">
                                <input type="hidden" name="action"  value="<?= $editing ? 'update' : 'create' ?>">
                                <input type="hidden" name="edit_id" value="<?= $editing ? $editing['id'] : 0 ?>">

                                <div class="form-group">
                                    <label>Name <span>*required</span></label>
                                    <input type="text" name="name" id="catName" class="form-control"
                                           placeholder="e.g. Government Jobs"
                                           value="<?= htmlspecialchars($editing['name'] ?? '') ?>"
                                           required>
                                </div>

                                <div class="form-group">
                                    <label>Slug <span>auto-generated</span></label>
                                    <input type="text" name="slug" id="catSlug" class="form-control"
                                           placeholder="e.g. government-jobs"
                                           value="<?= htmlspecialchars($editing['slug'] ?? '') ?>">
                                </div>

                                <div class="form-group">
                                    <label>Meta Title <span>SEO</span></label>
                                    <input type="text" name="meta_title" class="form-control"
                                           placeholder="Page title for search engines"
                                           maxlength="255"
                                           value="<?= htmlspecialchars($editing['meta_title'] ?? '') ?>">
                                </div>

                                <div class="form-group">
                                    <label>Meta Description <span>SEO</span></label>
                                    <textarea name="meta_desc" class="form-control"
                                              placeholder="Brief description for search results..."><?= htmlspecialchars($editing['meta_description'] ?? '') ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label>Meta Keywords <span>SEO</span></label>
                                    <input type="text" name="meta_kw" class="form-control"
                                           placeholder="keyword1, keyword2, keyword3"
                                           value="<?= htmlspecialchars($editing['meta_keywords'] ?? '') ?>">
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-<?= $editing ? 'save' : 'plus-circle' ?>"></i>
                                        <?= $editing ? 'Update Category' : 'Create Category' ?>
                                    </button>
                                    <?php if ($editing): ?>
                                    <a href="/admin/blog/categories-manager.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i>
                                        Cancel
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ── RIGHT: List ── -->
                <div>

                    <!-- Search -->
                    <div class="filter-section">
                        <form method="GET" style="margin:0;">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label>Search Categories</label>
                                    <input type="text" name="search" class="filter-control"
                                           placeholder="Search by name or slug..."
                                           value="<?= htmlspecialchars($search_q) ?>">
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i>
                                        Search
                                    </button>
                                    <?php if ($search_q !== ''): ?>
                                    <a href="/admin/blog/categories-manager.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i>
                                        Clear
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

                    <?php if ($search_q !== ''): ?>
                    <div class="search-info">
                        <i class="fas fa-search" style="margin-right:0.5rem;"></i>
                        Found <strong><?= $total_count ?></strong> result<?= $total_count !== 1 ? 's' : '' ?> for "<strong><?= htmlspecialchars($search_q) ?></strong>"
                    </div>
                    <?php endif; ?>

                    <div class="list-card">
                        <div class="list-card-header">
                            <h2>All Categories <span style="font-weight:400;color:var(--gray-500);font-size:0.875rem;">(<?= $total_count ?>)</span></h2>
                        </div>

                        <?php if (empty($categories)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-folder-open"></i></div>
                            <h3><?= $search_q ? 'No categories found' : 'No categories yet' ?></h3>
                            <p><?= $search_q ? 'Try a different search term.' : 'Create your first category using the form.' ?></p>
                        </div>
                        <?php else: ?>
                        <div class="table-wrap">
                            <table class="cat-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name / Slug</th>
                                        <th>SEO</th>
                                        <th>Views</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($categories as $i => $cat): ?>
                                <tr>
                                    <td style="color:var(--gray-400);font-size:0.875rem;"><?= $offset + $i + 1 ?></td>
                                    <td>
                                        <div class="cat-name"><?= htmlspecialchars($cat['name']) ?></div>
                                        <div style="margin-top:0.3rem;">
                                            <span class="cat-slug"><?= htmlspecialchars($cat['slug']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($cat['meta_title'])): ?>
                                        <span class="cat-seo-badge seo-ok"><i class="fas fa-check"></i> Complete</span>
                                        <?php else: ?>
                                        <span class="cat-seo-badge seo-missing"><i class="fas fa-minus"></i> Missing</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cat-views"><?= formatViews($cat['views']) ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="?edit=<?= $cat['id'] ?><?= $search_q ? '&search=' . urlencode($search_q) : '' ?>" class="btn-action btn-edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button class="btn-action btn-delete"
                                                    onclick="confirmDelete(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php
                            $qs = $search_q ? '&search=' . urlencode($search_q) : '';
                            if ($editing) $qs .= '&edit=' . $editing['id'];
                            ?>

                            <?php if ($page > 1): ?>
                                <a href="?page=1<?= $qs ?>" class="page-link"><i class="fas fa-angle-double-left"></i></a>
                                <a href="?page=<?= $page - 1 ?><?= $qs ?>" class="page-link"><i class="fas fa-angle-left"></i></a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                            <?php endif; ?>

                            <?php
                            $range = 2;
                            $start = max(1, $page - $range);
                            $end   = min($total_pages, $page + $range);
                            if ($start > 1): ?>
                                <a href="?page=1<?= $qs ?>" class="page-link">1</a>
                                <?php if ($start > 2): ?><span class="page-link disabled">...</span><?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <?php if ($i === $page): ?>
                                    <span class="page-link active"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?page=<?= $i ?><?= $qs ?>" class="page-link"><?= $i ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($end < $total_pages): ?>
                                <?php if ($end < $total_pages - 1): ?><span class="page-link disabled">...</span><?php endif; ?>
                                <a href="?page=<?= $total_pages ?><?= $qs ?>" class="page-link"><?= $total_pages ?></a>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?><?= $qs ?>" class="page-link"><i class="fas fa-angle-right"></i></a>
                                <a href="?page=<?= $total_pages ?><?= $qs ?>" class="page-link"><i class="fas fa-angle-double-right"></i></a>
                            <?php else: ?>
                                <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                                <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php endif; ?>
                    </div>

                </div><!-- end right -->
            </div><!-- end layout-grid -->

        </div><!-- content-wrapper -->
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>

<script>
const ADMIN_URL = "<?= ADMIN_URL ?>";

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function confirmDelete(id, name) {
    if (confirm('Delete category "' + name + '"?\n\nThis cannot be undone. Posts using this category will lose their category reference.')) {
        window.location.href = '/admin/blog/categories-manager.php?delete=' + encodeURIComponent(id);
    }
}

// Auto-generate slug from name (only when slug field is empty or matches previous auto-gen)
(function () {
    const nameEl = document.getElementById('catName');
    const slugEl = document.getElementById('catSlug');
    if (!nameEl || !slugEl) return;

    let userEditedSlug = slugEl.value !== '';

    function toSlug(str) {
        return str.toLowerCase().trim()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/[\s-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    nameEl.addEventListener('input', function () {
        if (!userEditedSlug) {
            slugEl.value = toSlug(this.value);
        }
    });

    slugEl.addEventListener('input', function () {
        userEditedSlug = this.value !== '';
    });

    slugEl.addEventListener('blur', function () {
        if (this.value) {
            this.value = toSlug(this.value);
        }
    });
})();

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (el) {
            el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-5px)';
            setTimeout(function () { el.remove(); }, 400);
        });
    }, 3000);

    const url = new URL(window.location.href);
    let changed = false;
    ['success', 'error'].forEach(function (k) {
        if (url.searchParams.has(k)) { url.searchParams.delete(k); changed = true; }
    });
    if (changed) window.history.replaceState({}, document.title, url.pathname + url.search);
});

window.addEventListener('resize', function () {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});

let touchStartX = 0, touchEndX = 0;
const sidebar = document.getElementById('sidebar');
sidebar.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, false);
sidebar.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchStartX - touchEndX > 100) toggleSidebar();
}, false);
</script>
</body>
</html>
