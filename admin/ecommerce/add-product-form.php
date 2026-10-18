<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: /admin/login.php'); exit; }
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['ecommerce']['manage_products'])) {
    exit('Access Denied');
}
$username = $_SESSION['username'];

define('PRODUCT_UPLOAD_URL', 'uploads/ecommerce/products/');
define('PRODUCT_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/products/');

function saveGalleryImages(PDO $pdo, int $product_id): void {
    // Remove any images the user clicked "x" on
    if (!empty($_POST['removed_gallery_ids']) && is_array($_POST['removed_gallery_ids'])) {
        foreach ($_POST['removed_gallery_ids'] as $rid) {
            $rid = (int)$rid;
            $row = $pdo->prepare("SELECT image FROM ecom_product_images WHERE id = ? AND product_id = ?");
            $row->execute([$rid, $product_id]);
            if ($img = $row->fetchColumn()) {
                if (file_exists(DROOT_PATH . '/' . $img)) @unlink(DROOT_PATH . '/' . $img);
                $pdo->prepare("DELETE FROM ecom_product_images WHERE id = ?")->execute([$rid]);
            }
        }
    }

    // Add any newly uploaded gallery photos
    if (!empty($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        if (!file_exists(PRODUCT_UPLOAD_ABS)) mkdir(PRODUCT_UPLOAD_ABS, 0777, true);

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM ecom_product_images WHERE product_id = ?");
        $stmt->execute([$product_id]);
        $next_order = (int)$stmt->fetchColumn();

        $count = count($_FILES['gallery_images']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['gallery_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['gallery_images']['name'][$i], PATHINFO_EXTENSION));
            $filename = 'gallery-' . uniqid() . '-' . $i . '.' . $ext;
            if (move_uploaded_file($_FILES['gallery_images']['tmp_name'][$i], PRODUCT_UPLOAD_ABS . $filename)) {
                $pdo->prepare("INSERT INTO ecom_product_images (product_id, image, sort_order) VALUES (?, ?, ?)")
                    ->execute([$product_id, PRODUCT_UPLOAD_URL . $filename, $next_order + $i]);
            }
        }
    }
}

$valid_types = ['physical', 'digital', 'license', 'affiliate'];

function syncProductVariants(PDO $pdo, int $product_id): void {
    // "Replace all" sync: simplest, safest way to keep the admin's repeater
    // rows and the database in lockstep — delete what's there, re-insert
    // whatever the form submitted, in the order shown.
    $labels = $_POST['variant_label'] ?? [];
    if (!is_array($labels) || empty($labels)) {
        $pdo->prepare("DELETE FROM ecom_product_variants WHERE product_id = ?")->execute([$product_id]);
        return;
    }
    $prices      = $_POST['variant_price'] ?? [];
    $sale_prices = $_POST['variant_sale_price'] ?? [];
    $stocks      = $_POST['variant_stock'] ?? [];
    $default_idx = (string)($_POST['variant_default'] ?? '0');

    $pdo->prepare("DELETE FROM ecom_product_variants WHERE product_id = ?")->execute([$product_id]);

    $insert = $pdo->prepare("INSERT INTO ecom_product_variants (product_id, label, price, sale_price, stock_qty, is_default, sort_order) VALUES (?,?,?,?,?,?,?)");
    $order = 0;
    foreach ($labels as $i => $label) {
        $label = trim((string)$label);
        if ($label === '') continue;
        $price = (float)($prices[$i] ?? 0);
        if ($price <= 0) continue;
        $sale = ($sale_prices[$i] ?? '') !== '' ? (float)$sale_prices[$i] : null;
        $stock = ($stocks[$i] ?? '') !== '' ? (int)$stocks[$i] : null;
        $is_default = ((string)$i === $default_idx) ? 1 : 0;
        $insert->execute([$product_id, $label, $price, $sale, $stock, $is_default, $order]);
        $order++;
    }
    // Guarantee exactly one default (the first row) if none was marked.
    $pdo->prepare("
        UPDATE ecom_product_variants SET is_default = 1
        WHERE product_id = ? AND id = (SELECT id FROM (SELECT id FROM ecom_product_variants WHERE product_id = ? ORDER BY sort_order ASC LIMIT 1) t)
        AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM ecom_product_variants WHERE product_id = ? AND is_default = 1) t2)
    ")->execute([$product_id, $product_id, $product_id]);
}

function syncProductSpecs(PDO $pdo, int $product_id): void {
    // Same "replace all" pattern as syncProductVariants() above — delete
    // what's there, re-insert whatever the form submitted (only rows where
    // BOTH the label and value were actually filled in), in submitted order.
    $keys = $_POST['spec_key'] ?? [];
    if (!is_array($keys) || empty($keys)) {
        $pdo->prepare("DELETE FROM ecom_product_specs WHERE product_id = ?")->execute([$product_id]);
        return;
    }
    $values = $_POST['spec_value'] ?? [];

    $pdo->prepare("DELETE FROM ecom_product_specs WHERE product_id = ?")->execute([$product_id]);

    $insert = $pdo->prepare("INSERT INTO ecom_product_specs (product_id, spec_key, spec_value, sort_order) VALUES (?,?,?,?)");
    $order = 0;
    foreach ($keys as $i => $key) {
        $key = trim((string)$key);
        $value = trim((string)($values[$i] ?? ''));
        if ($key === '' || $value === '') continue; // only fully-filled rows are saved
        $insert->execute([$product_id, $key, $value, $order]);
        $order++;
    }
}

function uniqueProductSlug(PDO $pdo, string $base, int $excludeId = 0): string {
    $slug = $base;
    $i = 1;
    while (true) {
        $q = $pdo->prepare("SELECT id FROM ecom_products WHERE slug = ? AND id != ?");
        $q->execute([$slug, $excludeId]);
        if (!$q->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

$notifications = [];
$editing = null;

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM ecom_products WHERE id=?");
    $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch(PDO::FETCH_ASSOC);
}

$gallery_images = [];
if ($editing) {
    $g = $pdo->prepare("SELECT id, image FROM ecom_product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $g->execute([$editing['id']]);
    $gallery_images = $g->fetchAll(PDO::FETCH_ASSOC);
}

$variant_rows = [];
if ($editing) {
    $v = $pdo->prepare("SELECT * FROM ecom_product_variants WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $v->execute([$editing['id']]);
    $variant_rows = $v->fetchAll(PDO::FETCH_ASSOC);
}

$spec_rows = [];
if ($editing) {
    $sp = $pdo->prepare("SELECT * FROM ecom_product_specs WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $sp->execute([$editing['id']]);
    $spec_rows = $sp->fetchAll(PDO::FETCH_ASSOC);
}

$product_type = $editing['product_type'] ?? ($_GET['type'] ?? 'physical');
if (!in_array($product_type, $valid_types, true)) $product_type = 'physical';

// ── Handle POST (create / update) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action           = $_POST['action'] ?? '';
    $edit_id          = (int)($_POST['edit_id'] ?? 0);
    $product_type     = in_array($_POST['product_type'] ?? '', $valid_types, true) ? $_POST['product_type'] : 'physical';
    $name             = trim($_POST['name'] ?? '');
    $slug_raw         = trim($_POST['slug'] ?? '');
    $category_id      = (int)($_POST['category_id'] ?? 0) ?: null;
    $subcategory_id   = (int)($_POST['subcategory_id'] ?? 0) ?: null;
    $brand_id         = (int)($_POST['brand_id'] ?? 0) ?: null;
    $sku              = trim($_POST['sku'] ?? '');
    $hsn_code         = trim($_POST['hsn_code'] ?? '');
    $barcode_input    = trim($_POST['barcode'] ?? '');
    $price            = (float)($_POST['price'] ?? 0);
    $sale_price       = ($_POST['sale_price'] ?? '') !== '' ? (float)$_POST['sale_price'] : null;
    $gst_rate         = (float)($_POST['gst_rate'] ?? 0);
    $stock_qty        = ($product_type === 'physical') ? (int)($_POST['stock_qty'] ?? 0) : null;
    $description      = trim($_POST['description'] ?? '');
    $badge_tag        = trim($_POST['badge_tag'] ?? '') ?: 'none';
    $item_type        = trim($_POST['item_type'] ?? '') ?: 'normal';
    $status           = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $download_link    = $product_type === 'digital'  ? trim($_POST['download_link'] ?? '') : null;
    $license_key      = $product_type === 'license'  ? trim($_POST['license_key'] ?? '')   : null;
    $affiliate_url    = $product_type === 'affiliate' ? trim($_POST['affiliate_url'] ?? '') : null;

    if (empty($name)) {
        $notifications[] = ['type' => 'error', 'message' => 'Product name is required.'];
    } else {
        $base_slug = $slug_raw !== '' ? generateSlug($slug_raw) : generateSlug($name);
        $slug      = uniqueProductSlug($pdo, $base_slug, $edit_id);

        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            if (!file_exists(PRODUCT_UPLOAD_ABS)) mkdir(PRODUCT_UPLOAD_ABS, 0777, true);
            $ext      = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $filename = $slug . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], PRODUCT_UPLOAD_ABS . $filename)) {
                $image_path = PRODUCT_UPLOAD_URL . $filename;
            }
        }

        // ── Prevent the duplicate-barcode bug: check BEFORE touching the
        // database at all, so a scanned/typed duplicate can never create an
        // orphaned product or a half-saved row. ──────────────────────────────
        if ($barcode_input !== '') {
            $dup = $pdo->prepare("SELECT id, name FROM ecom_products WHERE barcode = ? AND id != ?");
            $dup->execute([$barcode_input, $edit_id]);
            if ($existing_product = $dup->fetch(PDO::FETCH_ASSOC)) {
                $notifications[] = [
                    'type' => 'error',
                    'message' => 'This barcode is already used by "' . $existing_product['name'] . '" (product #' . $existing_product['id'] . '). Please scan or enter a different barcode, or open that product to edit it instead.'
                ];
                $skip_save = true;
            }
        }

        if (empty($skip_save)) {
        try {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO ecom_products
                    (category_id, subcategory_id, brand_id, name, slug, sku, hsn_code, product_type,
                     price, sale_price, gst_rate, stock_qty, image, description, badge_tag, item_type, status,
                     download_link, license_key, affiliate_url)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$category_id, $subcategory_id, $brand_id, $name, $slug, $sku, $hsn_code, $product_type,
                               $price, $sale_price, $gst_rate, $stock_qty, $image_path, $description, $badge_tag, $item_type, $status,
                               $download_link, $license_key, $affiliate_url]);

                $new_id = $pdo->lastInsertId();
                $barcode = $barcode_input !== '' ? $barcode_input : ('EM' . str_pad($new_id, 8, '0', STR_PAD_LEFT));
                $pdo->prepare("UPDATE ecom_products SET barcode=? WHERE id=?")->execute([$barcode, $new_id]);
                saveGalleryImages($pdo, (int)$new_id);
                syncProductVariants($pdo, (int)$new_id);
                syncProductSpecs($pdo, (int)$new_id);

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_product_create', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Created Product: $name (ID: $new_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/products.php?success=created");
                exit;

            } elseif ($action === 'update' && $edit_id > 0) {
                if ($image_path) {
                    $old = $pdo->prepare("SELECT image FROM ecom_products WHERE id=?");
                    $old->execute([$edit_id]);
                    if ($old_row = $old->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($old_row['image']) && file_exists(DROOT_PATH . '/' . $old_row['image'])) {
                            @unlink(DROOT_PATH . '/' . $old_row['image']);
                        }
                    }
                } else {
                    $keep = $pdo->prepare("SELECT image FROM ecom_products WHERE id=?");
                    $keep->execute([$edit_id]);
                    $image_path = $keep->fetchColumn() ?: null;
                }

                $pdo->prepare("UPDATE ecom_products SET
                    category_id=?, subcategory_id=?, brand_id=?, name=?, slug=?, sku=?, hsn_code=?, product_type=?,
                    price=?, sale_price=?, gst_rate=?, stock_qty=?, image=?, description=?, badge_tag=?, item_type=?, status=?,
                    download_link=?, license_key=?, affiliate_url=?,
                    barcode = IF(? = '', barcode, ?)
                    WHERE id=?")
                    ->execute([$category_id, $subcategory_id, $brand_id, $name, $slug, $sku, $hsn_code, $product_type,
                               $price, $sale_price, $gst_rate, $stock_qty, $image_path, $description, $badge_tag, $item_type, $status,
                               $download_link, $license_key, $affiliate_url, $barcode_input, $barcode_input, $edit_id]);
                saveGalleryImages($pdo, $edit_id);
                syncProductVariants($pdo, $edit_id);
                syncProductSpecs($pdo, $edit_id);

                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'ecom_product_update', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], "Updated Product: $name (ID: $edit_id)", $log_ip, $log_ua]);

                header("Location: /admin/ecommerce/products.php?success=updated");
                exit;
            }
        } catch (Exception $e) {
            $notifications[] = ['type' => 'error', 'message' => 'Save failed: ' . $e->getMessage()];
        }
        }
    }
}

$all_categories = $pdo->query("SELECT id, name FROM ecom_categories WHERE status='active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_brands      = $pdo->query("SELECT id, name FROM ecom_brands WHERE status='active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$badge_tag_options = $pdo->query("SELECT label, slug FROM ecom_product_tags WHERE tag_group='badge' AND (status='active' OR slug='none') ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$item_type_options = $pdo->query("SELECT label, slug FROM ecom_product_tags WHERE tag_group='item_type' AND (status='active' OR slug='normal') ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$gst_rates       = $pdo->query("SELECT id, label, rate, is_default FROM ecom_gst_rates ORDER BY rate ASC")->fetchAll(PDO::FETCH_ASSOC);
$default_gst_rate = 0;
foreach ($gst_rates as $gr) { if ($gr['is_default']) { $default_gst_rate = $gr['rate']; break; } }

$editing_subcats = [];
if ($editing && $editing['category_id']) {
    $sc = $pdo->prepare("SELECT id, name FROM ecom_subcategories WHERE category_id=? ORDER BY name ASC");
    $sc->execute([$editing['category_id']]);
    $editing_subcats = $sc->fetchAll(PDO::FETCH_ASSOC);
}

$type_labels = ['physical' => 'Physical Product', 'digital' => 'Digital Product', 'license' => 'License Product', 'affiliate' => 'Affiliate Product'];
$page_title = $editing ? 'Edit Product' : 'Add Product';
$page_subtitle = $editing ? 'Update product details' : 'Fill in the details for your new ' . strtolower($type_labels[$product_type] ?? 'product');
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
                <a href="products.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Products</a>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <form method="POST" enctype="multipart/form-data" id="productForm">
                <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
                <input type="hidden" name="edit_id" value="<?= $editing['id'] ?? 0 ?>">
                <input type="hidden" name="product_type" id="productType" value="<?= htmlspecialchars($product_type) ?>">

                <div class="row">
                    <div class="col-lg-8">
                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-info-circle text-primary"></i> Basic Info</h5>

                                <div class="mb-3">
                                    <label class="form-label">Product Name</label>
                                    <input type="text" name="name" id="prodName" class="form-control" value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Slug <small class="text-muted">(auto-generated)</small></label>
                                    <input type="text" name="slug" id="prodSlug" class="form-control" value="<?= htmlspecialchars($editing['slug'] ?? '') ?>">
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">SKU</label>
                                        <input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($editing['sku'] ?? '') ?>" placeholder="e.g. SKU-00123">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">HSN Code <small class="text-muted">(for GST invoices)</small></label>
                                        <input type="text" name="hsn_code" class="form-control" value="<?= htmlspecialchars($editing['hsn_code'] ?? '') ?>" placeholder="e.g. 8517">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Barcode / QR Code</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="text" name="barcode" id="barcodeField" class="form-control" autocomplete="off"
                                               value="<?= htmlspecialchars($editing['barcode'] ?? '') ?>"
                                               placeholder="Scan barcode or leave blank">
                                        <?php if ($editing && !empty($editing['barcode'])): ?>
                                        <a href="barcode-print.php?ids=<?= $editing['id'] ?>" target="_blank" class="btn btn-secondary flex-shrink-0"><i class="fas fa-barcode"></i> Print</a>
                                        <?php endif; ?>
                                    </div>
                                    <div id="barcodeCheckResult" class="mt-2" style="display:none;"></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="5"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-tags text-primary"></i> Categorization</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Category</label>
                                        <select name="category_id" id="pCategorySelect" class="form-select">
                                            <option value="">Select category…</option>
                                            <?php foreach ($all_categories as $c): ?>
                                            <option value="<?= $c['id'] ?>" <?= (($editing['category_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Sub Category</label>
                                        <select name="subcategory_id" id="pSubcategorySelect" class="form-select" <?= empty($editing_subcats) ? 'disabled' : '' ?>>
                                            <option value="">Select category first…</option>
                                            <?php foreach ($editing_subcats as $s): ?>
                                            <option value="<?= $s['id'] ?>" <?= (($editing['subcategory_id'] ?? 0) == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label">Brand</label>
                                    <select name="brand_id" id="brandSelect" class="form-select" onchange="if(this.value==='__add_new__'){this.value=this.dataset.prev||'';openAddBrandModal();}else{this.dataset.prev=this.value;}">
                                        <option value="">Select brand…</option>
                                        <?php foreach ($all_brands as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= (($editing['brand_id'] ?? 0) == $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__add_new__">+ Add New Brand…</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-rupee-sign text-primary"></i> Pricing<?= $product_type === 'physical' ? ' &amp; Stock' : '' ?></h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Price (₹)</label>
                                        <input type="number" step="0.01" min="0" name="price" class="form-control" value="<?= htmlspecialchars($editing['price'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Sale Price (₹)</label>
                                        <input type="number" step="0.01" min="0" name="sale_price" class="form-control" value="<?= htmlspecialchars($editing['sale_price'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-4 mb-3 field-physical">
                                        <label class="form-label">Stock Quantity</label>
                                        <input type="number" min="0" name="stock_qty" class="form-control" value="<?= htmlspecialchars($editing['stock_qty'] ?? '0') ?>">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">GST Rate</label>
                                        <select name="gst_rate" class="form-select" required>
                                            <?php $current_gst = $editing['gst_rate'] ?? $default_gst_rate; ?>
                                            <?php foreach ($gst_rates as $gr): ?>
                                            <option value="<?= $gr['rate'] ?>" <?= (float)$current_gst == (float)$gr['rate'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($gr['label']) ?> (<?= number_format((float)$gr['rate'], 2) ?>%)<?= $gr['is_default'] ? ' — Default' : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Applied automatically in Billing, Checkout, and Invoices.</div>
                                    </div>
                                </div>

                                <div class="mb-3 field-digital">
                                    <label class="form-label">Download Link</label>
                                    <input type="url" name="download_link" class="form-control" placeholder="https://…" value="<?= htmlspecialchars($editing['download_link'] ?? '') ?>">
                                </div>

                                <div class="mb-3 field-license">
                                    <label class="form-label">License Key(s)</label>
                                    <textarea name="license_key" class="form-control" rows="3" placeholder="One key per line"><?= htmlspecialchars($editing['license_key'] ?? '') ?></textarea>
                                </div>

                                <div class="mb-1 field-affiliate">
                                    <label class="form-label">Affiliate URL</label>
                                    <input type="url" name="affiliate_url" class="form-control" placeholder="https://…" value="<?= htmlspecialchars($editing['affiliate_url'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-1"><i class="fas fa-weight-hanging text-primary"></i> Sizes / Units <small class="text-muted fw-normal">(optional)</small></h5>
                                <p class="text-muted small mb-3">Sell this product in more than one size — e.g. 250 g / 500 g / 1 Kg, or 500 ml / 1 L / 2 L — each with its own MRP and selling price. Leave this empty to keep it a simple single-price product using the Pricing fields above.</p>
                                <div id="variantRows"></div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addVariantRow()"><i class="fas fa-plus"></i> Add Size / Unit</button>
                            </div>
                        </div>

                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-1"><i class="fas fa-list-ul text-primary"></i> Specifications <small class="text-muted fw-normal">(optional)</small></h5>
                                <p class="text-muted small mb-3">Shown as a bullet list on the product page — e.g. "Material: Cotton", "Weight: 500 g", "Country of Origin: India". Only rows where both fields are filled in are shown; leave empty to skip. (Brand is set above in Categorization and is shown automatically — no need to repeat it here.)</p>
                                <div id="specRows"></div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addSpecRow()"><i class="fas fa-plus"></i> Add Specification</button>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-image text-primary"></i> Image</h5>
                                <div id="imgPreviewBox" class="dt-thumb-placeholder mb-3" style="width:100%;height:180px;<?= !empty($editing['image']) ? 'overflow:hidden;' : '' ?>">
                                    <?php if (!empty($editing['image'])): ?>
                                        <img id="imgPreviewImg" src="/<?= htmlspecialchars($editing['image']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                    <?php else: ?>
                                        <i class="fas fa-image fa-2x"></i>
                                        <img id="imgPreviewImg" src="" style="display:none;width:100%;height:100%;object-fit:cover;">
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="image" id="prodImage" class="form-control" accept="image/*">
                            </div>
                        </div>

                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-images text-primary"></i> Gallery</h5>

                                <div id="galleryThumbs" class="d-flex flex-wrap gap-2 mb-3">
                                    <?php foreach ($gallery_images as $g): ?>
                                    <div class="gallery-thumb" data-existing-id="<?= $g['id'] ?>">
                                        <img src="/<?= htmlspecialchars($g['image']) ?>">
                                        <button type="button" class="gallery-thumb-remove" onclick="removeExistingGalleryImage(<?= $g['id'] ?>, this)" title="Remove"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <input type="file" name="gallery_images[]" id="galleryInput" class="form-control" accept="image/*" multiple>
                                <div id="newGalleryPreview" class="d-flex flex-wrap gap-2 mt-3"></div>
                            </div>
                        </div>

                        <style>
                        .gallery-thumb { position: relative; width: 64px; height: 64px; border-radius: 6px; overflow: hidden; border: 1px solid var(--gray-200); flex-shrink: 0; }
                        .gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
                        .gallery-thumb-remove { position: absolute; top: 2px; right: 2px; width: 18px; height: 18px; border-radius: 50%; background: rgba(0,0,0,.6); color: #fff; border: none; font-size: 0.6rem; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
                        .gallery-thumb-remove:hover { background: #dc3545; }
                        </style>

                        <div class="gd-card">
                            <div class="gd-card-body">
                                <h5 class="mb-3"><i class="fas fa-sliders-h text-primary"></i> Organization</h5>

                                <div class="mb-3">
                                    <label class="form-label d-block">Status</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="status" id="stActive" value="active" <?= (($editing['status'] ?? 'active') === 'active') ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-success btn-sm" for="stActive">Active</label>
                                        <input type="radio" class="btn-check" name="status" id="stInactive" value="inactive" <?= (($editing['status'] ?? '') === 'inactive') ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-secondary btn-sm" for="stInactive">Inactive</label>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">
                                        <span>Badge Tag <small class="text-muted">(optional)</small></span>
                                    </label>
                                    <select name="badge_tag" class="form-select">
                                        <?php foreach ($badge_tag_options as $t): ?>
                                        <option value="<?= htmlspecialchars($t['slug']) ?>" <?= (($editing['badge_tag'] ?? 'none') === $t['slug']) ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-1">
                                    <label class="form-label">
                                        <span>Item Type <small class="text-muted">(optional)</small></span>
                                    </label>
                                    <select name="item_type" id="itemTypeSelect" class="form-select" onchange="if(this.value==='__add_new__'){this.value=this.dataset.prev||'normal';openAddItemTypeModal();}else{this.dataset.prev=this.value;}">
                                        <?php foreach ($item_type_options as $t): ?>
                                        <option value="<?= htmlspecialchars($t['slug']) ?>" <?= (($editing['item_type'] ?? 'normal') === $t['slug']) ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__add_new__">+ Add New Item Type…</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="fas fa-<?= $editing ? 'save' : 'plus-circle' ?>"></i>
                            <?= $editing ? 'Update Product' : 'Create Product' ?>
                        </button>
                    </div>
                </div>
            </form>

        </div>
    </main>
</div>

<!-- Add New Brand Modal (inline, no page navigation) -->
<div class="modal fade" id="addBrandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Brand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="addBrandError" class="alert alert-danger py-2" style="display:none;"></div>
                <div class="mb-3">
                    <label class="form-label">Brand Name</label>
                    <input type="text" id="newBrandName" class="form-control" placeholder="e.g. Nike" autofocus>
                </div>
                <div class="mb-1">
                    <label class="form-label">Logo <small class="text-muted">(optional)</small></label>
                    <input type="file" id="newBrandLogo" class="form-control" accept="image/*">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBrandBtn" onclick="saveNewBrand()">Add Brand</button>
            </div>
        </div>
    </div>
</div>

<!-- Add New Item Type Modal (inline, no page navigation) -->
<div class="modal fade" id="addItemTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Item Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="addItemTypeError" class="alert alert-danger py-2" style="display:none;"></div>
                <div class="mb-1">
                    <label class="form-label">Item Type Name</label>
                    <input type="text" id="newItemTypeName" class="form-control" placeholder="e.g. Bundle" autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveItemTypeBtn" onclick="saveNewItemType()">Add Item Type</button>
            </div>
        </div>
    </div>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>

<script>
function openAddBrandModal() {
    document.getElementById('newBrandName').value = '';
    document.getElementById('newBrandLogo').value = '';
    document.getElementById('addBrandError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('addBrandModal')).show();
    setTimeout(() => document.getElementById('newBrandName').focus(), 300);
}

function saveNewBrand() {
    const name = document.getElementById('newBrandName').value.trim();
    const errBox = document.getElementById('addBrandError');
    if (!name) {
        errBox.textContent = 'Please enter a brand name.';
        errBox.style.display = 'block';
        return;
    }

    const btn = document.getElementById('saveBrandBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const formData = new FormData();
    formData.append('name', name);
    const logoFile = document.getElementById('newBrandLogo').files[0];
    if (logoFile) formData.append('logo', logoFile);

    fetch('add-brand-ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Add Brand';
            if (!data.success) {
                errBox.textContent = data.message || 'Could not add brand.';
                errBox.style.display = 'block';
                return;
            }
            const select = document.getElementById('brandSelect');
            const addNewOption = select.querySelector('option[value="__add_new__"]');
            const opt = document.createElement('option');
            opt.value = data.id;
            opt.textContent = data.name;
            select.insertBefore(opt, addNewOption);
            select.value = data.id;
            select.dataset.prev = data.id;

            bootstrap.Modal.getInstance(document.getElementById('addBrandModal')).hide();
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Add Brand';
            errBox.textContent = 'Something went wrong — please try again.';
            errBox.style.display = 'block';
        });
}

function openAddItemTypeModal() {
    document.getElementById('newItemTypeName').value = '';
    document.getElementById('addItemTypeError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('addItemTypeModal')).show();
    setTimeout(() => document.getElementById('newItemTypeName').focus(), 300);
}

function saveNewItemType() {
    const name = document.getElementById('newItemTypeName').value.trim();
    const errBox = document.getElementById('addItemTypeError');
    if (!name) {
        errBox.textContent = 'Please enter an item type name.';
        errBox.style.display = 'block';
        return;
    }

    const btn = document.getElementById('saveItemTypeBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('add-item-type-ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'label=' + encodeURIComponent(name)
    })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Add Item Type';
            if (!data.success) {
                errBox.textContent = data.message || 'Could not add item type.';
                errBox.style.display = 'block';
                return;
            }
            const select = document.getElementById('itemTypeSelect');
            const addNewOption = select.querySelector('option[value="__add_new__"]');
            const opt = document.createElement('option');
            opt.value = data.slug;
            opt.textContent = data.label;
            select.insertBefore(opt, addNewOption);
            select.value = data.slug;
            select.dataset.prev = data.slug;

            bootstrap.Modal.getInstance(document.getElementById('addItemTypeModal')).hide();
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Add Item Type';
            errBox.textContent = 'Something went wrong — please try again.';
            errBox.style.display = 'block';
        });
}
</script>

<script>
const CURRENT_TYPE = "<?= htmlspecialchars($product_type) ?>";

// Barcode scanner guns "type" the code then send Enter — stop that Enter
// from submitting the whole form while this field is focused.
document.getElementById('barcodeField').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        this.blur();
    }
});

// Live duplicate-barcode check — catches it the moment it's scanned/typed,
// before the user even tries to save.
const CURRENT_EDIT_ID = <?= (int)($editing['id'] ?? 0) ?>;
let barcodeCheckTimer = null;
let barcodeIsDuplicate = false;

document.getElementById('barcodeField').addEventListener('input', function () {
    const val = this.value.trim();
    const resultBox = document.getElementById('barcodeCheckResult');
    clearTimeout(barcodeCheckTimer);

    if (!val) {
        resultBox.style.display = 'none';
        barcodeIsDuplicate = false;
        return;
    }

    barcodeCheckTimer = setTimeout(function () {
        fetch('check-barcode-ajax.php?barcode=' + encodeURIComponent(val) + '&exclude_id=' + CURRENT_EDIT_ID)
            .then(r => r.json())
            .then(data => {
                if (data.exists) {
                    barcodeIsDuplicate = true;
                    resultBox.innerHTML = '<div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center justify-content-between">'
                        + '<span><i class="fas fa-exclamation-triangle"></i> This barcode already belongs to <strong>' + data.name.replace(/</g, '&lt;') + '</strong>.</span>'
                        + '<a href="add-product-form.php?edit=' + data.id + '" class="btn btn-sm btn-warning ms-2" target="_blank">View <i class="fas fa-external-link-alt ms-1"></i></a>'
                        + '</div>';
                    resultBox.style.display = 'block';
                } else {
                    barcodeIsDuplicate = false;
                    resultBox.style.display = 'none';
                }
            });
    }, 400);
});

document.getElementById('productForm').addEventListener('submit', function (e) {
    if (barcodeIsDuplicate) {
        e.preventDefault();
        alert('That barcode already belongs to another product. Please scan/enter a different one, or open the existing product to edit it instead.');
    }
});

function applyTypeFields(type) {
    document.querySelectorAll('.field-physical, .field-digital, .field-license, .field-affiliate').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.field-' + type).forEach(el => el.style.display = 'block');
    document.getElementById('productType').value = type;
}
applyTypeFields(CURRENT_TYPE);

// Slug auto-gen
(function () {
    const nameEl = document.getElementById('prodName');
    const slugEl = document.getElementById('prodSlug');
    let userEditedSlug = slugEl.value !== '';
    function toSlug(str) {
        return str.toLowerCase().trim().replace(/[^a-z0-9\s-]/g, '').replace(/[\s-]+/g, '-').replace(/^-+|-+$/g, '');
    }
    nameEl.addEventListener('input', function () { if (!userEditedSlug) slugEl.value = toSlug(this.value); });
    slugEl.addEventListener('input', function () { userEditedSlug = slugEl.value !== ''; });
})();

// Image preview
document.getElementById('prodImage').addEventListener('change', function (e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (ev) {
        const img = document.getElementById('imgPreviewImg');
        img.src = ev.target.result;
        img.style.display = 'block';
    };
    reader.readAsDataURL(file);
});

// Gallery: show small thumbnail previews of newly-picked photos before upload
document.getElementById('galleryInput').addEventListener('change', function (e) {
    const preview = document.getElementById('newGalleryPreview');
    preview.innerHTML = '';
    Array.from(e.target.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = function (ev) {
            const div = document.createElement('div');
            div.className = 'gallery-thumb';
            div.innerHTML = `<img src="${ev.target.result}">`;
            preview.appendChild(div);
        };
        reader.readAsDataURL(file);
    });
});

// Gallery: remove an already-saved image (marks it for deletion on save)
function removeExistingGalleryImage(id, btn) {
    const form = document.getElementById('productForm');
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'removed_gallery_ids[]';
    hidden.value = id;
    form.appendChild(hidden);
    btn.closest('.gallery-thumb').remove();
}

// Cascading Category -> Subcategory
const catSelect = document.getElementById('pCategorySelect');
const subSelect = document.getElementById('pSubcategorySelect');

catSelect.addEventListener('change', function () {
    loadSubcategories(this.value, null);
});

function loadSubcategories(categoryId, preselect) {
    subSelect.innerHTML = '<option value="">Loading…</option>';
    subSelect.disabled = true;
    if (!categoryId) { subSelect.innerHTML = '<option value="">Select category first…</option>'; return; }
    fetch('get-subcategories.php?category_id=' + encodeURIComponent(categoryId))
        .then(r => r.json())
        .then(data => {
            subSelect.innerHTML = '<option value="">Select subcategory…</option>';
            data.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id; opt.textContent = s.name;
                if (preselect && String(s.id) === String(preselect)) opt.selected = true;
                subSelect.appendChild(opt);
            });
            subSelect.disabled = data.length === 0;
        });
}
// ── Sizes / Units repeater ───────────────────────────────────────────────
const EXISTING_VARIANTS = <?= json_encode(array_map(function ($v) {
    return ['label' => $v['label'], 'price' => $v['price'], 'sale_price' => $v['sale_price'], 'stock_qty' => $v['stock_qty'], 'is_default' => (int)$v['is_default']];
}, $variant_rows)) ?>;
let variantRowCount = 0;

function variantRowHtml(idx, data) {
    data = data || {};
    const label = (data.label || '').replace(/"/g, '&quot;');
    const price = data.price ?? '';
    const sale = data.sale_price ?? '';
    const stock = data.stock_qty ?? '';
    const checked = data.is_default ? 'checked' : '';
    return `
    <div class="row g-2 align-items-center variant-row mb-2" data-idx="${idx}">
        <div class="col-auto">
            <input type="radio" name="variant_default_radio" class="form-check-input variant-default-radio" title="Default size shown first" ${checked}>
        </div>
        <div class="col-md-3">
            <input type="text" name="variant_label[]" class="form-control form-control-sm" placeholder="e.g. 500 g" value="${label}" required>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" min="0" name="variant_price[]" class="form-control form-control-sm" placeholder="MRP ₹" value="${price}" required>
        </div>
        <div class="col-md-3">
            <input type="number" step="0.01" min="0" name="variant_sale_price[]" class="form-control form-control-sm" placeholder="Selling Price ₹ (optional)" value="${sale}">
        </div>
        <div class="col-md-2">
            <input type="number" min="0" name="variant_stock[]" class="form-control form-control-sm" placeholder="Stock (optional)" value="${stock}">
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.variant-row').remove()"><i class="fas fa-times"></i></button>
        </div>
    </div>`;
}

function addVariantRow(data) {
    document.getElementById('variantRows').insertAdjacentHTML('beforeend', variantRowHtml(variantRowCount++, data));
}

(EXISTING_VARIANTS.length ? EXISTING_VARIANTS : []).forEach(v => addVariantRow(v));

// ── Specifications repeater ──────────────────────────────────────────────
const EXISTING_SPECS = <?= json_encode(array_map(function ($s) {
    return ['spec_key' => $s['spec_key'], 'spec_value' => $s['spec_value']];
}, $spec_rows)) ?>;
let specRowCount = 0;

function specRowHtml(idx, data) {
    data = data || {};
    const key = (data.spec_key || '').replace(/"/g, '&quot;');
    const value = (data.spec_value || '').replace(/"/g, '&quot;');
    return `
    <div class="row g-2 align-items-center spec-row mb-2" data-idx="${idx}">
        <div class="col-md-4">
            <input type="text" name="spec_key[]" class="form-control form-control-sm" placeholder="e.g. Material" value="${key}">
        </div>
        <div class="col-md-6">
            <input type="text" name="spec_value[]" class="form-control form-control-sm" placeholder="e.g. Cotton" value="${value}">
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.spec-row').remove()"><i class="fas fa-times"></i></button>
        </div>
    </div>`;
}

function addSpecRow(data) {
    document.getElementById('specRows').insertAdjacentHTML('beforeend', specRowHtml(specRowCount++, data));
}

(EXISTING_SPECS.length ? EXISTING_SPECS : []).forEach(s => addSpecRow(s));

// Before submit: turn the checked "default" radio into the variant_default
// index the server expects (position among the rows actually submitted).
document.getElementById('productForm').addEventListener('submit', function () {
    document.querySelectorAll('input[name="variant_default"]').forEach(el => el.remove());
    const rows = Array.from(document.querySelectorAll('#variantRows .variant-row'));
    let defaultIndex = 0;
    rows.forEach((row, i) => { if (row.querySelector('.variant-default-radio').checked) defaultIndex = i; });
    if (rows.length) {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'variant_default';
        hidden.value = defaultIndex;
        this.appendChild(hidden);
    }
});
</script>
</body>
</html>
