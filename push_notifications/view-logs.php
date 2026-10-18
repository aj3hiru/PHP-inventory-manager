<?php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

// 1. Security Check
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
    empty($permissions['push_notifications']['send'])
) {
    exit('Access Denied');
}

define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', ROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');

$username = $_SESSION['username'];

$notifications = [];

// 2. Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['campaign_id'])) {
    $campaign_id = (int)$_POST['campaign_id'];

    try {
        $row = $pdo->prepare("SELECT title FROM push_campaigns WHERE id = ?");
        $row->execute([$campaign_id]);
        $campaign = $row->fetch(PDO::FETCH_ASSOC);

        // Delete campaign (queue will cascade delete via FK)
        $pdo->prepare("DELETE FROM push_campaigns WHERE id = ?")->execute([$campaign_id]);

        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'push_delete', ?, ?, ?)")
            ->execute([$_SESSION['user_id'], "Deleted Push Campaign: " . ($campaign['title'] ?? 'Custom Push') . " (ID: $campaign_id)", $log_ip, $log_ua]);

        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=deleted');
        exit;
    } catch (Exception $e) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Failed to delete campaign: ' . $e->getMessage()];
    }
}

// ── Success / Error flash messages ─────────────────────────────────────────
if (isset($_GET['success']) && $_GET['success'] === 'deleted') {
    $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'Campaign deleted successfully!'];
}

// 3. Pagination
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$total_records = (int) $pdo->query("SELECT COUNT(*) FROM push_campaigns")->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $limit));

// 4. Fetch campaigns with pagination
$stmt = $pdo->prepare("
    SELECT
        c.id, c.post_id, c.title AS campaign_title, c.body, c.image, c.url,
        c.status, c.total_subscribers, c.sent, c.failed,
        c.created_at, c.updated_at,
        p.title AS post_title, p.slug, m.file_path AS post_image
    FROM push_campaigns c
    LEFT JOIN posts p ON c.post_id = p.id
    LEFT JOIN media m ON p.featured_image_id = m.id
    ORDER BY c.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Stat totals (across all campaigns, not just this page)
$totals = $pdo->query("SELECT COALESCE(SUM(sent),0) AS total_sent, COALESCE(SUM(failed),0) AS total_failed FROM push_campaigns")->fetch(PDO::FETCH_ASSOC);
$total_sent   = (int)($totals['total_sent'] ?? 0);
$total_failed = (int)($totals['total_failed'] ?? 0);
$push_count   = (int) $pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();

$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>Push Queue - <?= htmlspecialchars($site_name) ?> Admin</title>
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
.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

.alert { padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.875rem; font-size: 0.9375rem; border: 1px solid transparent; }
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.alert i { font-size: 1.125rem; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.625rem 1.25rem; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25); }
.btn-secondary { background: var(--gray-100); color: var(--gray-700); }
.btn-secondary:hover { background: var(--gray-200); }

/* Stats */
.stats-grid { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 0.5rem; margin-bottom: 1rem; scrollbar-width: none; -ms-overflow-style: none; }
.stat-card { flex: 0 0 auto; background: white; border-radius: var(--radius-lg); padding: 1rem; min-width: 150px; box-shadow: var(--shadow); border: 1px solid var(--gray-100); }
.stat-card.primary { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border: none; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.25rem; }
.stat-card.primary .stat-value { color: white; }
.stat-change { font-size: 0.75rem; opacity: 0.9; color: var(--gray-500); }
.stat-card.primary .stat-change { color: rgba(255,255,255,0.8); }

/* List Card / Table */
.list-card { background: white; border-radius: var(--radius-xl); border: 1px solid var(--gray-100); box-shadow: var(--shadow-sm); overflow: visible; }
.list-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.list-card-header h2 { font-size: 1.0625rem; font-weight: 700; color: var(--gray-900); }

.table-wrap { overflow-x: auto; }
.cat-table { width: 100%; border-collapse: collapse; }
.cat-table thead th { padding: 0.875rem 1.25rem; text-align: left; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
.cat-table tbody tr { border-bottom: 1px solid var(--gray-100); transition: background 0.15s; }
.cat-table tbody tr:last-child { border-bottom: none; }
.cat-table tbody tr:hover { background: var(--gray-50); }
.cat-table td { padding: 1rem 1.25rem; font-size: 0.9375rem; vertical-align: middle; }

.campaign-cell { display: flex; align-items: center; gap: 0.875rem; }
.post-thumb { width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius); background: var(--gray-100); flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: var(--gray-400); font-size: 1.05rem; }
.campaign-title { font-weight: 600; color: var(--gray-900); font-size: 0.9375rem; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.campaign-meta { font-size: 0.8125rem; color: var(--gray-500); margin-top: 0.15rem; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.campaign-meta a { color: var(--primary); text-decoration: none; }
.campaign-meta a:hover { text-decoration: underline; }

.status-badge { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.75rem; border-radius: 9999px; white-space: nowrap; }
.status-pending    { background: #fef3c7; color: #92400e; }
.status-processing { background: #dbeafe; color: #1e40af; }
.status-completed  { background: #d1fae5; color: #065f46; }
.status-failed     { background: #fee2e2; color: #991b1b; }

.progress-cell { min-width: 140px; }
.progress-track { height: 8px; border-radius: 999px; background: var(--gray-100); overflow: hidden; margin-bottom: 0.35rem; }
.progress-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--primary) 0%, var(--primary-dark) 100%); transition: width 0.4s ease; }
.progress-label { font-size: 0.75rem; color: var(--gray-500); font-weight: 600; }

.sent-failed .sent { color: var(--success); font-weight: 700; }
.sent-failed .failed { color: var(--danger); font-weight: 700; margin-left: 0.35rem; }
.time-cell { color: var(--gray-500); font-size: 0.8125rem; white-space: nowrap; }

/* Action dropdown */
.action-dropdown { position: relative; display: inline-block; }
.action-btn { width: 36px; height: 36px; border: none; background: transparent; color: var(--gray-500); border-radius: var(--radius); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; transition: all 0.2s; }
.action-btn:hover { background: var(--gray-100); color: var(--gray-800); }
.action-menu { display: none; position: absolute; right: 0; top: calc(100% + 4px); background: white; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); min-width: 170px; z-index: 500; overflow: hidden; border: 1px solid var(--gray-100); }
.action-menu.show { display: block; animation: fadeIn 0.15s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
.action-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.7rem 1rem; color: var(--gray-700); text-decoration: none; font-size: 0.875rem; font-weight: 500; transition: background 0.15s; border: none; background: none; width: 100%; text-align: left; cursor: pointer; }
.action-item:hover { background: var(--gray-50); }
.action-item.text-danger { color: var(--danger); }
.action-item.text-danger:hover { background: #fef2f2; }
.action-divider { height: 1px; background: var(--gray-100); margin: 0.25rem 0; }

/* Empty state */
.empty-state { text-align: center; padding: 3.5rem 2rem; }
.empty-icon { width: 72px; height: 72px; background: var(--primary-lighter); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem; }
.empty-state h3 { font-size: 1.125rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.5rem; }
.empty-state p { color: var(--gray-500); font-size: 0.9375rem; margin-bottom: 1.25rem; }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 0.375rem; padding: 1.25rem; flex-wrap: wrap; }
.page-link { min-width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius); font-size: 0.9375rem; font-weight: 600; text-decoration: none; transition: all 0.2s; border: 1px solid var(--gray-200); background: white; color: var(--gray-700); padding: 0 0.75rem; }
.page-link:hover { background: var(--gray-50); border-color: var(--gray-300); }
.page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
.page-link.disabled { color: var(--gray-300); cursor: not-allowed; pointer-events: none; }

/* Delete confirmation modal */
.modal-confirm { display: none; position: fixed; inset: 0; background: rgba(17,24,39,0.5); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 1rem; }
.modal-confirm.show { display: flex; animation: fadeIn 0.2s ease; }
.modal-content { background: white; border-radius: var(--radius-xl); padding: 2rem; max-width: 400px; width: 100%; text-align: center; box-shadow: var(--shadow-lg); animation: slideUp 0.3s ease; }
@keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal-icon { width: 60px; height: 60px; background: #fee2e2; color: var(--danger); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto 1rem; }
.modal-title { font-size: 1.125rem; font-weight: 700; color: var(--gray-900); margin-bottom: 0.5rem; }
.modal-text { color: var(--gray-500); margin-bottom: 1.5rem; font-size: 0.9375rem; }
.modal-actions { display: flex; gap: 0.75rem; justify-content: center; }
.modal-btn { padding: 0.6rem 1.5rem; border-radius: var(--radius); border: none; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.9375rem; }
.modal-btn-secondary { background: var(--gray-100); color: var(--gray-700); }
.modal-btn-secondary:hover { background: var(--gray-200); }
.modal-btn-danger { background: var(--danger); color: white; }
.modal-btn-danger:hover { background: #dc2626; }

@media (max-width: 640px) {
    .campaign-title, .campaign-meta { max-width: 180px; }
}
</style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading">
                    <h1>Push Queue</h1>
                    <p>Live status of your notification campaigns</p>
                </div>
            </div>
            <div class="nav-right">
                <a href="/push_notifications/push-manager.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    New
                </a>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include $_SERVER['DOCUMENT_ROOT'] . '/admin/components/header-user.php'; ?>
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
                    <div class="stat-value"><?= number_format($total_records) ?></div>
                    <div class="stat-change">Total Campaigns</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--success);"><?= formatViews($total_sent) ?></div>
                    <div class="stat-change">Total Sent</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--danger);"><?= formatViews($total_failed) ?></div>
                    <div class="stat-change">Total Failed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--info);"><?= formatViews($push_count) ?></div>
                    <div class="stat-change">Subscribers</div>
                </div>
            </div>

            <div class="list-card">
                <div class="list-card-header">
                    <h2>Recent Campaigns <span style="font-weight:400;color:var(--gray-500);font-size:0.875rem;">(<?= number_format($total_records) ?>)</span></h2>
                </div>

                <?php if (empty($campaigns)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-bell-slash"></i></div>
                    <h3>No campaigns yet</h3>
                    <p>Start sending notifications from the manager.</p>
                    <a href="/push_notifications/push-manager.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Create First Push
                    </a>
                </div>
                <?php else: ?>

                <div class="table-wrap">
                    <table class="cat-table">
                        <thead>
                            <tr>
                                <th>Campaign / Post</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Sent / Failed</th>
                                <th>Total</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($campaigns as $c):
                            $progress = $c['total_subscribers'] > 0 ? round($c['sent'] / $c['total_subscribers'] * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="campaign-cell">
                                        <?php if ($c['post_image']): ?>
                                            <img src="/<?= htmlspecialchars($c['post_image']) ?>" class="post-thumb" alt="">
                                        <?php else: ?>
                                            <div class="post-thumb"><i class="fas fa-bell"></i></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="campaign-title" title="<?= htmlspecialchars($c['campaign_title'] ?: 'Custom Push') ?>"><?= htmlspecialchars($c['campaign_title'] ?: 'Custom Push') ?></div>
                                            <?php if ($c['post_title']): ?>
                                            <div class="campaign-meta">
                                                <a href="<?= postUrl($c['slug'], $c['post_id']) ?>" target="_blank"><?= htmlspecialchars($c['post_title']) ?></a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="status-badge status-<?= htmlspecialchars($c['status']) ?>"><?= ucfirst($c['status']) ?></span></td>
                                <td class="progress-cell">
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                    </div>
                                    <div class="progress-label"><?= $progress ?>%</div>
                                </td>
                                <td class="sent-failed">
                                    <span class="sent"><?= number_format($c['sent']) ?></span>
                                    <span class="failed">/ <?= number_format($c['failed']) ?></span>
                                </td>
                                <td><?= number_format($c['total_subscribers']) ?></td>
                                <td class="time-cell"><?= date('d M y • h:i a', strtotime($c['created_at'])) ?></td>
                                <td>
                                    <div class="action-dropdown">
                                        <button class="action-btn" onclick="toggleMenu(this)">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="action-menu">
                                            <a href="/push_notifications/admin_push.php?edit=<?= $c['id'] ?>" class="action-item">
                                                <i class="fas fa-pen"></i> Edit
                                            </a>
                                            <?php if (!empty($c['url'])): ?>
                                            <a href="<?= htmlspecialchars($c['url']) ?>" target="_blank" class="action-item">
                                                <i class="fas fa-link"></i> View URL
                                            </a>
                                            <?php endif; ?>
                                            <div class="action-divider"></div>
                                            <button class="action-item text-danger" onclick="confirmDelete(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['campaign_title'] ?: 'Custom Push')) ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1" class="page-link"><i class="fas fa-angle-double-left"></i></a>
                        <a href="?page=<?= $page - 1 ?>" class="page-link"><i class="fas fa-angle-left"></i></a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>

                    <?php
                    $range = 2;
                    $start = max(1, $page - $range);
                    $end   = min($total_pages, $page + $range);
                    if ($start > 1): ?>
                        <a href="?page=1" class="page-link">1</a>
                        <?php if ($start > 2): ?><span class="page-link disabled">...</span><?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="page-link active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>" class="page-link"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1): ?><span class="page-link disabled">...</span><?php endif; ?>
                        <a href="?page=<?= $total_pages ?>" class="page-link"><?= $total_pages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="page-link"><i class="fas fa-angle-right"></i></a>
                        <a href="?page=<?= $total_pages ?>" class="page-link"><i class="fas fa-angle-double-right"></i></a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-confirm" id="deleteModal">
    <div class="modal-content">
        <div class="modal-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <div class="modal-title">Delete Campaign?</div>
        <div class="modal-text">
            Are you sure you want to delete "<span id="campaignName"></span>"? This action cannot be undone.
        </div>
        <div class="modal-actions">
            <button class="modal-btn modal-btn-secondary" onclick="closeModal()">Cancel</button>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="campaign_id" id="deleteCampaignId">
                <button type="submit" class="modal-btn modal-btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>

<script>
const ADMIN_URL = "<?= ADMIN_URL ?>";

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

// Toggle action menu
function toggleMenu(btn) {
    document.querySelectorAll('.action-menu.show').forEach(menu => {
        if (menu !== btn.nextElementSibling) menu.classList.remove('show');
    });
    const menu = btn.nextElementSibling;
    if (menu) menu.classList.toggle('show');
}

document.addEventListener('click', function (e) {
    if (!e.target.closest('.action-dropdown')) {
        document.querySelectorAll('.action-menu.show').forEach(menu => menu.classList.remove('show'));
    }
});

function confirmDelete(id, name) {
    document.getElementById('deleteCampaignId').value = id;
    document.getElementById('campaignName').textContent = name;
    document.getElementById('deleteModal').classList.add('show');
}

function closeModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeModal();
        document.querySelectorAll('.action-menu.show').forEach(menu => menu.classList.remove('show'));
    }
});

// Auto-dismiss alerts + clean up query params
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