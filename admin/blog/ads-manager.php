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
    empty($permissions['ads']['manage_ads'])
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

$page_options = [
    'post'     => 'Posts',
    'home'     => 'Homepage',
    'category' => 'Category pages',
    'page'     => 'Static pages',
    'search'   => 'Search pages',
    'tag'      => 'Tag / Archive pages',
];

$insertion_options = [
    'disabled'         => 'Disabled',
    'header'           => 'Header',
    'footer'           => 'Footer',
    'sidebar'          => 'Sidebar',
    'before_content'   => 'Before post',
    'after_content'    => 'After post',
    'between_posts'    => 'Between posts (listing pages)',
    'before_paragraph' => 'Before paragraph #',
    'after_paragraph'  => 'After paragraph #',
];

$alignment_options = [
    'default'     => 'Default',
    'center'      => 'Center',
    'left'        => 'Left',
    'right'       => 'Right',
    'float_left'  => 'Float Left',
    'float_right' => 'Float Right',
];

$msg = ''; $msg_type = '';

// ─── SAVE ────────────────────────────────────────────────────────────────────
function set_config($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO app_config (config_key, config_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
    $stmt->execute([$key, $value]);
}
function get_config($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT config_value FROM app_config WHERE config_key = ?");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v !== false && $v !== null ? $v : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_blocks') {
    csrf_check();

    try {
        $upd = $pdo->prepare("UPDATE ad_blocks SET enabled=?, ad_code=?, pages=?, insertion=?, paragraph_number=?, alignment=? WHERE block_number=?");
        for ($i = 1; $i <= 16; $i++) {
            $b         = $_POST['blocks'][$i] ?? [];
            $enabled   = !empty($b['enabled']) ? 1 : 0;
            $ad_code   = trim($b['code'] ?? '');
            $pages_in  = $b['pages'] ?? [];
            $pages     = json_encode(array_values(array_intersect($pages_in, array_keys($page_options))));
            $insertion = array_key_exists($b['insertion'] ?? '', $insertion_options) ? $b['insertion'] : 'disabled';
            $paragraph = max(1, (int)($b['paragraph'] ?? 1));
            $alignment = array_key_exists($b['alignment'] ?? '', $alignment_options) ? $b['alignment'] : 'default';
            $upd->execute([$enabled, $ad_code, $pages, $insertion, $paragraph, $alignment, $i]);
        }

        set_config($pdo, 'ads_header_script', trim($_POST['header_script'] ?? ''));
        set_config($pdo, 'ads_body_script', trim($_POST['body_script'] ?? ''));
        set_config($pdo, 'ads_footer_script', trim($_POST['footer_script'] ?? ''));
        set_config($pdo, 'ads_txt_content', trim($_POST['ads_txt'] ?? ''));
        set_config($pdo, 'ads_txt_enabled', !empty($_POST['ads_txt_enabled']) ? '1' : '0');

        header('Location: ads-manager.php?msg=saved');
        exit;
    } catch (PDOException $e) {
        $msg = 'Error saving ad settings.'; $msg_type = 'error';
    }
}

if (($_GET['msg'] ?? '') === 'saved') { $msg = 'Settings saved successfully!'; $msg_type = 'success'; }

$header_script  = get_config($pdo, 'ads_header_script', '');
$body_script    = get_config($pdo, 'ads_body_script', '');
$footer_script  = get_config($pdo, 'ads_footer_script', '');
$ads_txt_value  = get_config($pdo, 'ads_txt_content', '');
$ads_txt_on     = get_config($pdo, 'ads_txt_enabled', '0') === '1';

// ─── LOAD ────────────────────────────────────────────────────────────────────
$rows = $pdo->query("SELECT * FROM ad_blocks ORDER BY block_number ASC")->fetchAll(PDO::FETCH_ASSOC);
$blocks = [];
foreach ($rows as $r) {
    $blocks[(int)$r['block_number']] = $r;
    $blocks[(int)$r['block_number']]['pages_arr'] = json_decode($r['pages'] ?? '[]', true) ?: [];
}
// Safety net: if table is missing rows (e.g. manually edited), fill blanks
for ($i = 1; $i <= 16; $i++) {
    if (!isset($blocks[$i])) {
        $blocks[$i] = ['block_number'=>$i,'enabled'=>0,'ad_code'=>'','pages'=>'[]','pages_arr'=>[],'insertion'=>'disabled','paragraph_number'=>1,'alignment'=>'default'];
    }
}

$stats_active = 0;
foreach ($blocks as $b) { if ($b['enabled']) $stats_active++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Ads Manager - <?= htmlspecialchars($site_name) ?> Admin</title>
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
    --primary: #7c3aed; --primary-dark: #6d28d9; --primary-light: #ede9fe; --primary-lighter: #f5f3ff;
    --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --info: #3b82f6;
    --gray-50: #f9fafb; --gray-100: #f3f4f6; --gray-200: #e5e7eb; --gray-300: #d1d5db; --gray-400: #9ca3af;
    --gray-500: #6b7280; --gray-600: #4b5563; --gray-700: #374151; --gray-800: #1f2937; --gray-900: #111827;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --radius: 0.5rem; --radius-lg: 0.75rem; --radius-xl: 1rem; --sidebar-width: 280px;
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
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }
.page-heading { display: none; }
@media (min-width: 768px) { .page-heading { display: block; } .sitename-mob { display: none; } .page-heading h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); } .page-heading p { font-size: 0.875rem; color: var(--gray-500); } }
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; position: relative; transition: all 0.2s; }
.content-wrapper { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }
.alert { padding: 1rem 1.25rem; border-radius: var(--radius-lg); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.875rem; font-size: 0.9375rem; border: 1px solid transparent; }
.alert-success { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.alert-error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* ── Ads Manager (fixed 16-block editor) ── */
.top-tabs { display: flex; gap: 4px; margin-bottom: 1.25rem; border-bottom: 2px solid var(--gray-200); }
.top-tab-btn { display: flex; align-items: center; gap: .5rem; padding: .75rem 1.1rem; border: none; background: none; font-size: .9rem; font-weight: 600; color: var(--gray-500); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all .15s; }
.top-tab-btn:hover { color: var(--gray-800); }
.top-tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
.top-panel { display: none; }
.top-panel.active { display: block; }

.ai-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 1rem; }
.ai-tab-btn { width: 42px; height: 38px; display: flex; align-items: center; justify-content: center; font-size: .85rem; font-weight: 700; border: 1px solid var(--gray-200); background: white; border-radius: var(--radius); cursor: pointer; color: var(--gray-600); transition: all .15s; }
.ai-tab-btn:hover { border-color: var(--primary); color: var(--primary); }
.ai-tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
.ai-tab-btn.has-code { border-top: 3px solid var(--success); }
.ai-tab-btn.active.has-code { border-top-color: #a7f3d0; }

.block-card { background: white; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); overflow: hidden; display: none; }
.block-card.active { display: block; }
.block-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); }
.block-toolbar h3 { font-size: 1rem; font-weight: 700; color: var(--gray-900); }

/* Toggle switch — pure CSS, state-driven only by :checked. No JS needed,
   so the label text can never be out of sync with the switch. */
.enable-wrap { display: flex; align-items: center; gap: .6rem; }
.toggle-input { position: absolute; opacity: 0; width: 42px; height: 23px; margin: 0; cursor: pointer; }
.toggle-sl { position: relative; display: inline-block; width: 42px; height: 23px; background: var(--gray-300); border-radius: 22px; transition: background .2s; cursor: pointer; flex-shrink: 0; }
.toggle-sl::before { content: ''; position: absolute; height: 17px; width: 17px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.toggle-input:checked + .toggle-sl { background: var(--success); }
.toggle-input:checked + .toggle-sl::before { transform: translateX(19px); }
.status-on, .status-off { font-size: .85rem; font-weight: 600; }
.status-on { display: none; color: var(--success); }
.status-off { display: inline; color: var(--gray-500); }
.toggle-input:checked ~ .status-on { display: inline; }
.toggle-input:checked ~ .status-off { display: none; }

.hf-card { background: white; border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); border: 1px solid var(--gray-100); padding: 1.25rem; margin-bottom: 1.25rem; }
.hf-card label { display: block; font-weight: 700; font-size: .9rem; color: var(--gray-800); margin-bottom: .4rem; }
.hf-card small { display: block; margin-top: .4rem; color: var(--gray-500); font-size: .8rem; }
.hf-textarea { width: 100%; min-height: 160px; padding: 1rem; font-family: 'Courier New', monospace; font-size: .82rem; line-height: 1.6; border: 1px solid var(--gray-200); border-radius: var(--radius); outline: none; resize: vertical; }
.hf-textarea:focus { border-color: var(--primary); }

.editor-wrap { display: flex; min-height: 200px; border-bottom: 1px solid var(--gray-100); }
.gutter { width: 40px; background: var(--gray-50); border-right: 1px solid var(--gray-200); padding: 1rem 0; text-align: center; flex-shrink: 0; overflow: hidden; font-family: 'Courier New', monospace; font-size: .75rem; color: var(--gray-400); line-height: 1.6; }
.gutter span { display: block; }
.code-area { flex: 1; min-height: 200px; padding: 1rem; font-family: 'Courier New', monospace; font-size: .82rem; line-height: 1.6; border: none; outline: none; resize: vertical; color: var(--gray-800); }
.code-area:focus { background: #fafaf9; }

.block-options { padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 1rem; }
.pages-row { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; }
.pages-row label { display: flex; align-items: center; gap: .4rem; font-size: .85rem; color: var(--gray-700); cursor: pointer; white-space: nowrap; }
.pages-row input { width: auto; accent-color: var(--primary); }
.ins-row { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
.ins-row label.f-label { font-size: .85rem; font-weight: 600; color: var(--gray-700); white-space: nowrap; }
.ins-row select, .ins-row input[type="number"] { padding: .5rem .7rem; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: .85rem; outline: none; background: white; color: var(--gray-800); }
.ins-row select:focus, .ins-row input:focus { border-color: var(--primary); }
.para-field { display: none; align-items: center; gap: .5rem; }
.para-field.show { display: flex; }
.para-field input { width: 65px; text-align: center; }

.save-row { display: flex; justify-content: flex-end; margin-top: 1.25rem; }
.btn-save { display: inline-flex; align-items: center; gap: .5rem; padding: .7rem 1.5rem; background: var(--primary); color: white; font-size: .9rem; font-weight: 700; border: none; border-radius: var(--radius); cursor: pointer; box-shadow: 0 2px 8px rgba(124,58,237,.3); transition: background .18s; }
.btn-save:hover { background: var(--primary-dark); }
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
                    <h1>Ads Manager</h1>
                    <p>16 ad blocks — enable, place, and paste your ad code</p>
                </div>
            </div>
            <div class="nav-right">
                <span style="font-size:.85rem;color:var(--gray-500);"><?= $stats_active ?>/16 blocks live</span>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msg_type === 'success' ? 'success' : 'error' ?>">
                    <i class="fas fa-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="ads-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="save_blocks">

                <div class="top-tabs">
                    <button type="button" class="top-tab-btn active" id="toptab-blocks" onclick="switchTopTab('blocks')"><i class="fas fa-rectangle-ad"></i> Blocks</button>
                    <button type="button" class="top-tab-btn" id="toptab-hf" onclick="switchTopTab('hf')"><i class="fas fa-globe"></i> Header / Body / Footer</button>
                    <button type="button" class="top-tab-btn" id="toptab-adstxt" onclick="switchTopTab('adstxt')"><i class="fas fa-file-lines"></i> Ads.txt</button>
                </div>

                <div class="top-panel active" id="panel-blocks">

                    <div class="ai-tabs">
                        <?php for ($i = 1; $i <= 16; $i++): $b = $blocks[$i]; ?>
                            <button type="button" class="ai-tab-btn <?= $i === 1 ? 'active' : '' ?> <?= $b['ad_code'] !== '' ? 'has-code' : '' ?>" id="tabbtn-<?= $i ?>" onclick="switchBlock(<?= $i ?>)"><?= $i ?></button>
                        <?php endfor; ?>
                    </div>

                    <?php for ($i = 1; $i <= 16; $i++): $b = $blocks[$i]; ?>
                    <div class="block-card <?= $i === 1 ? 'active' : '' ?>" id="block-<?= $i ?>">
                        <div class="block-toolbar">
                            <h3>Block <?= $i ?></h3>
                            <div class="enable-wrap">
                                <input type="checkbox" class="toggle-input" id="tgl-<?= $i ?>" name="blocks[<?= $i ?>][enabled]" value="1" <?= $b['enabled'] ? 'checked' : '' ?>>
                                <label class="toggle-sl" for="tgl-<?= $i ?>"></label>
                                <span class="status-on">Enabled</span>
                                <span class="status-off">Disabled</span>
                            </div>
                        </div>

                        <div class="editor-wrap">
                            <div class="gutter" id="gutter-<?= $i ?>"><span>1</span></div>
                            <textarea class="code-area" name="blocks[<?= $i ?>][code]" id="ta-<?= $i ?>"
                                placeholder="<!-- Paste AdSense unit or any HTML/JS ad code here -->"
                                spellcheck="false" autocomplete="off"
                                oninput="syncGutter(<?= $i ?>); markHasCode(<?= $i ?>);"><?= htmlspecialchars($b['ad_code']) ?></textarea>
                        </div>

                        <div class="block-options">
                            <div class="pages-row">
                                <?php foreach ($page_options as $pval => $plabel): ?>
                                    <label>
                                        <input type="checkbox" name="blocks[<?= $i ?>][pages][]" value="<?= $pval ?>" <?= in_array($pval, $b['pages_arr']) ? 'checked' : '' ?>>
                                        <?= $plabel ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <div class="ins-row">
                                <label class="f-label">Insertion</label>
                                <select name="blocks[<?= $i ?>][insertion]" id="ins-<?= $i ?>" onchange="toggleParaField(<?= $i ?>)">
                                    <?php foreach ($insertion_options as $ival => $ilabel): ?>
                                        <option value="<?= $ival ?>" <?= $b['insertion'] === $ival ? 'selected' : '' ?>><?= $ilabel ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="f-label">Alignment</label>
                                <select name="blocks[<?= $i ?>][alignment]">
                                    <?php foreach ($alignment_options as $aval => $alabel): ?>
                                        <option value="<?= $aval ?>" <?= $b['alignment'] === $aval ? 'selected' : '' ?>><?= $alabel ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="para-field <?= in_array($b['insertion'], ['before_paragraph','after_paragraph']) ? 'show' : '' ?>" id="para-field-<?= $i ?>">
                                    <label class="f-label">Paragraph #</label>
                                    <input type="number" name="blocks[<?= $i ?>][paragraph]" value="<?= (int)$b['paragraph_number'] ?>" min="1" max="99">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>

                </div>

                <div class="top-panel" id="panel-hf">
                    <div class="hf-card">
                        <label for="header_script">Header Script</label>
                        <textarea class="hf-textarea" name="header_script" id="header_script" placeholder="<!-- Code injected just before &lt;/head&gt; on every page -->" spellcheck="false"><?= htmlspecialchars($header_script) ?></textarea>
                        <small>Runs on every page, right before the closing &lt;/head&gt; tag. Good for site verification tags or global ad-network scripts.</small>
                    </div>
                    <div class="hf-card">
                        <label for="body_script">Body Script</label>
                        <textarea class="hf-textarea" name="body_script" id="body_script" placeholder="<!-- Code injected right after the opening &lt;body&gt; tag -->" spellcheck="false"><?= htmlspecialchars($body_script) ?></textarea>
                        <small>Runs on every page, right after the opening &lt;body&gt; tag. Good for pixel/no-script tags (e.g. GTM noscript, Facebook Pixel noscript).</small>
                    </div>
                    <div class="hf-card">
                        <label for="footer_script">Footer Script</label>
                        <textarea class="hf-textarea" name="footer_script" id="footer_script" placeholder="<!-- Code injected just before &lt;/body&gt; on every page -->" spellcheck="false"><?= htmlspecialchars($footer_script) ?></textarea>
                        <small>Runs on every page, right before the closing &lt;/body&gt; tag.</small>
                    </div>
                </div>

                <div class="top-panel" id="panel-adstxt">
                    <div class="hf-card">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:.9rem;">
                            <label for="ads_txt" style="margin:0;">ads.txt Content</label>
                            <div class="enable-wrap">
                                <input type="checkbox" class="toggle-input" id="tgl-adstxt" name="ads_txt_enabled" value="1" <?= $ads_txt_on ? 'checked' : '' ?>>
                                <label class="toggle-sl" for="tgl-adstxt"></label>
                                <span class="status-on">Enabled</span>
                                <span class="status-off">Disabled</span>
                            </div>
                        </div>
                        <textarea class="hf-textarea" name="ads_txt" id="ads_txt" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0" spellcheck="false"><?= htmlspecialchars($ads_txt_value) ?></textarea>
                        <small>When enabled, this content will be served as plain text at <strong>yourdomain.com/ads.txt</strong> — ask and I'll wire up the route. When disabled, the file won't be served even if content is saved here.</small>
                    </div>
                </div>

                <div class="save-row">
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Settings 1 - 16</button>
                </div>
            </form>

        </div>
    </main>
</div>

<script>
    <?php if ($msg): ?>
        Toastify({
            text: "<?= addslashes($msg) ?>",
            duration: 2500, gravity: "top", position: "center",
            style: { background: "<?= $msg_type === 'success' ? '#10B981' : '#EF4444' ?>", borderRadius: "8px", fontSize: "16px", padding: "15px 25px" }
        }).showToast();
    <?php endif; ?>

    function switchTopTab(name) {
        document.querySelectorAll('.top-panel').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.top-tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('panel-' + name).classList.add('active');
        document.getElementById('toptab-' + name).classList.add('active');
    }

    function switchBlock(n) {
        document.querySelectorAll('.block-card').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.ai-tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById('block-' + n).classList.add('active');
        document.getElementById('tabbtn-' + n).classList.add('active');
    }

    function syncGutter(n) {
        const ta = document.getElementById('ta-' + n);
        const gutter = document.getElementById('gutter-' + n);
        const lines = ta.value.split('\n').length;
        let html = '';
        for (let i = 1; i <= lines; i++) html += '<span>' + i + '</span>';
        gutter.innerHTML = html;
    }

    function markHasCode(n) {
        const ta = document.getElementById('ta-' + n);
        const tab = document.getElementById('tabbtn-' + n);
        tab.classList.toggle('has-code', ta.value.trim() !== '');
    }

    function toggleParaField(n) {
        const sel = document.getElementById('ins-' + n);
        const field = document.getElementById('para-field-' + n);
        field.classList.toggle('show', sel.value === 'before_paragraph' || sel.value === 'after_paragraph');
    }

    // init gutters for any pre-filled code (edit mode after save)
    document.addEventListener('DOMContentLoaded', () => {
        for (let i = 1; i <= 16; i++) syncGutter(i);
    });

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