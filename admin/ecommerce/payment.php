<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { exit('Access Denied'); }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_payment'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

define('PAY_UPLOAD_URL', 'uploads/ecommerce/payment/');
define('PAY_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/payment/');

$methods = [
    'cod'           => ['label' => 'Cash On Delivery', 'icon' => 'fa-money-bill-wave'],
    'paytm'         => ['label' => 'Paytm',            'icon' => 'fa-wallet'],
    'phonepe'       => ['label' => 'PhonePe',           'icon' => 'fa-mobile-alt'],
    'razorpay'      => ['label' => 'Razorpay',          'icon' => 'fa-credit-card'],
    'bank_transfer' => ['label' => 'Bank Transfer',     'icon' => 'fa-university'],
];

$notifications = [];

// ── Handle POST (update one method's settings) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method_key = $_POST['method_key'] ?? '';

    if (!array_key_exists($method_key, $methods)) {
        $notifications[] = ['type' => 'error', 'message' => 'Invalid payment method.'];
    } else {
        $name        = trim($_POST['name'] ?? $methods[$method_key]['label']);
        $text        = trim($_POST['text'] ?? '');
        $is_enabled  = isset($_POST['status']) ? 1 : 0;

        // Gather any pkey[...] fields into a config JSON blob
        $config = [];
        if (isset($_POST['pkey']) && is_array($_POST['pkey'])) {
            foreach ($_POST['pkey'] as $k => $v) {
                $config[$k] = trim($v);
            }
        }
        $config_json = json_encode($config);

        // Image upload (optional — keep old image if none uploaded)
        $image_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            if (!file_exists(PAY_UPLOAD_ABS)) mkdir(PAY_UPLOAD_ABS, 0777, true);
            $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $filename = $method_key . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], PAY_UPLOAD_ABS . $filename)) {
                $image_path = PAY_UPLOAD_URL . $filename;
            }
        }

        try {
            if ($image_path) {
                $old = $pdo->prepare("SELECT image FROM ecom_payment_settings WHERE method_key=?");
                $old->execute([$method_key]);
                if ($old_row = $old->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($old_row['image']) && file_exists(DROOT_PATH . '/' . $old_row['image'])) {
                        @unlink(DROOT_PATH . '/' . $old_row['image']);
                    }
                }
                $pdo->prepare("
                    INSERT INTO ecom_payment_settings (method_key, name, image, text, config, is_enabled)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE name=VALUES(name), image=VALUES(image), text=VALUES(text), config=VALUES(config), is_enabled=VALUES(is_enabled)
                ")->execute([$method_key, $name, $image_path, $text, $config_json, $is_enabled]);
            } else {
                $pdo->prepare("
                    INSERT INTO ecom_payment_settings (method_key, name, text, config, is_enabled)
                    VALUES (?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE name=VALUES(name), text=VALUES(text), config=VALUES(config), is_enabled=VALUES(is_enabled)
                ")->execute([$method_key, $name, $text, $config_json, $is_enabled]);
            }

            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_payment_update', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Updated payment settings: " . $methods[$method_key]['label'], $log_ip, $log_ua]);

            header("Location: /admin/ecommerce/payment.php?method=" . urlencode($method_key) . "&success=1");
            exit;
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
    }
}

if (isset($_GET['success'])) {
    $notifications[] = ['type' => 'success', 'message' => 'Payment settings updated successfully!'];
}

// ── Load all settings, keyed by method ──────────────────────────────────────
$rows = $pdo->query("SELECT * FROM ecom_payment_settings")->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($rows as $r) {
    $r['config'] = json_decode($r['config'] ?? '{}', true) ?: [];
    $settings[$r['method_key']] = $r;
}
// Fill in any missing methods with sane defaults (in case the seed rows weren't inserted)
foreach ($methods as $key => $m) {
    if (!isset($settings[$key])) {
        $settings[$key] = ['method_key' => $key, 'name' => $m['label'], 'image' => null, 'text' => '', 'config' => [], 'is_enabled' => 0];
    }
}

$active_method = $_GET['method'] ?? 'cod';
if (!array_key_exists($active_method, $methods)) $active_method = 'cod';

$page_title = 'Payment';
$page_subtitle = 'Configure the payment methods available at checkout';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
.pay-method-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--gray-100); border-radius: 0.35rem; overflow: hidden; background: #fff; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,.1); }
.pay-method-list li { border-bottom: 1px solid var(--gray-100); background: #fff; }
.pay-method-list li:last-child { border-bottom: none; }
.pay-method-list a { display: flex; align-items: center; gap: 0.6rem; padding: 0.9rem 1.1rem; color: var(--gray-700); text-decoration: none; font-weight: 500; transition: background 0.15s; background: #fff; }
.pay-method-list a:hover { background: var(--gray-50); }
.pay-method-list a.active, .pay-method-list a.active:hover { background: #4361ee; color: #fff; }
.pay-method-list a i { width: 18px; text-align: center; }

.switch-row-lg { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.5rem; }
.switch-lg { position: relative; display: inline-block; width: 48px; height: 26px; flex-shrink: 0; }
.switch-lg input { opacity: 0; width: 0; height: 0; }
.switch-lg .slider { position: absolute; cursor: pointer; inset: 0; background: var(--gray-300); transition: 0.2s; border-radius: 999px; }
.switch-lg .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background: white; transition: 0.2s; border-radius: 50%; }
.switch-lg input:checked + .slider { background: var(--primary); }
.switch-lg input:checked + .slider:before { transform: translateX(22px); }

.pay-img-preview { width: 260px; max-width: 100%; height: 150px; border: 1px solid var(--gray-200); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; overflow: hidden; background: #fff; margin-bottom: 0.75rem; }
.pay-img-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }

.gd-card-body form .form-label { font-size: 1rem; font-weight: 700; color: var(--gray-800); margin-bottom: 0.6rem; }
.gd-card-body form .form-control,
.gd-card-body form .form-select { font-size: 0.95rem; padding: 0.65rem 0.9rem; }
.gd-card-body form .form-text { font-size: 0.85rem; }

.file-input-row { display: flex; align-items: stretch; border: 1px solid var(--gray-200); border-radius: var(--radius); overflow: hidden; max-width: 420px; }
.file-input-row .file-display { border: none; border-radius: 0; box-shadow: none !important; flex: 1; background: #fff; cursor: pointer; color: var(--gray-500); }
.file-input-row .file-display:focus { outline: none; }
.file-input-row .browse-btn { border: none; background: var(--gray-200); color: var(--gray-800); font-weight: 600; padding: 0 1.25rem; cursor: pointer; white-space: nowrap; transition: background 0.15s; }
.file-input-row .browse-btn:hover { background: var(--gray-300); }
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
            <div class="alert alert-<?= $n['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Payment</b></h3>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 mb-3">
                    <ul class="pay-method-list">
                        <?php foreach ($methods as $key => $m): ?>
                        <li>
                            <a href="?method=<?= $key ?>" class="<?= $active_method === $key ? 'active' : '' ?>">
                                <i class="fas <?= $m['icon'] ?>"></i> <?= htmlspecialchars($m['label']) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="col-md-9">
                    <div class="gd-card">
                        <div class="gd-card-body">
                            <?php $s = $settings[$active_method]; $cfg = $s['config']; ?>

                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="method_key" value="<?= $active_method ?>">

                                <div class="switch-row-lg">
                                    <label class="switch-lg">
                                        <input type="checkbox" name="status" <?= $s['is_enabled'] ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                    <span>Display <?= htmlspecialchars($methods[$active_method]['label']) ?></span>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Enter Name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($s['name']) ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label d-block">Current Image</label>
                                    <div class="pay-img-preview" id="payImgPreview">
                                        <?php if (!empty($s['image'])): ?>
                                            <img src="/<?= htmlspecialchars($s['image']) ?>" id="payImgTag">
                                        <?php else: ?>
                                            <i class="fas fa-image text-muted fa-2x" id="payImgIcon"></i>
                                            <img src="" id="payImgTag" style="display:none;">
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-text mb-2">Image Size Should Be 52 x 35.</div>
                                    <div class="file-input-row">
                                        <input type="text" class="form-control file-display" id="photoDisplay" placeholder="Upload Image..." readonly onclick="document.getElementById('photoInput').click();">
                                        <button type="button" class="browse-btn" onclick="document.getElementById('photoInput').click();">Browse</button>
                                    </div>
                                    <input type="file" name="photo" id="photoInput" accept="image/*" style="display:none;">
                                </div>

                                <?php if ($active_method === 'paytm'): ?>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Paytm Merchant ID</label>
                                            <input type="text" name="pkey[merchant_id]" class="form-control" placeholder="Paytm Merchant ID" value="<?= htmlspecialchars($cfg['merchant_id'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Paytm Merchant Key</label>
                                            <input type="text" name="pkey[merchant_key]" class="form-control" placeholder="Paytm Merchant Key" value="<?= htmlspecialchars($cfg['merchant_key'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Paytm Website</label>
                                            <input type="text" name="pkey[website]" class="form-control" placeholder="e.g. WEBSTAGING or DEFAULT" value="<?= htmlspecialchars($cfg['website'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Paytm Industry Type</label>
                                            <input type="text" name="pkey[industry_type]" class="form-control" placeholder="e.g. Retail" value="<?= htmlspecialchars($cfg['industry_type'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mode</label>
                                            <select name="pkey[mode]" class="form-select">
                                                <option value="test" <?= (($cfg['mode'] ?? '') === 'test') ? 'selected' : '' ?>>Test</option>
                                                <option value="live" <?= (($cfg['mode'] ?? '') === 'live') ? 'selected' : '' ?>>Live</option>
                                            </select>
                                        </div>
                                    </div>
                                <?php elseif ($active_method === 'phonepe'): ?>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">PhonePe Merchant ID</label>
                                            <input type="text" name="pkey[merchant_id]" class="form-control" placeholder="PhonePe Merchant ID" value="<?= htmlspecialchars($cfg['merchant_id'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">PhonePe Salt Key</label>
                                            <input type="text" name="pkey[salt_key]" class="form-control" placeholder="Salt Key" value="<?= htmlspecialchars($cfg['salt_key'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Salt Index</label>
                                            <input type="text" name="pkey[salt_index]" class="form-control" placeholder="e.g. 1" value="<?= htmlspecialchars($cfg['salt_index'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mode</label>
                                            <select name="pkey[mode]" class="form-select">
                                                <option value="test" <?= (($cfg['mode'] ?? '') === 'test') ? 'selected' : '' ?>>Test</option>
                                                <option value="live" <?= (($cfg['mode'] ?? '') === 'live') ? 'selected' : '' ?>>Live</option>
                                            </select>
                                        </div>
                                    </div>
                                <?php elseif ($active_method === 'razorpay'): ?>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Razorpay Key</label>
                                            <input type="text" name="pkey[key]" class="form-control" placeholder="Razorpay Key" value="<?= htmlspecialchars($cfg['key'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Razorpay Secret</label>
                                            <input type="text" name="pkey[secret]" class="form-control" placeholder="Razorpay Secret" value="<?= htmlspecialchars($cfg['secret'] ?? '') ?>">
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label class="form-label">Enter Text <span class="text-danger">*</span></label>
                                    <textarea name="text" class="form-control" rows="5" placeholder="Enter Text" required><?= htmlspecialchars($s['text']) ?></textarea>
                                    <?php if ($active_method === 'bank_transfer'): ?>
                                        <div class="form-text">Shown to customers at checkout — put your account number, name, bank, and IFSC code here.</div>
                                    <?php endif; ?>
                                </div>

                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
document.getElementById('photoInput').addEventListener('change', function (e) {
    const file = e.target.files[0];
    if (!file) return;
    document.getElementById('photoDisplay').value = file.name;

    const reader = new FileReader();
    reader.onload = function (ev) {
        const img = document.getElementById('payImgTag');
        const icon = document.getElementById('payImgIcon');
        img.src = ev.target.result;
        img.style.display = 'block';
        if (icon) icon.style.display = 'none';
    };
    reader.readAsDataURL(file);
});
</script>
</body>
</html>