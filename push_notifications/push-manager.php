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

// 2. Handle Logging (AJAX Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_push') {
    try {
        $log_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $log_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        $user_id = $_SESSION['user_id'];
        
        $title = $_POST['title'] ?? 'No Title';
        $post_id = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : 'N/A';
        
        $log_desc = "Sent Push Notification: " . mb_strimwidth($title, 0, 50, "...") . " (Linked Post ID: " . $post_id . ")";
        
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'push_send', ?, ?, ?)");
        $stmt->execute([$user_id, $log_desc, $log_ip, $log_ua]);
        
        echo json_encode(['saved' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// 3. Fetch Posts
$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.content, p.slug, m.file_path AS banner_image
    FROM posts p 
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.status = 'published'
    ORDER BY p.date DESC LIMIT 100
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get subscriber counts
$stmt = $pdo->query("SELECT COUNT(*) FROM push_subscriptions");
$push_count = $stmt->fetchColumn();

$pushToken = $config['push_secret'];
$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
    <title>Push Notifications - <?= htmlspecialchars($site_name) ?> Admin</title>
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

    .dark-mode-toggle i {
        transition: transform .4s ease, opacity .3s ease;
    }
    .dark-mode-toggle i.rotate {
        transform: rotate(180deg);
    }

    /* Push Notification Specific Styles */
    .push-grid {
        display: grid;
        gap: 1.5rem;
        grid-template-columns: 1fr;
    }

    @media (min-width: 1024px) {
        .push-grid {
            grid-template-columns: 1fr 380px;
        }
    }

    .card {
        background: white;
        border-radius: var(--radius-xl);
        border: 1px solid var(--gray-100);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .card-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--gray-100);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .card-header h2 {
        font-size: 1rem;
        font-weight: 700;
        color: var(--gray-900);
        margin: 0;
    }

    .card-header i {
        color: var(--primary);
        font-size: 1.125rem;
    }

    .card-body {
        padding: 1.5rem;
    }

    .form-section {
        margin-bottom: 1.5rem;
    }

    .form-section:last-child {
        margin-bottom: 0;
    }

    .section-label {
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--gray-500);
        margin-bottom: 1rem;
        display: block;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group:last-child {
        margin-bottom: 0;
    }

    .form-label {
        display: block;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--gray-600);
        margin-bottom: 0.5rem;
    }
      .form-label .required {
        color: var(--danger);
        margin-left: 0.25rem;
    }

    .form-control {
        width: 100%;
        padding: 0.625rem 0.875rem;
        border: 1px solid var(--gray-200);
        border-radius: var(--radius);
        font-size: 0.9375rem;
        font-family: inherit;
        color: var(--gray-800);
        background: white;
        transition: all 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    textarea.form-control {
        resize: vertical;
        min-height: 80px;
    }

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

    .btn-primary:hover:not(:disabled) {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
    }

    .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .btn-secondary {
        background: var(--gray-100);
        color: var(--gray-700);
    }

    .btn-secondary:hover {
        background: var(--gray-200);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }

    .btn-success:hover:not(:disabled) {
        background: #059669;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--gray-200);
        color: var(--gray-700);
    }

    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-lighter);
    }

    .btn-lg {
        padding: 0.875rem 1.5rem;
        font-size: 1rem;
    }

    .btn-block {
        width: 100%;
    }

    .btn i {
        font-size: 1rem;
    }

    /* Selected Post Preview */
    .selected-post {
        background: var(--gray-50);
        border: 1px solid var(--gray-200);
        border-radius: var(--radius-lg);
        padding: 1rem;
        display: none;
        align-items: center;
        gap: 1rem;
        margin-top: 0.75rem;
    }

    .selected-post.active {
        display: flex;
    }

    .selected-post img {
        width: 60px;
        height: 60px;
        object-fit: cover;
        border-radius: var(--radius);
        flex-shrink: 0;
    }

    .selected-post-info {
        flex: 1;
        min-width: 0;
    }

    .selected-post-label {
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--gray-500);
        margin-bottom: 0.25rem;
    }

    .selected-post-title {
        font-weight: 600;
        color: var(--gray-900);
        font-size: 0.9375rem;
        line-height: 1.4;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Smartphone Mockup */
    .preview-container {
        position: sticky;
        top: 100px;
    }

    .preview-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }

    .preview-header h3 {
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--gray-500);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .smartphone-frame {
        width: 280px;
        height: 560px;
        background: #111;
        border-radius: 40px;
        box-shadow: 0 0 0 10px #333, 0 20px 50px rgba(0,0,0,0.2);
        position: relative;
        margin: 0 auto;
        overflow: hidden;
        border: 4px solid #444;
    }

    .smartphone-notch {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 120px;
        height: 25px;
        background: #111;
        border-radius: 0 0 15px 15px;
        z-index: 5;
    }

    .smartphone-screen {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        position: relative;
        padding-top: 60px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .lock-screen-time {
        text-align: center;
        color: rgba(255,255,255,0.9);
        font-size: 48px;
        font-weight: 300;
        margin-bottom: 0.5rem;
        font-family: 'Inter', sans-serif;
        letter-spacing: -1px;
    }

    .lock-screen-date {
        text-align: center;
        color: rgba(255,255,255,0.8);
        font-size: 14px;
        margin-bottom: 2rem;
    }

    .notif-card {
        background: rgba(255, 255, 255, 0.95);
        margin: 0 15px;
        border-radius: var(--radius-lg);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        overflow: hidden;
        animation: slideIn 0.3s ease-out;
        width: calc(100% - 30px);
        backdrop-filter: blur(10px);
    }

    @keyframes slideIn {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .notif-header {
        padding: 10px 12px 5px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 11px;
        color: var(--gray-600);
    }

    .notif-app-info {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .notif-icon {
        width: 18px;
        height: 18px;
        background: var(--primary);
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 10px;
    }

    .notif-body {
        padding: 5px 12px 12px;
    }

    .notif-title {
        font-weight: 700;
        font-size: 14px;
        color: var(--gray-900);
        margin-bottom: 2px;
        line-height: 1.3;
    }

    .notif-desc {
        font-size: 12px;
        color: var(--gray-600);
        line-height: 1.4;
        margin-bottom: 8px;
    }

    .notif-big-img {
        width: 100%;
        height: 110px;
        object-fit: cover;
        border-radius: var(--radius);
        display: block;
    }

    .preview-footer {
        text-align: center;
        margin-top: 1.5rem;
        font-size: 0.75rem;
        color: var(--gray-500);
        padding: 0 1rem;
    }

    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 2000;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .modal-content {
        background: white;
        border-radius: var(--radius-xl);
        width: 100%;
        max-width: 600px;
        max-height: 80vh;
        display: flex;
        flex-direction: column;
        box-shadow: var(--shadow-lg);
        transform: scale(0.95);
        transition: transform 0.3s;
    }

    .modal-overlay.active .modal-content {
        transform: scale(1);
    }

    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--gray-100);
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .modal-header h3 {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--gray-900);
        flex: 1;
        margin: 0;
    }

    .modal-close {
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

    .modal-close:hover {
        background: var(--gray-200);
        color: var(--gray-900);
    }

    .modal-body {
        padding: 1rem;
        overflow-y: auto;
        flex: 1;
    }

    .search-box {
        position: relative;
        margin-bottom: 1rem;
    }

    .search-box input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.5rem;
        border: 1px solid var(--gray-200);
        border-radius: var(--radius-lg);
        font-size: 0.9375rem;
        transition: all 0.2s;
    }

    .search-box input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    .search-box i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--gray-400);
    }

    .post-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .post-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 0.875rem;
        border: 1px solid var(--gray-100);
        border-radius: var(--radius-lg);
        cursor: pointer;
        transition: all 0.2s;
        background: white;
        text-align: left;
        width: 100%;
        border-left: 3px solid transparent;
    }

    .post-item:hover {
        background: var(--gray-50);
        border-color: var(--primary-light);
        border-left-color: var(--primary);
        transform: translateX(4px);
    }

    .post-item img {
        width: 48px;
        height: 48px;
        object-fit: cover;
        border-radius: var(--radius);
        flex-shrink: 0;
    }

    .post-item-info {
        flex: 1;
        min-width: 0;
    }

    .post-item-title {
        font-weight: 600;
        color: var(--gray-900);
        font-size: 0.9375rem;
        margin-bottom: 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .post-item-meta {
        font-size: 0.75rem;
        color: var(--gray-500);
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--gray-500);
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        color: var(--gray-300);
    }

    /* Alert Messages */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: var(--radius-lg);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.875rem;
        font-size: 0.9375rem;
        border: 1px solid transparent;
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
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

    .alert-warning {
        background: #fffbeb;
        color: #92400e;
        border-color: #fcd34d;
    }

    .alert i {
        font-size: 1.125rem;
    }

    /* Status Message Container */
    #statusMsg {
        margin-top: 1rem;
    }

    /* Loading Spinner */
    .spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255,255,255,0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Responsive */
    @media (max-width: 640px) {
        .smartphone-frame {
            width: 260px;
            height: 520px;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .form-actions .btn {
            width: 100%;
        }
    }

    /* Touch optimizations */
    @media (hover: none) {
        .post-item:hover {
            transform: none;
        }
    }

    .form-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1.5rem;
    }

    .form-actions .btn {
        flex: 1;
    }

    .loader-container {
        text-align: center;
        padding: 2rem;
        color: var(--gray-400);
    }

    .loader-container i {
        font-size: 2rem;
        animation: spin 1s linear infinite;
    }
    </style>
</head>
<body>
<div class="admin-container">
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/admin/components/sidebar-nav.php'; ?>

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
                    <h1>Push Notifications</h1>
                    <p>Send notifications to subscribers</p>
                </div>
            </div>

            <div class="nav-right">
                <div class="stat-pills">
                    <div class="stat-pill">
                        <i class="fas fa-bell"></i>
                        <span><?= formatViews($push_count) ?> Subscribers</span>
                    </div>
                </div>

                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include $_SERVER['DOCUMENT_ROOT'] . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <div class="push-grid">
                <!-- Compose Form -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-pen-to-square"></i>
                        <h2>Compose Notification</h2>
                    </div>
                    <div class="card-body">
                        <!-- Select Post Section -->
                        <div class="form-section">
                            <span class="section-label">1. Select Content</span>
                            
                            <button type="button" class="btn btn-primary btn-block" onclick="openModal()">
                                <i class="fas fa-search"></i>
                                Search & Select Post
                            </button>

                            <div id="selectedPostPreview" class="selected-post">
                                <img id="selectedPostImg" src="" alt="">
                                <div class="selected-post-info">
                                    <div class="selected-post-label">Linked Post</div>
                                    <div id="selectedPostTitle" class="selected-post-title"></div>
                                </div>
                            </div>

                            <input type="hidden" id="selectedPostId">
                            <input type="hidden" id="selectedUrl">
                            <input type="hidden" id="selectedImage">
                        </div>

                        <!-- Notification Details Section -->
                        <div class="form-section">
                            <span class="section-label">2. Notification Details</span>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Target URL <span class="required">*</span>
                                </label>
                                <input type="url" id="customUrl" class="form-control" placeholder="https://example.com/page">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Title</label>
                                <input type="text" id="customTitle" class="form-control" placeholder="Notification Title">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Message Body</label>
                                <textarea id="customBody" class="form-control" rows="3" placeholder="Enter notification message..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Banner Image URL</label>
                                <input type="text" id="customImage" class="form-control" placeholder="https://...">
                            </div>
                        </div>

                        <!-- Actions -->
                        <div id="statusMsg"></div>

                        <div class="form-actions">
                            <button type="button" id="sendBtn" class="btn btn-success btn-lg" disabled>
                                <i class="fas fa-paper-plane"></i>
                                Send to All
                            </button>
                            <button type="button" id="viewLogsBtn" class="btn btn-outline btn-lg">
                                <i class="fas fa-chart-line"></i>
                                View Logs
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="preview-container">
                    <div class="preview-header">
                        <h3><i class="fas fa-mobile-screen"></i> Live Preview</h3>
                    </div>
                    
                    <div class="smartphone-frame">
                        <div class="smartphone-notch"></div>
                        
                        <div class="smartphone-screen">
                            <div class="lock-screen-time" id="clockTime"><?= date('H:i') ?></div>
                            <div class="lock-screen-date" id="clockDate"><?= date('l, F j') ?></div>

                            <div class="notif-card">
                                <div class="notif-header">
                                    <div class="notif-app-info">
                                        <div class="notif-icon">
                                            <i class="fas fa-bell"></i>
                                        </div>
                                        <span class="fw-bold"><?= htmlspecialchars($site_name) ?></span>
                                    </div>
                                    <span>now <i class="fas fa-chevron-down" style="font-size: 10px; margin-left: 2px;"></i></span>
                                </div>
                                <div class="notif-body">
                                    <div class="notif-title" id="prevTitle">Welcome!</div>
                                    <div class="notif-desc" id="prevBody">Select a post to preview notification</div>
                                    <img id="prevImg" src="" class="notif-big-img" style="display: none;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="preview-footer">

                        *Preview shows how notification appears on Android lock screen

                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Post Selection Modal -->
<div class="modal-overlay" id="postModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Select Post</h3>
            <button type="button" class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Type to search posts..." autocomplete="off">
            </div>
            
            <div id="searchLoader" class="loader-container" style="display: none;">
                <i class="fas fa-circle-notch"></i>
            </div>

            <div id="postList" class="post-list">
                <?php foreach ($posts as $post): 
                    $fullUrl = postUrl($post['slug'], $post['id']);
                    if (strpos($fullUrl, 'http') !== 0) $fullUrl = rtrim($site_url, '/') . '/' . ltrim($fullUrl, '/');
                    
                    $img = $post['banner_image'] ? '/' . $post['banner_image'] : '/assets/img/seo_og_default.png';
                    
                    $payload = json_encode([
                        'id' => $post['id'],
                        'title' => $post['title'],
                        'body' => 'New government job alert! Check it out.',
                        'image' => $img,
                        'url' => $fullUrl
                    ], JSON_UNESCAPED_SLASHES);
                ?>
                <button type="button" class="post-item" 
                        data-search="<?= strtolower(htmlspecialchars($post['title'])) ?>"
                        onclick='selectPost(<?= $payload ?>)'>
                    <img src="<?= $img ?>" alt="" onerror="this.src='https://via.placeholder.com/50'">
                    <div class="post-item-info">
                        <div class="post-item-title"><?= htmlspecialchars($post['title']) ?></div>
                        <div class="post-item-meta">ID: <?= $post['id'] ?></div>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
            
            <div id="noResults" class="empty-state" style="display: none;">
                <i class="fas fa-inbox"></i>
                <div>No posts found</div>
            </div>
        </div>
    </div>
</div>

<script>
const PUSH_TOKEN = <?= json_encode($pushToken) ?>;
// DOM Elements
const composePreviewBox = document.getElementById('selectedPostPreview');
const composeImg = document.getElementById('selectedPostImg');
const composeTitle = document.getElementById('selectedPostTitle');
const customUrl = document.getElementById('customUrl');
const customImage = document.getElementById('customImage');
const customTitle = document.getElementById('customTitle');
const customBody = document.getElementById('customBody');
const prevTitle = document.getElementById('prevTitle');
const prevBody = document.getElementById('prevBody');
const prevImg = document.getElementById('prevImg');
const sendBtn = document.getElementById('sendBtn');
const searchInput = document.getElementById('searchInput');
const postList = document.getElementById('postList');
const noResults = document.getElementById('noResults');
const selectedPostId = document.getElementById('selectedPostId');
const modal = document.getElementById('postModal');

// Sidebar Toggle
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

// Modal Functions
function openModal() {
    modal.classList.add('active');
    setTimeout(() => searchInput.focus(), 100);
}

function closeModal() {
    modal.classList.remove('active');
}

// Close modal on overlay click
modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
});

// Close on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('active')) {
        closeModal();
    }
});

// Clock Update
function updateClock() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('clockTime').textContent = `${hours}:${minutes}`;
    
    const options = { weekday: 'long', month: 'long', day: 'numeric' };
    document.getElementById('clockDate').textContent = now.toLocaleDateString('en-US', options);
}
setInterval(updateClock, 1000);
updateClock();

// Search Functionality
async function performSearch() {
    const q = searchInput.value.trim();
    postList.innerHTML = '';
    noResults.style.display = 'none';
    
    if (!q) {
        // Show initial list if empty
        location.reload();
        return;
    }
    
    document.getElementById('searchLoader').style.display = 'block';
    
    try {
        const res = await fetch('/api/6n4a0d7n8v.php?q=' + encodeURIComponent(q));
        const posts = await res.json();
        
        document.getElementById('searchLoader').style.display = 'none';
        
        if (!posts.length) {
            noResults.style.display = 'block';
            return;
        }
        
        posts.forEach(p => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'post-item';
            btn.onclick = () => selectPost({
                id: p.id,
                title: p.title,
                body: 'New government job alert! Check it out.',
                image: p.image,
                url: p.url
            });
            
            btn.innerHTML = `
                <img src="${p.image}" alt="" onerror="this.src='https://via.placeholder.com/50'">
                <div class="post-item-info">
                    <div class="post-item-title">${p.title}</div>
                    <div class="post-item-meta">ID: ${p.id}</div>
                </div>
            `;
            
            postList.appendChild(btn);
        });
    } catch (err) {
        document.getElementById('searchLoader').style.display = 'none';
        showToast('Search failed: ' + err.message, 'error');
    }
}

// Search triggers
document.getElementById('btnSearchTrigger')?.addEventListener('click', performSearch);
searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        performSearch();
    }
});

// Select Post
function selectPost(data) {
    // Fill visible inputs
    customUrl.value = data.url;
    customTitle.value = data.title;
    customBody.value = data.body;
    customImage.value = data.image;
    
    // Set hidden ID
    selectedPostId.value = data.id;
    
    // Update selected preview box
    composeImg.src = data.image;
    composeTitle.textContent = data.title;
    composePreviewBox.classList.add('active');
    
    // Update live preview
    updateLivePreview();
    
    // Enable button
    validateForm();
    
    // Close modal
    closeModal();
}

// Live Preview Update
function updateLivePreview() {
    prevTitle.textContent = customTitle.value || 'Notification Title';
    prevBody.textContent = customBody.value || 'Notification body text...';
    
    const imgUrl = customImage.value;
    if (imgUrl && imgUrl.trim() !== '') {
        prevImg.src = imgUrl;
        prevImg.style.display = 'block';
    } else {
        prevImg.style.display = 'none';
    }
}

// Input listeners for live preview
[customTitle, customBody, customImage].forEach(el => {
    el.addEventListener('input', () => {
        updateLivePreview();
        validateForm();
    });
});

customUrl.addEventListener('input', validateForm);

// Form Validation
function validateForm() {
    const isValid = customTitle.value.trim() !== '' && customUrl.value.trim() !== '';
    sendBtn.disabled = !isValid;
}

// Toast Notification
function showToast(message, type = 'default') {
    const colors = {
        success: '#10b981',
        error: '#ef4444',
        warning: '#f59e0b',
        default: '#6b7280'
    };
    
    Toastify({
        text: message,
        position: 'center',
        style: {
            borderRadius: '12px',
            padding: '16px 24px',
            fontSize: '14px',
            fontWeight: '500',
            background: colors[type] || colors.default
        }
    }).showToast();
}

// Show Alert in UI
function showAlert(message, type) {
    const container = document.getElementById('statusMsg');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle'
    };
    
    alert.innerHTML = `
        <i class="fas ${icons[type] || 'fa-info-circle'}"></i>
        <span>${message}</span>
    `;
    
    container.innerHTML = '';
    container.appendChild(alert);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateY(-10px)';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
}

// Send Notification
sendBtn.addEventListener('click', async () => {
    const title = customTitle.value.trim();
    const body = customBody.value.trim();
    const url = customUrl.value.trim();
    const image = customImage.value.trim();
    const postId = selectedPostId.value;
    
    if (!title || !url) {
        showAlert('Title and Target URL are required', 'error');
        return;
    }
    
    if (!confirm('Are you sure you want to send this push notification to all subscribers?')) {
        return;
    }
    
    const originalContent = sendBtn.innerHTML;
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="spinner"></span> Sending...';
    
    try {
        const res = await fetch('send-push.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Push-Token': PUSH_TOKEN
            },
            body: JSON.stringify({ title, body, url, image })
        });
        
        const data = await res.json();
        
        if (data.success) {
            showAlert('Push notification sent successfully! Background process started.', 'success');
            saveLog(title, body, postId);
            
            // Reset form after success
            setTimeout(() => {
                customTitle.value = '';
                customBody.value = '';
                customUrl.value = '';
                customImage.value = '';
                selectedPostId.value = '';
                composePreviewBox.classList.remove('active');
                updateLivePreview();
                validateForm();
            }, 2000);
        } else if (data.error) {
            showAlert('Error: ' + data.error, 'error');
        }
    } catch (e) {
        showAlert('Network Error: ' + e.message, 'error');
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = originalContent;
    }
});

// Save Log
async function saveLog(title, body, postId) {
    const formData = new FormData();
    formData.append('action', 'log_push');
    formData.append('title', title);
    formData.append('post_id', postId);
    
    try {
        await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
    } catch (e) {
        console.error('Log save failed:', e);
    }
}

// View Logs
document.getElementById('viewLogsBtn').addEventListener('click', () => {
    window.location.href = 'view-logs.php';
});

// Close sidebar on window resize
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});
</script>

</body>
</html>