<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

// 1. Security & Auth Check
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
    empty($permissions['settings']['maintenance_mode'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

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

// 2. Configuration
$cache_dir = DROOT_PATH . '/cache/';

// Ensure cache directory exists, create if not
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

// Cache settings are kept in one small JSON file, same style as
// cj_smart_cache/*.json elsewhere in this project.
$cache_settings_file = INCLUDES_PATH . '/cache_settings.json';

function loadCacheSettings($file) {
    $defaults = [
        'disabled'                  => false,
        'ttl_homepage'               => 3600,
        'ttl_post'                   => 21600,
        'exclude_urls'               => '',
        'auto_preload'               => false,
        'auto_clear_enabled'         => false,
        'auto_clear_interval_hours'  => 24,
        'auto_clear_last_cleared'    => null,
    ];
    if (file_exists($file)) {
        $loaded = json_decode((string)@file_get_contents($file), true);
        if (is_array($loaded)) {
            $defaults = array_merge($defaults, $loaded);
        }
    }
    return $defaults;
}

function saveCacheSettings($file, array $settings) {
    return @file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

$cache_settings = loadCacheSettings($cache_settings_file);

// 3. Helper Functions
function formatSize($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

function cm_format_ttl($seconds) {
    $seconds = (int)$seconds;
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return round($seconds / 60) . ' min';
    if ($seconds < 86400) return round($seconds / 3600) . ' hr';
    return round($seconds / 86400) . ' day' . ($seconds >= 172800 ? 's' : '');
}

// Cache folder is flat today (index_N.html / post_N.html) but this stays
// correct even if sub-folders get introduced later.
function getCacheFilesRecursive($dir, $skip = []) {
    $files = [];
    if (!is_dir($dir)) return $files;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() && !in_array($file->getFilename(), $skip, true)) {
                $files[] = $file->getPathname();
            }
        }
    } catch (Exception $e) {}
    return $files;
}

function getRecentPostUrlsForPreload(PDO $pdo, $limit) {
    try {
        $stmt = $pdo->prepare("SELECT id, slug FROM posts WHERE status = 'published' ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $urls = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $urls[] = rtrim(SITE_URL, '/') . postUrl($row['slug'], $row['id']);
        }
        return $urls;
    } catch (Throwable $e) {
        return [];
    }
}

function warmupUrls(array $urls) {
    if (empty($urls) || !function_exists('curl_init')) return;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 4000,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'EduMint-CachePreload/1.0',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.2);
    } while ($running > 0);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

function manualPreload(PDO $pdo, $recentCount = 10) {
    if (!defined('SITE_URL') || !function_exists('curl_init')) return 0;
    $urls = array_merge([rtrim(SITE_URL, '/') . '/'], getRecentPostUrlsForPreload($pdo, $recentCount));
    warmupUrls($urls);
    return count($urls);
}

function gcOldCacheFiles($dir, $maxAgeSeconds) {
    $removed = 0;
    if (!is_dir($dir)) return $removed;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $now = time();
        foreach ($it as $file) {
            if (
                $file->isFile() &&
                $file->getFilename() !== 'cache_settings.json' &&
                ($now - $file->getMTime()) > $maxAgeSeconds
            ) {
                if (@unlink($file->getPathname())) $removed++;
            }
        }
    } catch (Exception $e) {}
    return $removed;
}

// 4. Handle Actions (Toggle / Delete Single / Clear All / Settings / Preload / Views Flush)
$msg = '';
$msg_type = '';
$_cache_disabled = (bool)$cache_settings['disabled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'toggle_cache') {
        csrf_check();
        $enable = ($_POST['enable'] ?? '0') === '1';
        $cache_settings['disabled'] = !$enable;
        saveCacheSettings($cache_settings_file, $cache_settings);
        foreach (getCacheFilesRecursive($cache_dir, ['cache_settings.json']) as $f) { @unlink($f); }
        $_cache_disabled = !$enable;
        $msg = $enable ? 'Cache system started.' : 'Cache system stopped and cleared.';
        $msg_type = 'success';
    }
    elseif ($_cache_disabled && $_POST['action'] === 'ajax_clear_all') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Cache system is OFF — start it first.']);
        exit;
    }
    elseif ($_cache_disabled && in_array($_POST['action'], ['delete_file', 'clear_all', 'preload_now'], true)) {
        $msg = 'Cache system is currently OFF — please turn on "Start Cache System" first.';
        $msg_type = 'error';
    }
    elseif ($_POST['action'] === 'delete_file' && isset($_POST['file'])) {
        csrf_check();
        $rel = str_replace(['..', "\0"], '', $_POST['file']);
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $path = realpath($cache_dir . $rel);
        $cacheReal = realpath($cache_dir);
        if (
            $path && $cacheReal &&
            str_starts_with($path, $cacheReal . DIRECTORY_SEPARATOR) &&
            is_file($path) &&
            basename($path) !== 'cache_settings.json'
        ) {
            if (@unlink($path)) {
                $msg = "File '{$rel}' deleted successfully.";
                $msg_type = 'success';
            } else {
                $msg = "Error deleting file.";
                $msg_type = 'error';
            }
        } else {
            $msg = 'File not found.';
            $msg_type = 'error';
        }
    }
    elseif ($_POST['action'] === 'clear_all') {
        csrf_check();
        $countBefore = count(getCacheFilesRecursive($cache_dir, ['cache_settings.json']));
        foreach (getCacheFilesRecursive($cache_dir, ['cache_settings.json']) as $f) { @unlink($f); }
        $cache_settings['auto_clear_last_cleared'] = date('Y-m-d H:i:s');
        saveCacheSettings($cache_settings_file, $cache_settings);
        if (!empty($cache_settings['auto_preload'])) { manualPreload($pdo, 5); }
        $msg = "Cache cleared! Deleted {$countBefore} file(s).";
        $msg_type = 'success';
    }
    elseif ($_POST['action'] === 'ajax_clear_all') {
        csrf_check();
        header('Content-Type: application/json');
        $countBefore = count(getCacheFilesRecursive($cache_dir, ['cache_settings.json']));
        foreach (getCacheFilesRecursive($cache_dir, ['cache_settings.json']) as $f) { @unlink($f); }
        $cache_settings['auto_clear_last_cleared'] = date('Y-m-d H:i:s');
        saveCacheSettings($cache_settings_file, $cache_settings);
        if (!empty($cache_settings['auto_preload'])) { manualPreload($pdo, 5); }
        echo json_encode(['success' => true, 'deleted' => $countBefore, 'last_cleared' => $cache_settings['auto_clear_last_cleared']]);
        exit;
    }
    elseif ($_POST['action'] === 'save_auto_clear') {
        csrf_check();
        $cache_settings['auto_clear_enabled'] = !empty($_POST['auto_clear_enabled']);
        $cache_settings['auto_clear_interval_hours'] = max(1, (int)($_POST['interval_hours'] ?? 24));
        saveCacheSettings($cache_settings_file, $cache_settings);
        $msg = 'Auto-clear settings saved.';
        $msg_type = 'success';
    }
    elseif ($_POST['action'] === 'save_cache_settings') {
        csrf_check();
        $cache_settings['ttl_homepage'] = max(30, (int)($_POST['ttl_homepage'] ?? 3600));
        $cache_settings['ttl_post'] = max(30, (int)($_POST['ttl_post'] ?? 21600));
        $cache_settings['auto_preload'] = !empty($_POST['cache_auto_preload']);
        $cache_settings['exclude_urls'] = trim((string)($_POST['cache_exclude_urls'] ?? ''));
        saveCacheSettings($cache_settings_file, $cache_settings);
        $msg = 'Cache settings saved.';
        $msg_type = 'success';
    }
    elseif ($_POST['action'] === 'preload_now') {
        csrf_check();
        $count = manualPreload($pdo, 10);
        $msg = $count > 0
            ? "Preload triggered for {$count} page(s) — homepage + recent posts."
            : 'Preload could not run — cURL may not be available on this server, or SITE_URL is not set.';
        $msg_type = $count > 0 ? 'success' : 'error';
    }
    elseif ($_POST['action'] === 'flush_views_json') {
        csrf_check();
        $vjFile = DROOT_PATH . '/cj_smart_cache/blog_views.json';
        $vjData = file_exists($vjFile) ? (json_decode(file_get_contents($vjFile), true) ?: []) : [];
        if (empty($vjData)) {
            $msg = 'No pending views in the JSON buffer — nothing to flush.';
            $msg_type = 'success';
        } else {
            $flushedCount = 0; $removedCount = 0; $totalViewsFlushed = 0;
            $existStmt = $pdo->prepare("SELECT 1 FROM posts WHERE id = ? LIMIT 1");
            $insStmt = $pdo->prepare("INSERT INTO post_views (views, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + VALUES(views)");
            foreach ($vjData as $pid => $cnt) {
                $cnt = (int)$cnt;
                $existStmt->execute([$pid]);
                if ($existStmt->fetchColumn()) {
                    if ($cnt > 0) {
                        $insStmt->execute([$cnt, $pid]);
                        $totalViewsFlushed += $cnt;
                        $flushedCount++;
                    }
                    unset($vjData[$pid]);
                } else {
                    unset($vjData[$pid]);
                    $removedCount++;
                }
            }
            file_put_contents($vjFile, json_encode($vjData), LOCK_EX);
            $msg = "Views cache flushed: {$flushedCount} post(s) updated (+{$totalViewsFlushed} views), {$removedCount} orphaned entrie(s) for deleted posts removed from the JSON buffer.";
            $msg_type = 'success';
        }
    }
}

// Opportunistic auto-clear housekeeping (disk hygiene only, runs on page load)
if (!$_cache_disabled && !empty($cache_settings['auto_clear_enabled'])) {
    $last = $cache_settings['auto_clear_last_cleared'] ? strtotime($cache_settings['auto_clear_last_cleared']) : 0;
    $interval_seconds = $cache_settings['auto_clear_interval_hours'] * 3600;
    if ((time() - $last) >= $interval_seconds) {
        gcOldCacheFiles($cache_dir, $interval_seconds);
        $cache_settings['auto_clear_last_cleared'] = date('Y-m-d H:i:s');
        saveCacheSettings($cache_settings_file, $cache_settings);
    }
}

// 5. Scan Directory for List
$files = [];
foreach (getCacheFilesRecursive($cache_dir, ['cache_settings.json']) as $path) {
    $relName = ltrim(str_replace(rtrim($cache_dir, '/'), '', $path), '/\\');
    $files[] = [
        'name' => $relName,
        'size' => filesize($path),
        'date' => filemtime($path),
    ];
}

// Sort by date DESC (newest first)
usort($files, function($a, $b) {
    return $b['date'] - $a['date'];
});

// Calculate Stats
$total_files = count($files);
$total_size = array_sum(array_column($files, 'size'));

$next_clear_ts = null;
if (!empty($cache_settings['auto_clear_enabled']) && $cache_settings['auto_clear_last_cleared']) {
    $next_clear_ts = strtotime($cache_settings['auto_clear_last_cleared']) + ($cache_settings['auto_clear_interval_hours'] * 3600);
}

// Server capability diagnostics (informational only — not wired into a
// separate object-cache/gzip layer beyond what index.php/post.php already do)
$_engine_stats = [
    'dir_writable'   => is_dir($cache_dir) ? is_writable($cache_dir) : false,
    'gzip_available' => function_exists('gzencode'),
    'curl_available' => function_exists('curl_init'),
    'apcu_available' => function_exists('apcu_enabled') && @apcu_enabled(),
    'ttl_homepage'   => (int)$cache_settings['ttl_homepage'],
    'ttl_post'       => (int)$cache_settings['ttl_post'],
];

// Views buffer stats — reuses the exact same JSON buffer that
// /api/0f9e8d7c6n.php and admin/exc_fn/manual_flush.php already write to.
$_vj_file = DROOT_PATH . '/cj_smart_cache/blog_views.json';
$_vj_data = file_exists($_vj_file) ? (json_decode(file_get_contents($_vj_file), true) ?: []) : [];
$_vj_pending_count = count($_vj_data);
$_vj_pending_views = array_sum($_vj_data);
$_vj_orphaned_count = 0;
if (!empty($_vj_data)) {
    $_vj_ids = array_keys($_vj_data);
    $_vj_ph = implode(',', array_fill(0, count($_vj_ids), '?'));
    $_vj_existing = $pdo->prepare("SELECT id FROM posts WHERE id IN ($_vj_ph)");
    $_vj_existing->execute($_vj_ids);
    $_vj_existing_ids = array_flip($_vj_existing->fetchAll(PDO::FETCH_COLUMN));
    foreach ($_vj_ids as $_vj_pid) {
        if (!isset($_vj_existing_ids[(int)$_vj_pid]) && !isset($_vj_existing_ids[$_vj_pid])) {
            $_vj_orphaned_count++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Cache Manager - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
/* Dark mode toggle - identical to dashboard.php / categories-manager.php / file-manager.php / comments-manager.php / blogs-manager.php / tag-manager.php / analytics.php / activity-logs.php */
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
   tag-manager.php / analytics.php / activity-logs.php
   Do NOT diverge from this block on any admin page.
   ============================================================ */
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --success-light: #d1fae5;
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
   PAGE-SPECIFIC CSS — Cache Manager only (unique to this page)
   Extended with Overview / Settings / Diagnostics / Files tabs,
   ported from the storytimes-cms cache manager and re-themed onto
   this page's own --primary / --gray-* tokens above.
   ============================================================ */

/* Buttons */
.btn { padding: 0.5rem 1rem; border: none; border-radius: var(--radius); cursor: pointer; font-size: 0.8125rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-danger { background: #fee2e2; color: var(--danger); }
.btn-danger:hover { background: #fecaca; }
.btn-view, .btn-secondary { background: #e0f2fe; color: #0369a1; }
.btn-view:hover, .btn-secondary:hover { background: #bae6fd; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-sm { padding: 0.35rem 0.7rem; font-size: 0.75rem; }

/* Status Banner */
.cm-banner { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
    border:1px solid var(--gray-200); border-radius: var(--radius-xl); background:#fff;
    padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: var(--shadow-sm); }
.cm-banner-left { display:flex; align-items:center; gap:.85rem; }
.cm-banner-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
.cm-banner-icon.on  { background: var(--success-light); color: var(--success); }
.cm-banner-icon.off { background: #fef3c7; color: #b45309; }
.cm-banner-title { font-weight:700; font-size:.95rem; color:var(--gray-900); }
.cm-banner-sub   { font-size:.8rem; color:var(--gray-500); max-width:520px; line-height:1.5; }

/* Tabs */
.cm-tabs { display:flex; gap:.25rem; border-bottom:1px solid var(--gray-200); margin-bottom:1.25rem; overflow-x:auto; }
.cm-tab { border:none; background:none; font-family:inherit; font-size:.85rem; font-weight:600; color:var(--gray-500);
    padding:.7rem 1rem; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s,border-color .15s; white-space:nowrap; }
.cm-tab:hover { color: var(--gray-800); }
.cm-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.cm-panel { display:none; }
.cm-panel.active { display:block; }

/* Stat grid */
.stats-bar { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.85rem; margin-bottom:1.25rem; }
.stat-card-cm { background:#fff; border:1px solid var(--gray-100); border-radius:var(--radius-xl); padding:1rem 1.1rem; display:flex; flex-direction:column; gap:.35rem; box-shadow: var(--shadow-sm); }
.stat-card-cm .stat-icon { width:26px; height:26px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:.7rem; background:var(--primary-lighter); color:var(--primary); margin-bottom:.15rem; }
.stat-card-cm .label { font-size:.72rem; color:var(--gray-500); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
.stat-card-cm .value { font-size:1.15rem; font-weight:700; color:var(--gray-900); }
.stat-card-cm.primary .stat-icon { background: var(--primary); color:#fff; }
.stat-card-cm.danger { border-color:#fecaca; }
.stat-card-cm.danger .stat-icon { background:#fee2e2; color:#dc2626; }
.stat-card-cm.danger .value { color:#dc2626; }

/* Settings / info cards */
.cm-card { background:#fff; border:1px solid var(--gray-100); border-radius:var(--radius-xl); padding:1.25rem; margin-bottom:1.25rem; box-shadow: var(--shadow-sm); }
.cm-card h3 { font-size:.95rem; font-weight:700; color:var(--gray-900); margin:0 0 .3rem; display:flex; align-items:center; gap:.5rem; }
.cm-card p.hint { font-size:.8rem; color:var(--gray-500); margin:0 0 1rem; line-height:1.5; }

.cm-field-row { display:flex; flex-wrap:wrap; align-items:center; gap:1rem; margin-bottom:1rem; }
.cm-field-row:last-child { margin-bottom:0; }
.cm-field-row label.fl { font-size:.82rem; font-weight:600; color:var(--gray-700); min-width:170px; }
.cm-select, .cm-textarea { padding:.45rem .7rem; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:.83rem; outline:none; font-family:inherit; background:#fff; color:var(--gray-800); }
.cm-select:focus, .cm-textarea:focus { border-color:var(--primary); }
.cm-textarea { width:100%; min-height:90px; resize:vertical; font-family:ui-monospace,monospace; font-size:.78rem; line-height:1.6; }

.auto-clear-row { display:flex; flex-wrap:wrap; align-items:center; gap:1rem; }
.auto-clear-toggle { display:flex; align-items:center; gap:.625rem; cursor:pointer; }
.toggle-switch { position:relative; width:42px; height:23px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider { position:absolute; inset:0; background:var(--gray-300); border-radius:24px; transition:background .2s; cursor:pointer; }
.toggle-slider::before { content:''; position:absolute; height:17px; width:17px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.toggle-switch input:checked + .toggle-slider { background:var(--primary); }
.toggle-switch input:checked + .toggle-slider::before { transform:translateX(19px); }
.toggle-label { font-size:.83rem; font-weight:600; color:var(--gray-800); user-select:none; }
.interval-select { padding:.4rem .7rem; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:.83rem; outline:none; font-family:inherit; background:#fff; }
.auto-status-badge { display:inline-flex; align-items:center; gap:.35rem; font-size:.72rem; font-weight:600; padding:.2rem .55rem; border-radius:20px; }
.auto-status-badge.on { background:#d1fae5; color:#065f46; }
.auto-status-badge.off { background:var(--gray-100); color:var(--gray-500); }
.next-clear-info { font-size:.78rem; color:var(--gray-500); margin-top:.6rem; display:flex; align-items:center; gap:.35rem; }
#countdown-timer { font-weight:600; color:var(--primary); font-variant-numeric:tabular-nums; }

/* Diagnostics chips */
.diag-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.85rem; }
.diag-chip { display:flex; align-items:flex-start; gap:.7rem; padding:.85rem 1rem; border:1px solid var(--gray-200); border-radius:var(--radius); }
.diag-chip .dot { width:9px; height:9px; border-radius:50%; margin-top:.35rem; flex-shrink:0; }
.diag-chip .dot.ok   { background:var(--success); }
.diag-chip .dot.warn { background:#f59e0b; }
.diag-chip .title { font-size:.85rem; font-weight:700; color:var(--gray-900); }
.diag-chip .desc  { font-size:.76rem; color:var(--gray-500); line-height:1.5; margin-top:.15rem; }

/* Table Container - Horizontal Scroll */
.table-box { background: white; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }
table { width: 100%; border-collapse: collapse; min-width: 600px; }
th { background: var(--gray-50); padding: 0.875rem 1rem; text-align: left; border-bottom: 1px solid var(--gray-200); font-size: 0.75rem; color: var(--gray-500); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; }
td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--gray-100); font-size: 0.875rem; color: var(--gray-700); vertical-align: middle; white-space: nowrap; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--gray-50); }
.file-name { font-weight: 600; color: var(--gray-900); }
.badge-cache { display:inline-flex; align-items:center; gap:.3rem; background:var(--gray-100); color:var(--gray-600); font-size:.7rem; font-weight:600; padding:.2rem .5rem; border-radius:4px; text-transform:uppercase; }
.cm-search { width:100%; max-width:320px; padding:.5rem .8rem; border:1px solid var(--gray-300); border-radius:var(--radius); font-size:.82rem; outline:none; font-family:inherit; margin-bottom:1rem; }
.cm-search:focus { border-color:var(--primary); }
.empty-state { padding: 2.5rem; text-align: center; color: var(--gray-500); }

.cm-actions-row { display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; margin-bottom:1.5rem; }

@media (max-width: 640px) {
  .auto-clear-row { flex-direction: column; align-items: flex-start; }
  .cm-actions-row { flex-direction: column; align-items: stretch; }
  .cm-actions-row .btn { width: 100%; justify-content: center; }
  .cm-field-row { flex-direction: column; align-items: flex-start; }
  .cm-field-row label.fl { min-width: unset; }
}

/* Cache system OFF — grey out everything below the master toggle banner */
.cm-locked { opacity: .45; filter: grayscale(55%); pointer-events: none; user-select: none; }
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
                    <h1>Cache Manager</h1>
                    <p>Manage and control the site's HTML cache</p>
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

        <!-- Status Banner -->
        <div class="cm-banner">
            <div class="cm-banner-left">
                <div class="cm-banner-icon <?= $_cache_disabled ? 'off' : 'on' ?>">
                    <i class="fas <?= $_cache_disabled ? 'fa-power-off' : 'fa-bolt' ?>"></i>
                </div>
                <div>
                    <div class="cm-banner-title">Cache System — <?= $_cache_disabled ? 'OFF' : 'ON' ?></div>
                    <div class="cm-banner-sub">
                        <?php if ($_cache_disabled): ?>
                            Every page renders live, nothing is cached. Turn it back on below.
                        <?php else: ?>
                            Homepage and post pages are served from static HTML for faster loading.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <form method="POST" style="flex-shrink:0;" onsubmit="return confirm('<?= $_cache_disabled ? 'Start the cache system?' : 'Stop the cache system? This will clear all current cache files.' ?>');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="toggle_cache">
                <input type="hidden" name="enable" value="<?= $_cache_disabled ? '1' : '0' ?>">
                <button type="submit" class="btn <?= $_cache_disabled ? 'btn-primary' : 'btn-danger' ?>">
                    <i class="fas <?= $_cache_disabled ? 'fa-play' : 'fa-stop' ?>"></i>
                    <?= $_cache_disabled ? 'Start Cache System' : 'Stop Cache System' ?>
                </button>
            </form>
        </div>

        <div class="<?= $_cache_disabled ? 'cm-locked' : '' ?>" <?= $_cache_disabled ? 'title="Cache system is OFF — start it from the toggle above first"' : '' ?>>

        <!-- Tabs -->
        <div class="cm-tabs">
            <button type="button" class="cm-tab active" data-tab="overview" onclick="cmSwitchTab('overview')">Overview</button>
            <button type="button" class="cm-tab" data-tab="settings" onclick="cmSwitchTab('settings')">Settings</button>
            <button type="button" class="cm-tab" data-tab="diagnostics" onclick="cmSwitchTab('diagnostics')">Diagnostics</button>
            <button type="button" class="cm-tab" data-tab="files" onclick="cmSwitchTab('files')">Cached Files (<?= $total_files ?>)</button>
        </div>

        <!-- OVERVIEW -->
        <div class="cm-panel active" id="panel-overview">

            <div class="stats-bar">
                <div class="stat-card-cm primary">
                    <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="label">Total Files</div>
                    <div class="value"><?= $total_files ?></div>
                </div>
                <div class="stat-card-cm">
                    <div class="stat-icon"><i class="fas fa-hdd"></i></div>
                    <div class="label">Total Size</div>
                    <div class="value"><?= formatSize($total_size) ?></div>
                </div>
                <div class="stat-card-cm">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="label">Homepage TTL</div>
                    <div class="value" style="font-size:.9rem;"><?= cm_format_ttl($_engine_stats['ttl_homepage']) ?></div>
                </div>
                <div class="stat-card-cm">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="label">Post TTL</div>
                    <div class="value" style="font-size:.9rem;"><?= cm_format_ttl($_engine_stats['ttl_post']) ?></div>
                </div>
                <div class="stat-card-cm">
                    <div class="stat-icon"><i class="fas fa-folder-open"></i></div>
                    <div class="label">Path</div>
                    <div class="value" style="font-size:.85rem;"><code>/cache/</code></div>
                </div>
            </div>

            <div class="cm-actions-row">
                <?php if ($total_files > 0): ?>
                <form method="POST" onsubmit="return confirm('Are you sure you want to delete ALL cache files? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="clear_all">
                    <button type="submit" class="btn btn-danger" <?= $_cache_disabled ? 'disabled' : '' ?>>
                        <i class="fas fa-trash-alt"></i> Clear All Cache (<?= $total_files ?>)
                    </button>
                </form>
                <?php else: ?>
                <span style="font-size:.875rem;color:var(--gray-500);display:inline-flex;align-items:center;gap:.4rem;">
                    <i class="fas fa-check-circle" style="color:var(--success);"></i> Cache is clean — no files to clear.
                </span>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="preload_now">
                    <button type="submit" class="btn btn-secondary" <?= $_cache_disabled ? 'disabled' : '' ?>>
                        <i class="fas fa-fire"></i> Preload Cache Now
                    </button>
                </form>
            </div>

            <?php if ($cache_settings['auto_clear_last_cleared']): ?>
            <p style="font-size:.78rem;color:var(--gray-500);margin-top:-.75rem;margin-bottom:1rem;">
                Last cleared: <?= date('d M Y, H:i', strtotime($cache_settings['auto_clear_last_cleared'])) ?>
            </p>
            <?php endif; ?>

            <div class="cm-card">
                <h3><i class="fas fa-chart-line" style="color:var(--primary);"></i> Analytics Views Buffer</h3>
                <p class="hint">
                    Pending page-view counts are batched here in a JSON file (<code>cj_smart_cache/blog_views.json</code>)
                    before being written to the database. When a post is deleted, its pending entry can be left behind —
                    that leftover count still gets counted until it's flushed out here.
                </p>
                <div class="stats-bar" style="margin-bottom:1rem;">
                    <div class="stat-card-cm">
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                        <div class="label">Pending Entries</div>
                        <div class="value"><?= (int)$_vj_pending_count ?></div>
                    </div>
                    <div class="stat-card-cm">
                        <div class="stat-icon"><i class="fas fa-eye"></i></div>
                        <div class="label">Pending Views</div>
                        <div class="value"><?= (int)$_vj_pending_views ?></div>
                    </div>
                    <div class="stat-card-cm <?= $_vj_orphaned_count > 0 ? 'danger' : '' ?>">
                        <div class="stat-icon"><i class="fas fa-triangle-exclamation"></i></div>
                        <div class="label">Orphaned (Deleted Posts)</div>
                        <div class="value"><?= (int)$_vj_orphaned_count ?></div>
                    </div>
                </div>
                <form method="POST" onsubmit="return confirm('Flush pending views into the database and remove entries for deleted posts?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="flush_views_json">
                    <button type="submit" class="btn btn-primary" <?= $_vj_pending_count === 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-broom"></i> Flush &amp; Clean Views Buffer<?= $_vj_orphaned_count > 0 ? " ({$_vj_orphaned_count} orphaned)" : '' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- SETTINGS -->
        <div class="cm-panel" id="panel-settings">

            <div class="cm-card">
                <h3><i class="fas fa-hourglass-half" style="color:var(--primary);"></i> Page Cache Lifespan</h3>
                <p class="hint">How long a cached page is served before it's regenerated fresh. Editing a post already deletes that post's own cache file immediately regardless of this setting.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="save_cache_settings">
                    <div class="cm-field-row">
                        <label class="fl" for="ttlHomepage">Homepage refresh interval</label>
                        <select name="ttl_homepage" id="ttlHomepage" class="cm-select" <?= $_cache_disabled ? 'disabled' : '' ?>>
                            <?php foreach ([60=>'1 minute',300=>'5 minutes',600=>'10 minutes',1800=>'30 minutes',3600=>'1 hour'] as $sec=>$label): ?>
                            <option value="<?= $sec ?>" <?= $_engine_stats['ttl_homepage'] == $sec ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cm-field-row">
                        <label class="fl" for="ttlPost">Post page lifespan</label>
                        <select name="ttl_post" id="ttlPost" class="cm-select" <?= $_cache_disabled ? 'disabled' : '' ?>>
                            <?php foreach ([3600=>'1 hour',21600=>'6 hours',43200=>'12 hours',86400=>'24 hours',604800=>'7 days'] as $sec=>$label): ?>
                            <option value="<?= $sec ?>" <?= $_engine_stats['ttl_post'] == $sec ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cm-field-row" style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--gray-100);">
                        <label class="auto-clear-toggle" style="min-width:170px;">
                            <div class="toggle-switch">
                                <input type="checkbox" name="cache_auto_preload" <?= !empty($cache_settings['auto_preload']) ? 'checked' : '' ?> <?= $_cache_disabled ? 'disabled' : '' ?>>
                                <span class="toggle-slider"></span>
                            </div>
                            <span class="toggle-label">Auto-preload after Clear Cache</span>
                        </label>
                        <span style="font-size:.78rem;color:var(--gray-500);">Warms homepage + 5 most recent posts automatically every time cache is cleared.</span>
                    </div>

                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--gray-100);">
                        <label class="fl" for="excludeUrls" style="display:block;margin-bottom:.5rem;">Never Cache These Pages</label>
                        <textarea name="cache_exclude_urls" id="excludeUrls" class="cm-textarea" placeholder="/category/live-updates&#10;/tag/breaking" <?= $_cache_disabled ? 'disabled' : '' ?>><?= htmlspecialchars($cache_settings['exclude_urls']) ?></textarea>
                        <p class="hint" style="margin-top:.4rem;margin-bottom:0;">One URL path per line. Any page whose URL starts with one of these is always rendered live, never cached.</p>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top:1.25rem;" <?= $_cache_disabled ? 'disabled' : '' ?>>
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>

            <div class="cm-card">
                <h3>
                    <i class="fas fa-clock" style="color:var(--primary);"></i> Auto Cache Cleanup
                    <span class="auto-status-badge <?= !empty($cache_settings['auto_clear_enabled']) ? 'on' : 'off' ?>" id="autoStatusBadge">
                        <i class="fas fa-circle" style="font-size:.5rem;"></i>
                        <?= !empty($cache_settings['auto_clear_enabled']) ? 'Auto: ON' : 'Auto: OFF' ?>
                    </span>
                </h3>
                <p class="hint">Removes old cache files sitting around from before the interval below — disk hygiene only, not a full wipe. Safe to leave on with a long interval (24h+).</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="save_auto_clear">
                    <div class="auto-clear-row">
                        <label class="auto-clear-toggle">
                            <div class="toggle-switch">
                                <input type="checkbox" name="auto_clear_enabled" id="autoToggle"
                                       <?= !empty($cache_settings['auto_clear_enabled']) ? 'checked' : '' ?>
                                       <?= $_cache_disabled ? 'disabled' : '' ?>
                                       onchange="updateAutoStatus()">
                                <span class="toggle-slider"></span>
                            </div>
                            <span class="toggle-label">Enable Automatic Cache Cleanup</span>
                        </label>
                        <div class="cm-field-row" style="margin:0;gap:.5rem;">
                            <label for="intervalSelect" style="font-size:.83rem;color:var(--gray-600);min-width:unset;">Clear every</label>
                            <select name="interval_hours" id="intervalSelect" class="interval-select" <?= $_cache_disabled ? 'disabled' : '' ?>>
                                <?php foreach ([1=>1,6=>6,12=>12,24=>24,48=>48,72=>72,168=>168] as $h=>$v): ?>
                                <option value="<?= $h ?>" <?= $cache_settings['auto_clear_interval_hours'] == $h ? 'selected' : '' ?>>
                                    <?= $h < 24 ? $h.'h' : ($h/24).'d' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding:.45rem 1rem;" <?= $_cache_disabled ? 'disabled' : '' ?>>
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
                <?php if (!empty($cache_settings['auto_clear_enabled']) && $next_clear_ts): ?>
                <div class="next-clear-info">
                    <i class="fas fa-hourglass-half"></i>
                    Next auto-cleanup in: <span id="countdown-timer">calculating…</span>
                    (at <?= date('d M H:i', $next_clear_ts) ?>)
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- DIAGNOSTICS -->
        <div class="cm-panel" id="panel-diagnostics">
            <div class="cm-card">
                <h3><i class="fas fa-stethoscope" style="color:var(--primary);"></i> Health Check</h3>
                <p class="hint">Quick check of whether the server has what it needs to run the cache system properly.</p>
                <div class="diag-grid">
                    <div class="diag-chip">
                        <span class="dot <?= $_engine_stats['dir_writable'] ? 'ok' : 'warn' ?>"></span>
                        <div>
                            <div class="title">Cache Folder Writable</div>
                            <div class="desc"><?= $_engine_stats['dir_writable'] ? 'Yes — /cache/ can be written to.' : 'No — /cache/ is not writable. Fix folder permissions (usually 755) in your hosting file manager.' ?></div>
                        </div>
                    </div>
                    <div class="diag-chip">
                        <span class="dot <?= $_engine_stats['apcu_available'] ? 'ok' : 'warn' ?>"></span>
                        <div>
                            <div class="title">Object Cache (APCu)</div>
                            <div class="desc"><?= $_engine_stats['apcu_available'] ? 'APCu extension is available on this server.' : 'Not installed — page caching still works fine without it.' ?></div>
                        </div>
                    </div>
                    <div class="diag-chip">
                        <span class="dot <?= $_engine_stats['gzip_available'] ? 'ok' : 'warn' ?>"></span>
                        <div>
                            <div class="title">Gzip Support</div>
                            <div class="desc"><?= $_engine_stats['gzip_available'] ? 'PHP zlib extension is available.' : 'PHP zlib extension missing.' ?></div>
                        </div>
                    </div>
                    <div class="diag-chip">
                        <span class="dot <?= $_engine_stats['curl_available'] ? 'ok' : 'warn' ?>"></span>
                        <div>
                            <div class="title">Cache Preloading</div>
                            <div class="desc"><?= $_engine_stats['curl_available'] ? 'Available — Preload Cache Now and auto-preload will work.' : 'PHP cURL extension missing — preloading is unavailable, everything else still works normally.' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CACHED FILES -->
        <div class="cm-panel" id="panel-files">
            <input type="text" class="cm-search" id="fileSearch" placeholder="Search filename…" oninput="cmFilterFiles(this.value)">
            <div class="table-box">
                <table>
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th width="12%">Type</th>
                            <th width="15%">Size</th>
                            <th width="18%">Generated At</th>
                            <th width="18%" style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                            <tr>
                                <td colspan="5" class="empty-state">
                                    No cache files found. System is clean.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($files as $f): $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)); ?>
                                <tr data-fname="<?= htmlspecialchars(strtolower($f['name'])) ?>">
                                    <td>
                                        <div class="file-name"><?= htmlspecialchars($f['name']) ?></div>
                                    </td>
                                    <td><span class="badge-cache"><?= strtoupper($ext) ?: 'FILE' ?></span></td>
                                    <td><?= formatSize($f['size']) ?></td>
                                    <td><?= date('M j, Y H:i:s', $f['date']) ?></td>
                                    <td style="text-align: right;">
                                        <a href="/cache/<?= htmlspecialchars($f['name']) ?>" target="_blank" class="btn btn-view btn-sm"<?= $_cache_disabled ? ' style="pointer-events:none;opacity:.5;"' : '' ?>>
                                            View
                                        </a>

                                        <form method="POST" style="display:inline-block; margin-left: 5px;"
                                              onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($f['name'])) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($f['name']) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" <?= $_cache_disabled ? 'disabled' : '' ?>>
                                                Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div><!-- /.cm-locked wrapper -->

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

<?php if ($msg): ?>
    Toastify({
        text: "<?= addslashes($msg) ?>",
        duration: 3000,
        gravity: "top",
        position: "center",
        style: {
            background: "<?= $msg_type === 'success' ? '#10B981' : '#EF4444' ?>",
            borderRadius: "8px"
        }
    }).showToast();
<?php endif; ?>

// Auto-status badge live update
function updateAutoStatus() {
    const chk = document.getElementById('autoToggle');
    const badge = document.getElementById('autoStatusBadge');
    if (badge) {
        badge.className = 'auto-status-badge ' + (chk.checked ? 'on' : 'off');
        badge.innerHTML = '<i class="fas fa-circle" style="font-size:.5rem;"></i> ' + (chk.checked ? 'Auto: ON' : 'Auto: OFF');
    }
}

// Tab switching — plain show/hide, no reload, everything already rendered server-side
function cmSwitchTab(name) {
    document.querySelectorAll('.cm-tab').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.tab === name);
    });
    document.querySelectorAll('.cm-panel').forEach(function(panel) {
        panel.classList.toggle('active', panel.id === 'panel-' + name);
    });
    try { history.replaceState(null, '', '#' + name); } catch (e) {}
}
(function() {
    var hash = (location.hash || '').replace('#', '');
    if (['overview','settings','diagnostics','files'].includes(hash)) {
        cmSwitchTab(hash);
    }
})();

// Cached Files search filter
function cmFilterFiles(query) {
    query = query.trim().toLowerCase();
    document.querySelectorAll('#panel-files tbody tr[data-fname]').forEach(function(row) {
        row.style.display = row.dataset.fname.includes(query) ? '' : 'none';
    });
}

// Countdown timer to next auto-clear
<?php if (!empty($cache_settings['auto_clear_enabled']) && $next_clear_ts): ?>
(function() {
    var targetTs = <?= $next_clear_ts ?> * 1000;
    var el = document.getElementById('countdown-timer');
    if (!el) return;
    function updateCountdown() {
        var diff = Math.max(0, Math.floor((targetTs - Date.now()) / 1000));
        if (diff <= 0) { el.textContent = 'clearing soon…'; return; }
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        el.textContent = (h > 0 ? h + 'h ' : '') + (m > 0 ? m + 'm ' : '') + s + 's';
    }
    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
<?php endif; ?>
</script>

</body>
</html>