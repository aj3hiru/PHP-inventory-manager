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
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_products'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

$notifications = [];

function uniqueCsvSlug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = $base; $i = 1;
    while (true) {
        $q = $pdo->prepare("SELECT id FROM ecom_products WHERE slug = ? AND id != ?");
        $q->execute([$slug, $excludeId]);
        if (!$q->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

// ── Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query("SELECT id, name, sku, product_type, price, sale_price, stock_qty, status, badge_tag, item_type FROM ecom_products ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=products-export-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'sku', 'product_type', 'price', 'sale_price', 'stock_qty', 'status', 'badge_tag', 'item_type']);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

// ── Import ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $item_type_default = $_POST['item_type'] ?? 'physical';

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $notifications[] = ['type' => 'error', 'message' => 'Please choose a valid CSV file.'];
    } else {
        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$handle) {
            $notifications[] = ['type' => 'error', 'message' => 'Could not read the uploaded file.'];
        } else {
            $header = fgetcsv($handle);
            $header = array_map(fn($h) => strtolower(trim($h)), $header);
            $required = ['name', 'price'];
            $missing = array_diff($required, $header);

            if (!empty($missing)) {
                $notifications[] = ['type' => 'error', 'message' => 'CSV is missing required column(s): ' . implode(', ', $missing) . '. Required columns: name, price (optional: sku, stock_qty, status).'];
            } else {
                $imported = 0;
                $failed = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    $data = array_combine($header, $row);
                    $name = trim($data['name'] ?? '');
                    $price = (float)($data['price'] ?? 0);
                    if ($name === '') { $failed++; continue; }

                    $slug = uniqueCsvSlug($pdo, generateSlug($name));
                    $sku = trim($data['sku'] ?? '');
                    $stock_qty = isset($data['stock_qty']) && $data['stock_qty'] !== '' ? (int)$data['stock_qty'] : 0;
                    $status = (isset($data['status']) && strtolower(trim($data['status'])) === 'inactive') ? 'inactive' : 'active';

                    try {
                        $pdo->prepare("INSERT INTO ecom_products (name, slug, sku, product_type, price, stock_qty, status) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$name, $slug, $sku ?: null, $item_type_default, $price, $stock_qty, $status]);
                        $imported++;
                    } catch (Exception $e) {
                        $failed++;
                    }
                }
                fclose($handle);

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_product_csv_import', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Imported $imported products via CSV ($failed failed)", $log_ip, $log_ua]);

                $notifications[] = ['type' => 'success', 'message' => "Import complete: $imported product(s) added" . ($failed ? ", $failed row(s) skipped." : '.')];
            }
        }
    }
}

$total_products = (int) $pdo->query("SELECT COUNT(*) FROM ecom_products")->fetchColumn();

$page_title = 'Product CSV Import & Export';
$page_subtitle = 'Bulk manage your product catalog';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
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

            <div class="row">
                <div class="col-md-6">
                    <div class="gd-card h-100">
                        <div class="gd-card-body">
                            <h5 class="mb-3"><i class="fas fa-file-import text-primary"></i> Import Products</h5>
                            <p class="text-muted small">CSV must have a header row with at least <code>name</code> and <code>price</code> columns. Optional: <code>sku</code>, <code>stock_qty</code>, <code>status</code> (active/inactive).</p>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="import">
                                <div class="mb-3">
                                    <label class="form-label">CSV File <span class="text-danger">*</span></label>
                                    <input type="file" name="csv" class="form-control" accept=".csv" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Import as Product Type</label>
                                    <select name="item_type" class="form-select">
                                        <option value="physical">Physical</option>
                                        <option value="digital">Digital</option>
                                        <option value="license">License</option>
                                        <option value="affiliate">Affiliate</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Import CSV</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="gd-card h-100">
                        <div class="gd-card-body">
                            <h5 class="mb-3"><i class="fas fa-file-export text-primary"></i> Export Products</h5>
                            <p class="text-muted small">Download a CSV of all <?= number_format($total_products) ?> product(s) currently in your catalog.</p>
                            <a href="?export=csv" class="btn btn-success"><i class="fas fa-download"></i> Export CSV</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
</body>
</html>
