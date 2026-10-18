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
    empty($permissions['files']['access_file_manager'])
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
        header('Content-Type: application/json');
        echo json_encode(['error' => 'CSRF check failed.']);
        exit;
    }
}

// ─── DIRECTORIES ─────────────────────────────────────────────────────────────
$files_dir = 'files/';
if (!file_exists($files_dir)) {
    mkdir($files_dir, 0777, true);
}

$upload_dir_url = 'uploads/';
$upload_dir_abs = DROOT_PATH . '/uploads/';
if (!file_exists($upload_dir_abs)) {
    mkdir($upload_dir_abs, 0777, true);
}

// ─── MAKE SURE OPTIONAL MEDIA COLUMNS EXIST (title / caption / description) ──
try {
    $existing_cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='media' AND TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('title', $existing_cols)) $pdo->exec("ALTER TABLE media ADD COLUMN title VARCHAR(255) DEFAULT '' AFTER alt_text");
    if (!in_array('caption', $existing_cols)) $pdo->exec("ALTER TABLE media ADD COLUMN caption TEXT DEFAULT NULL AFTER title");
    if (!in_array('description', $existing_cols)) $pdo->exec("ALTER TABLE media ADD COLUMN description TEXT DEFAULT NULL AFTER caption");
} catch (Exception $e) {
    // Non-fatal: manager still works without these columns.
}

$file_type_map = [
    'image'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
    'pdf'      => ['pdf'],
    'video'    => ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'],
    'audio'    => ['mp3', 'wav', 'aac', 'flac', 'm4a'],
    'document' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'],
    'archive'  => ['zip', 'rar', 'tar', 'gz', '7z'],
];
$allowed_exts = array_merge(...array_values($file_type_map));

function fm_detect_type($ext, $map) {
    foreach ($map as $type => $exts) {
        if (in_array($ext, $exts, true)) return $type;
    }
    return 'other';
}

function fm_delete_media_row($pdo, $row, $root) {
    $base = rtrim($root, '/') . '/';
    if (!empty($row['file_path']) && file_exists($base . $row['file_path'])) @unlink($base . $row['file_path']);
    if (!empty($row['responsive_set'])) {
        foreach (json_decode($row['responsive_set'], true) ?: [] as $v) {
            if (!empty($v) && file_exists($base . $v)) @unlink($base . $v);
        }
    }
}

// ─── AJAX HANDLERS (must run before any HTML output) ──────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    if ($action === 'get_media_detail' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT id, file_path, file_type, alt_text, title, caption, description, uploaded_at FROM media WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'Not found']); exit; }
        echo json_encode([
            'id'          => $row['id'],
            'path'        => $row['file_path'],
            'file_type'   => $row['file_type'],
            'alt_text'    => $row['alt_text'] ?? '',
            'title'       => $row['title'] ?? '',
            'caption'     => $row['caption'] ?? '',
            'description' => $row['description'] ?? '',
            'uploaded_at' => $row['uploaded_at'] ?? '',
        ]);
        exit;
    }

    if ($action === 'update_media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Invalid ID']); exit; }
        $alt_text    = trim($_POST['alt_text'] ?? '');
        $title       = trim($_POST['title'] ?? '');
        $caption     = trim($_POST['caption'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stmt = $pdo->prepare("UPDATE media SET alt_text = ?, title = ?, caption = ?, description = ? WHERE id = ?");
        $stmt->execute([$alt_text, $title, $caption, $description, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Invalid ID']); exit; }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT file_path, file_type, responsive_set FROM media WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $pdo->rollBack(); echo json_encode(['error' => 'Not found']); exit; }

            fm_delete_media_row($pdo, $row, DROOT_PATH);
            $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$id]);

            $fname = basename($row['file_path']);
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'media_delete', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Deleted file: $fname (ID: $id)", $log_ip, $log_ua]);

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('File manager delete error: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to delete file.']);
        }
        exit;
    }

    if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids) || empty($ids)) { echo json_encode(['error' => 'No IDs']); exit; }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, file_path, responsive_set FROM media WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deleted_ids = [];
        foreach ($rows as $row) {
            fm_delete_media_row($pdo, $row, DROOT_PATH);
            $deleted_ids[] = $row['id'];
        }
        if ($deleted_ids) {
            $del_placeholders = implode(',', array_fill(0, count($deleted_ids), '?'));
            $pdo->prepare("DELETE FROM media WHERE id IN ($del_placeholders)")->execute($deleted_ids);
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'media_bulk_delete', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Bulk deleted " . count($deleted_ids) . " file(s)", $log_ip, $log_ua]);
        }
        echo json_encode(['success' => true, 'deleted' => count($deleted_ids)]);
        exit;
    }

    if ($action === 'upload_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'No file uploaded or upload error.']);
            exit;
        }
        $orig_name = $_FILES['file']['name'];
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_exts, true)) {
            echo json_encode(['error' => 'File type not allowed.']);
            exit;
        }
        if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
            echo json_encode(['error' => 'File too large (max 50MB).']);
            exit;
        }

        $file_type = fm_detect_type($ext, $file_type_map);
        if ($file_type === 'pdf') {
            $dir_url = $files_dir;
            $dir_abs = DROOT_PATH . '/' . $files_dir;
        } else {
            $dir_url = $upload_dir_url;
            $dir_abs = $upload_dir_abs;
        }

        $slug_base = generateSlug(pathinfo($orig_name, PATHINFO_FILENAME));
        $fname = $slug_base . '_' . uniqid() . '.' . $ext;
        $disk_path = $dir_abs . $fname;
        $db_path = $dir_url . $fname;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $disk_path)) {
            echo json_encode(['error' => 'Failed to save file.']);
            exit;
        }

        try {
            $pdo->prepare("INSERT INTO media (file_path, file_type, alt_text, uploaded_at) VALUES (?, ?, '', NOW())")
                ->execute([$db_path, $file_type]);
            $new_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'media_upload', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Uploaded $file_type: $orig_name (ID: $new_id)", $log_ip, $log_ua]);
            echo json_encode(['success' => true, 'id' => $new_id, 'path' => $db_path, 'file_type' => $file_type, 'name' => $orig_name]);
        } catch (Exception $e) {
            @unlink($disk_path);
            error_log('File manager upload error: ' . $e->getMessage());
            echo json_encode(['error' => 'Failed to save file record.']);
        }
        exit;
    }

    if ($action === 'bulk_download' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids) || empty($ids)) { echo json_encode(['error' => 'No IDs']); exit; }
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $paths = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'file_path');
        if (empty($paths)) { echo json_encode(['error' => 'Not found']); exit; }
        if (!class_exists('ZipArchive')) { echo json_encode(['error' => 'ZipArchive not available on server.']); exit; }

        $tmp_zip = tempnam(sys_get_temp_dir(), 'fm_zip_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp_zip, ZipArchive::CREATE) !== true) {
            echo json_encode(['error' => 'Could not create zip.']);
            exit;
        }
        $base = rtrim(DROOT_PATH, '/') . '/';
        $used_names = [];
        foreach ($paths as $p) {
            $full = $base . $p;
            if (!file_exists($full)) continue;
            $name = basename($p);
            $i = 1;
            $orig = $name;
            while (in_array($name, $used_names, true)) {
                $ext2 = pathinfo($orig, PATHINFO_EXTENSION);
                $stem = pathinfo($orig, PATHINFO_FILENAME);
                $name = $stem . '-' . (++$i) . ($ext2 ? '.' . $ext2 : '');
            }
            $used_names[] = $name;
            $zip->addFile($full, $name);
        }
        $zip->close();

        $token = bin2hex(random_bytes(16));
        $_SESSION['fm_zip_tokens'][$token] = $tmp_zip;
        echo json_encode(['success' => true, 'token' => $token]);
        exit;
    }

    if ($action === 'bulk_download_file' && isset($_GET['token'])) {
        $token = $_GET['token'];
        $path = $_SESSION['fm_zip_tokens'][$token] ?? null;
        if (!$path || !file_exists($path)) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        unset($_SESSION['fm_zip_tokens'][$token]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="files-' . date('Y-m-d-His') . '.zip"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ─── FILTER / SEARCH / PAGINATION (main page) ─────────────────────────────────
$filter_type = $_GET['type'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$fm_per_page = 40;
$fm_page = max(1, (int)($_GET['fm_page'] ?? 1));
$fm_offset = ($fm_page - 1) * $fm_per_page;

$where = [];
$params = [];
if ($filter_type !== 'all') {
    $where[] = 'file_type = ?';
    $params[] = $filter_type;
}
if ($search !== '') {
    $where[] = '(file_path LIKE ? OR alt_text LIKE ? OR title LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM media $where_sql");
$total_stmt->execute($params);
$fm_total = (int)$total_stmt->fetchColumn();
$fm_total_pages = max(1, (int)ceil($fm_total / $fm_per_page));
if ($fm_page > $fm_total_pages) {
    $fm_page = $fm_total_pages;
    $fm_offset = ($fm_page - 1) * $fm_per_page;
}

$stmt = $pdo->prepare("SELECT * FROM media $where_sql ORDER BY uploaded_at DESC LIMIT $fm_per_page OFFSET $fm_offset");
$stmt->execute($params);
$all_media = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = $pdo->query("SELECT file_type, COUNT(*) as cnt FROM media GROUP BY file_type")->fetchAll(PDO::FETCH_ASSOC);
$type_counts = ['all' => 0];
foreach ($counts as $c) {
    $type_counts[$c['file_type']] = (int)$c['cnt'];
    $type_counts['all'] += (int)$c['cnt'];
}

$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>File Manager - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
/* Dark mode toggle - identical to dashboard.php / categories-manager.php / comments-manager.php */
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
   SHELL CSS — copied 1:1 from dashboard.php / categories-manager.php / comments-manager.php
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

.admin-container {
    display: grid;
    grid-template-columns: 1fr;
    min-height: 100vh;
}
@media (min-width: 1024px) {
    .admin-container { grid-template-columns: var(--sidebar-width) 1fr; }
}

.sidebar {
    position: fixed;
    top: 0; left: 0; bottom: 0;
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
.sidebar.open { transform: translateX(0); }
@media (min-width: 1024px) {
    .sidebar { position: sticky; transform: translateX(0); height: 100vh; top: 0; }
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
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    border-radius: var(--radius-lg);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 1.25rem;
}
.close-sidebar {
    width: 36px; height: 36px;
    border: none;
    background: var(--gray-100);
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    color: var(--gray-600);
    transition: all 0.2s;
}
.close-sidebar:hover { background: var(--gray-200); }
@media (min-width: 1024px) { .close-sidebar { display: none; } }

.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section { margin-bottom: 1.5rem; padding: 0 1rem; }
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
.nav-link:hover { background: var(--gray-50); color: var(--gray-900); }
.nav-link.active { background: var(--primary-lighter); color: var(--primary); font-weight: 600; }
.nav-link i { width: 24px; text-align: center; font-size: 1.125rem; }

.sidebar-footer { padding: 1rem; border-top: 1px solid var(--gray-100); }
.user-card {
    display: flex; align-items: center; gap: 0.875rem;
    padding: 0.875rem;
    background: var(--gray-50);
    border-radius: var(--radius-lg);
}
.user-avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1rem;
}
.user-info { flex: 1; min-width: 0; }
.user-name {
    font-weight: 600; color: var(--gray-900); font-size: 0.9375rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.user-role { font-size: 0.75rem; color: var(--gray-500); }

.sidebar-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0; visibility: hidden;
    transition: all 0.3s;
}
.sidebar-overlay.active { opacity: 1; visibility: visible; }
@media (min-width: 1024px) { .sidebar-overlay { display: none; } }

.main-content { min-width: 0; }

.top-nav {
    position: sticky; top: 0;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    padding: 0.450rem;
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem;
    z-index: 100;
}
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle {
    width: 40px; height: 40px;
    border: none;
    background: var(--gray-100);
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.125rem;
    color: var(--gray-700);
    cursor: pointer;
    transition: all 0.2s;
}
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
.stat-pills { display: none; }
@media (min-width: 768px) { .stat-pills { display: flex; gap: 0.75rem; } }
.stat-pill {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--gray-100);
    border-radius: var(--radius);
    font-size: 0.875rem; font-weight: 500;
    color: var(--gray-700);
}
.stat-pill i { color: var(--primary); }

.icon-btn {
    width: 40px; height: 40px;
    border: none;
    background: transparent;
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.125rem;
    color: var(--gray-600);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}
.icon-btn:hover { background: var(--gray-100); }
.icon-btn .badge {
    position: absolute; top: 6px; right: 6px;
    width: 8px; height: 8px;
    background: var(--danger);
    border-radius: 50%;
    border: 2px solid white;
}

.content-wrapper {
    padding: 1.5rem;
    max-width: 1600px;
    margin: 0 auto;
}
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.alert {
    padding: 1rem 1.25rem;
    border-radius: var(--radius-lg);
    margin-bottom: 1rem;
    display: flex; align-items: center; gap: 0.875rem;
    font-size: 0.9375rem;
    border: 1px solid transparent;
}
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.alert i { font-size: 1.125rem; }

/* ============================================================
   PAGE-SPECIFIC CSS — File Manager only (unique to this page)
   ============================================================ */
.fm-stats { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.fm-stat { background: white; border: 1px solid var(--gray-200); border-radius: var(--radius); padding: 0.6rem 1rem; font-size: 0.8125rem; box-shadow: var(--shadow-sm); }
.fm-stat strong { color: var(--primary); font-size: 1rem; }

.fm-upload-card {
    background: white;
    border: 2px dashed var(--gray-300);
    border-radius: var(--radius-xl);
    padding: 1.75rem 1.5rem;
    text-align: center;
    margin-bottom: 1.5rem;
    cursor: pointer;
    transition: all 0.2s;
}
.fm-upload-card:hover, .fm-upload-card.drag-over { border-color: var(--primary); background: var(--primary-lighter); }
.fm-upload-card i { font-size: 2rem; color: var(--primary); margin-bottom: 0.5rem; }
.fm-upload-card p { color: var(--gray-500); font-size: 0.9375rem; }
.fm-upload-card .browse-hint { color: var(--primary); font-weight: 600; }
.upload-progress { margin-top: 1rem; display: none; }
.upload-progress-bar { height: 6px; background: var(--gray-200); border-radius: 3px; overflow: hidden; }
.upload-progress-fill { height: 100%; width: 0%; background: var(--primary); border-radius: 3px; transition: width 0.3s; }
.upload-progress-text { font-size: 0.8rem; color: var(--gray-500); margin-top: 0.3rem; text-align: center; }

.fm-toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; }
.fm-toolbar-left { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.fm-toolbar-right { display: flex; align-items: center; gap: 0.5rem; }
.fm-search { display: flex; align-items: center; gap: 0.5rem; background: white; border: 1px solid var(--gray-300); border-radius: var(--radius); padding: 0.4rem 0.75rem; }
.fm-search input { border: none; outline: none; font-size: 0.875rem; color: var(--gray-800); background: transparent; width: 180px; }
.fm-search i { color: var(--gray-400); }

.fm-filter-tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.fm-tab {
    padding: 0.4rem 0.85rem;
    border-radius: 20px;
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid var(--gray-200);
    background: white;
    color: var(--gray-600);
    transition: all 0.15s;
    text-decoration: none;
}
.fm-tab:hover { background: var(--gray-50); }
.fm-tab.active { background: var(--primary); color: white; border-color: var(--primary); }
.fm-tab .cnt { font-size: 0.7rem; background: rgba(0,0,0,0.12); border-radius: 10px; padding: 0.1rem 0.4rem; margin-left: 0.3rem; }
.fm-tab.active .cnt { background: rgba(255,255,255,0.25); }

.fm-bulk-bar {
    display: none; align-items: center; gap: 0.75rem;
    background: var(--primary-lighter);
    border: 1px solid var(--primary-light);
    border-radius: var(--radius);
    padding: 0.6rem 1rem;
    margin-bottom: 1rem;
}
.fm-bulk-bar.visible { display: flex; flex-wrap: wrap; }
.fm-bulk-count { font-weight: 600; color: var(--primary); }

.fm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
@media (max-width: 640px) { .fm-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); } }
.fm-item { background: white; border: 2px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; cursor: pointer; transition: all 0.2s; position: relative; }
.fm-item:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(124, 58, 237, 0.15); }
.fm-item.selected { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
.fm-item-check {
    position: absolute; top: 0.45rem; left: 0.45rem;
    width: 20px; height: 20px; border-radius: 4px;
    border: 2px solid white; background: rgba(0,0,0,0.3);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 0.65rem; transition: all 0.15s; z-index: 2;
    opacity: 0; pointer-events: none;
}
.fm-item:hover .fm-item-check { opacity: 1; }
.fm-item.selected .fm-item-check { background: var(--primary); border-color: var(--primary); opacity: 1; }
.selection-mode-active .fm-item .fm-item-check { opacity: 1; }
.fm-item-thumb { width: 100%; height: 120px; object-fit: cover; display: block; background: var(--gray-100); }
.fm-item-icon { width: 100%; height: 120px; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; background: var(--gray-50); }
.fm-item-icon.pdf { color: #e74c3c; }
.fm-item-icon.video { color: #3498db; }
.fm-item-icon.audio { color: #9b59b6; }
.fm-item-icon.document { color: #27ae60; }
.fm-item-icon.archive { color: #f39c12; }
.fm-item-icon.other { color: var(--gray-400); }
.fm-item-info { padding: 0.5rem 0.6rem; }
.fm-item-name { font-size: 0.7rem; color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.fm-item-type { font-size: 0.65rem; color: var(--gray-400); text-transform: uppercase; }
.fm-empty { text-align: center; padding: 3rem 2rem; color: var(--gray-400); }
.fm-empty i { font-size: 3rem; display: block; margin-bottom: 1rem; color: var(--gray-300); }

.fm-pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--gray-200); }
.fm-page-btn { padding: 0.45rem 0.9rem; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: 0.83rem; color: var(--gray-700); text-decoration: none; background: white; transition: background 0.15s; }
.fm-page-btn:hover:not(.disabled) { background: var(--gray-50); }
.fm-page-btn.disabled { opacity: 0.45; pointer-events: none; }
.fm-page-info { font-size: 0.8rem; color: var(--gray-500); white-space: nowrap; }

.fm-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 2000; display: none; align-items: center; justify-content: center; }
.fm-modal-overlay.open { display: flex; }
.fm-modal { background: white; border-radius: var(--radius-lg); box-shadow: 0 25px 80px rgba(0,0,0,0.3); width: 96%; max-width: 760px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
.fm-modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.fm-modal-header h3 { font-size: 1rem; font-weight: 600; color: var(--gray-900); }
.fm-modal-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: var(--gray-500); line-height: 1; padding: 0.2rem 0.5rem; border-radius: 4px; }
.fm-modal-close:hover { background: #fee2e2; color: #ef4444; }
.fm-modal-body { display: flex; flex: 1; overflow: hidden; min-height: 0; }
.fm-modal-preview { width: 260px; flex-shrink: 0; background: var(--gray-50); border-right: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: center; padding: 1rem; flex-direction: column; gap: 0.75rem; }
@media (max-width: 600px) { .fm-modal-preview { width: 100%; height: 180px; border-right: none; border-bottom: 1px solid var(--gray-200); } }
.fm-modal-preview img { max-width: 100%; max-height: 180px; object-fit: contain; border-radius: 6px; }
.fm-modal-preview .icon-preview { font-size: 4rem; text-align: center; }
.fm-file-meta { font-size: 0.8125rem; color: var(--gray-500); text-align: center; }
.fm-modal-fields { flex: 1; padding: 1.25rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.9rem; }
.fm-field label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--gray-700); margin-bottom: 0.3rem; }
.fm-field input, .fm-field textarea { border: 1px solid var(--gray-300); border-radius: var(--radius); padding: 0.5rem 0.7rem; font-size: 0.85rem; width: 100%; outline: none; transition: border-color 0.15s; font-family: inherit; }
.fm-field input:focus, .fm-field textarea:focus { border-color: var(--primary); }
.fm-field .url-row { display: flex; gap: 0.5rem; }
.fm-field .url-row input { flex: 1; }
.fm-field .copy-btn { padding: 0.45rem 0.75rem; background: var(--gray-100); border: 1px solid var(--gray-300); border-radius: var(--radius); cursor: pointer; font-size: 0.8rem; color: var(--gray-600); transition: all 0.15s; }
.fm-field .copy-btn:hover { background: var(--gray-200); }
.fm-modal-footer { padding: 0.875rem 1.25rem; border-top: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; gap: 0.75rem; flex-wrap: wrap; }
.fm-modal-footer-left { display: flex; gap: 0.5rem; }
.fm-modal-footer-right { display: flex; gap: 0.5rem; }

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.55rem 1.1rem;
    border-radius: var(--radius);
    font-size: 0.85rem;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover:not(:disabled) { background: var(--primary-dark); }
.btn-secondary { background: var(--gray-100); color: var(--gray-700); }
.btn-secondary:hover:not(:disabled) { background: var(--gray-200); }
.btn-danger { background: #fee2e2; color: var(--danger); }
.btn-danger:hover:not(:disabled) { background: #fecaca; }
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
                    <h1>File Manager</h1>
                    <p>Upload and manage all your files</p>
                </div>
            </div>

            <div class="nav-right">
                <div class="stat-pills">
                    <div class="stat-pill">
                        <i class="fas fa-folder"></i>
                        <span><?= $type_counts['all'] ?? 0 ?> Files</span>
                    </div>
                </div>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <!-- Stats -->
            <div class="fm-stats">
                <div class="fm-stat">Total Files: <strong><?= $type_counts['all'] ?? 0 ?></strong></div>
                <?php foreach (['image' => 'Images', 'pdf' => 'PDFs', 'video' => 'Videos', 'audio' => 'Audio', 'document' => 'Docs', 'archive' => 'Archives', 'banner' => 'Banners'] as $t => $label): if (!empty($type_counts[$t])): ?>
                <div class="fm-stat"><?= $label ?>: <strong><?= $type_counts[$t] ?></strong></div>
                <?php endif; endforeach; ?>
            </div>

            <!-- Upload Drop Zone -->
            <div class="fm-upload-card" id="dropZone" onclick="document.getElementById('fileUploadInput').click()">
                <i class="fas fa-cloud-upload-alt"></i>
                <p>Drag &amp; drop files here or <span class="browse-hint">browse to upload</span></p>
                <p style="font-size:.75rem;margin-top:.3rem;color:var(--gray-400);">Images, PDFs, Videos, Audio, Docs, ZIPs — max 50MB</p>
                <input type="file" id="fileUploadInput" multiple style="display:none"
                    accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.mp4,.webm,.ogg,.mov,.avi,.mkv,.mp3,.wav,.aac,.flac,.m4a,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.tar,.gz,.7z">
                <div class="upload-progress" id="uploadProgress">
                    <div class="upload-progress-bar"><div class="upload-progress-fill" id="uploadFill"></div></div>
                    <div class="upload-progress-text" id="uploadText">Uploading...</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="fm-toolbar">
                <div class="fm-toolbar-left">
                    <form method="GET" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
                        <div class="fm-search">
                            <i class="fas fa-search"></i>
                            <input type="text" name="q" placeholder="Search files..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <button type="submit" class="btn btn-secondary">Search</button>
                        <?php if ($search): ?><a href="?type=<?= htmlspecialchars($filter_type) ?>" class="btn btn-secondary">Clear</a><?php endif; ?>
                    </form>
                </div>
                <div class="fm-toolbar-right">
                    <button class="btn btn-secondary" id="selectModeBtn" onclick="toggleSelectMode()" title="Enable multi-select mode">
                        <i class="fas fa-check-square"></i> Select
                    </button>
                    <button class="btn btn-primary" onclick="document.getElementById('fileUploadInput').click()">
                        <i class="fas fa-plus"></i> Upload Files
                    </button>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="fm-filter-tabs">
                <?php
                $tabs = ['all' => 'All Files', 'image' => 'Images', 'pdf' => 'PDFs', 'video' => 'Videos', 'audio' => 'Audio', 'document' => 'Documents', 'archive' => 'Archives', 'banner' => 'Banners'];
                foreach ($tabs as $t => $label):
                    $cnt = $type_counts[$t] ?? 0;
                    if ($t !== 'all' && $cnt === 0) continue;
                ?>
                <a href="?type=<?= $t ?><?= $search ? '&q=' . urlencode($search) : '' ?>" class="fm-tab <?= $filter_type === $t ? 'active' : '' ?>">
                    <?= $label ?><span class="cnt"><?= $cnt ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Bulk Action Bar -->
            <div class="fm-bulk-bar" id="bulkBar">
                <span class="fm-bulk-count" id="bulkCount">0 selected</span>
                <button class="btn btn-secondary" onclick="bulkDownload()"><i class="fas fa-download"></i> Download Selected</button>
                <button class="btn btn-danger" onclick="bulkDelete()"><i class="fas fa-trash"></i> Delete Selected</button>
                <button class="btn btn-secondary" onclick="clearSelection()">Cancel</button>
            </div>

            <!-- Media Grid -->
            <?php if (empty($all_media)): ?>
            <div class="fm-empty">
                <i class="fas fa-folder-open"></i>
                <p>No files found<?= $search ? " for \"" . htmlspecialchars($search) . "\"" : '' ?>.</p>
                <p style="font-size:.8rem;margin-top:.25rem;">Upload some files to get started.</p>
            </div>
            <?php else: ?>
            <div class="fm-grid" id="mediaGrid">
                <?php foreach ($all_media as $m):
                    $path = $m['file_path'];
                    $fname = basename($path);
                    $ftype = $m['file_type'] ?? 'other';
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    $is_img = in_array($ftype, ['image', 'banner']);
                    $icon_class = match ($ftype) {
                        'pdf' => 'fa-file-pdf pdf',
                        'video' => 'fa-file-video video',
                        'audio' => 'fa-file-audio audio',
                        'document' => 'fa-file-word document',
                        'archive' => 'fa-file-archive archive',
                        default => 'fa-file other',
                    };
                ?>
                <div class="fm-item" data-id="<?= $m['id'] ?>" data-type="<?= htmlspecialchars($ftype) ?>"
                     onclick="handleItemClick(event, <?= $m['id'] ?>, '<?= addslashes($path) ?>', '<?= addslashes($fname) ?>', '<?= addslashes($ftype) ?>')">
                    <div class="fm-item-check"><i class="fas fa-check"></i></div>
                    <?php if ($is_img): ?>
                        <img class="fm-item-thumb" src="/<?= htmlspecialchars($path) ?>" alt="<?= htmlspecialchars($m['alt_text'] ?? '') ?>" loading="lazy">
                    <?php else: ?>
                        <div class="fm-item-icon <?= $ftype ?>"><i class="fas <?= $icon_class ?>"></i></div>
                    <?php endif; ?>
                    <div class="fm-item-info">
                        <div class="fm-item-name" title="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></div>
                        <div class="fm-item-type"><?= strtoupper($ext) ?> &bull; <?= htmlspecialchars($ftype) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($fm_total_pages > 1): ?>
            <div class="fm-pagination">
                <?php
                $pg_base_params = array_filter([
                    'type' => $filter_type !== 'all' ? $filter_type : null,
                    'q'    => $search !== '' ? $search : null,
                ], function ($v) { return $v !== null; });
                $pg_url = function ($p) use ($pg_base_params) { return '?' . http_build_query($pg_base_params + ['fm_page' => (int)$p]); };
                ?>
                <a href="<?= $pg_url(max(1, $fm_page - 1)) ?>" class="fm-page-btn <?= $fm_page <= 1 ? 'disabled' : '' ?>">&laquo; Prev</a>
                <span class="fm-page-info">Page <?= $fm_page ?> of <?= $fm_total_pages ?> &middot; <?= $fm_total ?> file<?= $fm_total === 1 ? '' : 's' ?></span>
                <a href="<?= $pg_url(min($fm_total_pages, $fm_page + 1)) ?>" class="fm-page-btn <?= $fm_page >= $fm_total_pages ? 'disabled' : '' ?>">Next &raquo;</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Detail Modal -->
<div class="fm-modal-overlay" id="detailModal">
    <div class="fm-modal">
        <div class="fm-modal-header">
            <h3 id="detailModalTitle">File Details</h3>
            <button class="fm-modal-close" onclick="closeDetailModal()">&times;</button>
        </div>
        <div class="fm-modal-body">
            <div class="fm-modal-preview">
                <img id="detailPreviewImg" src="" alt="" style="display:none;">
                <div id="detailPreviewIcon" class="icon-preview" style="display:none;"></div>
                <div class="fm-file-meta" id="detailFileMeta"></div>
            </div>
            <div class="fm-modal-fields">
                <div class="fm-field">
                    <label>Alt Text <small style="color:var(--gray-400);font-weight:400;">(for images — SEO &amp; accessibility)</small></label>
                    <input type="text" id="detailAlt" placeholder="e.g. Students studying in a classroom">
                </div>
                <div class="fm-field">
                    <label>Title</label>
                    <input type="text" id="detailTitle" placeholder="File title">
                </div>
                <div class="fm-field">
                    <label>Caption</label>
                    <textarea id="detailCaption" rows="2" placeholder="Short caption shown below the image"></textarea>
                </div>
                <div class="fm-field">
                    <label>Description</label>
                    <textarea id="detailDescription" rows="2" placeholder="Longer description (optional)"></textarea>
                </div>
                <div class="fm-field">
                    <label>File URL</label>
                    <div class="url-row">
                        <input type="text" id="detailUrl" readonly>
                        <button class="copy-btn" onclick="copyDetailUrl()"><i class="fas fa-copy"></i> Copy</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="fm-modal-footer">
            <div class="fm-modal-footer-left">
                <button class="btn btn-danger" onclick="deleteSingleFromModal()"><i class="fas fa-trash"></i> Delete</button>
                <a id="detailDownloadBtn" href="#" target="_blank" class="btn btn-secondary"><i class="fas fa-external-link-alt"></i> Open</a>
            </div>
            <div class="fm-modal-footer-right">
                <button class="btn btn-secondary" onclick="closeDetailModal()">Cancel</button>
                <button class="btn btn-primary" id="detailSaveBtn" onclick="saveDetail()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>
</div>

<footer>
    <div style="padding: 10px 20px; text-align: center; color: #6C6C6C;">
        <p style="font-size:0.8rem; margin-bottom:0px;">&copy; 2025-2026 EduMint24. All rights reserved.</p>
    </div>
</footer>

<script>
const ADMIN_URL = "/admin";
const FM_CSRF = "<?= addslashes($csrf) ?>";

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function showFmToast(msg, type) {
    Toastify({
        text: msg,
        duration: 3000,
        position: 'center',
        style: {
            background: type === 'error' ? '#ef4444' : '#10b981',
            borderRadius: '12px', padding: '14px 22px', fontSize: '14px',
            fontWeight: '500', boxShadow: '0 8px 24px rgba(0,0,0,0.15)'
        }
    }).showToast();
}

// ── Selection mode ──
let _selectedIds = new Set();
let _selectionMode = false;
let _currentDetailId = null;

function toggleSelectMode() {
    _selectionMode = !_selectionMode;
    const btn = document.getElementById('selectModeBtn');
    const grid = document.getElementById('mediaGrid');
    if (_selectionMode) {
        btn.innerHTML = '<i class="fas fa-times"></i> Done';
        btn.classList.remove('btn-secondary');
        btn.classList.add('btn-primary');
        if (grid) grid.classList.add('selection-mode-active');
    } else {
        btn.innerHTML = '<i class="fas fa-check-square"></i> Select';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
        if (grid) grid.classList.remove('selection-mode-active');
        clearSelection();
    }
}

function handleItemClick(event, id, path, fname, ftype) {
    if (_selectionMode || event.ctrlKey || event.metaKey) {
        toggleSelect(id, event.currentTarget);
    } else {
        openDetailModal(id, path, fname, ftype);
    }
}

function toggleSelect(id, el) {
    if (_selectedIds.has(id)) {
        _selectedIds.delete(id);
        el.classList.remove('selected');
    } else {
        _selectedIds.add(id);
        el.classList.add('selected');
    }
    updateBulkBar();
}

function clearSelection() {
    _selectedIds.clear();
    document.querySelectorAll('.fm-item.selected').forEach(el => el.classList.remove('selected'));
    updateBulkBar();
}

function updateBulkBar() {
    const bar = document.getElementById('bulkBar');
    const cnt = document.getElementById('bulkCount');
    cnt.textContent = _selectedIds.size + ' selected';
    if (_selectedIds.size > 0) bar.classList.add('visible');
    else bar.classList.remove('visible');
}

// ── Bulk actions ──
function bulkDelete() {
    if (!_selectedIds.size) return;
    if (!confirm(_selectedIds.size + ' selected file(s) will be permanently deleted. Continue?')) return;
    const fd = new FormData();
    fd.append('csrf_token', FM_CSRF);
    fd.append('ids', JSON.stringify([..._selectedIds]));
    fetch('/admin/file-manager.php?ajax=bulk_delete', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                _selectedIds.forEach(id => {
                    const el = document.querySelector('.fm-item[data-id="' + id + '"]');
                    if (el) el.remove();
                });
                _selectedIds.clear();
                updateBulkBar();
                showFmToast('Deleted ' + d.deleted + ' file(s) successfully.');
            } else { showFmToast('Error: ' + (d.error || 'Unknown'), 'error'); }
        })
        .catch(() => showFmToast('Network error.', 'error'));
}

function bulkDownload() {
    if (!_selectedIds.size) return;
    showFmToast('Preparing zip…');
    const fd = new FormData();
    fd.append('csrf_token', FM_CSRF);
    fd.append('ids', JSON.stringify([..._selectedIds]));
    fetch('/admin/file-manager.php?ajax=bulk_download', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.token) {
                window.location.href = '/admin/file-manager.php?ajax=bulk_download_file&token=' + encodeURIComponent(d.token);
            } else { showFmToast('Error: ' + (d.error || 'Unknown'), 'error'); }
        })
        .catch(() => showFmToast('Network error.', 'error'));
}

// ── Detail modal ──
function openDetailModal(id, path, fname, ftype) {
    _currentDetailId = id;
    document.getElementById('detailModal').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('detailModalTitle').textContent = fname;
    document.getElementById('detailAlt').value = '';
    document.getElementById('detailTitle').value = '';
    document.getElementById('detailCaption').value = '';
    document.getElementById('detailDescription').value = '';
    document.getElementById('detailUrl').value = window.location.origin + '/' + path;
    document.getElementById('detailDownloadBtn').href = '/' + path;

    const imgEl = document.getElementById('detailPreviewImg');
    const iconEl = document.getElementById('detailPreviewIcon');
    const meta = document.getElementById('detailFileMeta');
    const ext = fname.split('.').pop().toUpperCase();

    if (ftype === 'image' || ftype === 'banner') {
        imgEl.src = '/' + path;
        imgEl.style.display = '';
        iconEl.style.display = 'none';
    } else {
        imgEl.style.display = 'none';
        const icons = { pdf: 'fa-file-pdf', video: 'fa-file-video', audio: 'fa-file-audio', document: 'fa-file-lines', archive: 'fa-file-zipper' };
        iconEl.innerHTML = '<i class="fas ' + (icons[ftype] || 'fa-file') + '"></i>';
        iconEl.style.display = '';
    }
    meta.innerHTML = '<strong>' + ext + '</strong> file<br>' + fname;

    fetch('/admin/file-manager.php?ajax=get_media_detail&id=' + id)
        .then(r => r.json())
        .then(d => {
            if (d.error) return;
            document.getElementById('detailAlt').value = d.alt_text || '';
            document.getElementById('detailTitle').value = d.title || '';
            document.getElementById('detailCaption').value = d.caption || '';
            document.getElementById('detailDescription').value = d.description || '';
        }).catch(() => {});
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('open');
    document.body.style.overflow = '';
    _currentDetailId = null;
}

function saveDetail() {
    if (!_currentDetailId) return;
    const btn = document.getElementById('detailSaveBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    btn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', FM_CSRF);
    fd.append('id', _currentDetailId);
    fd.append('alt_text', document.getElementById('detailAlt').value);
    fd.append('title', document.getElementById('detailTitle').value);
    fd.append('caption', document.getElementById('detailCaption').value);
    fd.append('description', document.getElementById('detailDescription').value);
    fetch('/admin/file-manager.php?ajax=update_media', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.success) {
                btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                setTimeout(() => { btn.innerHTML = '<i class="fas fa-save"></i> Save'; }, 2000);
                showFmToast('Details saved.');
            } else { btn.innerHTML = '<i class="fas fa-save"></i> Save'; showFmToast('Error: ' + (d.error || 'Unknown'), 'error'); }
        }).catch(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save'; });
}

function deleteSingleFromModal() {
    if (!_currentDetailId) return;
    if (!confirm('This file will be permanently deleted. Continue?')) return;
    const fd = new FormData();
    fd.append('csrf_token', FM_CSRF);
    fd.append('id', _currentDetailId);
    fetch('/admin/file-manager.php?ajax=delete_media', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                const el = document.querySelector('.fm-item[data-id="' + _currentDetailId + '"]');
                if (el) el.remove();
                closeDetailModal();
                showFmToast('File deleted.');
            } else { showFmToast('Error: ' + (d.error || 'Unknown'), 'error'); }
        }).catch(() => showFmToast('Network error.', 'error'));
}

function copyDetailUrl() {
    const url = document.getElementById('detailUrl').value;
    navigator.clipboard.writeText(url).then(() => showFmToast('URL copied!'))
        .catch(() => { document.getElementById('detailUrl').select(); document.execCommand('copy'); });
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        closeDetailModal();
        if (_selectionMode) toggleSelectMode();
    }
});

// ── Upload ──
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileUploadInput');
const progWrap = document.getElementById('uploadProgress');
const progFill = document.getElementById('uploadFill');
const progText = document.getElementById('uploadText');

fileInput.addEventListener('change', () => { if (fileInput.files.length) doUpload(fileInput.files); });

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) doUpload(e.dataTransfer.files);
});

function doUpload(files) {
    const arr = Array.from(files);
    if (!arr.length) return;
    progWrap.style.display = 'block';
    progFill.style.width = '0%';
    progFill.style.background = 'var(--primary)';
    let done = 0;

    function uploadOne(file) {
        progText.textContent = 'Uploading: ' + file.name + ' (' + (done + 1) + '/' + arr.length + ')';
        const fd = new FormData();
        fd.append('csrf_token', FM_CSRF);
        fd.append('file', file);
        return fetch('/admin/file-manager.php?ajax=upload_file', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                done++;
                progFill.style.width = Math.round((done / arr.length) * 100) + '%';
                if (d.success) {
                    addItemToGrid(d.id, d.path, d.file_type, file.name);
                } else {
                    showFmToast('Failed: ' + file.name + ' — ' + (d.error || 'Unknown'), 'error');
                }
            });
    }

    arr.reduce((chain, f) => chain.then(() => uploadOne(f)), Promise.resolve())
        .then(() => {
            progText.textContent = 'Upload complete';
            progFill.style.background = '#10b981';
            setTimeout(() => { progWrap.style.display = 'none'; progFill.style.width = '0%'; }, 2000);
        })
        .catch(() => { progText.textContent = 'Upload failed'; });
}

function addItemToGrid(id, path, ftype, fname) {
    const grid = document.getElementById('mediaGrid');
    if (!grid) { location.reload(); return; }
    const ext = fname.split('.').pop().toUpperCase();
    const is_img = ftype === 'image' || ftype === 'banner';
    const icons = { pdf: 'fa-file-pdf pdf', video: 'fa-file-video video', audio: 'fa-file-audio audio', document: 'fa-file-word document', archive: 'fa-file-archive archive' };
    const icon = icons[ftype] || 'fa-file other';

    const div = document.createElement('div');
    div.className = 'fm-item';
    div.dataset.id = id;
    div.dataset.type = ftype;
    div.setAttribute('onclick', 'handleItemClick(event, ' + id + ', \'' + path.replace(/'/g, "\\'") + '\', \'' + fname.replace(/'/g, "\\'") + '\', \'' + ftype + '\')');
    div.innerHTML = `
        <div class="fm-item-check"><i class="fas fa-check"></i></div>
        ${is_img
            ? `<img class="fm-item-thumb" src="/${path}" alt="" loading="lazy">`
            : `<div class="fm-item-icon ${ftype}"><i class="fas ${icon}"></i></div>`
        }
        <div class="fm-item-info">
            <div class="fm-item-name" title="${fname}">${fname}</div>
            <div class="fm-item-type">${ext} &bull; ${ftype}</div>
        </div>`;
    grid.insertBefore(div, grid.firstChild);
    showFmToast('Uploaded: ' + fname);
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

</body>
</html>