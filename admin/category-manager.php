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
    empty($permissions['ecommerce']['manage_categories'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];
$seo_robots = 'noindex, nofollow, noarchive, nosnippet';

$log_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$log_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$upload_dir = DROOT_PATH . '/uploads/categories/';
$upload_url = '/uploads/categories/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ─── Auto-create the table on first run — no manual SQL needed to deploy ──────
$pdo->exec("CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL UNIQUE,
    image VARCHAR(255) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function csrf_check() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function slugify(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
    return trim($str, '-');
}

function uniqueCatSlug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = $base !== '' ? $base : 'category';
    $i = 1;
    while (true) {
        $q = $pdo->prepare("SELECT id FROM product_categories WHERE slug = ? AND id != ?");
        $q->execute([$slug, $excludeId]);
        if (!$q->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function upload_cat_image(array $file, string $upload_dir): string|false {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed)) return false;
    if ($file['size'] > 2 * 1024 * 1024) return false;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fname = 'category_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $upload_dir . $fname)) return 'uploads/categories/' . $fname;
    return false;
}

// ─── HELPERS: categories list — shared by the normal page render AND the
//     live-search AJAX endpoint, so both always produce identical markup ──────
function fetchCategoriesList(PDO $pdo, string $search, string $status_filter, int $page, int $per_page): array {
    $offset = ($page - 1) * $per_page;
    $where = [];
    $params = [];
    if ($search) { $where[] = "(name LIKE ? OR slug LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($status_filter !== 'all') { $where[] = "status = ?"; $params[] = $status_filter; }
    $sql = "SELECT * FROM product_categories" . ($where ? " WHERE " . implode(" AND ", $where) : "");
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM product_categories" . ($where ? " WHERE " . implode(" AND ", $where) : ""));
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();
    $total_pages = (int)ceil($total / $per_page);
    $stmt = $pdo->prepare($sql . " ORDER BY sort_order ASC, created_at DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [$rows, $total, $total_pages];
}

function renderCategoryRow(array $c): string {
    ob_start();
    ?>
    <tr>
        <td>
            <?php if ($c['image']): ?>
                <img src="/<?= htmlspecialchars($c['image']) ?>" alt="" class="cat-thumb">
            <?php else: ?>
                <div class="cat-thumb-placeholder"><i class="fas fa-image"></i></div>
            <?php endif; ?>
        </td>
        <td>
            <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="cat-slug"><?= htmlspecialchars($c['slug']) ?></div>
        </td>
        <td>
            <div class="status-dropdown" id="statusDropdown<?= (int)$c['id'] ?>">
                <button type="button" class="status-btn status-<?= $c['status'] ?>" onclick="toggleStatusMenu(<?= (int)$c['id'] ?>)">
                    <?= $c['status'] === 'active' ? 'Enabled' : 'Disabled' ?> <i class="fas fa-caret-down"></i>
                </button>
                <div class="status-menu">
                    <a href="javascript:;" onclick="setCategoryStatus(<?= (int)$c['id'] ?>, 'active')">Enable</a>
                    <a href="javascript:;" onclick="setCategoryStatus(<?= (int)$c['id'] ?>, 'inactive')">Disable</a>
                </div>
            </div>
        </td>
        <td>
            <div class="row-actions">
                <button class="btn-action btn-edit" onclick="openEditCategory(<?= (int)$c['id'] ?>)">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-action btn-delete" onclick="confirmDeleteCategory(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function renderCategoriesRows(array $rows): string {
    if (empty($rows)) {
        return '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-sitemap"></i><p>No categories found.</p></div></td></tr>';
    }
    $html = '';
    foreach ($rows as $c) $html .= renderCategoryRow($c);
    return $html;
}

function renderCategoriesPagination(int $page, int $total_pages, string $search, string $status_filter): string {
    if ($total_pages <= 1) return '';
    $base_url = "?search=" . urlencode($search) . "&status=" . urlencode($status_filter);
    ob_start();
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= $base_url ?>&page=1" class="page-link" data-page="1"><i class="fas fa-angle-double-left"></i></a>
            <a href="<?= $base_url ?>&page=<?= $page-1 ?>" class="page-link" data-page="<?= $page-1 ?>"><i class="fas fa-angle-left"></i></a>
        <?php else: ?>
            <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
            <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2); $end = min($total_pages, $page + 2);
        if ($start > 1) { echo "<a href='{$base_url}&page=1' class='page-link' data-page='1'>1</a>"; if ($start > 2) echo "<span class='page-link disabled'>…</span>"; }
        for ($i = $start; $i <= $end; $i++) { echo $i == $page ? "<span class='page-link active'>$i</span>" : "<a href='{$base_url}&page=$i' class='page-link' data-page='$i'>$i</a>"; }
        if ($end < $total_pages) { if ($end < $total_pages - 1) echo "<span class='page-link disabled'>…</span>"; echo "<a href='{$base_url}&page={$total_pages}' class='page-link' data-page='{$total_pages}'>{$total_pages}</a>"; }
        ?>
        <?php if ($page < $total_pages): ?>
            <a href="<?= $base_url ?>&page=<?= $page+1 ?>" class="page-link" data-page="<?= $page+1 ?>"><i class="fas fa-angle-right"></i></a>
            <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-link" data-page="<?= $total_pages ?>"><i class="fas fa-angle-double-right"></i></a>
        <?php else: ?>
            <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
            <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ─── AJAX: fetch a single category (instant-open edit modal) ──────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_category' && is_numeric($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM product_categories WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['category' => $row ?: null]);
    exit;
}

// ─── AJAX: live search/filter for the Categories table (no page reload) ───────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_categories') {
    $s_search = trim($_GET['search'] ?? '');
    $s_status = $_GET['status'] ?? 'all';
    $s_page   = max(1, (int)($_GET['page'] ?? 1));
    [$s_rows, $s_total, $s_total_pages] = fetchCategoriesList($pdo, $s_search, $s_status, $s_page, 15);
    header('Content-Type: application/json');
    echo json_encode([
        'rows_html'       => renderCategoriesRows($s_rows),
        'pagination_html' => renderCategoriesPagination($s_page, $s_total_pages, $s_search, $s_status),
        'total'           => $s_total,
        'count_text'      => $s_total . ' categor' . ($s_total !== 1 ? 'ies' : 'y') . ' found',
        'search'          => $s_search,
    ]);
    exit;
}

// ─── AJAX: instant status toggle (Enable/Disable) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    csrf_check();
    $tid = (int)($_POST['id'] ?? 0);
    $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
    header('Content-Type: application/json');
    if ($tid <= 0) { echo json_encode(['success' => false]); exit; }
    $pdo->prepare("UPDATE product_categories SET status = ? WHERE id = ?")->execute([$new_status, $tid]);
    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_status', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], "Set category #$tid status to $new_status", $log_ip, $log_ua]);
    echo json_encode(['success' => true, 'status' => $new_status]);
    exit;
}

$notifications = [];
$errors = [];

// ─── ACTION: CREATE / EDIT CATEGORY ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create_category', 'edit_category'])) {
    csrf_check();

    $action   = $_POST['action'];
    $edit_id  = (int)($_POST['category_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $slug_raw = trim($_POST['slug'] ?? '');
    $status   = isset($_POST['status']) ? 'active' : 'inactive';

    if (empty($name)) {
        $errors[] = 'Category name is required.';
    } else {
        $base_slug = $slug_raw !== '' ? slugify($slug_raw) : slugify($name);
        $slug = uniqueCatSlug($pdo, $base_slug, $edit_id);

        $image = null;
        if (!empty($_FILES['image']['name'])) {
            $uploaded = upload_cat_image($_FILES['image'], $upload_dir);
            if ($uploaded) {
                $image = $uploaded;
            } else {
                $errors[] = 'Image upload failed. Allowed: JPG, PNG, WebP (max 2MB).';
            }
        }

        if (empty($errors)) {
            try {
                if ($action === 'create_category') {
                    $pdo->prepare("INSERT INTO product_categories (name, slug, status, image) VALUES (?, ?, ?, ?)")
                        ->execute([$name, $slug, $status, $image]);
                    $new_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_create', ?, ?, ?)")
                        ->execute([$_SESSION['user_id'], "Created category: $name (ID: $new_id)", $log_ip, $log_ua]);
                    $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => "Category \"$name\" created."];
                } else {
                    if ($image) {
                        $old = $pdo->prepare("SELECT image FROM product_categories WHERE id = ?");
                        $old->execute([$edit_id]);
                        $old_img = $old->fetchColumn();
                        if ($old_img && file_exists(DROOT_PATH . '/' . $old_img)) @unlink(DROOT_PATH . '/' . $old_img);
                        $pdo->prepare("UPDATE product_categories SET name=?, slug=?, status=?, image=? WHERE id=?")
                            ->execute([$name, $slug, $status, $image, $edit_id]);
                    } else {
                        $pdo->prepare("UPDATE product_categories SET name=?, slug=?, status=? WHERE id=?")
                            ->execute([$name, $slug, $status, $edit_id]);
                    }
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_update', ?, ?, ?)")
                        ->execute([$_SESSION['user_id'], "Updated category ID: $edit_id", $log_ip, $log_ua]);
                    $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => "Category updated."];
                }
            } catch (PDOException $e) {
                $errors[] = ($e->getCode() == 23000) ? 'A category with that name/slug already exists.' : 'DB Error: ' . $e->getMessage();
            }
        }
    }

    foreach ($errors as $e) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => $e];
    }
}

// ─── ACTION: DELETE CATEGORY ──────────────────────────────────────────────────
if (isset($_GET['delete_category']) && is_numeric($_GET['delete_category'])) {
    $did = (int)$_GET['delete_category'];
    $stmt = $pdo->prepare("SELECT image FROM product_categories WHERE id = ?");
    $stmt->execute([$did]);
    $img = $stmt->fetchColumn();
    if ($img && file_exists(DROOT_PATH . '/' . $img)) @unlink(DROOT_PATH . '/' . $img);
    $pdo->prepare("DELETE FROM product_categories WHERE id = ?")->execute([$did]);
    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'category_delete', ?, ?, ?)")
        ->execute([$_SESSION['user_id'], "Deleted category ID: $did", $log_ip, $log_ua]);
    $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'Category deleted.'];
}

// ─── FETCH DATA ───────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

[$categories, $total_categories, $total_pages] = fetchCategoriesList($pdo, $search, $status_filter, $page, $per_page);

$total_cat    = (int)$pdo->query("SELECT COUNT(*) FROM product_categories")->fetchColumn();
$active_cat   = (int)$pdo->query("SELECT COUNT(*) FROM product_categories WHERE status='active'")->fetchColumn();
$inactive_cat = (int)$pdo->query("SELECT COUNT(*) FROM product_categories WHERE status='inactive'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>Categories - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
!function(){let e=localStorage.dm==="1",t=!1,n=!1,s=()=>new Promise((e,r)=>{let o=document.createElement("script");o.src="/assets/js/darkreader.min.js";o.onload=()=>{t=!0;e()};o.onerror=r;document.head.appendChild(o)}),a=()=>DarkReader.enable({brightness:100,contrast:100,sepia:10}),d=()=>DarkReader.disable(),i=()=>{document.querySelectorAll(".dark-mode-toggle i").forEach(o=>{o.classList.add("rotate");if(e){o.classList.remove("fa-moon");o.classList.add("fa-sun")}else{o.classList.remove("fa-sun");o.classList.add("fa-moon")};setTimeout(()=>o.classList.remove("rotate"),400)})};e&&(t?a():s().then(a));document.addEventListener("DOMContentLoaded",()=>{i();document.querySelectorAll(".dark-mode-toggle").forEach(o=>o.onclick=r)});async function r(o){o.preventDefault();if(n)return;n=!0;let c=document.querySelectorAll(".dark-mode-toggle");c.forEach(e=>e.classList.add("loading"));try{t||await s();e=!e;localStorage.dm=e?"1":"0";e?a():d();i()}finally{setTimeout(()=>{c.forEach(e=>e.classList.remove("loading"));n=!1},600)}}}();
</script>
<style>
:root{--primary:#7c3aed;--primary-dark:#6d28d9;--primary-light:#ede9fe;--primary-lighter:#f5f3ff;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;--info:#3b82f6;--gray-50:#f9fafb;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-300:#d1d5db;--gray-400:#9ca3af;--gray-500:#6b7280;--gray-600:#4b5563;--gray-700:#374151;--gray-800:#1f2937;--gray-900:#111827;--shadow-sm:0 1px 2px 0 rgb(0 0 0/.05);--shadow:0 1px 3px 0 rgb(0 0 0/.1),0 1px 2px -1px rgb(0 0 0/.1);--shadow-md:0 4px 6px -1px rgb(0 0 0/.1),0 2px 4px -2px rgb(0 0 0/.1);--shadow-lg:0 10px 15px -3px rgb(0 0 0/.1),0 4px 6px -4px rgb(0 0 0/.1);--radius:.5rem;--radius-lg:.75rem;--radius-xl:1rem;--header-height:70px;--sidebar-width:280px}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;background:var(--gray-50);color:var(--gray-800);line-height:1.5}
.admin-container{display:grid;grid-template-columns:1fr;min-height:100vh}
@media(min-width:1024px){.admin-container{grid-template-columns:var(--sidebar-width) 1fr}}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-width);background:white;border-right:1px solid var(--gray-200);z-index:1000;transform:translateX(-100%);transition:transform .3s cubic-bezier(.4,0,.2,1);overflow-y:auto;display:flex;flex-direction:column}
.sidebar.open{transform:translateX(0)}
@media(min-width:1024px){.sidebar{position:sticky;transform:translateX(0);height:100vh;top:0}}
.sidebar-header{padding:1.5rem;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:.65rem;font-size:1.15rem;font-weight:800;color:var(--primary);text-decoration:none}
.brand-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;color:white;font-size:1.25rem}
.close-sidebar{width:36px;height:36px;border:none;background:var(--gray-100);border-radius:var(--radius);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-600);transition:all .2s}
.close-sidebar:hover{background:var(--gray-200)}
@media(min-width:1024px){.close-sidebar{display:none}}
.sidebar-nav{flex:1;padding:1rem 0;overflow-y:auto}
.nav-section{margin-bottom:1.5rem;padding:0 1rem}
.nav-title{font-size:.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--gray-400);padding:0 1rem;margin-bottom:.75rem}
.nav-link{display:flex;align-items:center;gap:.875rem;padding:.875rem 1rem;border-radius:var(--radius);color:var(--gray-600);text-decoration:none;font-size:.9375rem;font-weight:500;transition:all .2s;margin-bottom:.25rem}
.nav-link:hover{background:var(--gray-50);color:var(--gray-900)}
.nav-link.active{background:var(--primary-lighter);color:var(--primary);font-weight:600}
.nav-link i{width:24px;text-align:center;font-size:1.125rem}
.sidebar-footer{padding:1rem;border-top:1px solid var(--gray-100)}
.user-card{display:flex;align-items:center;gap:.875rem;padding:.875rem;background:var(--gray-50);border-radius:var(--radius-lg)}
.user-avatar{width:40px;height:40px;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem}
.user-info{flex:1;min-width:0}
.user-name{font-weight:600;color:var(--gray-900);font-size:.9375rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:.75rem;color:var(--gray-500)}
.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;opacity:0;visibility:hidden;transition:all .3s}
.sidebar-overlay.active{opacity:1;visibility:visible}
@media(min-width:1024px){.sidebar-overlay{display:none}}
.main-content{min-width:0}
.top-nav{position:sticky;top:0;background:white;border-bottom:1px solid var(--gray-200);padding:0.450rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;z-index:100}
.nav-left{display:flex;align-items:center;gap:1rem}
.menu-toggle{width:40px;height:40px;border:none;background:var(--gray-100);border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:1.125rem;color:var(--gray-700);cursor:pointer;transition:all .2s}
.menu-toggle:hover{background:var(--gray-200)}
.sitename-mob{display:block;font-size:1.15rem;font-weight:800;color:var(--primary)}
@media(min-width:1024px){.menu-toggle{display:none}}
.page-heading{display:none}
@media(min-width:768px){.page-heading{display:block}.sitename-mob{display:none}.page-heading h1{font-size:1.5rem;font-weight:700;color:var(--gray-900)}.page-heading p{font-size:.875rem;color:var(--gray-500)}}
.nav-right{display:flex;align-items:center;gap:.75rem}
.icon-btn{width:40px;height:40px;border:none;background:transparent;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;font-size:1.125rem;color:var(--gray-600);cursor:pointer;position:relative;transition:all .2s}
.icon-btn:hover{background:var(--gray-100)}
.dark-mode-toggle i{transition:transform .4s ease,opacity .3s ease}
.dark-mode-toggle i.rotate{transform:rotate(180deg)}
.content-wrapper{padding:1.5rem;max-width:1600px;margin:0 auto}
@media(max-width:640px){.content-wrapper{padding:1rem}}

/* Alerts */
.alert{padding:1rem 1.25rem;border-radius:var(--radius-lg);margin-bottom:1rem;display:flex;align-items:center;gap:.875rem;font-size:.9375rem;border:1px solid transparent}
.alert-success{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca}
.alert-warning{background:#fffbeb;color:#92400e;border-color:#fde68a}
.alert i{font-size:1.125rem}

/* Stats */
.stats-grid{display:flex;gap:.75rem;overflow-x:auto;padding-bottom:.5rem;margin-bottom:1.25rem;scrollbar-width:none;-ms-overflow-style:none}
.stats-grid::-webkit-scrollbar{display:none}
.stat-card{flex:0 0 auto;background:white;border-radius:var(--radius-lg);padding:1rem;min-width:130px;box-shadow:var(--shadow);border:1px solid var(--gray-100)}
.stat-card.primary{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);color:white;border:none}
.stat-value{font-size:1.5rem;font-weight:700;color:var(--gray-900);margin-bottom:.2rem}
.stat-card.primary .stat-value{color:white}
.stat-change{font-size:.75rem;color:var(--gray-500)}
.stat-card.primary .stat-change{color:rgba(255,255,255,.8)}

/* Filter Section */
.filter-section{background:white;border-radius:var(--radius-xl);padding:1.25rem;margin-bottom:1rem;border:1px solid var(--gray-100);box-shadow:var(--shadow-sm)}
.filter-row{display:grid;gap:1rem;grid-template-columns:1fr}
@media(min-width:640px){.filter-row{grid-template-columns:1fr 1fr}}
@media(min-width:1024px){.filter-row{grid-template-columns:1fr 1fr auto}}
.filter-group{display:flex;flex-direction:column;gap:.4rem}
.filter-group label{font-size:.8125rem;font-weight:600;color:var(--gray-600)}
.filter-control{padding:.625rem .875rem;border:1px solid var(--gray-200);border-radius:var(--radius);font-size:.9375rem;background:white;color:var(--gray-800);transition:all .2s;width:100%}
.filter-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light)}

/* Toolbar */
.toolbar{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;margin-bottom:1rem}
.toolbar-title{font-size:1.0625rem;font-weight:700;color:var(--gray-900)}
.toolbar-sub{font-size:.8125rem;color:var(--gray-500);margin-top:.1rem}

/* Buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.625rem 1.25rem;border-radius:var(--radius);font-size:.9375rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;transition:all .2s;white-space:nowrap}
.btn-primary{background:var(--primary);color:white}
.btn-primary:hover{background:var(--primary-dark);transform:translateY(-1px);box-shadow:0 4px 12px rgba(124,58,237,.25)}
.btn-secondary{background:var(--gray-100);color:var(--gray-700)}
.btn-secondary:hover{background:var(--gray-200)}
.btn-danger{background:#fef2f2;color:var(--danger)}
.btn-danger:hover{background:var(--danger);color:white}
.btn-sm{padding:.4rem .875rem;font-size:.8125rem}

/* Table */
.table-wrap{background:white;border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);border:1px solid var(--gray-100);overflow:hidden}
.table-responsive{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:.875rem 1rem;text-align:left;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-500);background:var(--gray-50);border-bottom:1px solid var(--gray-200);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--gray-100);transition:background .15s}
tbody tr:hover{background:var(--gray-50)}
tbody tr:last-child{border-bottom:none}
tbody td{padding:.875rem 1rem;font-size:.9375rem;color:var(--gray-700);vertical-align:middle}

/* Category cells */
.cat-thumb{width:44px;height:44px;border-radius:var(--radius);object-fit:cover;border:1px solid var(--gray-200);background:var(--gray-100)}
.cat-thumb-placeholder{width:44px;height:44px;border-radius:var(--radius);background:var(--gray-100);display:flex;align-items:center;justify-content:center;color:var(--gray-400);font-size:1.125rem;border:1px dashed var(--gray-200)}
.cat-name{font-weight:600;color:var(--gray-900);font-size:.9375rem}
.cat-slug{font-size:.8125rem;color:var(--gray-500);font-family:monospace}

/* Status dropdown (Enable/Disable) */
.status-dropdown{position:relative;display:inline-block}
.status-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .75rem;border-radius:9999px;font-size:.8125rem;font-weight:600;border:none;cursor:pointer;transition:all .15s}
.status-active{background:#ecfdf5;color:#065f46}
.status-active:hover{background:#d1fae5}
.status-inactive{background:#fef2f2;color:#991b1b}
.status-inactive:hover{background:#fee2e2}
.status-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;background:white;border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:110px;z-index:10;overflow:hidden}
.status-dropdown.open .status-menu{display:block}
.status-menu a{display:block;padding:.5rem .875rem;font-size:.8125rem;color:var(--gray-700);text-decoration:none;transition:background .15s}
.status-menu a:hover{background:var(--gray-50);color:var(--primary)}

/* Row actions */
.row-actions{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.btn-action{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .75rem;border-radius:var(--radius);font-size:.8125rem;font-weight:500;text-decoration:none;border:none;cursor:pointer;transition:all .15s;white-space:nowrap}
.btn-edit{background:var(--primary-lighter);color:var(--primary)}
.btn-edit:hover{background:var(--primary-light)}
.btn-delete{background:#fef2f2;color:var(--danger)}
.btn-delete:hover{background:var(--danger);color:white}

/* Pagination */
.pagination{display:flex;align-items:center;justify-content:center;gap:.375rem;padding:1.25rem;flex-wrap:wrap}
.page-link{display:flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 .625rem;border-radius:var(--radius);font-size:.875rem;font-weight:500;color:var(--gray-600);text-decoration:none;border:1px solid var(--gray-200);background:white;transition:all .2s}
.page-link:hover{background:var(--gray-50);border-color:var(--gray-300)}
.page-link.active{background:var(--primary);color:white;border-color:var(--primary)}
.page-link.disabled{color:var(--gray-300);cursor:not-allowed;pointer-events:none}

/* Empty state */
.empty-state{text-align:center;padding:4rem 2rem;color:var(--gray-400)}
.empty-state i{font-size:3rem;margin-bottom:1rem;opacity:.4}
.empty-state p{font-size:1rem;font-weight:500}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;display:none;align-items:center;justify-content:center;padding:1rem;overflow-y:auto}
.modal-overlay.open{display:flex}
.modal{background:white;border-radius:var(--radius-xl);width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 15px 40px rgba(0,0,0,.18)}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;border-bottom:1px solid var(--gray-100);position:sticky;top:0;background:white;z-index:1;border-radius:var(--radius-xl) var(--radius-xl) 0 0}
.modal-title{font-size:1.125rem;font-weight:700;color:var(--gray-900)}
.modal-close{width:36px;height:36px;border:none;background:var(--gray-100);border-radius:var(--radius);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-600);transition:all .2s;flex-shrink:0}
.modal-close:hover{background:var(--gray-200)}
.modal-body{padding:1.5rem}
.modal.is-loading .modal-body{opacity:.45;pointer-events:none}
.modal-footer{padding:1rem 1.5rem;border-top:1px solid var(--gray-100);display:flex;justify-content:flex-end;gap:.75rem;background:var(--gray-50);border-radius:0 0 var(--radius-xl) var(--radius-xl)}

/* Form */
.form-grid{display:grid;gap:1rem}
.form-grid-2{grid-template-columns:1fr 1fr}
@media(max-width:540px){.form-grid-2{grid-template-columns:1fr}}
.form-group{display:flex;flex-direction:column;gap:.4rem}
.form-group label{font-size:.8125rem;font-weight:600;color:var(--gray-700)}
.form-group label .req{color:var(--danger)}
.form-control{padding:.625rem .875rem;border:1px solid var(--gray-200);border-radius:var(--radius);font-size:.9375rem;background:white;color:var(--gray-800);transition:all .2s;width:100%;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light)}
.form-hint{font-size:.75rem;color:var(--gray-500);margin-top:.25rem}
.img-preview-wrap{display:flex;align-items:center;gap:1rem;margin-top:.5rem}
.img-preview{width:64px;height:64px;border-radius:var(--radius);object-fit:cover;border:2px solid var(--gray-200);background:var(--gray-100)}
.img-preview-placeholder{width:64px;height:64px;border-radius:var(--radius);background:var(--gray-100);display:flex;align-items:center;justify-content:center;color:var(--gray-400);font-size:1.5rem;border:2px dashed var(--gray-200)}
.checkbox-row{display:flex;align-items:center;gap:.625rem;cursor:pointer}
.checkbox-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--primary);cursor:pointer}
.checkbox-row span{font-size:.9375rem;font-weight:500;color:var(--gray-700)}

/* No inline display on mobile for table */
@media(max-width:768px){
    .hide-mobile{display:none}
}

/* Search info */
.search-info{padding:.875rem 1.25rem;background:var(--primary-lighter);border-radius:var(--radius-lg);margin-bottom:1rem;color:var(--primary-dark);font-size:.875rem;font-weight:500}
.search-info strong{color:var(--primary)}
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
                    <h1>Categories</h1>
                    <p>Manage your product categories</p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" style="background:var(--primary-lighter);color:var(--primary)">
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
                    <div class="stat-value"><?= number_format($total_cat) ?></div>
                    <div class="stat-change">Total Categories</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--success)"><?= number_format($active_cat) ?></div>
                    <div class="stat-change">Enabled</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--danger)"><?= number_format($inactive_cat) ?></div>
                    <div class="stat-change">Disabled</div>
                </div>
            </div>

            <!-- Filter -->
            <div class="filter-section">
                <form method="GET" action="" id="catFilterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" id="catSearchInput" class="filter-control" placeholder="Category name or slug..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status" id="catStatusFilter" class="filter-control">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Enabled</option>
                                <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="filter-group" style="justify-content:flex-end">
                            <label>&nbsp;</label>
                            <div style="display:flex;gap:.5rem;align-items:center">
                                <i class="fas fa-circle-notch fa-spin" id="catSearchSpinner" style="display:none;color:var(--primary)"></i>
                                <a href="?" class="btn btn-secondary" id="catClearBtn"><i class="fas fa-times"></i></a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div id="catSearchInfo">
            <?php if ($search): ?>
            <div class="search-info">Showing results for <strong>"<?= htmlspecialchars($search) ?>"</strong></div>
            <?php endif; ?>
            </div>

            <div class="toolbar">
                <div>
                    <div class="toolbar-title">All Categories</div>
                    <div class="toolbar-sub" id="catCountText"><?= $total_categories ?> categor<?= $total_categories !== 1 ? 'ies' : 'y' ?> found</div>
                </div>
                <button class="btn btn-primary" onclick="openCreateCategoryModal()">
                    <i class="fas fa-plus"></i> Add Category
                </button>
            </div>

            <div class="table-wrap">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="catTableBody"><?= renderCategoriesRows($categories) ?></tbody>
                    </table>
                </div>

                <div id="catPaginationWrap"><?= renderCategoriesPagination($page, $total_pages, $search, $status_filter) ?></div>
            </div>

        </div><!-- /content-wrapper -->
    </main>
</div>

<!-- ══════════════════════════════════════════════
     MODAL: Add Category
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalCreateCategory">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-plus-circle" style="color:var(--primary);margin-right:.5rem"></i>Add Category</div>
            <button class="modal-close" onclick="closeModal('modalCreateCategory')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create_category">
            <div class="modal-body">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Category Name <span class="req">*</span></label>
                        <input type="text" name="name" id="cc_name" class="form-control" placeholder="e.g. Women Clothing" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Slug</label>
                        <input type="text" name="slug" id="cc_slug" class="form-control" placeholder="auto-generated if left blank">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Image</label>
                        <div class="img-preview-wrap">
                            <div>
                                <div class="img-preview-placeholder" id="ccImgPlaceholder"><i class="fas fa-image"></i></div>
                                <img id="ccImgPreview" class="img-preview" src="" style="display:none">
                            </div>
                            <div>
                                <input type="file" name="image" id="cc_image" accept="image/jpeg,image/png,image/webp" onchange="previewImg(this,'ccImgPreview','ccImgPlaceholder')">
                                <div class="form-hint">JPG, PNG or WebP — max 2MB</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="checkbox-row">
                            <input type="checkbox" name="status" id="cc_status" checked>
                            <span>Enabled (visible on the site)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCreateCategory')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     MODAL: Edit Category
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalEditCategory">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-edit" style="color:var(--primary);margin-right:.5rem"></i>Edit Category</div>
            <button class="modal-close" onclick="closeModal('modalEditCategory')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" id="ec_id">
            <div class="modal-body">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Category Name <span class="req">*</span></label>
                        <input type="text" name="name" id="ec_name" class="form-control" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Slug</label>
                        <input type="text" name="slug" id="ec_slug" class="form-control">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Image</label>
                        <div class="img-preview-wrap">
                            <div>
                                <div class="img-preview-placeholder" id="ecImgPlaceholder"><i class="fas fa-image"></i></div>
                                <img id="ecImgPreview" class="img-preview" src="" style="display:none">
                            </div>
                            <div>
                                <input type="file" name="image" id="ec_image" accept="image/jpeg,image/png,image/webp" onchange="previewImg(this,'ecImgPreview','ecImgPlaceholder')">
                                <div class="form-hint">JPG, PNG or WebP — max 2MB. Leave blank to keep current image.</div>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="checkbox-row">
                            <input type="checkbox" name="status" id="ec_status">
                            <span>Enabled (visible on the site)</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditCategory')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>

<script>
const CSRF_TOKEN = "<?= $csrf ?>";

// ─── Modal helpers ────────────────────────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(el => closeModal(el.id));
});

// ─── Add Category modal: always opens fresh/blank ─────────────────────────────
function openCreateCategoryModal() {
    const form = document.querySelector('#modalCreateCategory form');
    if (form) form.reset();
    document.getElementById('cc_status').checked = true;
    document.getElementById('ccImgPreview').style.display = 'none';
    document.getElementById('ccImgPlaceholder').style.display = 'flex';
    openModal('modalCreateCategory');
}

// ─── Edit Category modal: opens instantly, fields fill in as data arrives ────
function openEditCategory(id) {
    document.getElementById('ec_id').value = id;
    document.getElementById('ec_name').value = '';
    document.getElementById('ec_slug').value = '';
    document.getElementById('ec_status').checked = true;
    document.getElementById('ec_image').value = '';
    document.getElementById('ecImgPreview').style.display = 'none';
    document.getElementById('ecImgPlaceholder').style.display = 'flex';
    setEditModalLoading(true);
    openModal('modalEditCategory');

    fetch('?ajax=get_category&id=' + id)
        .then(r => r.json())
        .then(data => {
            setEditModalLoading(false);
            const c = data.category;
            if (!c) { showToast('Failed to load category.', 'error'); return; }
            document.getElementById('ec_name').value = c.name;
            document.getElementById('ec_slug').value = c.slug;
            document.getElementById('ec_status').checked = c.status === 'active';
            if (c.image) {
                document.getElementById('ecImgPreview').src = '/' + c.image;
                document.getElementById('ecImgPreview').style.display = 'block';
                document.getElementById('ecImgPlaceholder').style.display = 'none';
            }
        })
        .catch(() => {
            setEditModalLoading(false);
            showToast('Failed to load category.', 'error');
        });
}

function setEditModalLoading(isLoading) {
    const modal = document.querySelector('#modalEditCategory .modal');
    if (modal) modal.classList.toggle('is-loading', isLoading);
}

function confirmDeleteCategory(id, name) {
    if (confirm('Delete category "' + name + '"?\n\nThis cannot be undone.')) {
        window.location.href = '?delete_category=' + id;
    }
}

// ─── Image preview ──────────────────────────────────────────────────────────
function previewImg(input, previewId, placeholderId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById(previewId).src = e.target.result;
            document.getElementById(previewId).style.display = 'block';
            document.getElementById(placeholderId).style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ─── Status dropdown (Enable/Disable) — instant AJAX toggle ──────────────────
function toggleStatusMenu(id) {
    document.querySelectorAll('.status-dropdown.open').forEach(d => {
        if (d.id !== 'statusDropdown' + id) d.classList.remove('open');
    });
    document.getElementById('statusDropdown' + id).classList.toggle('open');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.status-dropdown')) {
        document.querySelectorAll('.status-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});
function setCategoryStatus(id, status) {
    const dropdown = document.getElementById('statusDropdown' + id);
    dropdown.classList.remove('open');
    const btn = dropdown.querySelector('.status-btn');
    const prevHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_status&csrf_token=' + encodeURIComponent(CSRF_TOKEN) + '&id=' + id + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.className = 'status-btn status-' + data.status;
            btn.innerHTML = (data.status === 'active' ? 'Enabled' : 'Disabled') + ' <i class="fas fa-caret-down"></i>';
            showToast('Status updated.', 'success');
        } else {
            btn.innerHTML = prevHtml;
            showToast('Failed to update status.', 'error');
        }
    })
    .catch(() => {
        btn.innerHTML = prevHtml;
        showToast('Failed to update status.', 'error');
    });
}

// ─── Live search / filter for the Categories table — no page reload ──────────
let catSearchTimer = null;
function liveSearchCategories(page) {
    page = page || 1;
    const searchInput = document.getElementById('catSearchInput');
    const statusSelect = document.getElementById('catStatusFilter');
    const spinner = document.getElementById('catSearchSpinner');
    const search = searchInput.value.trim();
    const status = statusSelect.value;

    spinner.style.display = 'inline-block';

    fetch('?ajax=search_categories&search=' + encodeURIComponent(search) + '&status=' + encodeURIComponent(status) + '&page=' + page)
        .then(r => r.json())
        .then(data => {
            document.getElementById('catTableBody').innerHTML = data.rows_html;
            document.getElementById('catPaginationWrap').innerHTML = data.pagination_html;
            document.getElementById('catCountText').textContent = data.count_text;
            document.getElementById('catSearchInfo').innerHTML = data.search
                ? '<div class="search-info">Showing results for <strong>"' + escapeHtmlText(data.search) + '"</strong></div>'
                : '';
            spinner.style.display = 'none';

            const url = new URL(window.location);
            search ? url.searchParams.set('search', search) : url.searchParams.delete('search');
            (status && status !== 'all') ? url.searchParams.set('status', status) : url.searchParams.delete('status');
            page > 1 ? url.searchParams.set('page', page) : url.searchParams.delete('page');
            window.history.replaceState({}, '', url);
        })
        .catch(() => { spinner.style.display = 'none'; });
}

function escapeHtmlText(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

const catSearchInputEl = document.getElementById('catSearchInput');
if (catSearchInputEl) {
    catSearchInputEl.addEventListener('input', () => {
        clearTimeout(catSearchTimer);
        catSearchTimer = setTimeout(() => liveSearchCategories(1), 300);
    });
}
const catStatusFilterEl = document.getElementById('catStatusFilter');
if (catStatusFilterEl) {
    catStatusFilterEl.addEventListener('change', () => liveSearchCategories(1));
}
const catFilterFormEl = document.getElementById('catFilterForm');
if (catFilterFormEl) {
    catFilterFormEl.addEventListener('submit', e => {
        e.preventDefault();
        clearTimeout(catSearchTimer);
        liveSearchCategories(1);
    });
}
const catClearBtnEl = document.getElementById('catClearBtn');
if (catClearBtnEl) {
    catClearBtnEl.addEventListener('click', e => {
        e.preventDefault();
        catSearchInputEl.value = '';
        catStatusFilterEl.value = 'all';
        liveSearchCategories(1);
    });
}
const catPaginationWrapEl = document.getElementById('catPaginationWrap');
if (catPaginationWrapEl) {
    catPaginationWrapEl.addEventListener('click', e => {
        const link = e.target.closest('a.page-link[data-page]');
        if (!link) return;
        e.preventDefault();
        liveSearchCategories(parseInt(link.dataset.page, 10));
    });
}

// ─── Toast ───────────────────────────────────────────────────────────────────
function showToast(message, type = 'default') {
    const bg = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#6b7280';
    Toastify({ text: message, position: 'center', style: { borderRadius: '12px', padding: '14px 22px', fontSize: '14px', fontWeight: '500', background: bg } }).showToast();
}

// ─── Sidebar ─────────────────────────────────────────────────────────────────
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
</script>
</body>
</html>
