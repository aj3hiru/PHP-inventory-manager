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
if (!$user || $user['status'] !== 'active' || (empty($permissions['ecommerce']['manage_products']) && empty($permissions['ecommerce']['manage_billing']))) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

// ── Print mode: specific product ids (optionally repeated via comma list) ──
$print_mode = false;
$print_items = [];

if (isset($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
    if (!empty($ids)) {
        $counts = array_count_values($ids); // supports "1,1,2" style repeats for quantity
        $unique_ids = array_keys($counts);
        $in = implode(',', array_fill(0, count($unique_ids), '?'));
        $q = $pdo->prepare("SELECT id, name, barcode, price, sale_price FROM ecom_products WHERE id IN ($in)");
        $q->execute($unique_ids);
        $products_by_id = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) { $products_by_id[$p['id']] = $p; }
        foreach ($unique_ids as $pid) {
            if (!isset($products_by_id[$pid])) continue;
            for ($i = 0; $i < $counts[$pid]; $i++) { $print_items[] = $products_by_id[$pid]; }
        }
        $print_mode = !empty($print_items);
    }
} elseif (isset($_GET['product_id']) && is_array($_GET['product_id'])) {
    foreach ($_GET['product_id'] as $idx => $pid) {
        $pid = (int)$pid;
        $qty = max(1, (int)($_GET['qty'][$idx] ?? 1));
        if ($pid <= 0) continue;
        $q = $pdo->prepare("SELECT id, name, barcode, price, sale_price FROM ecom_products WHERE id = ?");
        $q->execute([$pid]);
        if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
            for ($i = 0; $i < $qty; $i++) { $print_items[] = $p; }
        }
    }
    $print_mode = !empty($print_items);
}

$all_products = $pdo->query("SELECT id, name, sku, barcode, price, sale_price FROM ecom_products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$biz = $pdo->query("SELECT barcode_footer_text FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$barcode_footer = trim($biz['barcode_footer_text'] ?? '');

$page_title = 'Print Barcodes';
$page_subtitle = 'Generate and print barcode labels for your products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php if (!$print_mode) include __DIR__ . '/components/ecom-head.php'; else { ?>
<meta charset="UTF-8">
<title>Barcode Labels</title>
<?php } ?>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<style>
.label-sheet { display: flex; flex-wrap: wrap; gap: 10px; padding: 10px; }
.barcode-label { width: 200px; border: 1px dashed #ccc; padding: 8px; text-align: center; font-family: Arial, sans-serif; }
.barcode-label .p-name { font-size: 12px; font-weight: 700; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.barcode-label .p-price { font-size: 12px; font-weight: 700; margin-top: 2px; }
.barcode-label .p-footer { font-size: 10px; color: #555; margin-top: 2px; }
@media print {
    body { margin: 0; }
    .no-print { display: none !important; }
    .barcode-label { border: none; page-break-inside: avoid; }
}
</style>
</head>
<body<?= $print_mode ? '' : '' ?>>

<?php if ($print_mode): ?>

    <div class="no-print" style="padding:1rem; text-align:center;">
        <input type="text" id="customLabelText" value="<?= htmlspecialchars($barcode_footer) ?>" placeholder="Custom text on labels (e.g. shop name)" style="padding:0.5rem 0.8rem; border:1px solid #ccc; border-radius:6px; width:280px; margin-right:0.5rem;" oninput="document.querySelectorAll('.p-footer').forEach(el=>{el.textContent=this.value; el.style.display = this.value ? 'block' : 'none';})">
        <button onclick="window.print()" style="padding:0.6rem 1.5rem; background:#7c3aed; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer;">
            🖨️ Print <?= count($print_items) ?> Label(s)
        </button>
        <a href="barcode-print.php" style="margin-left:1rem;">← Back to picker</a>
    </div>

    <div class="label-sheet">
        <?php foreach ($print_items as $i => $p): ?>
        <div class="barcode-label">
            <div class="p-name"><?= htmlspecialchars($p['name']) ?></div>
            <svg id="bc-<?= $i ?>"></svg>
            <div class="p-price">₹<?= number_format((!empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price']) ? (float)$p['sale_price'] : (float)$p['price'], 2) ?></div>
            <div class="p-footer" style="<?= $barcode_footer === '' ? 'display:none;' : '' ?>"><?= htmlspecialchars($barcode_footer) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    <?php foreach ($print_items as $i => $p): ?>
    JsBarcode("#bc-<?= $i ?>", "<?= htmlspecialchars($p['barcode'] ?: $p['id']) ?>", { format: "CODE128", width: 1.6, height: 40, fontSize: 12, margin: 4 });
    <?php endforeach; ?>
    </script>

<?php else: ?>

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
            <div class="gd-card">
                <div class="gd-card-body">
                    <div class="gd-heading-row">
                        <h3><b>Select Products to Print</b></h3>
                    </div>
                </div>
            </div>

            <form method="GET" target="_blank" id="barcodeForm">
                <div class="gd-card">
                    <div class="gd-card-body table-card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="admin-table" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="checkAll"></th>
                                        <th>Name</th>
                                        <th>SKU</th>
                                        <th>Barcode</th>
                                        <th>Price</th>
                                        <th width="120">Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($all_products as $i => $p): ?>
                                <tr>
                                    <td><input type="checkbox" class="row-check" name="product_id[]" value="<?= $p['id'] ?>"></td>
                                    <td><?= htmlspecialchars($p['name']) ?></td>
                                    <td><?= htmlspecialchars($p['sku'] ?: '—') ?></td>
                                    <td><code><?= htmlspecialchars($p['barcode'] ?: '—') ?></code></td>
                                    <td>₹<?= number_format((!empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < (float)$p['price']) ? (float)$p['sale_price'] : (float)$p['price'], 2) ?></td>
                                    <td><input type="number" name="qty[]" class="form-control form-control-sm" value="1" min="1"></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-barcode"></i> Print Selected Labels</button>
            </form>
        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
<script>
$(document).ready(function () {
    $('#admin-table').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [0, 5] }] });
});
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>

<?php endif; ?>

</body>
</html>
