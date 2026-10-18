<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

// Check security 
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
    empty($permissions['dashboard_access'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];


$notifications = [];
// Success messages
if (isset($_GET['success'])) {

    switch ($_GET['success']) {

        case 'created':
            $notifications[] = [
                'type' => 'success',
                'icon' => 'fas fa-check-circle',
                'message' => 'Post created successfully!'
            ];
            break;

        case 'updated':
            $notifications[] = [
                'type' => 'success',
                'icon' => 'fas fa-check-circle',
                'message' => 'Post updated successfully!'
            ];
            break;

        case 'deleted':
            $notifications[] = [
                'type' => 'success',
                'icon' => 'fas fa-check-circle',
                'message' => 'Post deleted successfully!'
            ];
            break;
    }
}

// Error messages
if (isset($_GET['error'])) {

    switch ($_GET['error']) {

        case 'delete_failed':
            $notifications[] = [
                'type' => 'error',
                'icon' => 'fas fa-exclamation-circle',
                'message' => 'Failed to delete post!'
            ];
            break;

        case 'save_failed':
            $notifications[] = [
                'type' => 'error',
                'icon' => 'fas fa-exclamation-circle',
                'message' => 'Failed to save post!'
            ];
            break;
    }
}

// Image upload warning
if (isset($_GET['img_error'])) {

    $notifications[] = [
        'type' => 'warning',
        'icon' => 'fas fa-exclamation-triangle',
        'message' => 'Image upload failed, but post saved successfully.'
    ];
}



// NOTE: Post deletion + listing/filters/pagination now live in blogs-manager.php.
// This page is a pure overview dashboard, so that logic was removed from here.

// --- Post status counts (Total / Published / Draft / Archived) ---
if ($_SESSION['role'] === 'admin') {
    $all_count       = (int) $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $published_count = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status='published'")->fetchColumn();
    $draft_count     = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status='draft'")->fetchColumn();
    $archived_count  = (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE status='archived'")->fetchColumn();
} else {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT
    COUNT(*) AS all_count,
    SUM(p.status='published') AS published_count,
    SUM(p.status='draft') AS draft_count,
    SUM(p.status='archived') AS archived_count
    FROM posts p JOIN authors a ON p.author_id = a.id WHERE a.user_id = ?");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $all_count       = (int)($row['all_count'] ?? 0);
    $published_count = (int)($row['published_count'] ?? 0);
    $draft_count     = (int)($row['draft_count'] ?? 0);
    $archived_count  = (int)($row['archived_count'] ?? 0);
}

// --- Push subscriber count ---
$push_count = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();

// --- Posts created today / yesterday (scoped to own posts for non-admins) ---
$db_today_str = date('Y-m-d');
$db_yesterday_str = date('Y-m-d', strtotime('-1 day'));

$owned_sql = $_SESSION['role'] !== 'admin' ? " AND a.user_id = :uid" : "";
$stmt = $pdo->prepare("SELECT DATE(p.date) AS d, COUNT(*) AS c FROM posts p JOIN authors a ON p.author_id = a.id WHERE DATE(p.date) IN (:today, :yesterday)" . $owned_sql . " GROUP BY DATE(p.date)");
$stmt->bindValue(':today', $db_today_str);
$stmt->bindValue(':yesterday', $db_yesterday_str);
if ($_SESSION['role'] !== 'admin') $stmt->bindValue(':uid', $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$posts_by_day = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$posts_today_count = (int)($posts_by_day[$db_today_str] ?? 0);
$posts_yesterday_count = (int)($posts_by_day[$db_yesterday_str] ?? 0);

// --- Traffic overview + 7-day trend (reuses the same cj_smart_cache/daily_performance.json
//     that analytics.php already builds, so no schema changes are needed) ---
$dailyPerformanceFile = DROOT_PATH . '/cj_smart_cache/daily_performance.json';
$dailyPerformanceData = file_exists($dailyPerformanceFile) ? (json_decode(file_get_contents($dailyPerformanceFile), true) ?: []) : [];

function dbDayViews(array $dailyPerformanceData, string $day): int {
    return isset($dailyPerformanceData[$day]) ? array_sum($dailyPerformanceData[$day]) : 0;
}
function dbGrowthPct($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return round((($current - $previous) / $previous) * 100, 1);
}

$traffic_today = dbDayViews($dailyPerformanceData, $db_today_str);
$traffic_yesterday = dbDayViews($dailyPerformanceData, $db_yesterday_str);
$traffic_growth = dbGrowthPct($traffic_today, $traffic_yesterday);

$traffic_7d = 0;
$chart_labels = [];
$chart_data = [];
$cursor = strtotime('-6 days');
$endTs = strtotime('today');
while ($cursor <= $endTs) {
    $d = date('Y-m-d', $cursor);
    $day_views = dbDayViews($dailyPerformanceData, $d);
    $chart_labels[] = date('M j', $cursor);
    $chart_data[] = $day_views;
    $traffic_7d += $day_views;
    $cursor = strtotime('+1 day', $cursor);
}
$chart_has_data = array_sum($chart_data) > 0;

// Variables
$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>Dashboard - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
!function () {

    let e = localStorage.dm === "1",
        t = !1,
        n = !1,

        s = () => new Promise((e, r) => {
            let o = document.createElement("script");
            o.src = "/assets/js/darkreader.min.js";
            o.onload = () => { t = !0; e(); };
            o.onerror = r;
            document.head.appendChild(o);
        }),

        a = () => DarkReader.enable({
            brightness: 100,
            contrast: 100,
            sepia: 10
        }),

        d = () => DarkReader.disable(),

        i = () => {
            document.querySelectorAll(".dark-mode-toggle i").forEach(o => {
                o.classList.add("rotate");

                if (e) {
                    o.classList.remove("fa-moon");
                    o.classList.add("fa-sun");
                } else {
                    o.classList.remove("fa-sun");
                    o.classList.add("fa-moon");
                }

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
            setTimeout(() => {
                c.forEach(e => e.classList.remove("loading"));
                n = !1;
            }, 600);
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
    --header-height: 70px;
    --sidebar-width: 280px;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--gray-50);
    color: var(--gray-800);
    line-height: 1.5;
}

/* Layout Grid System */
.admin-container {
    display: grid;
    grid-template-columns: 1fr;
    min-height: 100vh;
}

@media (min-width: 1024px) {
    .admin-container {
        grid-template-columns: var(--sidebar-width) 1fr;
    }
}

/* Sidebar - Desktop Fixed, Mobile Drawer */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    background: white;
    border-right: 1px solid var(--gray-200);
    z-index: 1000;
    transform: translateX(-100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.sidebar.open {
    transform: translateX(0);
}

@media (min-width: 1024px) {
    .sidebar {
        position: sticky;
        transform: translateX(0);
        height: 100vh;
        top: 0;
    }
}

.sidebar-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--primary);
    text-decoration: none;
}

.brand-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    border-radius: var(--radius-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.25rem;
}

.close-sidebar {
    width: 36px;
    height: 36px;
    border: none;
    background: var(--gray-100);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--gray-600);
    transition: all 0.2s;
}

.close-sidebar:hover {
    background: var(--gray-200);
}

@media (min-width: 1024px) {
    .close-sidebar {
        display: none;
    }
}

.sidebar-nav {
    flex: 1;
    padding: 1rem 0;
    overflow-y: auto;
}
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 2px; }

.nav-section {
    margin-bottom: 1.5rem;
    padding: 0 1rem;
}

.nav-title {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--gray-400);
    padding: 0 1rem;
    margin-bottom: 0.75rem;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1rem;
    border-radius: var(--radius);
    color: var(--gray-600);
    text-decoration: none;
    font-size: 0.9375rem;
    font-weight: 500;
    transition: all 0.2s;
    margin-bottom: 0.25rem;
}

.nav-link:hover {
    background: var(--gray-50);
    color: var(--gray-900);
}

.nav-link.active {
    background: var(--primary-lighter);
    color: var(--primary);
    font-weight: 600;
}

.nav-link i {
    width: 24px;
    text-align: center;
    font-size: 1.125rem;
}

.sidebar-footer {
    padding: 1rem;
    border-top: 1px solid var(--gray-100);
}

.user-card {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem;
    background: var(--gray-50);
    border-radius: var(--radius-lg);
}

.user-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
}

.user-info {
    flex: 1;
    min-width: 0;
}

.user-name {
    font-weight: 600;
    color: var(--gray-900);
    font-size: 0.9375rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-role {
    font-size: 0.75rem;
    color: var(--gray-500);
}

/* Overlay for mobile sidebar */
.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.sidebar-overlay.active {
    opacity: 1;
    visibility: visible;
}

@media (min-width: 1024px) {
    .sidebar-overlay {
        display: none;
    }
}

/* Main Content Area */
.main-content {
    min-width: 0;
}

/* Top Navigation Bar */
.top-nav {
    position: sticky;
    top: 0;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    padding: 0.450rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    z-index: 100;
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.menu-toggle {
    width: 40px;
    height: 40px;
    border: none;
    background: var(--gray-100);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    color: var(--gray-700);
    cursor: pointer;
    transition: all 0.2s;
}

.menu-toggle:hover {
    background: var(--gray-200);
}

.sitename-mob {
 display: block;
 font-size: 1.15rem;
 font-weight: 800;
 color: var(--primary);
  }

@media (min-width: 1024px) {
    .menu-toggle {
        display: none;
    }
}

.page-heading {
    display: none;
}

@media (min-width: 768px) {
    .page-heading {
        display: block;
    }
    
    .sitename-mob {
    	display: none;
   }
    .page-heading h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--gray-900);
    }
    .page-heading p {
        font-size: 0.875rem;
        color: var(--gray-500);
    }
}

.nav-right {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.stat-pills {
    display: none;
}

@media (min-width: 768px) {
    .stat-pills {
        display: flex;
        gap: 0.75rem;
    }
}

.stat-pill {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--gray-100);
    border-radius: var(--radius);
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--gray-700);
}

.stat-pill i {
    color: var(--primary);
}

.icon-btn {
    width: 40px;
    height: 40px;
    border: none;
    background: transparent;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    color: var(--gray-600);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}

.icon-btn:hover {
    background: var(--gray-100);
}

.icon-btn .badge {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 8px;
    height: 8px;
    background: var(--danger);
    border-radius: 50%;
    border: 2px solid white;
}

/* Content Container */
.content-wrapper {
    padding: 1.5rem;
    max-width: 1600px;
    margin: 0 auto;
}

@media (max-width: 640px) {
    .content-wrapper {
        padding: 1rem;
    }
}

.dark-mode-toggle i{
transition: transform .4s ease, opacity .3s ease;
}
.dark-mode-toggle i.rotate{
transform: rotate(180deg);
}

/* Alerts */
.alert {
    padding: 1rem 1.25rem;
    border-radius: var(--radius-lg);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.875rem;
    font-size: 0.9375rem;
    border: 1px solid transparent;
}

.alert-success {
    background: #ecfdf5;
    color: #065f46;
    border-color: #a7f3d0;
}

.alert-error {
    background: #fef2f2;
    color: #991b1b;
    border-color: #fecaca;
}

.alert i {
    font-size: 1.125rem;
}

/* Dashboard Grid Layout */
.dashboard-grid {
    display: grid;
    gap: 1.5rem;
}

/* Stats Overview Cards */
.stats-grid {
    display: flex;
    gap: 0.75rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.stat-card {
    flex: 0 0 auto;
    background: white;
    border-radius: var(--radius-lg);
    padding: 1rem;
    min-width: 140px;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-100);
}

.stat-card.primary {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    border: none;
}

.stat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.75rem;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--gray-500);
    font-weight: 500;
}

.stat-card.primary .stat-label {
    color: rgba(255,255,255,0.8);
}

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
}

.stat-card:not(.primary) .stat-icon {
    background: var(--primary-lighter);
    color: var(--primary);
}

.stat-card.primary .stat-icon {
    background: rgba(255,255,255,0.2);
    color: white;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.stat-card.primary .stat-value {
    color: white;
}

.stat-change {
    font-size: 0.75rem;
    opacity: 0.9;
}

/* ── Overview widgets (Traffic / Trend Chart / Today's Posts) ── */
.db-actions-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.db-display-wrap { position: relative; }
.db-display-panel {
    position: absolute; top: calc(100% + 0.5rem); right: 0; z-index: 20;
    min-width: 220px;
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 0.75rem;
    box-shadow: var(--shadow-lg);
    display: none;
    flex-direction: column;
    gap: 0.125rem;
}
.db-display-panel.open { display: flex; }
.db-display-check {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.5rem 0.625rem; border-radius: 7px;
    font-size: 0.8438rem; color: var(--gray-700);
    cursor: pointer; user-select: none; transition: background 0.15s;
}
.db-display-check:hover { background: var(--gray-50); }
.db-display-check input[type="checkbox"] { width: 15px; height: 15px; cursor: pointer; accent-color: var(--primary); }

.db-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.25rem;
    transition: box-shadow 0.2s;
}
.db-card:hover { box-shadow: var(--shadow-md); }
[data-widget].db-card-hidden { display: none !important; }

.db-card-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.75rem; flex-wrap: wrap;
    padding: 1.125rem 1.375rem;
    border-bottom: 1px solid var(--gray-100);
}
.db-card-title {
    font-size: 1rem; font-weight: 700; color: var(--gray-900);
    display: flex; align-items: center; gap: 0.75rem;
}
.dci {
    display: inline-flex; align-items: center; justify-content: center;
    width: 2.125rem; height: 2.125rem; border-radius: 9px; font-size: 0.9375rem; flex-shrink: 0;
}
.dci-indigo { background: var(--primary-lighter); color: var(--primary); }
.dci-violet { background: rgba(139,92,246,0.12); color: #8b5cf6; }
.dci-emerald { background: rgba(16,185,129,0.12); color: var(--success); }

.db-card-sub { font-size: 0.8125rem; color: var(--gray-400); font-weight: 500; }
.db-card-link {
    display: inline-flex; align-items: center; gap: 0.4rem;
    font-size: 0.8125rem; font-weight: 600; color: var(--primary);
    text-decoration: none; white-space: nowrap; transition: gap 0.15s;
}
.db-card-link:hover { gap: 0.6rem; }
.db-card-body { padding: 1.375rem; }

.db-empty {
    text-align: center; padding: 2rem 0;
    color: var(--gray-400); font-size: 0.875rem;
}
.db-empty i { display: block; font-size: 1.5rem; margin-bottom: 0.5rem; opacity: 0.7; }

.db-chart-wrap { position: relative; height: 220px; width: 100%; }

/* Traffic cards */
.traffic-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}
@media (max-width: 720px) { .traffic-grid { grid-template-columns: 1fr; } }
.traffic-card {
    display: flex; align-items: center; gap: 0.875rem;
    padding: 1.25rem; border-radius: var(--radius-lg);
    background: var(--gray-50);
    border: 1px solid var(--gray-100);
    transition: transform 0.18s, box-shadow 0.18s;
}
.traffic-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.tc-icon {
    width: 2.75rem; height: 2.75rem; border-radius: var(--radius);
    display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;
}
.tc-lbl { font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; }
.tc-num { font-size: 1.75rem; font-weight: 800; color: var(--gray-900); line-height: 1.1; }
.tc-sub { font-size: 0.75rem; font-weight: 600; color: var(--gray-400); margin-top: 0.3rem; display: flex; align-items: center; gap: 0.3rem; }
.traffic-today { background: linear-gradient(135deg, var(--primary-lighter), rgba(124,58,237,0.02)); border-color: var(--primary-light); }
.traffic-today .tc-icon { background: var(--primary-light); color: var(--primary); }
.traffic-today .tc-num { color: var(--primary); }
.traffic-yesterday .tc-icon { background: rgba(245,158,11,0.15); color: #f59e0b; }
.traffic-yesterday .tc-num { color: #d97706; }
.traffic-week .tc-icon { background: rgba(16,185,129,0.15); color: var(--success); }
.traffic-week .tc-num { color: #059669; }

/* Today's Posts widget */
.tp-stat-row { display: flex; gap: 1rem; flex-wrap: wrap; }
.tp-stat-card {
    flex: 1; min-width: 160px;
    padding: 1.125rem 1.25rem; border-radius: var(--radius-lg);
    background: var(--gray-50);
    border: 1px solid var(--gray-100);
}
.tp-stat-num { font-size: 2.25rem; font-weight: 800; line-height: 1; margin-bottom: 0.375rem; }
.tp-stat-lbl { font-size: 0.8125rem; font-weight: 600; color: var(--gray-500); display: flex; align-items: center; gap: 0.4rem; }
.tp-today { background: linear-gradient(135deg, var(--primary-lighter), rgba(124,58,237,0.02)); border-color: var(--primary-light); }
.tp-today .tp-stat-num { color: var(--primary); }
.tp-today .tp-stat-lbl i { color: var(--primary); }
.tp-yesterday .tp-stat-num { color: #d97706; }
.tp-yesterday .tp-stat-lbl i { color: #f59e0b; }

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    border-radius: var(--radius);
    font-size: 0.9375rem;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
}

.btn-secondary {
    background: var(--gray-100);
    color: var(--gray-700);
}

.btn-secondary:hover {
    background: var(--gray-200);
}

/* Quick Actions Toolbar */
.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    justify-content: space-between;
}

.toolbar-title {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: var(--gray-900);
}

.toolbar-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.btn-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}

@media (min-width: 640px) {
    .btn-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 1024px) {
    .btn-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (min-width: 1280px) {
    .btn-grid {
        grid-template-columns: repeat(6, 1fr);
    }
}

.action-btn {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    color: var(--gray-700);
    text-decoration: none;
    font-size: 0.9375rem;
    font-weight: 500;
    transition: all 0.2s;
}

.action-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.action-btn i {
    font-size: 1.125rem;
    color: var(--primary);
}

/* Mobile Optimizations */
@media (max-width: 640px) {
    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .toolbar-actions {
        width: 100%;
    }

    .toolbar-actions .btn {
        flex: 1;
    }

    .db-actions-row {
        justify-content: stretch;
    }

    .db-actions-row .btn {
        flex: 1;
    }
}

/* Touch Device Optimizations */
@media (hover: none) {
    .action-btn:hover,
    .traffic-card:hover {
        transform: none;
    }
}

/* Loading States */
.skeleton {
    background: linear-gradient(90deg, var(--gray-100) 25%, var(--gray-200) 50%, var(--gray-100) 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>
</head>
<body>

<div class="admin-container">
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Navigation -->
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading">
                    <h1>Dashboard</h1>
                    <p><?= date('l, d F Y') ?> — <?= htmlspecialchars($site_name) ?> Admin Panel</p>
                </div>
            </div>

            <div class="nav-right">
                <div class="stat-pills">
                    <div class="stat-pill">
                        <i class="fas fa-bell"></i>
                        <span><?= formatViews($push_count) ?> Push</span>
                    </div>
                </div>

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

        <!-- Content Wrapper -->
        <div class="content-wrapper">

    <?php foreach ($notifications as $n): ?>
    <div class="alert alert-<?= $n['type'] ?>">
        <i class="<?= $n['icon'] ?>"></i>
        <span><?= htmlspecialchars($n['message']) ?></span>
    </div>
<?php endforeach; ?>

            <!-- Actions row -->
            <div class="db-actions-row">
                <a href="/admin/blog/post-manager.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Post
                </a>
                <a href="/admin/blog/blogs-manager.php" class="btn btn-secondary">
                    <i class="fas fa-newspaper"></i> All Posts
                </a>
                <?php if (!empty($permissions['analytics']['view_basic'])): ?>
                <a href="/admin/blog/analytics.php" class="btn btn-secondary">
                    <i class="fas fa-chart-line"></i> Full Analytics
                </a>
                <?php endif; ?>
                <div class="db-display-wrap">
                    <button class="btn btn-secondary" id="displayOptionsToggle" type="button">
                        <i class="fas fa-sliders-h"></i> Display Options <i class="fas fa-chevron-down" id="doArrow"></i>
                    </button>
                    <div class="db-display-panel" id="displayOptionsPanel">
                        <label class="db-display-check"><input type="checkbox" data-widget="poststatus" checked> Post Status</label>
                        <?php if (!empty($permissions['analytics']['view_basic'])): ?>
                        <label class="db-display-check"><input type="checkbox" data-widget="traffic" checked> Traffic Overview</label>
                        <label class="db-display-check"><input type="checkbox" data-widget="trendchart" checked> Traffic Chart</label>
                        <?php endif; ?>
                        <label class="db-display-check"><input type="checkbox" data-widget="todaysposts" checked> Today's Posts</label>
                    </div>
                </div>
            </div>

            <!-- Post Status Overview -->
            <div class="stats-grid" data-widget="poststatus">
                <div class="stat-card primary">
                    <div class="stat-value"><?= number_format($all_count) ?></div>
                    <div class="stat-change">Total Posts</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value" style="color: var(--success);"><?= number_format($published_count) ?></div>
                    <div class="stat-change">Published</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value" style="color: var(--warning);"><?= number_format($draft_count) ?></div>
                    <div class="stat-change">Drafts</div>
                </div>

                <div class="stat-card">
                    <div class="stat-value" style="color: var(--info);"><?= number_format($archived_count) ?></div>
                    <div class="stat-change">Archived</div>
                </div>

                <?php if (!empty($permissions['push_notifications']['send'])): ?>
                <div class="stat-card">
                    <div class="stat-value" style="color: var(--warning);"><?= formatViews($push_count) ?></div>
                    <div class="stat-change">Push Subs</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($permissions['analytics']['view_basic'])): ?>
            <!-- Traffic Overview -->
            <div class="db-card" data-widget="traffic">
                <div class="db-card-head">
                    <div class="db-card-title"><span class="dci dci-indigo"><i class="fas fa-chart-line"></i></span> Traffic Overview</div>
                    <a href="/admin/blog/analytics.php" class="db-card-link">View Full Analytics <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="db-card-body">
                    <div class="traffic-grid">
                        <div class="traffic-card traffic-today">
                            <div class="tc-icon"><i class="fas fa-calendar-day"></i></div>
                            <div class="tc-info">
                                <div class="tc-lbl">Today</div>
                                <div class="tc-num"><?= formatViews($traffic_today) ?></div>
                                <div class="tc-sub"><i class="fas fa-<?= $traffic_growth >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i> <?= abs($traffic_growth) ?>% vs yesterday</div>
                            </div>
                        </div>
                        <div class="traffic-card traffic-yesterday">
                            <div class="tc-icon"><i class="fas fa-calendar-minus"></i></div>
                            <div class="tc-info">
                                <div class="tc-lbl">Yesterday</div>
                                <div class="tc-num"><?= formatViews($traffic_yesterday) ?></div>
                                <div class="tc-sub"><i class="fas fa-eye"></i> views</div>
                            </div>
                        </div>
                        <div class="traffic-card traffic-week">
                            <div class="tc-icon"><i class="fas fa-calendar-week"></i></div>
                            <div class="tc-info">
                                <div class="tc-lbl">Last 7 Days</div>
                                <div class="tc-num"><?= formatViews($traffic_7d) ?></div>
                                <div class="tc-sub"><i class="fas fa-eye"></i> total views</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Traffic Trend Chart -->
            <div class="db-card" data-widget="trendchart">
                <div class="db-card-head">
                    <div class="db-card-title"><span class="dci dci-emerald"><i class="fas fa-wave-square"></i></span> Traffic Trend</div>
                    <div class="db-card-sub">Last 7 days</div>
                </div>
                <div class="db-card-body">
                    <?php if (!$chart_has_data): ?>
                    <div class="db-empty"><i class="fas fa-chart-area"></i> No traffic data for this period yet.</div>
                    <?php else: ?>
                    <div class="db-chart-wrap"><canvas id="trendChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Today's Posts -->
            <div class="db-card" data-widget="todaysposts">
                <div class="db-card-head">
                    <div class="db-card-title"><span class="dci dci-violet"><i class="fas fa-file-alt"></i></span> Today's Posts</div>
                    <a href="/admin/blog/blogs-manager.php" class="db-card-link">All Posts <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="db-card-body">
                    <div class="tp-stat-row">
                        <div class="tp-stat-card tp-today">
                            <div class="tp-stat-num"><?= number_format($posts_today_count) ?></div>
                            <div class="tp-stat-lbl"><i class="fas fa-calendar-day"></i> Posted Today</div>
                        </div>
                        <div class="tp-stat-card tp-yesterday">
                            <div class="tp-stat-num"><?= number_format($posts_yesterday_count) ?></div>
                            <div class="tp-stat-lbl"><i class="fas fa-calendar-minus"></i> Posted Yesterday</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Toolbar -->
            <div class="toolbar">
                <h2 class="toolbar-title">Quick Actions</h2>
            </div>

            <!-- Action Buttons Grid -->
            <div class="btn-grid" style="margin-bottom: 1.5rem;">
                <a href="/admin/blog/post-manager.php" class="action-btn">
                    <i class="fas fa-plus-circle"></i>
                    Create Post
                </a>
                
                <?php if (!empty($permissions['files']['access_file_manager'])): ?>
                <a href="/admin/file-manager.php" class="action-btn">
                    <i class="fas fa-images"></i>
                    File Manager
                </a>
                <?php endif; ?>
                
                <?php if (!empty($permissions['push_notifications']['send'])): ?>
                <a href="/push_notifications/push-manager.php" class="action-btn">
                    <i class="fas fa-bell"></i>
                    Web Push
                </a>
                <?php endif; ?>
                	
                <a href="/admin/blog/blogs-manager.php" class="action-btn">
                <i class="fas fa-newspaper"></i>
                    Manage Blogs
                </a>
                
                <?php if (!empty($permissions['analytics']['view_basic'])): ?>
                <a href="/admin/blog/analytics.php" class="action-btn">
                    <i class="fas fa-chart-line"></i>
                    Tracking
                </a>
                <?php endif; ?>
                
                <?php if (!empty($permissions['blogs']['manage_comments'])): ?>
                <a href="/admin/blog/comments-manager.php" class="action-btn">
                 <i class="fas fa-comments"></i>
                    Comments
                </a>
                <?php endif; ?>
                 
                 <?php if (!empty($permissions['settings']['maintenance_mode'])): ?>
                <a href="/admin/cache-manager.php" class="action-btn">
                    <i class="fas fa-bolt"></i>
                    Cache Manager
                </a>
                <?php endif; ?>
               
               <?php if (!empty($permissions['blogs']['manage_tags'])): ?>
                <a href="/admin/blog/tag-manager.php" class="action-btn">
                    <i class="fas fa-tags"></i>
                    Tag Manager
                </a>
                <?php endif; ?>
                	
                <?php if (!empty($permissions['blogs']['manage_categories'])): ?>
                <a href="/admin/blog/categories-manager.php" class="action-btn">
                    <i class="fas fa-list"></i>
                    Category Manager
                </a>
                <?php endif; ?>
                
                <?php if (!empty($permissions['security']['view_logs'])): ?>
                <a href="/admin/activity-logs.php" class="action-btn">
                    <i class="fas fa-history"></i>
                    Activity Logs
                </a>
                <?php endif; ?>
                
                <?php if (!empty($permissions['users']['create'])): ?>
                <a href="/admin/user-manager.php" class="action-btn">
                    <i class="fas fa-user"></i>
                    Users Manager
                </a>
                <?php endif; ?>
               
            </div>
        </div>
    </main>
</div>
<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php if (!empty($permissions['analytics']['view_basic']) && $chart_has_data): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<?php endif; ?>
<script>
const ADMIN_URL = "<?= ADMIN_URL ?>";

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function showToast(message, type = "default") {
    const config = {
        text: message,
        position: "center",
        style: {
            borderRadius: "12px",
            padding: "16px 24px",
            fontSize: "14px",
            fontWeight: "500"
        }
    };
    config.style.background = type === "success" ? "#10b981" : type === "error" ? "#ef4444" : type === "warning" ? "#f59e0b" : "#6b7280";
    Toastify(config).showToast();
}

<?php if (!empty($permissions['analytics']['view_basic']) && $chart_has_data): ?>
/* ── Traffic Trend Chart ── */
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendGradient = trendCtx.createLinearGradient(0, 0, 0, 260);
trendGradient.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
trendGradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');

new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
            label: 'Views',
            data: <?= json_encode($chart_data) ?>,
            backgroundColor: trendGradient,
            borderColor: '#10b981',
            borderWidth: 2.5,
            pointRadius: 0,
            pointHitRadius: 12,
            pointHoverRadius: 5,
            pointHoverBorderWidth: 2,
            pointHoverBackgroundColor: '#10b981',
            pointHoverBorderColor: '#fff',
            fill: true,
            tension: 0.4,
            cubicInterpolationMode: 'monotone'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 10, right: 8 } },
        interaction: { intersect: false, mode: 'index' },
        scales: {
            y: {
                beginAtZero: true,
                suggestedMax: <?= max($chart_data) > 0 ? max($chart_data) * 1.25 : 4 ?>,
                grid: { color: 'rgba(0,0,0,0.05)', drawTicks: false },
                border: { display: false },
                ticks: { precision: 0, padding: 8, maxTicksLimit: 5, color: '#9ca3af', font: { size: 11 } }
            },
            x: {
                grid: { display: false },
                border: { color: 'rgba(0,0,0,0.08)' },
                ticks: { autoSkip: true, maxTicksLimit: 7, maxRotation: 0, minRotation: 0, color: '#9ca3af', font: { size: 11 } }
            }
        },
        plugins: {
            tooltip: {
                backgroundColor: 'rgba(17,24,39,0.92)',
                padding: 10,
                cornerRadius: 8,
                displayColors: false,
                titleFont: { size: 12, weight: '600' },
                bodyFont: { size: 12 },
                callbacks: { label: (ctx) => ' ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' view' : ' views') }
            },
            legend: { display: false }
        }
    }
});
<?php endif; ?>

/* ── Display Options (show/hide dashboard cards, remembered per browser) ── */
(function () {
    const PREF_KEY = 'admin_dashboard_widgets';
    const toggle = document.getElementById('displayOptionsToggle');
    const panel  = document.getElementById('displayOptionsPanel');
    const arrow  = document.getElementById('doArrow');
    if (!toggle || !panel) return;

    function loadPrefs() {
        try { return JSON.parse(localStorage.getItem(PREF_KEY) || '{}'); } catch { return {}; }
    }
    function savePrefs(prefs) {
        localStorage.setItem(PREF_KEY, JSON.stringify(prefs));
    }
    function applyVisibility(key, visible) {
        document.querySelectorAll('[data-widget="' + key + '"]').forEach(el => {
            el.classList.toggle('db-card-hidden', !visible);
        });
    }

    const prefs = loadPrefs();
    panel.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        const key = cb.dataset.widget;
        const visible = prefs[key] !== false;
        cb.checked = visible;
        applyVisibility(key, visible);
        cb.addEventListener('change', function () {
            const p = loadPrefs();
            p[key] = this.checked;
            savePrefs(p);
            applyVisibility(key, this.checked);
        });
    });

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = panel.classList.toggle('open');
        arrow.style.transform = isOpen ? 'rotate(180deg)' : '';
    });
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            panel.classList.remove('open');
            arrow.style.transform = '';
        }
    });
})();

// Close sidebar on window resize if open
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});

// Touch swipe to close sidebar
let touchStartX = 0;
let touchEndX = 0;

const sidebar = document.getElementById('sidebar');

sidebar.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].screenX;
}, false);

sidebar.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchStartX - touchEndX > 100) {
        toggleSidebar();
    }
}, false);


document.addEventListener('DOMContentLoaded', function () {

    function clearNotificationParamsAndUI() {

        const notifTimer = setTimeout(function () {

            document.querySelectorAll('.alert').forEach(function (notifEl) {

                notifEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                notifEl.style.opacity = '0';
                notifEl.style.transform = 'translateY(-5px)';

                setTimeout(function () {
                    notifEl.remove();
                }, 400);

            });

        }, 3000);

        const notifUrl = new URL(window.location.href);
        let notifChanged = false;

        if (notifUrl.searchParams.has('success')) {
            notifUrl.searchParams.delete('success');
            notifChanged = true;
        }

        if (notifUrl.searchParams.has('error')) {
            notifUrl.searchParams.delete('error');
            notifChanged = true;
        }

        if (notifChanged) {
            window.history.replaceState(
                { notif_cleared: true },
                document.title,
                notifUrl.pathname + notifUrl.search
            );
        }

    }

    clearNotificationParamsAndUI();

});
</script>

</body>
</html>
