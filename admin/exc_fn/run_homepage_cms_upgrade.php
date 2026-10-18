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
echo "<h3>Running Homepage CMS Upgrade...</h3>";

function columnExists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // 1) Popularity counters on products — power the "Popular Products"
    //    auto-fill (manual pins + top by purchases/views).
    if (!columnExists($pdo, 'ecom_products', 'view_count')) {
        $pdo->exec("ALTER TABLE ecom_products ADD COLUMN view_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status");
        echo "<p style='color:green;font-weight:bold;'>Added ecom_products.view_count</p>";
    } else {
        echo "<p>ecom_products.view_count already exists — skipped.</p>";
    }
    if (!columnExists($pdo, 'ecom_products', 'purchase_count')) {
        $pdo->exec("ALTER TABLE ecom_products ADD COLUMN purchase_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER view_count");
        echo "<p style='color:green;font-weight:bold;'>Added ecom_products.purchase_count</p>";
    } else {
        echo "<p>ecom_products.purchase_count already exists — skipped.</p>";
    }

    // 2) Widen source_type / card_design so new values ('popular', 'classic')
    //    are accepted regardless of whether the column was originally an ENUM
    //    or VARCHAR. Safe to run even if already VARCHAR.
    $pdo->exec("ALTER TABLE ecom_home_sections MODIFY source_type VARCHAR(20) NOT NULL DEFAULT 'latest'");
    $pdo->exec("ALTER TABLE ecom_home_sections MODIFY card_design VARCHAR(20) NOT NULL DEFAULT 'design1'");
    echo "<p style='color:green;font-weight:bold;'>ecom_home_sections.source_type / card_design widened to accept 'popular' and 'classic'.</p>";

    // 3) Slider autoplay on/off + interval, stored in the same settings
    //    key/value table already used for the category strip. Also seed the
    //    "looks good out of the box" defaults: slider on, category strip
    //    showing every category, and the automatic per-category rows on —
    //    so a brand-new store's homepage is never empty.
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('slider_enabled', 'on')")->execute();
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('slider_autoplay', 'on')")->execute();
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('slider_interval', '4000')")->execute();
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('category_strip_mode', 'all')")->execute();
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('category_strip_count', '10')")->execute();
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('auto_categories_enabled', 'on')")->execute();
    $pdo->prepare("INSERT IGNORE INTO ecom_home_settings (setting_key, setting_value) VALUES ('auto_categories_limit', '10')")->execute();
    echo "<p style='color:green;font-weight:bold;'>Default homepage settings seeded — slider on, category strip showing all categories, auto-category rows on.</p>";

    echo "<p><strong>All done.</strong> Your homepage will already show a starter slider, all your categories in ";
    echo "the icon strip, and a product row per category — fully automatic. Go to ";
    echo "<a href='/admin/ecommerce/homepage-settings.php'>Homepage Settings</a> any time to replace the starter slider ";
    echo "with your own banners, add a 'Popular Products' section (manual pins + auto by views/purchases), pick from ";
    echo "5 card designs, and show/hide any part of the homepage.</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;font-weight:bold;'>Migration failed:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
