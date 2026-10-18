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
echo "<h3>Running Content Slider Upgrade...</h3>";

function columnExists(PDO $pdo, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // The homepage banner slider is moving from admin-uploaded images to
    // demo-style CONTENT slides (heading, subtext, badge, icon, button —
    // same look as shop/demo-home.html). These new columns are added
    // alongside the old image_desktop/image_mobile ones (kept for any
    // existing rows / backward compatibility, but the storefront and
    // admin form will only use the new content fields going forward).
    $newColumns = [
        'slide_type'   => "VARCHAR(20) NOT NULL DEFAULT 'content' AFTER id",
        'template'     => "VARCHAR(20) NOT NULL DEFAULT 'tpl-1' AFTER slide_type",
        'badge_text'   => "VARCHAR(60) NULL AFTER template",
        'heading'      => "VARCHAR(120) NULL AFTER badge_text",
        'subtext'      => "VARCHAR(200) NULL AFTER heading",
        'button_text'  => "VARCHAR(40) NULL AFTER subtext",
        'icon_emoji'   => "VARCHAR(20) NULL AFTER button_text",
        'icon_image'   => "VARCHAR(255) NULL AFTER icon_emoji",
    ];

    foreach ($newColumns as $col => $definition) {
        if (!columnExists($pdo, 'ecom_home_slider', $col)) {
            $pdo->exec("ALTER TABLE ecom_home_slider ADD COLUMN $col $definition");
            echo "<p style='color:green;font-weight:bold;'>Added ecom_home_slider.$col</p>";
        } else {
            echo "<p>ecom_home_slider.$col already exists — skipped.</p>";
        }
    }

    // Backward-compat: make image_desktop/image_mobile nullable-friendly
    // (they may already allow NULL; this is a no-op if so) since content
    // slides won't fill them at all.
    $pdo->exec("ALTER TABLE ecom_home_slider MODIFY image_desktop VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE ecom_home_slider MODIFY image_mobile VARCHAR(255) NULL");
    echo "<p style='color:green;font-weight:bold;'>image_desktop / image_mobile confirmed nullable (kept for backward compatibility, no longer required).</p>";

    echo "<p><strong>All done.</strong> Go to <a href='/admin/ecommerce/homepage-settings.php'>Homepage Settings</a> ";
    echo "→ Banner Slider tab to add content-style slides (heading, subtext, badge, icon, button, and a ready-made template).</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;font-weight:bold;'>Migration failed:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
