<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';
require_once DROOT_PATH . '/includes/permissions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /admin/login.php'); exit; }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['users']['create'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];
$me = (int)$_SESSION['user_id'];
$can_edit   = !empty($permissions['users']['edit']);
$can_delete = !empty($permissions['users']['delete']);

$notifications = [];
$log = function (string $type, string $text) use ($pdo, $me, $log_ip, $log_ua) {
    $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)")
        ->execute([$me, $type, $text, $log_ip, $log_ua]);
};
$active_owners = function (int $except = 0) use ($pdo): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?");
    $s->execute([$except]);
    return (int)$s->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $notifications[] = ['type' => 'danger', 'message' => 'Invalid request. Please refresh and try again.'];
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);

        if ($action === 'save') {
            $uname  = trim($_POST['username'] ?? '');
            $email  = trim($_POST['email'] ?? '');
            $pass   = $_POST['password'] ?? '';
            $role   = array_key_exists($_POST['role'] ?? '', STORE_ROLES) ? $_POST['role'] : 'author';
            $status = in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? $_POST['status'] : 'active';
            $perms  = ($_POST['perm_mode'] ?? 'preset') === 'custom'
                ? store_permissions_from_post($_POST['permissions'] ?? [])
                : store_role_preset($role);
            if ($role === 'admin') $perms = store_role_preset('admin');   // an owner can always do everything
            $perms['dashboard_access'] = $perms['dashboard_access'] || $status === 'active';

            if ($uname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $notifications[] = ['type' => 'danger', 'message' => 'Enter a username and a valid email.'];
            } elseif (!$id && strlen($pass) < 8) {
                $notifications[] = ['type' => 'danger', 'message' => 'Password must be at least 8 characters.'];
            } elseif ($id && $pass !== '' && strlen($pass) < 8) {
                $notifications[] = ['type' => 'danger', 'message' => 'New password must be at least 8 characters.'];
            } elseif ($id && !$can_edit) {
                $notifications[] = ['type' => 'danger', 'message' => 'You are not allowed to edit staff.'];
            } elseif ($id === $me && ($status !== 'active' || $role !== 'admin') && $active_owners($me) === 0) {
                $notifications[] = ['type' => 'danger', 'message' => 'You are the only active owner, so your own role and status cannot be changed.'];
            } else {
                try {
                    if ($id) {
                        $old = $pdo->prepare("SELECT role, status FROM users WHERE id = ?");
                        $old->execute([$id]);
                        $old = $old->fetch(PDO::FETCH_ASSOC);
                        if ($old && $old['role'] === 'admin' && $old['status'] === 'active' && ($role !== 'admin' || $status !== 'active') && $active_owners($id) === 0) {
                            throw new RuntimeException('At least one active owner is needed.');
                        }
                        $sql = "UPDATE users SET username = ?, email = ?, role = ?, status = ?, permissions = ?" . ($pass !== '' ? ", password_hash = ?" : "") . " WHERE id = ?";
                        $args = [$uname, $email, $role, $status, json_encode($perms)];
                        if ($pass !== '') $args[] = password_hash($pass, PASSWORD_DEFAULT);
                        $args[] = $id;
                        $pdo->prepare($sql)->execute($args);
                        $log('staff_update', "Updated staff {$uname} (" . STORE_ROLES[$role] . ", {$status})");
                        if ($id === $me) $_SESSION['username'] = $username = $uname;
                        header('Location: /admin/staff.php?saved=1'); exit;
                    }
                    $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, permissions) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$uname, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $status, json_encode($perms)]);
                    $log('staff_create', "Added staff {$uname} (" . STORE_ROLES[$role] . ")");
                    header('Location: /admin/staff.php?added=1'); exit;
                } catch (RuntimeException $e) {
                    $notifications[] = ['type' => 'danger', 'message' => $e->getMessage()];
                } catch (PDOException $e) {
                    $notifications[] = ['type' => 'danger', 'message' => 'That username or email is already used by another account.'];
                }
            }
        } elseif ($action === 'delete') {
            if (!$can_delete) {
                $notifications[] = ['type' => 'danger', 'message' => 'You are not allowed to remove staff.'];
            } elseif ($id === $me) {
                $notifications[] = ['type' => 'danger', 'message' => 'You cannot remove your own account.'];
            } else {
                $t = $pdo->prepare("SELECT username, role, status FROM users WHERE id = ?");
                $t->execute([$id]);
                $t = $t->fetch(PDO::FETCH_ASSOC);
                if ($t && $t['role'] === 'admin' && $t['status'] === 'active' && $active_owners($id) === 0) {
                    $notifications[] = ['type' => 'danger', 'message' => 'At least one active owner is needed.'];
                } elseif ($t) {
                    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
                    $log('staff_delete', "Removed staff {$t['username']}");
                    header('Location: /admin/staff.php?removed=1'); exit;
                }
            }
        }
    }
}
if (isset($_GET['added']))   $notifications[] = ['type' => 'success', 'message' => 'Staff member added.'];
if (isset($_GET['saved']))   $notifications[] = ['type' => 'success', 'message' => 'Changes saved.'];
if (isset($_GET['removed'])) $notifications[] = ['type' => 'success', 'message' => 'Staff member removed.'];

$staff = $pdo->query("SELECT id, username, email, role, status, permissions, created_at FROM users ORDER BY FIELD(role, 'admin', 'editor', 'author'), username")->fetchAll(PDO::FETCH_ASSOC);
$labels = store_permission_labels();
$presets = ['admin' => store_role_preset('admin'), 'editor' => store_role_preset('editor'), 'author' => store_role_preset('author')];

$page_title = 'Staff';
$page_subtitle = 'Who can log in, and what each person can do';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/ecommerce/components/ecom-head.php'; ?>
<style>
.perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: .35rem 1rem; }
.perm-grid label { display: flex; gap: .5rem; align-items: flex-start; font-size: .9rem; }
.perm-group { font-weight: 700; font-size: .8rem; text-transform: uppercase; color: var(--gray-500, #6b7280); margin: .75rem 0 .35rem; }
.role-badge { font-weight: 600; }
.perm-summary { font-size: .8rem; color: var(--gray-500, #6b7280); }
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
                <div class="page-heading-mini">
                    <h1><?= htmlspecialchars($page_title) ?></h1>
                    <p><?= htmlspecialchars($page_subtitle) ?></p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">
            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h3 class="mb-0"><b>Staff</b> <span class="badge bg-primary"><?= count($staff) ?></span></h3>
                        <button class="btn btn-primary btn-sm" onclick="openStaff(null)"><i class="fas fa-user-plus"></i> Add staff</button>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Owner: everything. Manager: billing, products, customers, udhaar, coupons, bill details. Billing staff: billing, customers and udhaar only. You can also tick switches one by one.</p>
                </div>
            </div>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle" width="100%">
                            <thead>
                                <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Added</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staff as $s): $sp = json_decode($s['permissions'] ?? '{}', true) ?: []; ?>
                            <tr>
                                <td><b><?= htmlspecialchars($s['username']) ?></b><?= (int)$s['id'] === $me ? ' <span class="badge bg-light text-dark">you</span>' : '' ?></td>
                                <td><?= htmlspecialchars($s['email'] ?? '') ?></td>
                                <td><span class="role-badge"><?= htmlspecialchars(STORE_ROLES[$s['role']] ?? $s['role']) ?></span></td>
                                <td><span class="badge <?= $s['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= ucfirst(htmlspecialchars($s['status'])) ?></span></td>
                                <td><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                                <td>
                                    <div class="action-list">
                                        <?php if ($can_edit): ?>
                                        <button class="btn btn-info btn-sm" title="Edit" onclick='openStaff(<?= json_encode(["id" => (int)$s["id"], "username" => $s["username"], "email" => $s["email"], "role" => $s["role"], "status" => $s["status"], "permissions" => $sp], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-pen"></i></button>
                                        <?php endif; ?>
                                        <?php if ($can_delete && (int)$s['id'] !== $me): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($s['username'])) ?>? They will not be able to log in.');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-danger btn-sm" title="Remove"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="POST" class="modal-content" id="staffForm" autocomplete="off">
            <div class="modal-header">
                <h5 class="modal-title" id="staffTitle">Add staff</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="sf_id" value="">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" name="username" id="sf_username" required autocomplete="off"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="sf_email" required autocomplete="off"></div>
                    <div class="col-md-6"><label class="form-label" id="sf_pass_label">Password</label><input type="password" class="form-control" name="password" id="sf_password" minlength="8" autocomplete="new-password"></div>
                    <div class="col-md-3"><label class="form-label">Role</label>
                        <select class="form-select" name="role" id="sf_role">
                            <?php foreach (STORE_ROLES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Status</label>
                        <select class="form-select" name="status" id="sf_status"><option value="active">Active</option><option value="suspended">Suspended</option></select>
                    </div>
                </div>

                <div class="mt-3">
                    <label class="d-flex gap-2 align-items-center"><input type="checkbox" id="sf_custom"> Choose switches one by one</label>
                    <input type="hidden" name="perm_mode" id="sf_perm_mode" value="preset">
                </div>
                <div id="sf_perms" class="mt-1">
                    <?php foreach ($labels as $group => $items): if (!is_array($items)) continue; ?>
                    <div class="perm-group"><?= ['ecommerce' => 'Store', 'users' => 'Staff', 'security' => 'Security'][$group] ?? $group ?></div>
                    <div class="perm-grid">
                        <?php foreach ($items as $key => $label): ?>
                        <label><input type="checkbox" name="permissions[<?= $group ?>][<?= $key ?>]" value="1" data-perm="<?= $group . '.' . $key ?>"> <?= htmlspecialchars($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="permissions[dashboard_access]" value="1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/ecommerce/components/ecom-scripts.php'; ?>
<script>
const PRESETS = <?= json_encode($presets) ?>;
const boxes = () => document.querySelectorAll('#sf_perms input[data-perm]');
function showPerms(perms) {
    boxes().forEach(b => { const [g, k] = b.dataset.perm.split('.'); b.checked = !!(perms[g] && perms[g][k]); });
}
function syncMode() {
    const custom = document.getElementById('sf_custom').checked;
    const owner = document.getElementById('sf_role').value === 'admin';
    document.getElementById('sf_perm_mode').value = custom && !owner ? 'custom' : 'preset';
    boxes().forEach(b => b.disabled = !custom || owner);
    if (!custom || owner) showPerms(PRESETS[document.getElementById('sf_role').value]);
}
function openStaff(s) {
    document.getElementById('staffTitle').textContent = s ? 'Edit ' + s.username : 'Add staff';
    document.getElementById('sf_id').value = s ? s.id : '';
    document.getElementById('sf_username').value = s ? s.username : '';
    document.getElementById('sf_email').value = s ? s.email : '';
    document.getElementById('sf_password').value = '';
    document.getElementById('sf_password').required = !s;
    document.getElementById('sf_pass_label').textContent = s ? 'New password (leave empty to keep)' : 'Password';
    document.getElementById('sf_role').value = s ? s.role : 'author';
    document.getElementById('sf_status').value = s ? s.status : 'active';
    const preset = PRESETS[document.getElementById('sf_role').value];
    const custom = !!s && JSON.stringify(s.permissions) !== JSON.stringify(preset);
    document.getElementById('sf_custom').checked = custom;
    syncMode();
    if (custom) showPerms(s.permissions);
    new bootstrap.Modal(document.getElementById('staffModal')).show();
}
document.getElementById('sf_role').addEventListener('change', syncMode);
document.getElementById('sf_custom').addEventListener('change', syncMode);
</script>
</body>
</html>
