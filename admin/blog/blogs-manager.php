<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

 if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['admin', 'editor', 'author'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

$stmt = $pdo->prepare("SELECT permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);

$states_list = $pdo->query("SELECT id, state_name FROM states ORDER BY state_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$cats_list = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$state_filter = isset($_GET['state']) ? $_GET['state'] : 'all';
$cat_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$csrf = csrfToken();
$log_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$log_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Deletes a single post: media files, post_meta and the row itself, and logs the action.
function deleteOnePost(PDO $pdo, int $delete_id, string $log_ip, string $log_ua): array {
    $ownership_sql = $_SESSION['role'] !== 'admin' ? " AND a.user_id = " . (int)$_SESSION['user_id'] : "";
    $stmt = $pdo->prepare("SELECT p.title, p.featured_image_id FROM posts p JOIN authors a ON p.author_id = a.id WHERE p.id = ?" . $ownership_sql);
    $stmt->execute([$delete_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$post) {
        return [false, 'Post not found or you do not have permission to delete it.'];
    }

    $stmt = $pdo->prepare("SELECT id, file_path, responsive_set FROM media WHERE post_id=? OR id=?");
    $stmt->execute([$delete_id, $post['featured_image_id'] ?? 0]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (!empty($m['file_path']) && file_exists($m['file_path'])) @unlink($m['file_path']);
        if (!empty($m['responsive_set'])) {
            foreach (json_decode($m['responsive_set'], true) ?: [] as $v)
                if (!empty($v) && file_exists($v)) @unlink($v);
        }
    }
    $pdo->prepare("DELETE FROM media WHERE post_id=? OR id=?")->execute([$delete_id, $post['featured_image_id'] ?? 0]);
    $pdo->prepare("DELETE FROM post_meta WHERE post_id = ?")->execute([$delete_id]);
    $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$delete_id]);

    $log_desc = "Deleted Post: " . mb_strimwidth($post['title'] ?? 'Unknown', 0, 50, "...") . " (ID: $delete_id)";
    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'post_delete', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], $log_desc, $log_ip, $log_ua]);

    return [true, $post['title'] ?? ''];
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if (!csrfGetValid()) {
        http_response_code(403);
        exit('Security error: invalid or missing token. Please go back and try again.');
    }
    $delete_id = (int)$_GET['delete'];
    try {
        $pdo->beginTransaction();
        [$ok, $msg] = deleteOnePost($pdo, $delete_id, $log_ip, $log_ua);
        if (!$ok) {
            $pdo->rollBack();
            $error = $msg;
        } else {
            $pdo->commit();

            $redirectUrl = "blogs-manager.php?success=deleted";
            if ($status_filter !== 'all') $redirectUrl .= '&status=' . urlencode($status_filter);
            if (!empty($search_query)) $redirectUrl .= '&search=' . urlencode($search_query);
            if ($state_filter !== 'all') $redirectUrl .= '&state=' . $state_filter;
            if ($cat_filter !== 'all') $redirectUrl .= '&category=' . $cat_filter;
            if (!empty($_GET['per_page'])) $redirectUrl .= '&per_page=' . (int)$_GET['per_page'];

            header("Location: " . $redirectUrl);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Failed to delete post: " . $e->getMessage();
    }
}

// Bulk delete — same per-post logic as above, run inside its own transaction per post
// so one bad row doesn't abort the whole batch.
if (isset($_GET['bulk_delete']) && !empty($_GET['bulk_delete'])) {
    if (!csrfGetValid()) {
        http_response_code(403);
        exit('Security error: invalid or missing token. Please go back and try again.');
    }
    $ids_to_delete = array_filter(array_map('intval', explode(',', $_GET['bulk_delete'])), fn($id) => $id > 0);
    $deleted_count = 0;
    foreach ($ids_to_delete as $delete_id) {
        try {
            $pdo->beginTransaction();
            [$ok] = deleteOnePost($pdo, $delete_id, $log_ip, $log_ua);
            if ($ok) {
                $pdo->commit();
                $deleted_count++;
            } else {
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    $redirectUrl = "blogs-manager.php?success=bulk_deleted&count=" . $deleted_count;
    if ($status_filter !== 'all') $redirectUrl .= '&status=' . urlencode($status_filter);
    if (!empty($search_query)) $redirectUrl .= '&search=' . urlencode($search_query);
    if ($state_filter !== 'all') $redirectUrl .= '&state=' . $state_filter;
    if ($cat_filter !== 'all') $redirectUrl .= '&category=' . $cat_filter;
    if (!empty($_GET['per_page'])) $redirectUrl .= '&per_page=' . (int)$_GET['per_page'];

    header("Location: " . $redirectUrl);
    exit;
}

$allowed_per_page = [10, 20, 50, 100];
$posts_per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowed_per_page, true) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $posts_per_page;

$conditions = [];
$params = [];

if ($_SESSION['role'] !== 'admin') {
    $conditions[] = "a.user_id = :user_id";
    $params[':user_id'] = $_SESSION['user_id'];
}

if (!empty($search_query)) {
    // NOTE: with PDO::ATTR_EMULATE_PREPARES = false, the same named placeholder
    // cannot be reused more than once in a query — each occurrence needs its
    // own bound value, or the query silently fails. Use one placeholder per spot.
    $conditions[] = "(p.title LIKE :search1 OR p.content LIKE :search2 OR a.name LIKE :search3)";
    $search_like = '%' . $search_query . '%';
    $params[':search1'] = $search_like;
    $params[':search2'] = $search_like;
    $params[':search3'] = $search_like;
}
if ($state_filter !== 'all' && is_numeric($state_filter)) {
    $conditions[] = "p.state_id = :state";
    $params[':state'] = $state_filter;
}
if ($cat_filter !== 'all' && is_numeric($cat_filter)) {
    $conditions[] = "p.category_id = :cat";
    $params[':cat'] = $cat_filter;
}

// Base conditions (everything except status) — used to compute per-status counts for the status tabs
$base_where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
$status_counts_stmt = $pdo->prepare("SELECT p.status, COUNT(*) AS cnt FROM posts p JOIN authors a ON p.author_id = a.id $base_where GROUP BY p.status");
foreach ($params as $key => $val) {
    $status_counts_stmt->bindValue($key, $val);
}
$status_counts_stmt->execute();
$status_counts_raw = $status_counts_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$count_published = (int)($status_counts_raw['published'] ?? 0);
$count_draft = (int)($status_counts_raw['draft'] ?? 0);
$count_archived = (int)($status_counts_raw['archived'] ?? 0);
$count_all = $count_published + $count_draft + $count_archived;

if ($status_filter !== 'all' && $status_filter !== '') {
    $conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}

$where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

if ($status_filter === 'all' || $status_filter === '') {
    $total_posts = $count_all;
} elseif (isset($status_counts_raw[$status_filter]) || in_array($status_filter, ['published', 'draft', 'archived'], true)) {
    $total_posts = (int)($status_counts_raw[$status_filter] ?? 0);
} else {
    $count_query = "SELECT COUNT(*) FROM posts p JOIN authors a ON p.author_id = a.id $where";
    $count_stmt = $pdo->prepare($count_query);
    foreach ($params as $key => $val) {
        $count_stmt->bindValue($key, $val);
    }
    $count_stmt->execute();
    $total_posts = $count_stmt->fetchColumn();
}
$total_pages = ceil($total_posts / $posts_per_page);

$query = "
    SELECT 
        p.*, 
        a.name as author_name, 
        m.file_path as banner_image, 
        m.alt_text as banner_alt, 
        COALESCE(v.views, 0) AS views 
    FROM posts p 
    JOIN authors a ON p.author_id = a.id 
    LEFT JOIN media m ON p.featured_image_id = m.id 
    LEFT JOIN post_views v ON p.id = v.post_id 
    $where 
    ORDER BY p.date DESC 
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $posts_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

function buildUrl(array $overrides = []): string {
    global $status_filter, $state_filter, $cat_filter, $search_query, $posts_per_page;
    $base = ['status' => $status_filter, 'state' => $state_filter, 'category' => $cat_filter, 'search' => $search_query, 'per_page' => $posts_per_page];
    $merged = array_merge($base, $overrides);
    $filtered = array_filter($merged, function ($v, $k) {
        if ($k === 'per_page') return (int)$v !== 10;
        return $v !== '' && $v !== 'all' && $v !== null;
    }, ARRAY_FILTER_USE_BOTH);
    return 'blogs-manager.php' . (!empty($filtered) ? '?' . http_build_query($filtered) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Posts - <?= htmlspecialchars($site_name) ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'" crossorigin="anonymous">
    
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
    --success-light: #ecfdf5;
    --warning: #f59e0b;
    --warning-light: #fef3c7;
    --danger: #ef4444;
    --danger-light: #fef2f2;
    --info: #3b82f6;
    --info-light: #eff6ff;
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
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

/* Alerts */
.alert { padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.875rem; font-size: 0.9375rem; border: 1px solid transparent; }
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* Toolbar */
.toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; }
.toolbar-title { font-size: 1.125rem; font-weight: 700; color: var(--gray-900); }
.toolbar-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.625rem 1.25rem; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124,58,237,0.25); }
.btn-secondary { background: var(--gray-100); color: var(--gray-700); }
.btn-secondary:hover { background: var(--gray-200); }

.btn-danger { background: var(--danger); color: white; }
.btn-danger:hover { background: #dc2626; }
.btn-sm { padding: 0.45rem 0.9rem; font-size: 0.8125rem; }

/* Bulk-select bar */
.bulk-bar { display: none; align-items: center; gap: 0.75rem; flex-wrap: wrap; background: var(--primary-lighter); border: 1px solid var(--primary-light); color: var(--primary-dark); padding: 0.75rem 1rem; border-radius: var(--radius-lg); margin-bottom: 1rem; }
.bulk-bar.active { display: flex; }
.bulk-bar .bulk-count { font-weight: 700; }
.bulk-check-cell { display: none; width: 36px; text-align: center; }
.bulk-check-cell.show { display: table-cell; }
th.bulk-check-cell.show { display: table-cell; }
.row-check, .select-all-check { width: 17px; height: 17px; cursor: pointer; accent-color: var(--primary); }
.mpc-check-wrap { display: none; align-items: flex-start; padding-top: 2px; }
.mpc-check-wrap.show { display: flex; }

/* Filter section */
.filter-section { background: white; border-radius: var(--radius-xl); border: 1px solid var(--gray-100); box-shadow: var(--shadow-sm); padding: 1.25rem; margin-bottom: 1.5rem; }
.advanced-filters { display: grid; gap: 1rem; }
@media (min-width: 768px) { .advanced-filters { grid-template-columns: repeat(3, 1fr) 110px auto; align-items: end; } }
.filter-group { display: flex; flex-direction: column; gap: 0.5rem; }
.filter-group label { font-size: 0.8125rem; font-weight: 600; color: var(--gray-600); }
.filter-control { padding: 0.625rem 0.875rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: 0.9375rem; background: white; color: var(--gray-800); transition: all 0.2s; width: 100%; }
.filter-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
.filter-actions { display: flex; gap: 0.75rem; }
.search-info { padding: 0.875rem 1.25rem; background: var(--primary-lighter); border-radius: var(--radius-lg); margin-top: 1rem; color: var(--primary-dark); font-size: 0.9375rem; font-weight: 500; }
.search-info strong { color: var(--primary); }

/* Status Tabs */
.post-status-tabs { display: flex; align-items: center; gap: 0.5rem; overflow-x: auto; padding-bottom: 0.75rem; margin-bottom: 1rem; border-bottom: 1px solid var(--gray-100); scrollbar-width: none; }
.post-status-tabs::-webkit-scrollbar { display: none; }
.pst-link { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; background: white; border: 1px solid var(--gray-200); border-radius: 9999px; font-size: 0.8125rem; font-weight: 500; color: var(--gray-600); text-decoration: none; white-space: nowrap; transition: all 0.2s; }
.pst-link:hover { background: var(--gray-50); color: var(--gray-900); }
.pst-link.active { background: var(--primary); color: white; border-color: var(--primary); }
.pst-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; background: var(--gray-100); border-radius: 10px; font-size: 0.7rem; font-weight: 700; color: var(--gray-600); }
.pst-link.active .pst-count { background: rgba(255,255,255,.25); color: white; }

/* "Add New Post" — nicer CTA than the flat default .btn-primary */
.btn-add-post { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.2rem 0.6rem 1rem; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white !important; font-weight: 600; font-size: 0.875rem; letter-spacing: 0.01em; border-radius: 9999px; border: none; white-space: nowrap; cursor: pointer; text-decoration: none; transition: all 0.2s; }
.btn-add-post:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124,58,237,0.3); }
.btn-add-post .add-post-icon { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,0.22); font-size: 0.65rem; flex-shrink: 0; }

/* Table card (desktop / tablet) */
.table-card { background: white; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow: hidden; }
.table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead { background: var(--gray-50); }
.data-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
.data-table td { padding: 0.875rem 1rem; font-size: 0.875rem; color: var(--gray-700); border-bottom: 1px solid var(--gray-100); background: white; vertical-align: middle; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover td { background: var(--gray-50); }
.text-muted { color: var(--gray-500); }

.post-thumb { width: 44px; height: 44px; border-radius: var(--radius); object-fit: cover; border: 1px solid var(--gray-100); display: block; }
.post-thumb-placeholder { width: 44px; height: 44px; border-radius: var(--radius); background: var(--primary-lighter); display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 700; color: var(--primary); }
.dt-title-row { display: flex; align-items: center; gap: 0.75rem; }
.dt-title-info { min-width: 0; }
.dt-title-info .pt-title { font-weight: 600; color: var(--gray-900); text-decoration: none; font-size: 0.875rem; line-height: 1.35; display: block; transition: color 0.12s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 400px; }
.dt-title-info .pt-title:hover { color: var(--primary); }
.dt-title-info .pt-author { font-size: 0.75rem; color: var(--gray-400); margin-top: 2px; }
@media (max-width: 1100px) { .dt-title-info .pt-title { max-width: 260px; } }

/* Title cell reserves space for a hover-reveal action row underneath, so row height never jumps */
.dt-title-td { position: relative; padding-bottom: 2.75rem !important; background: white; }
.row-actions { position: absolute; left: 1rem; right: 1rem; bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding-top: 0.4rem; border-top: 1px solid var(--gray-100); opacity: 0; pointer-events: none; transition: opacity 0.15s ease; }
.data-table tbody tr:hover .row-actions, .data-table tbody tr:focus-within .row-actions { opacity: 1; pointer-events: auto; }
.ra-link { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; font-weight: 600; padding: 0.3rem 0.7rem; border-radius: 6px; border: none; cursor: pointer; text-decoration: none; font-family: inherit; background: none; transition: filter 0.12s; }
.ra-link:hover { filter: brightness(0.94); }
.ra-edit { color: var(--gray-700); background: var(--gray-100); }
.ra-view { color: var(--primary); background: var(--primary-light); }
.ra-share { color: var(--success); background: var(--success-light); }
.ra-delete { color: var(--danger); background: var(--danger-light); }

/* Status badge (used in table + mobile card) */
.badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.75rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }
.badge-published { background: #d1fae5; color: #065f46; }
.badge-draft { background: #fef3c7; color: #92400e; }
.badge-archived { background: #e0e7ff; color: #3730a3; }

/* Pagination footer inside table-card */
.tc-pagination { display: flex; align-items: center; gap: 0.375rem; padding: 0.875rem 1.25rem; border-top: 1px solid var(--gray-100); background: var(--gray-50); flex-wrap: wrap; }
.tc-pagination .pp-info { color: var(--gray-500); font-size: 0.8125rem; margin-right: auto; }

/* Mobile card list — real responsive rebuild, not just hidden columns */
.mobile-post-list { display: none; flex-direction: column; gap: 0.75rem; }
.mobile-post-card { display: flex; flex-direction: column; padding: 0.875rem; background: white; border: 1px solid var(--gray-100); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); }
.mpc-top { display: flex; gap: 0.625rem; align-items: flex-start; }
.mpc-thumb { width: 48px; height: 48px; border-radius: var(--radius); object-fit: cover; border: 1px solid var(--gray-100); flex-shrink: 0; }
.mpc-thumb-placeholder { width: 48px; height: 48px; border-radius: var(--radius); background: var(--primary-lighter); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.125rem; flex-shrink: 0; }
.mpc-info { min-width: 0; flex: 1; }
.mpc-info .pt-title { font-weight: 600; color: var(--gray-900); text-decoration: none; font-size: 0.9375rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.mpc-meta-row { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap; }
.mpc-author { font-size: 0.75rem; color: var(--gray-400); }
.mpc-date { font-size: 0.7rem; color: var(--gray-500); display: inline-flex; align-items: center; gap: 0.25rem; white-space: nowrap; }
.mpc-views { font-size: 0.7rem; color: var(--gray-500); display: inline-flex; align-items: center; gap: 0.25rem; white-space: nowrap; }
.mpc-actions { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--gray-100); }
.mpc-actions .ra-link { flex: 1; justify-content: center; }
@media (max-width: 767px) {
    .table-card { display: none; }
    .mobile-post-list { display: flex; }
}

/* Empty state */
.empty-state { text-align: center; padding: 4rem 2rem; background: white; border-radius: var(--radius-xl); border: 1px solid var(--gray-100); box-shadow: var(--shadow-sm); }
.empty-icon { width: 96px; height: 96px; background: var(--primary-lighter); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 1.5rem; }
.empty-state h3 { font-size: 1.25rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.5rem; }
.empty-state p { color: var(--gray-500); margin-bottom: 1.5rem; }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 0.375rem; margin-top: 2rem; flex-wrap: wrap; }
.page-link { min-width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; transition: all 0.2s; border: 1px solid var(--gray-200); background: white; color: var(--gray-700); padding: 0 0.75rem; }
.page-link:hover { background: var(--gray-50); border-color: var(--gray-300); }
.page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
.page-link.disabled { color: var(--gray-300); cursor: not-allowed; pointer-events: none; }
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
                <div class="page-heading">
                    <h1>All Posts</h1>
                    <p>Create, edit, and organize your content</p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" style="background:var(--primary-lighter);color:var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

    <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span>Post deleted successfully!</span></div>
    <?php endif; ?>
    <?php if (isset($_GET['success']) && $_GET['success'] === 'bulk_deleted'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= (int)($_GET['count'] ?? 0) ?> post(s) deleted successfully!</span></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <!-- Toolbar -->
    <div class="toolbar">
        <div>
            <div class="toolbar-title">All Posts</div>
        </div>
        <div class="toolbar-actions">
            <?php if (!empty($posts)): ?>
            <button type="button" id="bulkToggleBtn" onclick="toggleBulkMode()" class="btn btn-secondary btn-sm"><i class="fas fa-check-square"></i> Bulk Select</button>
            <?php endif; ?>
            <a href="/admin/blog/post-manager.php" class="btn-add-post"><span class="add-post-icon"><i class="fas fa-plus"></i></span> Add New Post</a>
        </div>
    </div>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulkBar">
        <span><i class="fas fa-check-square"></i> <span class="bulk-count" id="bulkCount">0</span> selected</span>
        <button type="button" id="bulkDeleteBtn" onclick="bulkDeleteSelected()" class="btn btn-danger btn-sm" disabled style="opacity:0.5;"><i class="fas fa-trash"></i> Delete Selected</button>
        <button type="button" onclick="clearBulkSelection()" class="btn btn-secondary btn-sm">Clear Selection</button>
    </div>

    <!-- Status Tabs -->
    <div class="post-status-tabs">
        <a href="<?= buildUrl(['status' => 'all', 'page' => '']) ?>" class="pst-link <?= $status_filter === 'all' ? 'active' : '' ?>">
            All <span class="pst-count"><?= $count_all ?></span>
        </a>
        <a href="<?= buildUrl(['status' => 'published', 'page' => '']) ?>" class="pst-link <?= $status_filter === 'published' ? 'active' : '' ?>">
            Published <span class="pst-count"><?= $count_published ?></span>
        </a>
        <a href="<?= buildUrl(['status' => 'draft', 'page' => '']) ?>" class="pst-link <?= $status_filter === 'draft' ? 'active' : '' ?>">
            Drafts <span class="pst-count"><?= $count_draft ?></span>
        </a>
        <a href="<?= buildUrl(['status' => 'archived', 'page' => '']) ?>" class="pst-link <?= $status_filter === 'archived' ? 'active' : '' ?>">
            Archived <span class="pst-count"><?= $count_archived ?></span>
        </a>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <div class="advanced-filters">
                <div class="filter-group">
                    <label>State</label>
                    <select name="state" class="filter-control">
                        <option value="all">All States</option>
                        <?php foreach ($states_list as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= $state_filter == $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars($st['state_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Category</label>
                    <select name="category" class="filter-control">
                        <option value="all">All Categories</option>
                        <?php foreach ($cats_list as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat_filter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Title, content, author..." value="<?= htmlspecialchars($search_query) ?>" class="filter-control" autocomplete="off">
                </div>
                <div class="filter-group">
                    <label>Per Page</label>
                    <select name="per_page" class="filter-control" onchange="this.form.submit()">
                        <?php foreach ($allowed_per_page as $pp): ?>
                            <option value="<?= $pp ?>" <?= $posts_per_page == $pp ? 'selected' : '' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($search_query) || $state_filter !== 'all' || $cat_filter !== 'all'): ?>
                        <a href="<?= buildUrl(['search' => '', 'state' => 'all', 'category' => 'all', 'page' => '']) ?>" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($search_query) || $state_filter !== 'all' || $cat_filter !== 'all'): ?>
                <div class="search-info">Found <strong><?= $total_posts ?></strong> result<?= $total_posts !== 1 ? 's' : '' ?><?php if (!empty($search_query)): ?> for "<strong><?= htmlspecialchars($search_query) ?></strong>"<?php endif; ?></div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Posts -->
    <?php if (empty($posts)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-file-alt"></i></div>
            <h3><?= !empty($search_query) ? 'No results found' : 'No posts yet' ?></h3>
            <p><?= !empty($search_query) ? 'Try a different search term.' : 'Start by creating your first blog post.' ?></p>
            <a href="/admin/blog/post-manager.php" class="btn-add-post"><span class="add-post-icon"><i class="fas fa-plus"></i></span> Add New Post</a>
        </div>
    <?php else: ?>

        <!-- Desktop / tablet table -->
        <div class="table-card">
            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="bulk-check-cell" id="selectAllCell"><input type="checkbox" id="selectAllCheck" class="select-all-check" onchange="toggleSelectAll(this)" title="Select all"></th>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Status</th>
                        <th>Views</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $post): ?>
                    <tr>
                        <td class="bulk-check-cell"><input type="checkbox" class="row-check" value="<?= $post['id'] ?>" onchange="onRowCheckChange()"></td>
                        <td class="dt-title-td">
                            <div class="dt-title-row">
                                <?php if ($post['banner_image']): ?>
                                <img src="/<?= htmlspecialchars($post['banner_image']) ?>" alt="<?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?>" class="post-thumb" loading="lazy">
                                <?php else: ?>
                                <div class="post-thumb-placeholder"><?= strtoupper(substr($post['title'], 0, 1)) ?></div>
                                <?php endif; ?>
                                <div class="dt-title-info">
                                    <a href="/admin/blog/post-manager.php?id=<?= $post['id'] ?>" class="pt-title"><?= htmlspecialchars(mb_strimwidth($post['title'], 0, 70, '…')) ?></a>
                                    <div class="pt-author"><?= htmlspecialchars($post['author_name']) ?></div>
                                </div>
                            </div>
                            <div class="row-actions">
                                <a href="/admin/blog/post-manager.php?id=<?= $post['id'] ?>" class="ra-link ra-edit"><i class="fas fa-pen"></i> Edit</a>
                                <a href="<?= postUrl($post['slug'], $post['id']) ?>" target="_blank" class="ra-link ra-view"><i class="fas fa-eye"></i> View</a>
                                <button type="button" onclick="sharePost('https://careerdiksha.co.in<?= postUrl($post['slug'], $post['id']) ?>','<?= htmlspecialchars(addslashes($post['title'])) ?>')" class="ra-link ra-share"><i class="fas fa-share-alt"></i> Share</button>
                                <button type="button" onclick="confirmDelete(<?= $post['id'] ?>,'<?= htmlspecialchars(addslashes($post['title'])) ?>')" class="ra-link ra-delete"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($post['author_name']) ?></td>
                        <td><span class="badge badge-<?= $post['status'] ?>"><?= ucfirst($post['status']) ?></span></td>
                        <td class="text-muted"><i class="fas fa-eye" style="font-size:0.7rem; margin-right:3px;"></i><?= formatViews($post['views']) ?></td>
                        <td class="text-muted" style="white-space:nowrap;"><i class="fas fa-calendar" style="font-size:0.7rem; margin-right:3px;"></i><?= date('M j, Y', strtotime($post['date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="tc-pagination">
                <span class="pp-info">Showing <?= $offset + 1 ?>–<?= min($offset + $posts_per_page, $total_posts) ?> of <?= $total_posts ?> posts</span>
                <a href="<?= buildUrl(['page' => 1]) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" title="First">&laquo;</a>
                <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" title="Prev">&lsaquo;</a>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <a href="<?= buildUrl(['page' => $i]) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Next">&rsaquo;</a>
                <a href="<?= buildUrl(['page' => $total_pages]) ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Last">&raquo;</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Mobile card list -->
        <div class="mobile-post-list">
            <?php foreach ($posts as $post): ?>
            <div class="mobile-post-card">
                <div class="mpc-top">
                    <div class="mpc-check-wrap" id="mpcCheckWrap-<?= $post['id'] ?>"><input type="checkbox" class="row-check" value="<?= $post['id'] ?>" onchange="onRowCheckChange()"></div>
                    <?php if ($post['banner_image']): ?>
                    <img src="/<?= htmlspecialchars($post['banner_image']) ?>" alt="<?= htmlspecialchars($post['banner_alt'] ?: $post['title']) ?>" class="mpc-thumb" loading="lazy">
                    <?php else: ?>
                    <div class="mpc-thumb-placeholder"><?= strtoupper(substr($post['title'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="mpc-info">
                        <a href="/admin/blog/post-manager.php?id=<?= $post['id'] ?>" class="pt-title"><?= htmlspecialchars(mb_strimwidth($post['title'], 0, 90, '…')) ?></a>
                        <div class="mpc-meta-row">
                            <span class="mpc-author"><?= htmlspecialchars($post['author_name']) ?></span>
                            <span class="badge badge-<?= $post['status'] ?>"><?= ucfirst($post['status']) ?></span>
                            <span class="mpc-date"><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($post['date'])) ?></span>
                            <span class="mpc-views"><i class="fas fa-eye"></i> <?= formatViews($post['views']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="mpc-actions">
                    <a href="/admin/blog/post-manager.php?id=<?= $post['id'] ?>" class="ra-link ra-edit"><i class="fas fa-pen"></i> Edit</a>
                    <a href="<?= postUrl($post['slug'], $post['id']) ?>" target="_blank" class="ra-link ra-view"><i class="fas fa-eye"></i> View</a>
                    <button type="button" onclick="sharePost('https://careerdiksha.co.in<?= postUrl($post['slug'], $post['id']) ?>','<?= htmlspecialchars(addslashes($post['title'])) ?>')" class="ra-link ra-share"><i class="fas fa-share-alt"></i></button>
                    <button type="button" onclick="confirmDelete(<?= $post['id'] ?>,'<?= htmlspecialchars(addslashes($post['title'])) ?>')" class="ra-link ra-delete"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
            <div class="tc-pagination" style="border-radius:var(--radius-lg); justify-content:center; border:1px solid var(--gray-100);">
                <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" title="Prev">&lsaquo;</a>
                <span class="pp-info" style="margin:0 0.5rem;">Page <?= $page ?> of <?= $total_pages ?></span>
                <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="page-link <?= $page >= $total_pages ? 'disabled' : '' ?>" title="Next">&rsaquo;</a>
            </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

        </div>
    </main>
</div>

<footer>
    <div style="padding: 10px 20px; text-align: center; color: #6C6C6C;">
        <p style="font-size:0.8rem; margin-bottom:0px;">&copy; 2025-2026 <?= htmlspecialchars($site_name) ?>. All rights reserved.</p>
    </div>
</footer>

<script src="/assets/js/share.min.js?v=161125"></script>
<script>
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
let touchStartX = 0;
document.getElementById('sidebar').addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX);
document.getElementById('sidebar').addEventListener('touchend', e => { if (touchStartX - e.changedTouches[0].screenX > 100) toggleSidebar(); });

const CSRF_TOKEN = <?= json_encode($csrf) ?>;

function confirmDelete(postId, postTitle) {
    if (confirm(`Are you sure you want to delete "${postTitle}"?\n\nThis action cannot be undone!`)) {
        const params = new URLSearchParams(window.location.search);
        params.set('delete', postId);
        params.set('token', CSRF_TOKEN);
        window.location.href = 'blogs-manager.php?' + params.toString();
    }
}

<?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
Toastify({ text: "Post deleted successfully!", duration: 3000, gravity: "top", position: "right", backgroundColor: "linear-gradient(to right, #00b09b, #96c93d)" }).showToast();
<?php elseif (isset($_GET['success']) && $_GET['success'] === 'bulk_deleted'): ?>
Toastify({ text: "<?= (int)($_GET['count'] ?? 0) ?> post(s) deleted successfully!", duration: 3000, gravity: "top", position: "right", backgroundColor: "linear-gradient(to right, #00b09b, #96c93d)" }).showToast();
<?php endif; ?>

/* ── Bulk select ─────────────────────────────────────────────── */
let bulkModeActive = false;

function toggleBulkMode() {
    bulkModeActive = !bulkModeActive;
    document.querySelectorAll('.bulk-check-cell').forEach(el => el.classList.toggle('show', bulkModeActive));
    document.querySelectorAll('.mpc-check-wrap').forEach(el => el.classList.toggle('show', bulkModeActive));
    document.getElementById('bulkBar').classList.toggle('active', bulkModeActive);
    const btn = document.getElementById('bulkToggleBtn');
    if (btn) btn.classList.toggle('btn-primary', bulkModeActive);
    if (!bulkModeActive) clearBulkSelection();
}

function toggleSelectAll(checkbox) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = checkbox.checked);
    onRowCheckChange();
}

function onRowCheckChange() {
    const checked = document.querySelectorAll('.row-check:checked');
    const total = document.querySelectorAll('.row-check').length;
    const countEl = document.getElementById('bulkCount');
    const delBtn = document.getElementById('bulkDeleteBtn');
    countEl.textContent = checked.length;
    delBtn.disabled = checked.length === 0;
    delBtn.style.opacity = checked.length === 0 ? '0.5' : '1';
    const selectAll = document.getElementById('selectAllCheck');
    if (selectAll) selectAll.checked = total > 0 && checked.length === total;
}

function clearBulkSelection() {
    document.querySelectorAll('.row-check').forEach(c => c.checked = false);
    const selectAll = document.getElementById('selectAllCheck');
    if (selectAll) selectAll.checked = false;
    onRowCheckChange();
}

function bulkDeleteSelected() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.value);
    if (ids.length === 0) return;
    if (confirm(`Are you sure you want to delete ${ids.length} selected post(s)?\n\nThis action cannot be undone!`)) {
        const params = new URLSearchParams(window.location.search);
        params.set('bulk_delete', ids.join(','));
        params.set('token', CSRF_TOKEN);
        window.location.href = 'blogs-manager.php?' + params.toString();
    }
}

function sharePost(url, title) {
    window.sharePopup.show({ url, title, text: 'Checkout this CareerJyoti Vacancy Updates!' });
}

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(el => {
            el.style.transition = 'opacity .4s ease, transform .4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-5px)';
            setTimeout(() => el.remove(), 400);
        });
    }, 3000);
    const url = new URL(window.location.href);
    ['success','error'].forEach(p => url.searchParams.delete(p));
    window.history.replaceState({}, document.title, url.pathname + url.search);
});
</script>
</body>
</html>