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
    empty($permissions['blogs']['manage_tags'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_tag') {
        $name = trim($_POST['tag_name']);
        $slug = !empty($_POST['tag_slug']) ? $_POST['tag_slug'] : generateSlug($name);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO tags (name, slug, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$name, $slug]);
            $msg = "Tag created successfully!";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error: Tag or Slug already exists.";
            $msg_type = 'error';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'edit_tag') {
        $id = (int)$_POST['tag_id'];
        $name = trim($_POST['tag_name']);
        $slug = !empty($_POST['tag_slug']) ? $_POST['tag_slug'] : generateSlug($name);
        $status = isset($_POST['is_active']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("UPDATE tags SET name = ?, slug = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $slug, $status, $id]);
            $msg = "Tag updated successfully!";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error updating tag.";
            $msg_type = 'error';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_tag') {
        $id = (int)$_POST['tag_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
            $stmt->execute([$id]);
            $msg = "Tag deleted successfully.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error deleting tag.";
            $msg_type = 'error';
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'link_post') {
        $tag_id = (int)$_POST['tag_id'];
        $post_id = (int)$_POST['post_id'];
        
        try {
            $check = $pdo->prepare("SELECT id FROM posts WHERE id = ?");
            $check->execute([$post_id]);
            
            if ($check->rowCount() > 0) {
                $stmt = $pdo->prepare("INSERT INTO post_tag (post_id, tag_id) VALUES (?, ?)");
                $stmt->execute([$post_id, $tag_id]);
                $msg = "Tag linked to Post #$post_id successfully.";
                $msg_type = 'success';
            } else {
                $msg = "Post ID #$post_id does not exist.";
                $msg_type = 'error';
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                 $msg = "This tag is already linked to that post.";
            } else {
                 $msg = "Database Error.";
            }
            $msg_type = 'error';
        }
    }
}

$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$order_sql = " ORDER BY t.created_at DESC";
switch ($sort) {
    case 'oldest': $order_sql = " ORDER BY t.created_at ASC"; break;
    case 'popular': $order_sql = " ORDER BY post_count DESC"; break;
    case 'views': $order_sql = " ORDER BY t.views DESC"; break;
    case 'name': $order_sql = " ORDER BY t.name ASC"; break;
}

$sql = "SELECT t.*, COUNT(pt.post_id) as post_count 
        FROM tags t 
        LEFT JOIN post_tag pt ON t.id = pt.tag_id ";

$count_sql = "SELECT COUNT(*) FROM tags t ";
$params = [];

if ($search) {
    $sql .= " WHERE t.name LIKE ? ";
    $count_sql .= " WHERE t.name LIKE ? ";
    $params[] = "%$search%";
}

$sql .= " GROUP BY t.id " . $order_sql . " LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_stmt = $pdo->prepare($count_sql);
$total_stmt->execute($params);
$total_rows = $total_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$stat_stmt = $pdo->query("SELECT COUNT(*) as total, SUM(views) as total_views FROM tags");
$stats = $stat_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Tag Manager - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
/* Dark mode toggle - identical to dashboard.php / categories-manager.php / file-manager.php / comments-manager.php / blogs-manager.php */
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
/* ============================================================
   SHELL CSS — copied 1:1 from dashboard.php / categories-manager.php /
   file-manager.php / comments-manager.php / blogs-manager.php
   Do NOT diverge from this block on any admin page.
   ============================================================ */
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

.sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-width); background: white; border-right: 1px solid var(--gray-200); z-index: 1000; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); overflow-y: auto; display: flex; flex-direction: column; }
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

.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.main-content { min-width: 0; }
.top-nav { position: sticky; top: 0; background: white; border-bottom: 1px solid var(--gray-200); padding: 0.450rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; z-index: 100; }
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-700); cursor: pointer; transition: all 0.2s; }
.menu-toggle:hover { background: var(--gray-200); }
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }
.page-heading { display: none; }
@media (min-width: 768px) { .page-heading { display: block; } .sitename-mob { display: none; } .page-heading h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); } .page-heading p { font-size: 0.875rem; color: var(--gray-500); } }
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; position: relative; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }
.icon-btn .badge { position: absolute; top: 6px; right: 6px; width: 8px; height: 8px; background: var(--danger); border-radius: 50%; border: 2px solid white; }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

/* Alerts */
.alert { padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.875rem; font-size: 0.9375rem; border: 1px solid transparent; }
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.alert i { font-size: 1.125rem; }

/* ============================================================
   PAGE-SPECIFIC CSS — Tag Manager only (unique to this page)
   Converted to the shared design tokens (var(--primary) etc.)
   ============================================================ */
.stats-bar { display: flex; gap: 25px; margin-bottom: 1.5rem; background: white; padding: 1.25rem; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); align-items: center; justify-content: space-between; flex-wrap: wrap; }
.stat-item { font-size: 0.9375rem; color: var(--gray-600); }
.stat-item strong { color: var(--gray-900); font-size: 1.0625rem; }

.action-bar { display: flex; justify-content: space-between; margin-bottom: 1.25rem; gap: 15px; flex-wrap: wrap; }

.search-group { display: flex; flex: 1; min-width: 250px; max-width: 600px; width: 100%; }
.search-box { display: flex; width: 100%; }
.search-box input { flex: 1; min-width: 0; padding: 0.75rem 0.875rem; border: 1px solid var(--gray-200); border-right: none; border-radius: var(--radius) 0 0 var(--radius); outline: none; font-size: 0.9375rem; background: white; color: var(--gray-800); }
.search-box input:focus { border-color: var(--primary); }
.search-box select { padding: 0.75rem 0.625rem; border: 1px solid var(--gray-200); border-left: 1px solid var(--gray-100); background: var(--gray-50); outline: none; font-size: 0.875rem; cursor: pointer; max-width: 130px; color: var(--gray-700); }
.search-box button { background: var(--primary); color: white; border: none; padding: 0.75rem 1.25rem; border-radius: 0 var(--radius) var(--radius) 0; cursor: pointer; font-size: 0.9375rem; font-weight: 600; white-space: nowrap; transition: all 0.2s; }
.search-box button:hover { background: var(--primary-dark); }

.btn-clear { background: var(--gray-100); color: var(--gray-600); padding: 0.75rem 1.25rem; border-radius: var(--radius); text-decoration: none; font-size: 0.9375rem; font-weight: 500; display: inline-block; border: 1px solid var(--gray-200); white-space: nowrap; transition: all 0.2s; }
.btn-clear:hover { background: var(--gray-200); color: var(--gray-900); }

/* MOBILE FIX: Stack elements if screen is small */
@media (max-width: 600px) {
    .search-group { max-width: 100%; }
    .search-box { flex-direction: column; gap: 5px; }
    .search-box input { border-radius: var(--radius); border: 1px solid var(--gray-200); width: 100%; box-sizing: border-box; }
    .search-box select { border-radius: var(--radius); border: 1px solid var(--gray-200); width: 100%; max-width: 100%; box-sizing: border-box; }
    .search-box button { border-radius: var(--radius); width: 100%; }
    .btn-clear { width: 100%; text-align: center; box-sizing: border-box; }
}

.table-box { background: white; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); overflow-x: auto; width: 100%; }
table { width: 100%; border-collapse: collapse; min-width: 900px; }
th { background: var(--gray-50); padding: 0.875rem 0.75rem; text-align: left; border-bottom: 1px solid var(--gray-200); font-size: 0.75rem; color: var(--gray-500); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
td { padding: 0.875rem 0.75rem; border-bottom: 1px solid var(--gray-100); font-size: 0.875rem; color: var(--gray-700); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--gray-50); }

.btn { padding: 0.4rem 0.75rem; border: none; border-radius: var(--radius); cursor: pointer; font-size: 0.8125rem; font-weight: 600; text-decoration: none; display: inline-block; transition: all 0.2s; margin-right: 0.3rem; }
.btn-primary { background: var(--primary); color: white; padding: 0.625rem 1.25rem; font-size: 0.9375rem; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-danger { background: #fee2e2; color: var(--danger); }
.btn-danger:hover { background: #fecaca; }
.btn-edit { background: #e0f2fe; color: #0369a1; }
.btn-edit:hover { background: #bae6fd; }
.btn-link { background: #dcfce7; color: #166534; }
.btn-link:hover { background: #bbf7d0; }

.badge { padding: 0.3rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.02em; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: var(--gray-100); color: var(--gray-500); }
.badge-count { background: var(--primary-lighter); color: var(--primary); border: 1px solid var(--primary-light); padding: 0.3rem 0.75rem; }

.modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(2px); }
.modal-content { background-color: white; margin: 8% auto; padding: 1.75rem; border: none; width: 90%; max-width: 550px; border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); }
.close { color: var(--gray-400); float: right; font-size: 2rem; font-weight: bold; cursor: pointer; line-height: 20px; }
.close:hover { color: var(--gray-700); }
.modal h2 { margin-top: 0; margin-bottom: 1.25rem; font-size: 1.375rem; color: var(--gray-900); }

.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.875rem; color: var(--gray-600); }
.form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid var(--gray-200); border-radius: var(--radius); box-sizing: border-box; font-size: 0.9375rem; transition: border-color 0.2s; font-family: inherit; }
.form-group input:focus, .form-group select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px var(--primary-light); }
.form-group small { display: block; margin-top: 0.375rem; color: var(--gray-500); font-size: 0.8125rem; }

.pagination { display: flex; justify-content: center; padding: 1.75rem; gap: 0.5rem; flex-wrap: wrap; }
.page-link { padding: 0.625rem 1rem; border: 1px solid var(--gray-200); color: var(--primary); text-decoration: none; border-radius: var(--radius); font-weight: 600; transition: all 0.2s; }
.page-link:hover { background: var(--gray-50); border-color: var(--gray-300); }
.page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
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
                    <h1>Tag Manager</h1>
                    <p>Manage post tags, SEO slugs, and linkages</p>
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

        <div class="stats-bar">
            <div>
                <span class="stat-item">Total Tags: <strong><?= $stats['total'] ?></strong></span>
                <span style="margin: 0 15px; color: #ddd;">|</span>
                <span class="stat-item">Total Tag Views: <strong><?= number_format($stats['total_views']) ?></strong></span>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">+ New Tag</button>
        </div>

        <div class="action-bar">
            <form class="search-group" method="GET">
                <div class="search-box">
                    <input type="text" name="search" placeholder="Search tags..." value="<?= htmlspecialchars($search) ?>">
                    <select name="sort">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most Used</option>
                        <option value="views" <?= $sort === 'views' ? 'selected' : '' ?>>Most Viewed</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name (A-Z)</option>
                    </select>
                    <button type="submit">Filter</button>
                </div>
            </form>
            
            <?php if (!empty($search)): ?>
                <a href="tag-manager.php" class="btn-clear">Clear Search</a>
            <?php endif; ?>
        </div>

        <div class="table-box">
            <table>
                <thead>
                    <tr>
                        <th width="5%">ID</th>
                        <th width="25%">Tag Name</th>
                        <th width="20%">Slug</th>
                        <th width="12%">Linked Posts</th>
                        <th width="10%">Views</th>
                        <th width="10%">Status</th>
                        <th width="18%" style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tags)): ?>
                        <tr><td colspan="7" style="text-align:center; padding: 50px; color: #888; font-size: 16px;">No tags found matching your criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tags as $tag): ?>
                            <tr>
                                <td>#<?= $tag['id'] ?></td>
                                <td><strong><?= htmlspecialchars($tag['name']) ?></strong></td>
                                <td style="color:#666;">/<?= htmlspecialchars($tag['slug']) ?></td>
                                <td>
                                    <?php if($tag['post_count'] > 0): ?>
                                        <span class="badge badge-count"><?= $tag['post_count'] ?> Posts</span>
                                    <?php else: ?>
                                        <span style="color:#bbb; font-size:13px;">Unused</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($tag['views']) ?></td>
                                <td>
                                    <?php if ($tag['is_active']): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button onclick="openLinkModal(<?= $tag['id'] ?>, '<?= addslashes($tag['name']) ?>')" class="btn btn-link" title="Link to Post">Link</button>
                                    <button onclick='openEditModal(<?= json_encode($tag) ?>)' class="btn btn-edit">Edit</button>
                                    
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete tag &quot;<?= $tag['name'] ?>&quot;? This will remove it from all linked posts.');">
                                        <input type="hidden" name="action" value="delete_tag">
                                        <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
                                        <button type="submit" class="btn btn-danger">Del</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        </div>
    </main>
</div>

<div id="addModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addModal')">&times;</span>
        <h2>Add New Tag</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_tag">
            <div class="form-group">
                <label>Tag Name *</label>
                <input type="text" name="tag_name" required placeholder="e.g. Technology">
            </div>
            <div class="form-group">
                <label>Slug (Optional)</label>
                <input type="text" name="tag_slug" placeholder="e.g. technology (leave blank to auto-generate)">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Create Tag</button>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editModal')">&times;</span>
        <h2>Edit Tag</h2>
        <form method="POST">
            <input type="hidden" name="action" value="edit_tag">
            <input type="hidden" name="tag_id" id="edit_id">
            
            <div class="form-group">
                <label>Tag Name</label>
                <input type="text" name="tag_name" id="edit_name" required>
            </div>
            <div class="form-group">
                <label>Slug</label>
                <input type="text" name="tag_slug" id="edit_slug" required>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="is_active" id="edit_status">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Update Tag</button>
        </form>
    </div>
</div>

<div id="linkModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('linkModal')">&times;</span>
        <h2>Link Tag to Post</h2>
        <p>Attach tag <strong><span id="link_tag_name"></span></strong> to a post.</p>
        <form method="POST">
            <input type="hidden" name="action" value="link_post">
            <input type="hidden" name="tag_id" id="link_tag_id">
            
            <div class="form-group">
                <label>Post ID *</label>
                <input type="number" name="post_id" required placeholder="Enter Post ID (e.g. 54)">
                <small>Enter the ID of the post you want to add this tag to.</small>
            </div>
            <button type="submit" class="btn btn-link" style="width:100%; color:#fff; background:#059669; padding: 12px;">Attach Tag</button>
        </form>
    </div>
</div>

<script>
    <?php if ($msg): ?>
        Toastify({
            text: "<?= addslashes($msg) ?>",
            duration: 3000,
            gravity: "top", 
            position: "center", 
            style: {
                background: "<?= $msg_type === 'success' ? '#10B981' : '#EF4444' ?>",
                borderRadius: "8px",
                fontSize: "16px",
                padding: "15px 25px"
            }
        }).showToast();
    <?php endif; ?>

    function openAddModal() {
        document.getElementById('addModal').style.display = "block";
    }

    function openEditModal(tag) {
        document.getElementById('edit_id').value = tag.id;
        document.getElementById('edit_name').value = tag.name;
        document.getElementById('edit_slug').value = tag.slug;
        document.getElementById('edit_status').value = tag.is_active;
        document.getElementById('editModal').style.display = "block";
    }

    function openLinkModal(id, name) {
        document.getElementById('link_tag_id').value = id;
        document.getElementById('link_tag_name').innerText = name;
        document.getElementById('linkModal').style.display = "block";
    }

    function closeModal(id) {
        document.getElementById(id).style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = "none";
        }
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }

    window.addEventListener('resize', () => {
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
</script>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>


</body>
</html>
