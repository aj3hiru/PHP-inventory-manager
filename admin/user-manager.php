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
    empty($permissions['users']['create'])
) {
    exit('Access Denied');
}




$username = $_SESSION['username'];
$seo_robots = 'noindex, nofollow, noarchive, nosnippet';

$log_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$log_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

$upload_dir = DROOT_PATH . '/uploads/authors/';
$upload_url = '/uploads/authors/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ─── AJAX: fetch a user + their linked author profile (if any), for the unified
//     Add/Edit User modal (must be before any HTML output) ────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'user_full' && is_numeric($_GET['id'])) {
    $uid = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT id, username, email, role, permissions, status, created_at FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $urow = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->prepare("SELECT * FROM authors WHERE user_id = ?");
    $stmt2->execute([$uid]);
    $arow = $stmt2->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['user' => $urow ?: null, 'author' => $arow ?: null]);
    exit;
}

// ─── AJAX: live search/filter for the Users table (no page reload) ────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_users') {
    $s_search = trim($_GET['search'] ?? '');
    $s_role   = $_GET['role'] ?? 'all';
    $s_page   = max(1, (int)($_GET['page'] ?? 1));
    [$s_users, $s_total, $s_total_pages] = fetchUsersList($pdo, $s_search, $s_role, $s_page, 15);
    header('Content-Type: application/json');
    echo json_encode([
        'rows_html'       => renderUsersRows($s_users),
        'pagination_html' => renderUsersPagination($s_page, $s_total_pages, $s_search, $s_role),
        'total'           => $s_total,
        'count_text'      => $s_total . ' user' . ($s_total !== 1 ? 's' : '') . ' found',
        'search'          => $s_search,
    ]);
    exit;
}

$notifications = [];
$errors = [];

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

// ─── DEFAULT PERMISSIONS ──────────────────────────────────────────────────────
define('DEFAULT_PERMISSIONS', json_encode([
    "dashboard_access" => false,
    "blogs" => [
        "create" => false, "edit_own" => false, "edit_all" => false,
        "delete_own" => false, "delete_all" => false, "publish" => false,
        "unpublish" => false, "schedule" => false, "feature" => false,
        "manage_categories" => false, "manage_tags" => false,
        "manage_comments" => false, "view_drafts" => false, "manage_seo" => false
    ],
    "media" => ["upload" => false, "delete" => false, "manage_all" => false],
    "push_notifications" => ["send" => false, "schedule" => false, "manage_templates" => false],
    "ecommerce" => ["manage_categories" => false, "manage_products" => false, "manage_orders" => false, "manage_customers" => false, "manage_coupons" => false, "manage_payment" => false, "manage_billing" => false, "manage_credits" => false],
    "users" => ["create" => false, "edit" => false, "delete" => false, "suspend" => false, "change_roles" => false, "manage_permissions" => false],
    "authors" => ["create" => false, "edit" => false, "delete" => false, "approve" => false, "feature" => false],
    "analytics" => ["view_basic" => false, "view_advanced" => false],
    "ads" => ["manage_ads" => false, "view_revenue" => false],
    "settings" => ["general" => false, "seo" => false, "smtp" => false, "api_keys" => false, "maintenance_mode" => false],
    "pages" => ["create" => false, "edit" => false, "delete" => false],
    "files" => ["access_file_manager" => false],
    "security" => ["view_logs" => false, "manage_blacklist" => false, "manage_recaptcha" => false]
]));

// ─── ROLE-BASED PERMISSION PRESETS (auto-fill for "Advance Access") ───────────
function getRolePermissionDefaults(string $role): array {
    $all = json_decode(DEFAULT_PERMISSIONS, true);
    if ($role === 'admin') {
        // Admin: everything ticked by default
        array_walk_recursive($all, function (&$v) { $v = true; });
        return $all;
    }
    if ($role === 'editor') {
        $all['dashboard_access'] = true;
        $all['blogs'] = array_fill_keys(array_keys($all['blogs']), true);
        $all['media'] = array_fill_keys(array_keys($all['media']), true);
        $all['push_notifications']['send'] = true;
        $all['push_notifications']['schedule'] = true;
        $all['authors']['edit'] = true;
        $all['authors']['approve'] = true;
        $all['authors']['feature'] = true;
        $all['analytics']['view_basic'] = true;
        $all['analytics']['view_advanced'] = true;
        $all['pages']['create'] = true;
        $all['pages']['edit'] = true;
        $all['files']['access_file_manager'] = true;
        return $all;
    }
    // author (default)
    $all['dashboard_access'] = true;
    $all['blogs']['create'] = true;
    $all['blogs']['edit_own'] = true;
    $all['blogs']['delete_own'] = true;
    $all['blogs']['view_drafts'] = true;
    $all['blogs']['manage_seo'] = true;
    $all['media']['upload'] = true;
    $all['analytics']['view_basic'] = true;
    $all['files']['access_file_manager'] = true;
    return $all;
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function slugify(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
    return trim($str, '-');
}

function buildPermissionsJson(): string {
    $default = json_decode(DEFAULT_PERMISSIONS, true);
    $post = $_POST['permissions'] ?? [];
    foreach ($default as $key => $val) {
        if (is_array($val)) {
            foreach ($val as $subkey => $_) {
                $default[$key][$subkey] = isset($post[$key][$subkey]);
            }
        } else {
            $default[$key] = isset($post[$key]);
        }
    }
    return json_encode($default);
}

function renderPermissionsPanel(?string $permJson, string $prefix): string {
    $perms = $permJson ? json_decode($permJson, true) : json_decode(DEFAULT_PERMISSIONS, true);
    if (!$perms) $perms = json_decode(DEFAULT_PERMISSIONS, true);
    $labels = [
        'dashboard_access' => 'Dashboard Access',
        'blogs' => ['create'=>'Create','edit_own'=>'Edit Own','edit_all'=>'Edit All','delete_own'=>'Delete Own','delete_all'=>'Delete All','publish'=>'Publish','unpublish'=>'Unpublish','schedule'=>'Schedule','feature'=>'Feature','manage_categories'=>'Categories','manage_tags'=>'Tags','manage_comments'=>'Comments','view_drafts'=>'View Drafts','manage_seo'=>'Manage SEO'],
        'media' => ['upload'=>'Upload','delete'=>'Delete','manage_all'=>'Manage All'],
        'push_notifications' => ['send'=>'Send','schedule'=>'Schedule','manage_templates'=>'Templates'],
        'ecommerce' => ['manage_categories'=>'Categories','manage_products'=>'Products','manage_orders'=>'Orders','manage_customers'=>'Customers','manage_coupons'=>'Coupons','manage_payment'=>'Payment Settings','manage_billing'=>'Billing / POS','manage_credits'=>'Due'],
        'users' => ['create'=>'Create','edit'=>'Edit','delete'=>'Delete','suspend'=>'Suspend','change_roles'=>'Change Roles','manage_permissions'=>'Manage Permissions'],
        'authors' => ['create'=>'Create','edit'=>'Edit','delete'=>'Delete','approve'=>'Approve','feature'=>'Feature'],
        'analytics' => ['view_basic'=>'Basic Analytics','view_advanced'=>'Advanced Analytics'],
        'ads' => ['manage_ads'=>'Manage Ads','view_revenue'=>'View Revenue'],
        'settings' => ['general'=>'General','seo'=>'SEO','smtp'=>'SMTP','api_keys'=>'API Keys','maintenance_mode'=>'Maintenance'],
        'pages' => ['create'=>'Create','edit'=>'Edit','delete'=>'Delete'],
        'files' => ['access_file_manager'=>'File Manager'],
        'security' => ['view_logs'=>'View Logs','manage_blacklist'=>'Blacklist','manage_recaptcha'=>'reCAPTCHA'],
    ];
    $groupIcons = [
        'dashboard_access'=>'fa-tachometer-alt','blogs'=>'fa-blog','media'=>'fa-photo-video',
        'push_notifications'=>'fa-bell','users'=>'fa-users','authors'=>'fa-feather-alt',
        'ecommerce'=>'fa-store',
        'analytics'=>'fa-chart-bar','ads'=>'fa-ad','settings'=>'fa-cog','pages'=>'fa-file-alt',
        'files'=>'fa-folder','security'=>'fa-shield-alt'
    ];
    $html = '<div class="perm-panel">';
    foreach ($perms as $key => $val) {
        $icon = $groupIcons[$key] ?? 'fa-circle';
        if (!is_array($val)) {
            $checked = $val ? ' checked' : '';
            $isChecked = $val ? ' checked' : '';
            $itemClass = $val ? ' checked' : '';
            $html .= '<div class="perm-group"><div class="perm-grid">';
            $html .= '<label class="perm-item' . $itemClass . '"><input type="checkbox" name="permissions[' . $key . ']"' . $checked . ' onchange="this.closest(\'.perm-item\').classList.toggle(\'checked\',this.checked)"> <i class="fas ' . $icon . '" style="font-size:.7rem;margin-right:.2rem"></i> ' . ($labels[$key] ?? ucfirst(str_replace('_',' ',$key))) . '</label>';
            $html .= '</div></div>';
        } else {
            $groupLabel = ucfirst(str_replace('_',' ',$key));
            $html .= '<div class="perm-group"><div class="perm-group-title"><i class="fas ' . $icon . '"></i> ' . $groupLabel . '</div><div class="perm-grid">';
            foreach ($val as $subkey => $subval) {
                $checked = $subval ? ' checked' : '';
                $itemClass = $subval ? ' checked' : '';
                $sublabel = $labels[$key][$subkey] ?? ucfirst(str_replace('_',' ',$subkey));
                $html .= '<label class="perm-item' . $itemClass . '"><input type="checkbox" name="permissions[' . $key . '][' . $subkey . ']"' . $checked . ' onchange="this.closest(\'.perm-item\').classList.toggle(\'checked\',this.checked)"> ' . $sublabel . '</label>';
            }
            $html .= '</div></div>';
        }
    }
    $html .= '</div>';
    return $html;
}

function renderAuthorFieldsBlock(string $p): string {
    ob_start();
    ?>
    <div class="form-section-title" style="margin-top:1.25rem"><i class="fas fa-feather-alt"></i> Author Profile <span style="font-weight:400;color:var(--gray-500);font-size:.75rem">(optional — fill in to also create/update an author profile for this user)</span></div>
    <div class="form-grid form-grid-2">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" id="<?= $p ?>_full_name" class="form-control" placeholder="Enter your full name">
        </div>
        <div class="form-group">
            <label>Display Name</label>
            <input type="text" name="name" id="<?= $p ?>_name" class="form-control" placeholder="Enter display name">
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Bio</label>
            <textarea name="bio" id="<?= $p ?>_bio" class="form-control" rows="2" placeholder="Short author biography..."></textarea>
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Profile Image</label>
            <div class="img-preview-wrap">
                <div id="<?= $p ?>ImgPreviewWrap">
                    <div class="img-preview-placeholder" id="<?= $p ?>ImgPlaceholder"><i class="fas fa-user"></i></div>
                    <img id="<?= $p ?>ImgPreview" class="img-preview" src="" style="display:none">
                </div>
                <div>
                    <input type="file" name="profile_image" id="<?= $p ?>_profile_image" accept="image/jpeg,image/png,image/webp" onchange="previewImg(this,'<?= $p ?>ImgPreview','<?= $p ?>ImgPlaceholder')">
                    <div class="form-hint">JPG, PNG or WebP — max 2MB</div>
                </div>
            </div>
        </div>
    </div>
    <div class="form-grid form-grid-2" style="margin-top:1rem">
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="a_email" id="<?= $p ?>_email" class="form-control" placeholder="author@example.com">
        </div>
        <div class="form-group">
            <label>Mobile</label>
            <input type="text" name="mobile_number" id="<?= $p ?>_mobile" class="form-control" placeholder="+91 XXXXXXXXXX">
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Address</label>
            <textarea name="address" id="<?= $p ?>_address" class="form-control" rows="2" placeholder="City, State, Country..."></textarea>
        </div>
        <div class="form-group">
            <label>Designation</label>
            <input type="text" name="designation" id="<?= $p ?>_designation" class="form-control" placeholder="e.g. Senior Writer">
        </div>
        <div class="form-group">
            <label>Experience</label>
            <input type="text" name="experience" id="<?= $p ?>_experience" class="form-control" placeholder="e.g. 5 years">
        </div>
        <div class="form-group">
            <label>Languages Known</label>
            <input type="text" name="languages_known" id="<?= $p ?>_languages" class="form-control" placeholder="Hindi, English, ...">
        </div>
        <div class="form-group">
            <label>Qualifications</label>
            <input type="text" name="qualifications" id="<?= $p ?>_qualifications" class="form-control" placeholder="B.Sc, M.A., ...">
        </div>
        <div class="form-group" style="grid-column:1/-1">
            <label>Certifications</label>
            <textarea name="certifications" id="<?= $p ?>_certifications" class="form-control" rows="2" placeholder="Any notable certifications..."></textarea>
        </div>
        <div class="form-group">
            <label><i class="fab fa-instagram" style="color:#e1306c"></i> Instagram</label>
            <input type="url" name="instagram" id="<?= $p ?>_instagram" class="form-control" placeholder="https://instagram.com/...">
        </div>
        <div class="form-group">
            <label><i class="fab fa-linkedin" style="color:#0077b5"></i> LinkedIn</label>
            <input type="url" name="linkedin" id="<?= $p ?>_linkedin" class="form-control" placeholder="https://linkedin.com/in/...">
        </div>
        <div class="form-group">
            <label><i class="fab fa-twitter" style="color:#1da1f2"></i> Twitter / X</label>
            <input type="url" name="twitter" id="<?= $p ?>_twitter" class="form-control" placeholder="https://twitter.com/...">
        </div>
        <div class="form-group">
            <label><i class="fab fa-facebook" style="color:#1877f2"></i> Facebook</label>
            <input type="url" name="facebook" id="<?= $p ?>_facebook" class="form-control" placeholder="https://facebook.com/...">
        </div>
        <div class="form-group">
            <label><i class="fas fa-at" style="color:var(--gray-600)"></i> Threads</label>
            <input type="url" name="threads" id="<?= $p ?>_threads" class="form-control" placeholder="https://threads.net/@...">
        </div>
        <div class="form-group">
            <label>Author Status</label>
            <select name="a_status" id="<?= $p ?>_status" class="form-control">
                <option value="active" selected>Active</option>
                <option value="pending">Pending</option>
                <option value="suspended">Suspended</option>
            </select>
        </div>
        <div class="form-group" style="justify-content:flex-end;padding-top:.5rem">
            <label class="checkbox-row">
                <input type="checkbox" name="is_featured" id="<?= $p ?>_featured" value="1">
                <span><i class="fas fa-feather-alt"></i> Make Author</span>
            </label>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── HELPERS: users list — shared by the normal page render AND the live-search
//     AJAX endpoint, so both always produce identical markup ───────────────────
function fetchUsersList(PDO $pdo, string $search, string $role_filter, int $page, int $per_page): array {
    $offset = ($page - 1) * $per_page;
    $where = [];
    $params = [];
    if ($search) { $where[] = "(username LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($role_filter !== 'all') { $where[] = "role = ?"; $params[] = $role_filter; }
    $sql = "SELECT u.*, (SELECT COUNT(*) FROM authors a WHERE a.user_id = u.id) as has_author FROM users u" . ($where ? " WHERE " . implode(" AND ", $where) : "");
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u" . ($where ? " WHERE " . implode(" AND ", $where) : ""));
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();
    $total_pages = (int)ceil($total / $per_page);
    $stmt = $pdo->prepare($sql . " ORDER BY u.created_at DESC LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [$users, $total, $total_pages];
}

function renderUserRow(array $u, int $current_user_id): string {
    ob_start();
    ?>
    <tr>
        <td>
            <div class="u-identity">
                <div class="u-avatar"><?= strtoupper(substr($u['username'], 0, 1)) ?></div>
                <div>
                    <div class="u-name"><?= htmlspecialchars($u['username']) ?></div>
                    <div class="u-email"><?= htmlspecialchars($u['email']) ?></div>
                </div>
            </div>
        </td>
        <td>
            <span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span>
        </td>
        <td>
            <div class="row-actions">
                <button class="btn-action btn-edit" onclick="openEditUser(<?= (int)$u['id'] ?>)">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <?php if ($u['has_author']): ?>
                <button class="btn-action btn-view-author" onclick="openEditUser(<?= (int)$u['id'] ?>)">
                    <i class="fas fa-eye"></i> <span class="hide-mobile">View User</span>
                </button>
                <?php endif; ?>
                <?php if ((int)$u['id'] !== $current_user_id): ?>
                <button class="btn-action btn-delete" onclick="confirmDeleteUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')">
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function renderUsersRows(array $users): string {
    if (empty($users)) {
        return '<tr><td colspan="3"><div class="empty-state"><i class="fas fa-users"></i><p>No users found.</p></div></td></tr>';
    }
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $html = '';
    foreach ($users as $u) $html .= renderUserRow($u, $current_user_id);
    return $html;
}

function renderUsersPagination(int $page, int $total_pages, string $search, string $role_filter): string {
    if ($total_pages <= 1) return '';
    $base_url = "?search=" . urlencode($search) . "&role=" . urlencode($role_filter);
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

function upload_profile_image(array $file, string $upload_dir): string|false {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed)) return false;
    if ($file['size'] > 2 * 1024 * 1024) return false;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fname = 'author_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $upload_dir . $fname)) return 'uploads/authors/' . $fname;
    return false;
}

// ─── HELPER: create or update an author profile from posted fields ───────────
// Used by both create_user and edit_user so the separate "Create Author
// Profile" flow is no longer needed — everything happens from one form.
function saveAuthorProfile(PDO $pdo, int $user_id, int $existing_author_id, array $files, string $upload_dir): array {
    $fields = [
        'full_name'       => trim($_POST['full_name'] ?? ''),
        'name'            => trim($_POST['name'] ?? ''),
        'bio'             => trim($_POST['bio'] ?? ''),
        'email'           => trim($_POST['a_email'] ?? ''),
        'mobile_number'   => trim($_POST['mobile_number'] ?? ''),
        'address'         => trim($_POST['address'] ?? ''),
        'qualifications'  => trim($_POST['qualifications'] ?? ''),
        'designation'     => trim($_POST['designation'] ?? ''),
        'experience'      => trim($_POST['experience'] ?? ''),
        'certifications'  => trim($_POST['certifications'] ?? ''),
        'languages_known' => trim($_POST['languages_known'] ?? ''),
        'instagram'       => trim($_POST['instagram'] ?? ''),
        'threads'         => trim($_POST['threads'] ?? ''),
        'linkedin'        => trim($_POST['linkedin'] ?? ''),
        'facebook'        => trim($_POST['facebook'] ?? ''),
        'twitter'         => trim($_POST['twitter'] ?? ''),
        'status'          => in_array($_POST['a_status'] ?? '', ['active','pending','suspended']) ? $_POST['a_status'] : 'active',
        'is_featured'     => isset($_POST['is_featured']) ? 1 : 0,
    ];

    if (empty($fields['full_name']) || empty($fields['name'])) {
        return [false, 'Author profile needs both Full Name and Display Name — or leave both blank to skip it.'];
    }

    $slug = slugify($fields['name']);
    $slug_check = $pdo->prepare("SELECT id FROM authors WHERE slug = ? AND id != ?");
    $slug_check->execute([$slug, $existing_author_id]);
    if ($slug_check->fetch()) $slug .= '-' . time();

    $profile_image = null;
    if (!empty($files['profile_image']['name'])) {
        $uploaded = upload_profile_image($files['profile_image'], $upload_dir);
        if ($uploaded) {
            $profile_image = $uploaded;
        } else {
            return [false, 'Image upload failed. Allowed: JPG, PNG, WebP (max 2MB).'];
        }
    }

    try {
        if ($existing_author_id > 0) {
            if ($profile_image) {
                $old_img = $pdo->prepare("SELECT profile_image FROM authors WHERE id = ?");
                $old_img->execute([$existing_author_id]);
                $old = $old_img->fetchColumn();
                if ($old && file_exists($upload_dir . basename($old))) @unlink($upload_dir . basename($old));
            }
            $sql = "UPDATE authors SET full_name=?, name=?, slug=?, bio=?, email=?, mobile_number=?, address=?, qualifications=?, designation=?, experience=?, certifications=?, languages_known=?, instagram=?, threads=?, linkedin=?, facebook=?, twitter=?, status=?, is_featured=?" . ($profile_image ? ", profile_image=?" : "") . " WHERE id=?";
            $ordered = [$fields['full_name'], $fields['name'], $slug, $fields['bio'], $fields['email'], $fields['mobile_number'], $fields['address'], $fields['qualifications'], $fields['designation'], $fields['experience'], $fields['certifications'], $fields['languages_known'], $fields['instagram'], $fields['threads'], $fields['linkedin'], $fields['facebook'], $fields['twitter'], $fields['status'], $fields['is_featured']];
            if ($profile_image) $ordered[] = $profile_image;
            $ordered[] = $existing_author_id;
            $pdo->prepare($sql)->execute($ordered);
        } else {
            $sql = "INSERT INTO authors (full_name, name, slug, bio, email, mobile_number, address, qualifications, designation, experience, certifications, languages_known, instagram, threads, linkedin, facebook, twitter, status, is_featured, user_id" . ($profile_image ? ", profile_image" : "") . ")
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($profile_image ? ", ?" : "") . ")";
            $ordered = [$fields['full_name'], $fields['name'], $slug, $fields['bio'], $fields['email'], $fields['mobile_number'], $fields['address'], $fields['qualifications'], $fields['designation'], $fields['experience'], $fields['certifications'], $fields['languages_known'], $fields['instagram'], $fields['threads'], $fields['linkedin'], $fields['facebook'], $fields['twitter'], $fields['status'], $fields['is_featured'], $user_id];
            if ($profile_image) $ordered[] = $profile_image;
            $pdo->prepare($sql)->execute($ordered);
        }
        $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'author_save', ?, ?, ?)")
            ->execute([$_SESSION['user_id'] ?? 0, "Saved author: " . $fields['name'], $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown']);
        return [true, $fields['name']];
    } catch (PDOException $e) {
        return [false, 'DB Error (author profile): ' . $e->getMessage()];
    }
}

// ─── ACTION: DELETE USER ─────────────────────────────────────────────────────
if (isset($_GET['delete_user']) && is_numeric($_GET['delete_user'])) {
    $del_id = (int)$_GET['delete_user'];
    if ($del_id === (int)$_SESSION['user_id']) {
        $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'You cannot delete your own account.'];
    } else {
        try {
            // Author profile image cleanup
            $stmt = $pdo->prepare("SELECT profile_image FROM authors WHERE user_id = ?");
            $stmt->execute([$del_id]);
            $img = $stmt->fetchColumn();
            if ($img && file_exists(DROOT_PATH . '/' . $img)) @unlink(DROOT_PATH . '/' . $img);

            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$del_id]);
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'user_delete', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Deleted user ID: $del_id", $log_ip, $log_ua]);
            $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'User deleted successfully.'];
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Delete failed: ' . $e->getMessage()];
        }
    }
}

// ─── ACTION: TOGGLE USER STATUS (promote/demote role) ───────────────────────
if (isset($_GET['toggle_role']) && is_numeric($_GET['toggle_role'])) {
    $tid = (int)$_GET['toggle_role'];
    $new_role = $_GET['role'] ?? '';
    if (in_array($new_role, ['admin', 'editor', 'author'])) {
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$new_role, $tid]);
        $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'User role updated.'];
    }
}

// ─── ACTION: CREATE / EDIT USER ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['create_user', 'edit_user'])) {
    csrf_check();

    $action     = $_POST['action'];
    $edit_id    = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $u_username = trim($_POST['username'] ?? '');
    $u_email    = trim($_POST['email'] ?? '');
    $u_password = $_POST['password'] ?? '';
    $u_role     = in_array($_POST['role'] ?? '', ['admin','editor','author']) ? $_POST['role'] : 'author';

    if (empty($u_username) || empty($u_email)) {
        $errors[] = 'Username and email are required.';
    } elseif (!filter_var($u_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif ($action === 'create_user' && empty($u_password)) {
        $errors[] = 'Password is required for new users.';
    } else {
        try {
            if ($action === 'create_user') {
                $hash = password_hash($u_password, PASSWORD_BCRYPT);
                $u_permissions = buildPermissionsJson();
                $pdo->prepare("INSERT INTO users (username, email, password_hash, role, permissions) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$u_username, $u_email, $hash, $u_role, $u_permissions]);
                $new_uid = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'user_create', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Created user: $u_username (ID: $new_uid)", $log_ip, $log_ua]);
                $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => "User \"$u_username\" created."];

                // Optional: author profile filled in on the same form
                if (trim($_POST['name'] ?? '') !== '' || trim($_POST['full_name'] ?? '') !== '') {
                    [$a_ok, $a_msg] = saveAuthorProfile($pdo, $new_uid, 0, $_FILES, $upload_dir);
                    $notifications[] = $a_ok
                        ? ['type' => 'success', 'icon' => 'fas fa-feather-alt', 'message' => "Author profile created for \"$a_msg\"."]
                        : ['type' => 'warning', 'icon' => 'fas fa-exclamation-triangle', 'message' => "User created, but author profile wasn't saved: $a_msg"];
                }
            } else {
                $sql = "UPDATE users SET username = ?, email = ?, role = ?, permissions = ?";
                $params = [$u_username, $u_email, $u_role, buildPermissionsJson()];
                if (!empty($u_password)) {
                    $sql .= ", password_hash = ?";
                    $params[] = password_hash($u_password, PASSWORD_BCRYPT);
                }
                $sql .= " WHERE id = ?";
                $params[] = $edit_id;
                $pdo->prepare($sql)->execute($params);
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'user_edit', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Edited user ID: $edit_id", $log_ip, $log_ua]);
                $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => "User updated."];

                // Optional: create or update the linked author profile from the same form
                if (trim($_POST['name'] ?? '') !== '' || trim($_POST['full_name'] ?? '') !== '') {
                    $ea = $pdo->prepare("SELECT id FROM authors WHERE user_id = ?");
                    $ea->execute([$edit_id]);
                    $existing_author_id = (int)($ea->fetchColumn() ?: 0);
                    [$a_ok, $a_msg] = saveAuthorProfile($pdo, $edit_id, $existing_author_id, $_FILES, $upload_dir);
                    $notifications[] = $a_ok
                        ? ['type' => 'success', 'icon' => 'fas fa-feather-alt', 'message' => "Author profile saved for \"$a_msg\"."]
                        : ['type' => 'warning', 'icon' => 'fas fa-exclamation-triangle', 'message' => "User updated, but author profile wasn't saved: $a_msg"];
                }
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $errors[] = 'Username or email already exists.';
            } else {
                $errors[] = 'DB Error: ' . $e->getMessage();
            }
        }
    }
}

// ─── ACTION: DELETE AUTHOR ───────────────────────────────────────────────────
if (isset($_GET['delete_author']) && is_numeric($_GET['delete_author'])) {
    $da_id = (int)$_GET['delete_author'];
    $stmt = $pdo->prepare("SELECT profile_image FROM authors WHERE id = ?");
    $stmt->execute([$da_id]);
    $img = $stmt->fetchColumn();
    if ($img && file_exists($upload_dir . $img)) @unlink($upload_dir . $img);
    $pdo->prepare("DELETE FROM authors WHERE id = ?")->execute([$da_id]);
    $notifications[] = ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'Author profile deleted.'];
}

// ─── FETCH DATA ───────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

// Users list
[$users, $total_users, $total_u_pages] = fetchUsersList($pdo, $search, $role_filter, $page, $per_page);

// Stats
$total_u    = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_a    = $pdo->query("SELECT COUNT(*) FROM authors")->fetchColumn();
$admins     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$active_a   = $pdo->query("SELECT COUNT(*) FROM authors WHERE status='active'")->fetchColumn();
$pending_a  = $pdo->query("SELECT COUNT(*) FROM authors WHERE status='pending'")->fetchColumn();

// Prefetch user for edit modal
$edit_user_data = null;
if (isset($_GET['edit_user']) && is_numeric($_GET['edit_user'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_user']]);
    $edit_user_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

$edit_author_data = null;
if (isset($_GET['edit_author']) && is_numeric($_GET['edit_author'])) {
    $stmt = $pdo->prepare("SELECT * FROM authors WHERE id = ?");
    $stmt->execute([(int)$_GET['edit_author']]);
    $edit_author_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

$errors_html = '';
foreach ($errors as $e) {
    $notifications[] = ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => $e];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>User & Author Manager - <?= htmlspecialchars($site_name) ?> Admin</title>
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
@media(min-width:1024px){.filter-row{grid-template-columns:1fr 1fr 1fr auto}}
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

/* User row */
.u-identity{display:flex;align-items:center;gap:.75rem}
.u-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.9375rem;flex-shrink:0}
.u-avatar img{width:38px;height:38px;border-radius:50%;object-fit:cover}
.u-name{font-weight:600;color:var(--gray-900);font-size:.9375rem}
.u-email{font-size:.8125rem;color:var(--gray-500)}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .625rem;border-radius:9999px;font-size:.75rem;font-weight:600}
.badge-admin{background:#fdf4ff;color:#7e22ce}
.badge-editor{background:#eff6ff;color:#1d4ed8}
.badge-author{background:#f0fdf4;color:#15803d}
.badge-active{background:#ecfdf5;color:#065f46}
.badge-pending{background:#fffbeb;color:#92400e}
.badge-suspended{background:#fef2f2;color:#991b1b}
.badge-featured{background:linear-gradient(135deg,#f59e0b,#d97706);color:white}

/* Row actions */
.row-actions{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.btn-action{display:inline-flex;align-items:center;gap:.3rem;padding:.35rem .75rem;border-radius:var(--radius);font-size:.8125rem;font-weight:500;text-decoration:none;border:none;cursor:pointer;transition:all .15s;white-space:nowrap}
.btn-edit{background:var(--primary-lighter);color:var(--primary)}
.btn-edit:hover{background:var(--primary-light)}
.btn-delete{background:#fef2f2;color:var(--danger)}
.btn-delete:hover{background:var(--danger);color:white}
.btn-author{background:#f0fdf4;color:#15803d}
.btn-author:hover{background:#dcfce7}
.btn-view-author{background:#eff6ff;color:#1d4ed8}
.btn-view-author:hover{background:#dbeafe}

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
.modal{background:white;border-radius:var(--radius-xl);width:100%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:0 15px 40px rgba(0,0,0,.18)}
.modal-lg{max-width:860px}
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
.form-grid-3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:640px){.form-grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:420px){.form-grid-3{grid-template-columns:1fr}}
.form-group{display:flex;flex-direction:column;gap:.4rem}
.form-group label{font-size:.8125rem;font-weight:600;color:var(--gray-700)}
.form-group label .req{color:var(--danger)}
.form-control{padding:.625rem .875rem;border:1px solid var(--gray-200);border-radius:var(--radius);font-size:.9375rem;background:white;color:var(--gray-800);transition:all .2s;width:100%;font-family:inherit}
.form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light)}
textarea.form-control{resize:vertical;min-height:90px}
.form-hint{font-size:.75rem;color:var(--gray-500);margin-top:.25rem}
.form-section-title{font-size:.875rem;font-weight:700;color:var(--gray-700);padding:.75rem 0 .5rem;border-bottom:1px solid var(--gray-100);margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem}
.form-section-title i{color:var(--primary)}

/* Permissions panel */
.perm-panel{margin-top:.5rem}
.perm-group{margin-bottom:1rem}
.perm-group-title{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-500);margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.4rem}
.perm-item{display:flex;align-items:center;gap:.5rem;padding:.35rem .5rem;border-radius:var(--radius);background:var(--gray-50);border:1px solid var(--gray-100);cursor:pointer;transition:all .15s;font-size:.8125rem;color:var(--gray-700)}
.perm-item:hover{border-color:var(--primary);background:var(--primary-lighter)}
.perm-item input[type=checkbox]{accent-color:var(--primary);width:14px;height:14px;flex-shrink:0;cursor:pointer}
.perm-item.checked{background:var(--primary-lighter);border-color:var(--primary);color:var(--primary);font-weight:500}
.adv-toggle{display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:.75rem 1rem;background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--radius);font-weight:600;color:var(--gray-700);font-size:.875rem;margin-top:.75rem;user-select:none}
.adv-toggle:hover{border-color:var(--primary);color:var(--primary)}
.adv-toggle .chev{transition:transform .2s}
.adv-toggle.open .chev{transform:rotate(180deg)}
.adv-panel{display:none;margin-top:.75rem}
.adv-panel.open{display:block}
.img-preview-wrap{display:flex;align-items:center;gap:1rem;margin-top:.5rem}
.img-preview{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--gray-200);background:var(--gray-100)}
.img-preview-placeholder{width:64px;height:64px;border-radius:50%;background:var(--gray-100);display:flex;align-items:center;justify-content:center;color:var(--gray-400);font-size:1.5rem;border:2px dashed var(--gray-200)}
.checkbox-row{display:flex;align-items:center;gap:.625rem;cursor:pointer}
.checkbox-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--primary);cursor:pointer}
.checkbox-row span{font-size:.9375rem;font-weight:500;color:var(--gray-700)}

/* Author card view for smaller data */
.author-grid{display:grid;gap:1rem;grid-template-columns:1fr}
@media(min-width:640px){.author-grid{grid-template-columns:1fr 1fr}}
@media(min-width:1024px){.author-grid{grid-template-columns:1fr 1fr 1fr}}

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
                    <h1>Users &amp; Authors</h1>
                    <p>Manage accounts, roles and author profiles</p>
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
                    <div class="stat-value"><?= number_format($total_u) ?></div>
                    <div class="stat-change">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--primary)"><?= number_format($admins) ?></div>
                    <div class="stat-change">Admins</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--info)"><?= number_format($total_a) ?></div>
                    <div class="stat-change">Author Profiles</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--success)"><?= number_format($active_a) ?></div>
                    <div class="stat-change">Active Authors</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="color:var(--warning)"><?= number_format($pending_a) ?></div>
                    <div class="stat-change">Pending Authors</div>
                </div>
            </div>

            <!-- Filter -->
            <div class="filter-section">
                <form method="GET" action="" id="usersFilterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" id="usersSearchInput" class="filter-control" placeholder="Username or email..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                        </div>
                        <div class="filter-group">
                            <label>Role</label>
                            <select name="role" id="usersRoleFilter" class="filter-control">
                                <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>All Roles</option>
                                <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="editor" <?= $role_filter === 'editor' ? 'selected' : '' ?>>Editor</option>
                                <option value="author" <?= $role_filter === 'author' ? 'selected' : '' ?>>Author</option>
                            </select>
                        </div>
                        <div class="filter-group" style="justify-content:flex-end">
                            <label>&nbsp;</label>
                            <div style="display:flex;gap:.5rem;align-items:center">
                                <i class="fas fa-circle-notch fa-spin" id="usersSearchSpinner" style="display:none;color:var(--primary)"></i>
                                <a href="?" class="btn btn-secondary" id="usersClearBtn"><i class="fas fa-times"></i></a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div id="usersSearchInfo">
            <?php if ($search): ?>
            <div class="search-info">Showing results for <strong>"<?= htmlspecialchars($search) ?>"</strong></div>
            <?php endif; ?>
            </div>

            <div class="toolbar">
                <div>
                    <div class="toolbar-title">All Users</div>
                    <div class="toolbar-sub" id="usersCountText"><?= $total_users ?> user<?= $total_users !== 1 ? 's' : '' ?> found</div>
                </div>
                <button class="btn btn-primary" onclick="openCreateUserModal()">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
            </div>

            <div class="table-wrap">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody"><?= renderUsersRows($users) ?></tbody>
                    </table>
                </div>

                <!-- Pagination Users -->
                <div id="usersPaginationWrap"><?= renderUsersPagination($page, $total_u_pages, $search, $role_filter) ?></div>
            </div>

        </div><!-- /content-wrapper -->
    </main>
</div>

<!-- ══════════════════════════════════════════════
     MODAL: Create User
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalCreateUser">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-user-plus" style="color:var(--primary);margin-right:.5rem"></i>Add New User</div>
            <button class="modal-close" onclick="closeModal('modalCreateUser')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-body">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Username <span class="req">*</span></label>
                        <input type="text" name="username" class="form-control" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" class="form-control" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Password <span class="req">*</span></label>
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="createRole" class="form-control" onchange="applyRoleDefaults(this.value,'createPermPanel')">
                            <option value="author">Author</option>
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>

                <?= renderAuthorFieldsBlock('cf') ?>

                <div class="adv-toggle" id="createAdvToggle" onclick="toggleAdvanceAccess('createAdvToggle','createPermPanel')">
                    <span><i class="fas fa-shield-alt"></i> Advance Access</span>
                    <i class="fas fa-chevron-down chev"></i>
                </div>
                <div class="adv-panel" id="createPermPanel">
                    <?= renderPermissionsPanel(null, 'create') ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCreateUser')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     MODAL: Edit User
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalEditUser">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-user-edit" style="color:var(--primary);margin-right:.5rem"></i>Edit User</div>
            <button class="modal-close" onclick="closeModal('modalEditUser')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            <input type="hidden" name="author_id" id="editAuthorId" value="0">
            <div class="modal-body">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Username <span class="req">*</span></label>
                        <input type="text" name="username" id="editUsername" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Email <span class="req">*</span></label>
                        <input type="email" name="email" id="editEmail" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="editRole" class="form-control" onchange="applyRoleDefaults(this.value,'editPermissionsPanel')">
                            <option value="author">Author</option>
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>

                <?= renderAuthorFieldsBlock('ef') ?>

                <div class="adv-toggle" id="editAdvToggle" onclick="toggleAdvanceAccess('editAdvToggle','editPermissionsPanel')">
                    <span><i class="fas fa-shield-alt"></i> Advance Access</span>
                    <i class="fas fa-chevron-down chev"></i>
                </div>
                <div id="editPermissionsPanel" class="adv-panel perm-panel">
                    <?= renderPermissionsPanel(null, 'edit') ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEditUser')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>

<script>
const ADMIN_URL = "<?= ADMIN_URL ?>";
const UPLOAD_URL = "<?= $upload_url ?>";
const ROLE_DEFAULTS = <?= json_encode([
    'author' => getRolePermissionDefaults('author'),
    'editor' => getRolePermissionDefaults('editor'),
    'admin'  => getRolePermissionDefaults('admin'),
]) ?>;

// ─── Role → permission auto-fill, and the "Advance Access" collapsible ────────
function applyRoleDefaults(role, panelId) {
    const defaults = ROLE_DEFAULTS[role];
    const panel = document.getElementById(panelId);
    if (!defaults || !panel) return;
    panel.querySelectorAll('input[type=checkbox][name^="permissions"]').forEach(cb => {
        const m = cb.name.match(/permissions\[([^\]]+)\](?:\[([^\]]+)\])?/);
        if (!m) return;
        const g = m[1], s = m[2];
        const val = s ? !!(defaults[g] && defaults[g][s]) : !!defaults[g];
        cb.checked = val;
        cb.closest('.perm-item').classList.toggle('checked', val);
    });
}
function toggleAdvanceAccess(toggleId, panelId) {
    document.getElementById(toggleId).classList.toggle('open');
    document.getElementById(panelId).classList.toggle('open');
}

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

// ─── setVal helper ──────────────────────────────────────────────────────────
function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val || '';
}

// ─── Add User modal: always opens fresh/blank ─────────────────────────────────
function openCreateUserModal() {
    const form = document.querySelector('#modalCreateUser form');
    if (form) form.reset();
    document.getElementById('cfImgPreview').style.display = 'none';
    document.getElementById('cfImgPlaceholder').style.display = 'flex';
    document.getElementById('createAdvToggle').classList.remove('open');
    document.getElementById('createPermPanel').classList.remove('open');
    applyRoleDefaults('author', 'createPermPanel');
    openModal('modalCreateUser');
}

// ─── Edit User modal: fetches the user + their linked author profile (if any)
//     via AJAX, so this one modal covers "edit user", "make author" and
//     "edit author" — there's no separate author-profile flow anymore ─────────
function openEditUser(userId) {
    // Open the modal INSTANTLY — don't make the click wait on the network.
    // Clear it to a blank/loading state first, then fill fields in as soon
    // as the data arrives (usually well under a second).
    document.getElementById('editUserId').value = userId;
    document.getElementById('editUsername').value = '';
    document.getElementById('editEmail').value = '';
    document.getElementById('editRole').value = 'author';
    document.getElementById('editAuthorId').value = 0;
    document.getElementById('editAdvToggle').classList.remove('open');
    document.getElementById('editPermissionsPanel').classList.remove('open');
    document.querySelectorAll('#modalEditUser input[type=checkbox][name^="permissions"]').forEach(cb => {
        cb.checked = false;
        cb.closest('.perm-item').classList.remove('checked');
    });
    resetEditAuthorFields();
    setEditModalLoading(true);
    openModal('modalEditUser');

    fetch('?ajax=user_full&id=' + userId)
        .then(r => r.json())
        .then(data => {
            const u = data.user, a = data.author;
            setEditModalLoading(false);
            if (!u) { showToast('Failed to load user.', 'error'); return; }

            document.getElementById('editUserId').value = u.id;
            document.getElementById('editUsername').value = u.username;
            document.getElementById('editEmail').value = u.email;
            document.getElementById('editRole').value = u.role;

            // Populate permissions checkboxes with this user's actual saved permissions
            let perms = {};
            try { perms = u.permissions ? JSON.parse(u.permissions) : {}; } catch(e) {}
            document.querySelectorAll('#modalEditUser input[type=checkbox][name^="permissions"]').forEach(cb => {
                const m = cb.name.match(/permissions\[([^\]]+)\](?:\[([^\]]+)\])?/);
                if (!m) return;
                const g = m[1], s = m[2];
                const val = s ? !!(perms[g] && perms[g][s]) : !!perms[g];
                cb.checked = val;
                cb.closest('.perm-item').classList.toggle('checked', val);
            });
            document.getElementById('editAdvToggle').classList.remove('open');
            document.getElementById('editPermissionsPanel').classList.remove('open');

            // Author profile fields (blank if this user has no profile yet)
            document.getElementById('editAuthorId').value = a ? a.id : 0;
            if (a) {
                setVal('ef_full_name', a.full_name);
                setVal('ef_name', a.name);
                setVal('ef_bio', a.bio);
                setVal('ef_email', a.email);
                setVal('ef_mobile', a.mobile_number);
                setVal('ef_address', a.address);
                setVal('ef_designation', a.designation);
                setVal('ef_experience', a.experience);
                setVal('ef_languages', a.languages_known);
                setVal('ef_qualifications', a.qualifications);
                setVal('ef_certifications', a.certifications);
                setVal('ef_instagram', a.instagram);
                setVal('ef_linkedin', a.linkedin);
                setVal('ef_twitter', a.twitter);
                setVal('ef_facebook', a.facebook);
                setVal('ef_threads', a.threads);
                document.getElementById('ef_status').value = a.status || 'active';
                document.getElementById('ef_featured').checked = a.is_featured == 1;
                if (a.profile_image) {
                    document.getElementById('efImgPreview').src = '/' + a.profile_image;
                    document.getElementById('efImgPreview').style.display = 'block';
                    document.getElementById('efImgPlaceholder').style.display = 'none';
                }
            }
        })
        .catch(() => {
            setEditModalLoading(false);
            showToast('Failed to load user data.', 'error');
        });
}

function setEditModalLoading(isLoading) {
    const modal = document.querySelector('#modalEditUser .modal');
    if (modal) modal.classList.toggle('is-loading', isLoading);
}

function resetEditAuthorFields() {
    ['ef_full_name','ef_name','ef_bio','ef_email','ef_mobile','ef_address','ef_designation','ef_experience','ef_languages','ef_qualifications','ef_certifications','ef_instagram','ef_linkedin','ef_twitter','ef_facebook','ef_threads'].forEach(id => setVal(id, ''));
    document.getElementById('ef_status').value = 'active';
    document.getElementById('ef_featured').checked = false;
    document.getElementById('ef_profile_image').value = '';
    document.getElementById('efImgPreview').style.display = 'none';
    document.getElementById('efImgPlaceholder').style.display = 'flex';
}

function confirmDeleteUser(id, name) {
    if (confirm('Delete user "' + name + '"?\n\nThis will also delete their author profile if it exists. Cannot be undone.')) {
        window.location.href = '?delete_user=' + id;
    }
}

function confirmDeleteAuthor(id, name) {
    if (confirm('Delete author profile "' + name + '"?\n\nThe user account will remain. Only the author profile is deleted.')) {
        window.location.href = '?delete_author=' + id;
    }
}

// ─── Live search / filter for the Users table — no page reload, no "Filter"
//     button needed. Debounced so it fires ~300ms after typing stops. ────────
let usersSearchTimer = null;
function liveSearchUsers(page) {
    page = page || 1;
    const searchInput = document.getElementById('usersSearchInput');
    const roleSelect = document.getElementById('usersRoleFilter');
    const spinner = document.getElementById('usersSearchSpinner');
    const search = searchInput.value.trim();
    const role = roleSelect.value;

    spinner.style.display = 'inline-block';

    fetch('?ajax=search_users&search=' + encodeURIComponent(search) + '&role=' + encodeURIComponent(role) + '&page=' + page)
        .then(r => r.json())
        .then(data => {
            document.getElementById('usersTableBody').innerHTML = data.rows_html;
            document.getElementById('usersPaginationWrap').innerHTML = data.pagination_html;
            document.getElementById('usersCountText').textContent = data.count_text;
            document.getElementById('usersSearchInfo').innerHTML = data.search
                ? '<div class="search-info">Showing results for <strong>"' + escapeHtmlText(data.search) + '"</strong></div>'
                : '';
            spinner.style.display = 'none';

            // Keep the URL bookmarkable/shareable without a full page reload
            const url = new URL(window.location);
            search ? url.searchParams.set('search', search) : url.searchParams.delete('search');
            (role && role !== 'all') ? url.searchParams.set('role', role) : url.searchParams.delete('role');
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

const usersSearchInputEl = document.getElementById('usersSearchInput');
if (usersSearchInputEl) {
    usersSearchInputEl.addEventListener('input', () => {
        clearTimeout(usersSearchTimer);
        usersSearchTimer = setTimeout(() => liveSearchUsers(1), 300);
    });
}
const usersRoleFilterEl = document.getElementById('usersRoleFilter');
if (usersRoleFilterEl) {
    usersRoleFilterEl.addEventListener('change', () => liveSearchUsers(1));
}
const usersFilterFormEl = document.getElementById('usersFilterForm');
if (usersFilterFormEl) {
    usersFilterFormEl.addEventListener('submit', e => {
        e.preventDefault();
        clearTimeout(usersSearchTimer);
        liveSearchUsers(1);
    });
}
const usersClearBtnEl = document.getElementById('usersClearBtn');
if (usersClearBtnEl) {
    usersClearBtnEl.addEventListener('click', e => {
        e.preventDefault();
        usersSearchInputEl.value = '';
        usersRoleFilterEl.value = 'all';
        liveSearchUsers(1);
    });
}
const usersPaginationWrapEl = document.getElementById('usersPaginationWrap');
if (usersPaginationWrapEl) {
    usersPaginationWrapEl.addEventListener('click', e => {
        const link = e.target.closest('a.page-link[data-page]');
        if (!link) return;
        e.preventDefault();
        liveSearchUsers(parseInt(link.dataset.page, 10));
    });
}

// ─── Image preview (used by both cf_/ef_ profile image inputs) ────────────────
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
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});
let touchStartX = 0;
document.getElementById('sidebar').addEventListener('touchstart', e => touchStartX = e.changedTouches[0].screenX);
document.getElementById('sidebar').addEventListener('touchend', e => { if (touchStartX - e.changedTouches[0].screenX > 100) toggleSidebar(); });

// ─── Auto-open modal if edit action was in URL (PHP prepopulate flow) ─────────
<?php if ($edit_user_data): ?>
openEditUser(<?= (int)$edit_user_data['id'] ?>);
<?php endif; ?>
<?php if ($edit_author_data): ?>
openEditUser(<?= (int)$edit_author_data['user_id'] ?>);
<?php endif; ?>

// ─── Notification auto-dismiss ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(el => {
            el.style.transition = 'opacity .4s ease, transform .4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-5px)';
            setTimeout(() => el.remove(), 400);
        });
    }, 4000);
    const url = new URL(window.location.href);
    ['success','error','img_error'].forEach(p => url.searchParams.delete(p));
    window.history.replaceState({}, document.title, url.pathname + url.search);
});
</script>
</body>
</html>