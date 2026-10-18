<?php
require_once dirname(dirname(dirname(__FILE__))) . '/includes/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, role, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$user ||
    $user['status'] !== 'active' ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true)
) {
    exit('Access Denied');
}

header('Content-Type: text/html; charset=utf-8');
echo "<h3>Running Verified-Purchase Review Upgrade...</h3>";

function columnExists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // Reviews are moving to a Flipkart-style "verified purchase" model:
    // - "Write a Review" only shows for a customer who actually bought this
    //   product (an order containing it, marked Delivered).
    // - Each such order can be reviewed exactly once — reviewing it again
    //   requires buying the product again (a new Delivered order).
    // These two columns are what makes that check possible; customer_name
    // stays as-is for display, these are for the eligibility logic.
    if (!columnExists($pdo, 'ecom_product_reviews', 'customer_id')) {
        $pdo->exec("ALTER TABLE ecom_product_reviews ADD COLUMN customer_id INT UNSIGNED NULL AFTER product_id");
        echo "<p style='color:green;font-weight:bold;'>Added ecom_product_reviews.customer_id</p>";
    } else {
        echo "<p>ecom_product_reviews.customer_id already exists — skipped.</p>";
    }
    if (!columnExists($pdo, 'ecom_product_reviews', 'order_id')) {
        $pdo->exec("ALTER TABLE ecom_product_reviews ADD COLUMN order_id INT UNSIGNED NULL AFTER customer_id");
        echo "<p style='color:green;font-weight:bold;'>Added ecom_product_reviews.order_id</p>";
    } else {
        echo "<p>ecom_product_reviews.order_id already exists — skipped.</p>";
    }

    // Index to make the "has this order already been reviewed" lookup fast.
    $idx = $pdo->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecom_product_reviews' AND INDEX_NAME = 'idx_order_product'
    ")->fetchColumn();
    if ((int)$idx === 0) {
        $pdo->exec("ALTER TABLE ecom_product_reviews ADD INDEX idx_order_product (order_id, product_id)");
        echo "<p style='color:green;font-weight:bold;'>Added index idx_order_product.</p>";
    } else {
        echo "<p>Index idx_order_product already exists — skipped.</p>";
    }

    echo "<p><strong>All done.</strong> Existing old reviews (written before this upgrade) have no order_id — ";
    echo "they'll keep showing on the product page as before, they just won't block anyone from leaving a new, ";
    echo "verified review after a real purchase. Nothing else to configure.</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;font-weight:bold;'>Migration failed:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
