<?php
require_once dirname(dirname(dirname(__FILE__))) . '/includes/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, role, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);

if (
    !$user ||
    $user['status'] !== 'active' ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true)
) {
    exit('Access Denied');
}

header('Content-Type: text/html; charset=utf-8');
echo "<h3>Running Product Variants (Units) Migration...</h3>";

try {
    // Each row is one size/pack/weight option for a product
    // (e.g. "500 g", "1 Kg", "1 L"), with its own MRP, selling price and
    // (optionally) its own stock. A product with zero rows here is a plain,
    // single-price product exactly like today — nothing changes for it.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ecom_product_variants (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id  INT UNSIGNED NOT NULL,
            label       VARCHAR(50) NOT NULL,
            price       DECIMAL(10,2) NOT NULL,
            sale_price  DECIMAL(10,2) NULL,
            stock_qty   INT NULL,
            is_default  TINYINT(1) NOT NULL DEFAULT 0,
            sort_order  INT NOT NULL DEFAULT 0,
            KEY idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->query("SELECT 1 FROM ecom_product_variants LIMIT 1");
    echo "<p style='color:green;font-weight:bold;'>ecom_product_variants is ready.</p>";

    // Record which size/unit was ordered, on the order line itself — so past
    // orders/invoices always show the correct size even if that variant is
    // later edited or removed from the product.
    $col_check = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecom_order_items' AND COLUMN_NAME = 'variant_label'
    ")->fetchColumn();
    if (!$col_check) {
        $pdo->exec("ALTER TABLE ecom_order_items ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id, ADD COLUMN variant_label VARCHAR(50) NULL AFTER product_name");
        echo "<p style='color:green;font-weight:bold;'>Added ecom_order_items.variant_id / variant_label</p>";
    } else {
        echo "<p>ecom_order_items.variant_label already exists — skipped.</p>";
    }

    echo "<p><strong>All done.</strong> Go to any product in <a href='/admin/ecommerce/products.php'>Products</a> ";
    echo "and open Edit — you'll now see a 'Sizes / Units' card where you can add rows like 250 g / 500 g / 1 Kg, ";
    echo "each with its own price. Leave it empty on any product to keep it a simple single-price item.</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;font-weight:bold;'>Migration failed:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
