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
    empty($permissions['security']['view_logs'])
) {
    exit('Access Denied');
}

// ─── CSRF ────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_check() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        die('CSRF check failed.');
    }
}

$notifications = [];

// Pagination setup
$logs_per_page = 20; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $logs_per_page;

// Filtering (also doubles as the scope for the "Clear" button below —
// whatever is currently filtered is exactly what gets deleted)
$action_filter = isset($_GET['action']) ? $_GET['action'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build Query Conditions
$where_clauses = ["1=1"];
$params = [];

if ($action_filter !== 'all') {
    $where_clauses[] = "l.action_type = :action";
    $params[':action'] = $action_filter;
}

if (!empty($search_query)) {
    // Native prepares (PDO::ATTR_EMULATE_PREPARES => false) don't allow the same
    // named placeholder to repeat in one query, so each occurrence gets its own name.
    $where_clauses[] = "(l.description LIKE :search1 OR l.ip_address LIKE :search2 OR u.username LIKE :search3)";
    $search_like = '%' . $search_query . '%';
    $params[':search1'] = $search_like;
    $params[':search2'] = $search_like;
    $params[':search3'] = $search_like;
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// ── Handle Clear (deletes exactly what the current filter/search shows) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['clear_action'] ?? '') === 'clear_filtered') {
    csrf_check();
    try {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id $where_sql");
        $count_stmt->execute($params);
        $cleared_count = (int)$count_stmt->fetchColumn();

        $pdo->prepare("DELETE l FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id $where_sql")->execute($params);

        $scope_bits = [];
        if ($action_filter !== 'all') $scope_bits[] = "action={$action_filter}";
        if ($search_query !== '') $scope_bits[] = "search=\"{$search_query}\"";
        $scope_label = $scope_bits ? implode(', ', $scope_bits) : 'all logs';

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'logs_clear', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Cleared activity logs ({$scope_label}) — {$cleared_count} entries removed", $log_ip, $log_ua]);

        $redirect_params = $_GET;
        $redirect_params['success'] = 'cleared';
        header("Location: /admin/activity-logs.php?" . http_build_query($redirect_params));
        exit;
    } catch (Exception $e) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Clear failed: ' . $e->getMessage()];
    }
}

// ── Success / Error flash messages ─────────────────────────────────────────
if (isset($_GET['success']) && $_GET['success'] === 'cleared') {
    $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'Matching activity logs have been cleared.'];
}

// 1. Get Distinct Action Types for Dropdown
$actions_stmt = $pdo->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type ASC");
$existing_actions = $actions_stmt->fetchAll(PDO::FETCH_COLUMN);

// 2. Count Total Logs (matching current filter — also the "Clear" scope count)
// We join users here too in case we are filtering by username
$count_sql = "SELECT COUNT(*) FROM activity_logs l LEFT JOIN users u ON l.user_id = u.id " . $where_sql;
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_logs = $count_stmt->fetchColumn();
$total_pages = ceil($total_logs / $logs_per_page);

// 3. Fetch Logs (JOINED WITH USERS TABLE)
$query = "
    SELECT 
        l.*, 
        u.username, 
        u.role,
        u.email
    FROM activity_logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    $where_sql 
    ORDER BY l.created_at DESC 
    LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $logs_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for status colors
function getActionColor($action) {
    if (strpos($action, 'delete') !== false) return 'danger';
    if (strpos($action, 'create') !== false) return 'success';
    if (strpos($action, 'update') !== false) return 'warning';
    if (strpos($action, 'login') !== false) return 'info';
    return 'default';
}
$username = $_SESSION['username'];

// ── Render table rows + pagination once, so both the normal page load and
//    the live-search AJAX response (below) use the exact same markup ─────────
function renderLogsTbody(array $logs) {
    ob_start();
    if (empty($logs)) {
        ?>
        <tr>
            <td colspan="6" class="empty-state">
                No logs found.
            </td>
        </tr>
        <?php
    } else {
        foreach ($logs as $log) {
            ?>
            <tr>
                <td>#<?= $log['id'] ?></td>
                <td>
                    <?php if ($log['username']): ?>
                        <strong><?= htmlspecialchars($log['username']) ?></strong>
                        <br>
                        <span class="role-badge"><?= ucfirst(htmlspecialchars($log['role'])) ?></span>
                    <?php else: ?>
                        <strong>System/Guest</strong>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= getActionColor($log['action_type']) ?>">
                        <?= htmlspecialchars($log['action_type']) ?>
                    </span>
                </td>
                <td>
                    <?= htmlspecialchars($log['description']) ?>
                </td>
                <td class="log-meta">
                    <span class="ip-addr">IP: <?= htmlspecialchars($log['ip_address']) ?></span>
                    <span class="ua-string" title="<?= htmlspecialchars($log['user_agent']) ?>">
                        <?= htmlspecialchars($log['user_agent']) ?>
                    </span>
                </td>
                <td>
                    <?= date('M j, Y', strtotime($log['created_at'])) ?><br>
                    <span style="font-size: 11px; color: #888;"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                </td>
            </tr>
            <?php
        }
    }
    return ob_get_clean();
}

function renderLogsPagination($page, $total_pages, $action_filter, $search_query) {
    if ($total_pages <= 1) return '';
    ob_start();
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1&action=<?= $action_filter ?>&search=<?= urlencode($search_query) ?>">&laquo; First</a>
            <a href="?page=<?= $page - 1 ?>&action=<?= $action_filter ?>&search=<?= urlencode($search_query) ?>">&lsaquo; Prev</a>
        <?php endif; ?>

        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end = min($total_pages, $page + $range);

        if ($start > 1) {
            echo '<span class="disabled">...</span>';
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i == $page) {
                echo '<span class="current">' . $i . '</span>';
            } else {
                echo '<a href="?page=' . $i . '&action=' . $action_filter . '&search=' . urlencode($search_query) . '">' . $i . '</a>';
            }
        }

        if ($end < $total_pages) {
            echo '<span class="disabled">...</span>';
        }
        ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 ?>&action=<?= $action_filter ?>&search=<?= urlencode($search_query) ?>">Next &rsaquo;</a>
            <a href="?page=<?= $total_pages ?>&action=<?= $action_filter ?>&search=<?= urlencode($search_query) ?>">Last &raquo;</a>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

$tbody_html = renderLogsTbody($logs);
$pagination_html = renderLogsPagination($page, $total_pages, $action_filter, $search_query);

// ── Live search: if this request came from the JS fetch() below, skip the
//    whole HTML shell and hand back just the bits that changed, as JSON ────
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'total_logs'      => (int)$total_logs,
        'tbody_html'      => $tbody_html,
        'pagination_html' => $pagination_html,
        'clear_disabled'  => $total_logs == 0,
        'clear_scope'     => ($action_filter !== 'all' || $search_query !== '') ? 'matching' : 'all',
        'clear_form_qs'   => http_build_query(['action' => $action_filter, 'search' => $search_query]),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Activity Logs - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>
/* Dark mode toggle - identical to dashboard.php / categories-manager.php / file-manager.php / comments-manager.php / blogs-manager.php / tag-manager.php / analytics.php */
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
   file-manager.php / comments-manager.php / blogs-manager.php /
   tag-manager.php / analytics.php
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
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

/* ============================================================
   PAGE-SPECIFIC CSS — Activity Logs only (unique to this page)
   Converted to shared design tokens (var(--primary) etc.)
   ============================================================ */

/* Filter Controls */
.filter-bar { background: white; padding: 1.25rem; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 1.25rem; align-items: center; }
.filter-group { display: flex; align-items: center; gap: 8px; }
.filter-select { padding: 0.625rem 0.75rem; border: 1px solid var(--gray-200); border-radius: var(--radius); background: white; color: var(--gray-700); font-size: 0.9375rem; }
.search-input { padding: 0.625rem 0.75rem; border: 1px solid var(--gray-200); border-radius: var(--radius); min-width: 250px; font-size: 0.9375rem; }
.btn-filter { padding: 0.625rem 1.25rem; background: var(--primary); color: white; border: none; border-radius: var(--radius); cursor: pointer; font-weight: 600; transition: all 0.2s; }
.btn-filter:hover { background: var(--primary-dark); }
.btn-reset { padding: 0.625rem 1.25rem; background: var(--gray-100); color: var(--gray-700); text-decoration: none; border-radius: var(--radius); display: inline-block; font-size: 0.875rem; font-weight: 500; transition: all 0.2s; }
.btn-reset:hover { background: var(--gray-200); }

/* Logs Table */
.table-responsive { overflow-x: auto; background: white; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); }
.logs-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.logs-table th { background: var(--gray-50); padding: 0.875rem 1rem; text-align: left; border-bottom: 1px solid var(--gray-200); font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); }
.logs-table td { padding: 0.875rem 1rem; border-bottom: 1px solid var(--gray-100); font-size: 0.875rem; color: var(--gray-700); vertical-align: middle; }
.logs-table tr:last-child td { border-bottom: none; }
.logs-table tr:hover td { background: var(--gray-50); }

/* Action Badges */
.badge { display: inline-block; padding: 0.25rem 0.5rem; border-radius: var(--radius); font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
.badge-danger { background: #fde8e8; color: #c81e1e; }
.badge-success { background: #def7ec; color: #03543f; }
.badge-warning { background: #fdf6b2; color: #723b13; }
.badge-info { background: #e1effe; color: #1e429f; }
.badge-default { background: var(--gray-100); color: var(--gray-700); }

/* Role Badges */
.role-badge { font-size: 0.625rem; padding: 0.125rem 0.375rem; border-radius: 3px; border: 1px solid var(--gray-200); color: var(--gray-500); margin-top: 4px; display: inline-block; }

/* Metadata */
.log-meta { font-size: 0.75rem; color: var(--gray-500); font-family: monospace; }
.ip-addr { display: block; margin-bottom: 2px; }
.ua-string { display: inline-block; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: help; border-bottom: 1px dotted var(--gray-400); }

.pagination { display: flex; justify-content: center; margin-top: 1.25rem; gap: 5px; flex-wrap: wrap; }
.pagination a, .pagination span { padding: 0.5rem 0.75rem; border: 1px solid var(--gray-200); background: white; text-decoration: none; color: var(--gray-700); border-radius: var(--radius); font-weight: 500; }
.pagination a:hover { background: var(--gray-50); border-color: var(--gray-300); }
.pagination .current { background: var(--primary); color: white; border-color: var(--primary); }
.pagination .disabled { color: var(--gray-300); pointer-events: none; }

.empty-state { padding: 2.5rem; text-align: center; color: var(--gray-500); }

/* Flash Alerts */
.alert { padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.875rem; font-size: 0.9375rem; border: 1px solid transparent; }
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.alert i { font-size: 1.125rem; }

/* Clear (filtered) control — icon that morphs into a fast inline Yes/No */
.clear-zone { display: flex; align-items: center; margin-left: auto; }
.clear-trigger { width: 40px; height: 40px; border: none; background: #fee2e2; color: #dc2626; border-radius: var(--radius); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; transition: background 0.15s; flex-shrink: 0; }
.clear-trigger:hover { background: #fecaca; }
.clear-trigger:disabled { opacity: 0.4; cursor: not-allowed; }
.clear-confirm { display: none; align-items: center; gap: 0.5rem; background: #fef2f2; border: 1px solid #fecaca; padding: 0.4rem 0.5rem 0.4rem 0.75rem; border-radius: var(--radius); font-size: 0.8125rem; white-space: nowrap; animation: clearFadeIn 0.12s ease; }
.clear-confirm.show { display: flex; }
.clear-confirm span { color: #991b1b; font-weight: 600; }
.clear-yes, .clear-no { border: none; padding: 0.32rem 0.7rem; border-radius: 4px; cursor: pointer; font-size: 0.78rem; font-weight: 700; }
.clear-yes { background: #dc2626; color: white; }
.clear-yes:hover { background: #b91c1c; }
.clear-no { background: var(--gray-100); color: var(--gray-600); font-weight: 600; }
.clear-no:hover { background: var(--gray-200); }
@keyframes clearFadeIn { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }
@media (max-width: 640px) { .clear-zone { margin-left: 0; width: 100%; } .clear-confirm { flex: 1; justify-content: space-between; } }
</style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" type="button" onclick="toggleSidebar()" aria-label="Open menu">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading">
                    <h1>Activity Logs</h1>
                    <p>Review administrator actions and system events</p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" type="button" aria-label="Toggle dark mode" style="background: var(--primary-lighter); color: var(--primary);">
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

        <form method="GET" class="filter-bar" id="filterForm">
            <div class="filter-group">
                <select name="action" class="filter-select" id="actionSelect">
                    <option value="all">All Actions</option>
                    <?php foreach ($existing_actions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $action_filter === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $act))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group" style="flex-grow: 1;">
                <input type="text" name="search" class="search-input" id="searchInput" placeholder="Search description, IP, or Username..." value="<?= htmlspecialchars($search_query) ?>" autocomplete="off">
                <button type="submit" class="btn-filter">Search</button>
                <a href="activity-logs.php" class="btn-reset" id="resetLink" style="<?= ($action_filter !== 'all' || !empty($search_query)) ? '' : 'display:none;' ?>">Reset</a>
            </div>

            <div class="filter-group clear-zone">
                <span id="resultsSummary" style="font-size:.8125rem;color:var(--gray-500);white-space:nowrap;margin-right:.25rem;">
                    <?= (int)$total_logs ?> log<?= $total_logs == 1 ? '' : 's' ?>
                </span>
                <button type="button" class="clear-trigger" id="clearTrigger" onclick="showClearConfirm()" <?= $total_logs == 0 ? 'disabled' : '' ?> title="Clear <?= $action_filter !== 'all' || $search_query !== '' ? 'matching' : 'all' ?> logs">
                    <i class="fas fa-trash-alt"></i>
                </button>
                <div class="clear-confirm" id="clearConfirmBox">
                    <span id="clearConfirmText">Delete <?= (int)$total_logs ?> log<?= $total_logs == 1 ? '' : 's' ?>?</span>
                    <button type="button" class="clear-yes" onclick="submitClearLogs()">Yes</button>
                    <button type="button" class="clear-no" onclick="hideClearConfirm()">No</button>
                </div>
            </div>
        </form>

        <!-- Deletes exactly what the filter/search above currently shows -->
        <form method="POST" id="clearLogsForm" action="?<?= htmlspecialchars(http_build_query(['action' => $action_filter, 'search' => $search_query])) ?>" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="clear_action" value="clear_filtered">
        </form>

        <div class="table-responsive">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th width="5%">ID</th>
                        <th width="15%">User</th>
                        <th width="15%">Action</th>
                        <th width="35%">Description</th>
                        <th width="15%">Tech Info</th>
                        <th width="15%">Date</th>
                    </tr>
                </thead>
                <tbody id="logsTbody"><?= $tbody_html ?></tbody>
            </table>
        </div>

        <div id="paginationBox"><?= $pagination_html ?></div>

        </div>
    </main>
</div>

<footer>
    <div style="padding: 10px 20px; text-align: center; color: #6C6C6C;">
        <p style="font-size:0.8rem; margin-bottom:0px;">&copy; 2025-2026 <?= htmlspecialchars($site_name) ?>. All rights reserved.</p>
    </div>
</footer>

<script>
const ADMIN_URL = "/admin";

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

// Trash icon -> fast inline Yes/No (no browser confirm() popup)
function showClearConfirm() {
    document.getElementById('clearTrigger').style.display = 'none';
    document.getElementById('clearConfirmBox').classList.add('show');
}
function hideClearConfirm() {
    document.getElementById('clearConfirmBox').classList.remove('show');
    document.getElementById('clearTrigger').style.display = '';
}
function submitClearLogs() {
    document.getElementById('clearLogsForm').submit();
}
// Click outside the clear-zone cancels the confirm
document.addEventListener('click', function (e) {
    const zone = document.querySelector('.clear-zone');
    if (zone && !zone.contains(e.target)) hideClearConfirm();
});

// ── Live search: filters as you type, no page reload ────────────────────────
(function () {
    const form = document.getElementById('filterForm');
    const actionSelect = document.getElementById('actionSelect');
    const searchInput = document.getElementById('searchInput');
    const tbody = document.getElementById('logsTbody');
    const paginationBox = document.getElementById('paginationBox');
    const resultsSummary = document.getElementById('resultsSummary');
    const resetLink = document.getElementById('resetLink');
    const clearTrigger = document.getElementById('clearTrigger');
    const clearConfirmText = document.getElementById('clearConfirmText');
    const clearLogsForm = document.getElementById('clearLogsForm');

    let debounceTimer = null;
    let activeController = null;

    function currentParams(page) {
        const p = new URLSearchParams();
        p.set('action', actionSelect.value);
        p.set('search', searchInput.value.trim());
        p.set('page', page || 1);
        p.set('ajax', '1');
        return p;
    }

    function runLiveSearch(page) {
        if (activeController) activeController.abort();
        activeController = new AbortController();

        const params = currentParams(page);
        fetch('activity-logs.php?' + params.toString(), { signal: activeController.signal })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                tbody.innerHTML = data.tbody_html;
                paginationBox.innerHTML = data.pagination_html;
                resultsSummary.textContent = data.total_logs + (data.total_logs === 1 ? ' log' : ' logs');
                clearTrigger.disabled = data.clear_disabled;
                clearTrigger.title = 'Clear ' + data.clear_scope + ' logs';
                clearConfirmText.textContent = 'Delete ' + data.total_logs + (data.total_logs === 1 ? ' log?' : ' logs?');
                clearLogsForm.setAttribute('action', '?' + data.clear_form_qs);
                hideClearConfirm();

                const hasFilter = actionSelect.value !== 'all' || searchInput.value.trim() !== '';
                resetLink.style.display = hasFilter ? '' : 'none';

                // Keep the URL (and refresh/back button) in sync, without reloading
                const urlParams = new URLSearchParams();
                urlParams.set('action', actionSelect.value);
                if (searchInput.value.trim()) urlParams.set('search', searchInput.value.trim());
                if ((page || 1) > 1) urlParams.set('page', page || 1);
                const qs = urlParams.toString();
                history.replaceState(null, '', qs ? ('?' + qs) : 'activity-logs.php');
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') console.error('Live search failed:', err);
            });
    }

    // Type-to-filter, debounced so it doesn't fire on every single keystroke instantly
    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { runLiveSearch(1); }, 350);
    });

    // Dropdown filters immediately
    actionSelect.addEventListener('change', function () { runLiveSearch(1); });

    // Fallback: normal submit (e.g. Enter key) also goes through AJAX, no reload
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearTimeout(debounceTimer);
        runLiveSearch(1);
    });

    // Pagination links render fresh each time via AJAX too — event delegation
    paginationBox.addEventListener('click', function (e) {
        const link = e.target.closest('a');
        if (!link) return;
        e.preventDefault();
        const url = new URL(link.href, window.location.origin);
        runLiveSearch(url.searchParams.get('page') || 1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();

// Auto-dismiss alerts + clean success/error params from the URL
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
</script>

</body>
</html>