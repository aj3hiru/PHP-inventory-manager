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

define('HOME_UPLOAD_URL', 'uploads/ecommerce/homepage/');
define('HOME_UPLOAD_ABS', DROOT_PATH . '/uploads/ecommerce/homepage/');
if (!file_exists(HOME_UPLOAD_ABS)) mkdir(HOME_UPLOAD_ABS, 0777, true);

function saveUpload($field, $prefix) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $filename = $prefix . '-' . uniqid() . '.' . $ext;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], HOME_UPLOAD_ABS . $filename)) {
        return HOME_UPLOAD_URL . $filename;
    }
    return null;
}

function moveItem($pdo, $table, $id, $direction, $extraWhere = '', $extraParams = []) {
    $cur = $pdo->prepare("SELECT sort_order FROM $table WHERE id = ?");
    $cur->execute([$id]);
    $curOrder = $cur->fetchColumn();
    if ($curOrder === false) return;

    $op = $direction === 'up' ? '<' : '>';
    $ord = $direction === 'up' ? 'DESC' : 'ASC';
    $sql = "SELECT id, sort_order FROM $table WHERE sort_order $op ?" . ($extraWhere ? " AND $extraWhere" : '') . " ORDER BY sort_order $ord LIMIT 1";
    $swap = $pdo->prepare($sql);
    $swap->execute(array_merge([$curOrder], $extraParams));
    $target = $swap->fetch(PDO::FETCH_ASSOC);
    if (!$target) return;

    $pdo->prepare("UPDATE $table SET sort_order = ? WHERE id = ?")->execute([$target['sort_order'], $id]);
    $pdo->prepare("UPDATE $table SET sort_order = ? WHERE id = ?")->execute([$curOrder, $target['id']]);
}

function saveV5Setting($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO ecom_home_settings_v5 (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$key, $value, $value]);
}

// shop/includes/shop-header.php reads the header's location/delivery-time block
// from the plain ecom_home_settings table (not the _v5 one), so this saves there.
function saveHomeSetting($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO ecom_home_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$key, $value, $value]);
}

$notifications = [];
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ═══════════════════════ TAB 1 — SLIDER (content-style, like demo-home.html) ═══════════════════════
    if ($action === 'add_slide') {
        $heading = trim($_POST['heading'] ?? '');
        if ($heading === '') {
            $notifications[] = ['type' => 'error', 'message' => 'Heading likhna zaroori hai.'];
        } else {
            $iconImage = saveUpload('icon_image', 'slide-icon');
            $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_slider")->fetchColumn();
            $pdo->prepare("INSERT INTO ecom_home_slider
                (template, badge_text, heading, subtext, button_text, link, icon_emoji, icon_image, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    trim($_POST['template'] ?? 'tpl-1') ?: 'tpl-1',
                    trim($_POST['badge_text'] ?? ''),
                    $heading,
                    trim($_POST['subtext'] ?? ''),
                    trim($_POST['button_text'] ?? '') ?: 'SHOP NOW',
                    trim($_POST['link'] ?? '#') ?: '#',
                    trim($_POST['icon_emoji'] ?? ''),
                    $iconImage,
                    $max + 1,
                ]);
            $notifications[] = ['type' => 'success', 'message' => 'Slide add ho gaya.'];
        }
    } elseif ($action === 'edit_slide') {
        $id = (int)$_POST['id'];
        $newIconImage = saveUpload('icon_image', 'slide-icon');
        $sets = [
            'template = ?', 'badge_text = ?', 'heading = ?', 'subtext = ?',
            'button_text = ?', 'link = ?', 'icon_emoji = ?',
        ];
        $params = [
            trim($_POST['template'] ?? 'tpl-1') ?: 'tpl-1',
            trim($_POST['badge_text'] ?? ''),
            trim($_POST['heading'] ?? ''),
            trim($_POST['subtext'] ?? ''),
            trim($_POST['button_text'] ?? '') ?: 'SHOP NOW',
            trim($_POST['link'] ?? '#') ?: '#',
            trim($_POST['icon_emoji'] ?? ''),
        ];
        if ($newIconImage) { $sets[] = 'icon_image = ?'; $params[] = $newIconImage; }
        $params[] = $id;
        $pdo->prepare("UPDATE ecom_home_slider SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        $notifications[] = ['type' => 'success', 'message' => 'Slide update ho gaya.'];
    } elseif ($action === 'delete_slide') {
        $pdo->prepare("DELETE FROM ecom_home_slider WHERE id = ?")->execute([(int)$_POST['id']]);
        $notifications[] = ['type' => 'success', 'message' => 'Slide hata diya.'];
    } elseif ($action === 'toggle_slide') {
        $pdo->prepare("UPDATE ecom_home_slider SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'move_slide') {
        moveItem($pdo, 'ecom_home_slider', (int)$_POST['id'], $_POST['direction']);
    }

    // ═══════════════════════ TAB 2 — CATEGORY STRIP ═══════════════════════
    elseif ($action === 'save_strip_settings') {
        $mode = in_array($_POST['strip_mode'] ?? '', ['all', 'manual', 'auto_popular'], true) ? $_POST['strip_mode'] : 'all';
        $limit = max(1, min(30, (int)($_POST['strip_limit'] ?? 10)));
        saveV5Setting($pdo, 'home_strip_mode', $mode);
        saveV5Setting($pdo, 'home_strip_limit', $limit);
        $notifications[] = ['type' => 'success', 'message' => 'Category strip settings save ho gayi.'];
    } elseif ($action === 'add_strip_item') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        $label = trim($_POST['custom_label'] ?? '');
        $emoji = trim($_POST['custom_emoji'] ?? '');
        $link = trim($_POST['custom_link'] ?? '');
        $image = saveUpload('custom_image', 'strip');
        if ($cat_id <= 0 && $label === '') {
            $notifications[] = ['type' => 'error', 'message' => 'Category chuno ya custom label likho.'];
        } else {
            $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_category_strip_v5")->fetchColumn();
            $pdo->prepare("INSERT INTO ecom_home_category_strip_v5 (category_id, custom_label, custom_emoji, custom_image, custom_link, sort_order) VALUES (?,?,?,?,?,?)")
                ->execute([$cat_id ?: null, $label ?: null, $emoji ?: null, $image, $link ?: '#', $max + 1]);
            $notifications[] = ['type' => 'success', 'message' => 'Strip item add ho gaya.'];
        }
    } elseif ($action === 'delete_strip_item') {
        $pdo->prepare("DELETE FROM ecom_home_category_strip_v5 WHERE id = ?")->execute([(int)$_POST['id']]);
        $notifications[] = ['type' => 'success', 'message' => 'Strip item hata diya.'];
    } elseif ($action === 'move_strip_item') {
        moveItem($pdo, 'ecom_home_category_strip_v5', (int)$_POST['id'], $_POST['direction']);
    }

    // ═══════════════════════ TAB 3 — TRENDING SECTIONS ═══════════════════════
    elseif ($action === 'add_trending_section') {
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            $notifications[] = ['type' => 'error', 'message' => 'Section ka naam likho.'];
        } else {
            $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_trending_sections")->fetchColumn();
            $pdo->prepare("INSERT INTO ecom_home_trending_sections (title, sort_order) VALUES (?,?)")->execute([$title, $max + 1]);
            $notifications[] = ['type' => 'success', 'message' => 'Naya trending section bana diya.'];
        }
    } elseif ($action === 'rename_trending_section') {
        $pdo->prepare("UPDATE ecom_home_trending_sections SET title = ? WHERE id = ?")->execute([trim($_POST['title'] ?? ''), (int)$_POST['id']]);
        $notifications[] = ['type' => 'success', 'message' => 'Section ka naam update ho gaya.'];
    } elseif ($action === 'delete_trending_section') {
        $sid = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM ecom_home_trending_items WHERE section_id = ?")->execute([$sid]);
        $pdo->prepare("DELETE FROM ecom_home_trending_sections WHERE id = ?")->execute([$sid]);
        $notifications[] = ['type' => 'success', 'message' => 'Section hata diya.'];
    } elseif ($action === 'toggle_trending_section') {
        $pdo->prepare("UPDATE ecom_home_trending_sections SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'move_trending_section') {
        moveItem($pdo, 'ecom_home_trending_sections', (int)$_POST['id'], $_POST['direction']);
    } elseif ($action === 'add_trending_item') {
        $section_id = (int)$_POST['section_id'];
        $type = in_array($_POST['item_type'] ?? '', ['product', 'category', 'brand', 'manual'], true) ? $_POST['item_type'] : 'manual';
        $product_id = ($type === 'product') ? (int)($_POST['product_id'] ?? 0) : null;
        $category_id = ($type === 'category') ? (int)($_POST['category_id'] ?? 0) : null;
        $label = trim($_POST['custom_label'] ?? '');
        $link = trim($_POST['custom_link'] ?? '');
        $image = saveUpload('custom_image', 'trend');
        if ($type === 'manual' && $label === '') {
            $notifications[] = ['type' => 'error', 'message' => 'Manual item ke liye naam likho.'];
        } elseif ($type === 'product' && !$product_id) {
            $notifications[] = ['type' => 'error', 'message' => 'Product chuno.'];
        } elseif ($type === 'category' && !$category_id) {
            $notifications[] = ['type' => 'error', 'message' => 'Category chuno.'];
        } else {
            $max = (int) $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_trending_items WHERE section_id = ?")->execute([$section_id]);
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_trending_items WHERE section_id = ?");
            $maxStmt->execute([$section_id]);
            $max = (int)$maxStmt->fetchColumn();
            $pdo->prepare("INSERT INTO ecom_home_trending_items (section_id, item_type, product_id, category_id, custom_label, custom_image, custom_link, sort_order) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$section_id, $type, $product_id ?: null, $category_id ?: null, $label ?: null, $image, $link ?: '#', $max + 1]);
            $notifications[] = ['type' => 'success', 'message' => 'Item add ho gaya.'];
        }
    } elseif ($action === 'delete_trending_item') {
        $pdo->prepare("DELETE FROM ecom_home_trending_items WHERE id = ?")->execute([(int)$_POST['id']]);
        $notifications[] = ['type' => 'success', 'message' => 'Item hata diya.'];
    } elseif ($action === 'move_trending_item') {
        $itemId = (int)$_POST['id'];
        $secStmt = $pdo->prepare("SELECT section_id FROM ecom_home_trending_items WHERE id = ?");
        $secStmt->execute([$itemId]);
        $sid = $secStmt->fetchColumn();
        moveItem($pdo, 'ecom_home_trending_items', $itemId, $_POST['direction'], 'section_id = ?', [$sid]);
    }

    // ═══════════════════════ TAB 4 — POPULAR PRODUCTS ═══════════════════════
    elseif ($action === 'save_popular_settings') {
        $mode = in_array($_POST['popular_mode'] ?? '', ['best_selling', 'most_viewed', 'manual'], true) ? $_POST['popular_mode'] : 'best_selling';
        $limit = max(1, min(50, (int)($_POST['popular_limit'] ?? 10)));
        saveV5Setting($pdo, 'home_popular_mode', $mode);
        saveV5Setting($pdo, 'home_popular_limit', $limit);
        $notifications[] = ['type' => 'success', 'message' => 'Popular Products settings save ho gayi.'];
    } elseif ($action === 'add_popular_pin') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid <= 0) {
            $notifications[] = ['type' => 'error', 'message' => 'Product chuno.'];
        } else {
            $exists = $pdo->prepare("SELECT id FROM ecom_home_popular_pins WHERE product_id = ?");
            $exists->execute([$pid]);
            if ($exists->fetch()) {
                $notifications[] = ['type' => 'error', 'message' => 'Ye product pehle se pin hai.'];
            } else {
                $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_popular_pins")->fetchColumn();
                $pdo->prepare("INSERT INTO ecom_home_popular_pins (product_id, sort_order) VALUES (?,?)")->execute([$pid, $max + 1]);
                $notifications[] = ['type' => 'success', 'message' => 'Product pin ho gaya.'];
            }
        }
    } elseif ($action === 'delete_popular_pin') {
        $pdo->prepare("DELETE FROM ecom_home_popular_pins WHERE id = ?")->execute([(int)$_POST['id']]);
        $notifications[] = ['type' => 'success', 'message' => 'Pin hata diya.'];
    } elseif ($action === 'move_popular_pin') {
        moveItem($pdo, 'ecom_home_popular_pins', (int)$_POST['id'], $_POST['direction']);
    }

    // ═══════════════════════ TAB 5 — FESTIVE BANNER ═══════════════════════
    elseif ($action === 'save_festive_banner') {
        $desktop = saveUpload('image_desktop', 'festive-d');
        $mobile  = saveUpload('image_mobile', 'festive-m');
        $link = trim($_POST['link'] ?? '#') ?: '#';
        $status = isset($_POST['status']) ? 'active' : 'inactive';
        $sets = ['link = ?', 'status = ?']; $params = [$link, $status];
        if ($desktop) { $sets[] = 'image_desktop = ?'; $params[] = $desktop; }
        if ($mobile)  { $sets[] = 'image_mobile = ?';  $params[] = $mobile; }
        $pdo->prepare("UPDATE ecom_home_festive_banner SET " . implode(', ', $sets) . " WHERE id = 1")->execute($params);
        $notifications[] = ['type' => 'success', 'message' => 'Festive banner save ho gaya.'];
    }

    // ═══════════════════════ TAB 6 — CATEGORY SECTIONS ═══════════════════════
    elseif ($action === 'add_category_section') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        if ($cat_id <= 0) {
            $notifications[] = ['type' => 'error', 'message' => 'Category chuno.'];
        } else {
            $exists = $pdo->prepare("SELECT id FROM ecom_home_category_sections WHERE category_id = ?");
            $exists->execute([$cat_id]);
            if ($exists->fetch()) {
                $notifications[] = ['type' => 'error', 'message' => 'Ye category pehle se add hai.'];
            } else {
                $max = (int) $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_category_sections")->fetchColumn();
                $pdo->prepare("INSERT INTO ecom_home_category_sections (category_id, sort_order) VALUES (?,?)")->execute([$cat_id, $max + 1]);
                $notifications[] = ['type' => 'success', 'message' => 'Category section add ho gaya.'];
            }
        }
    } elseif ($action === 'delete_category_section') {
        $csid = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM ecom_home_category_section_pins WHERE category_section_id = ?")->execute([$csid]);
        $pdo->prepare("DELETE FROM ecom_home_category_sections WHERE id = ?")->execute([$csid]);
        $notifications[] = ['type' => 'success', 'message' => 'Category section hata diya.'];
    } elseif ($action === 'toggle_category_section') {
        $pdo->prepare("UPDATE ecom_home_category_sections SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([(int)$_POST['id']]);
    } elseif ($action === 'move_category_section') {
        moveItem($pdo, 'ecom_home_category_sections', (int)$_POST['id'], $_POST['direction']);
    } elseif ($action === 'save_category_section_settings') {
        $csid = (int)$_POST['id'];
        $mode = in_array($_POST['sort_mode'] ?? '', ['latest', 'best_selling', 'most_viewed', 'manual'], true) ? $_POST['sort_mode'] : 'latest';
        $limit = max(1, min(50, (int)($_POST['product_limit'] ?? 10)));
        $pdo->prepare("UPDATE ecom_home_category_sections SET sort_mode = ?, product_limit = ? WHERE id = ?")->execute([$mode, $limit, $csid]);
        $notifications[] = ['type' => 'success', 'message' => 'Section settings save ho gayi.'];
    } elseif ($action === 'add_category_section_pin') {
        $csid = (int)$_POST['category_section_id'];
        $pid = (int)($_POST['product_id'] ?? 0);
        if ($pid <= 0) {
            $notifications[] = ['type' => 'error', 'message' => 'Product chuno.'];
        } else {
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM ecom_home_category_section_pins WHERE category_section_id = ?");
            $maxStmt->execute([$csid]);
            $max = (int)$maxStmt->fetchColumn();
            $pdo->prepare("INSERT INTO ecom_home_category_section_pins (category_section_id, product_id, sort_order) VALUES (?,?,?)")->execute([$csid, $pid, $max + 1]);
            $notifications[] = ['type' => 'success', 'message' => 'Product pin ho gaya.'];
        }
    } elseif ($action === 'delete_category_section_pin') {
        $pdo->prepare("DELETE FROM ecom_home_category_section_pins WHERE id = ?")->execute([(int)$_POST['id']]);
        $notifications[] = ['type' => 'success', 'message' => 'Pin hata diya.'];
    }

    // ═══════════════════════ TAB 7 — TYPOGRAPHY ═══════════════════════
    elseif ($action === 'save_typography') {
        $keys = ['product_title', 'product_price', 'section_heading', 'category_strip_label', 'trending_label'];
        foreach ($keys as $k) {
            $d = max(8, min(60, (int)($_POST["{$k}_desktop"] ?? 14)));
            $m = max(8, min(60, (int)($_POST["{$k}_mobile"] ?? 13)));
            $pdo->prepare("UPDATE ecom_home_typography SET desktop_px = ?, mobile_px = ? WHERE setting_key = ?")->execute([$d, $m, $k]);
        }
        $notifications[] = ['type' => 'success', 'message' => 'Typography settings save ho gayi.'];
    }

    // ═══════════════════════ GENERAL — section visibility/order + misc ═══════════════════════
    elseif ($action === 'save_general_settings') {
        foreach (['show_slider', 'show_category_strip', 'show_trending', 'show_popular', 'show_festive_banner', 'show_category_sections'] as $k) {
            saveV5Setting($pdo, $k, isset($_POST[$k]) ? '1' : '0');
        }
        saveV5Setting($pdo, 'hide_out_of_stock', isset($_POST['hide_out_of_stock']) ? '1' : '0');
        $notifications[] = ['type' => 'success', 'message' => 'General settings save ho gayi.'];
    } elseif ($action === 'save_header_info') {
        saveHomeSetting($pdo, 'show_location', isset($_POST['show_location']) ? '1' : '0');
        saveHomeSetting($pdo, 'show_delivery_info', isset($_POST['show_delivery_info']) ? '1' : '0');
        $delivery_label = trim($_POST['delivery_label'] ?? '');
        saveHomeSetting($pdo, 'delivery_label', $delivery_label !== '' ? $delivery_label : "We're open");
        // Optional override for the time text next to the clock icon (e.g. "Today 12:00 PM - 03:00 PM").
        // Left blank => falls back to Business Settings' business_hours, same as before.
        saveHomeSetting($pdo, 'delivery_time_text', trim($_POST['delivery_time_text'] ?? ''));
        $notifications[] = ['type' => 'success', 'message' => 'Header info settings save ho gayi.'];
    } elseif ($action === 'save_section_order') {
        $order = $_POST['section_order'] ?? [];
        if (is_array($order)) {
            saveV5Setting($pdo, 'section_order', implode(',', array_map('trim', $order)));
            $notifications[] = ['type' => 'success', 'message' => 'Homepage section order save ho gaya.'];
        }
    }

    $_SESSION['home_cms_flash'] = $notifications;
    header('Location: /admin/ecommerce/homepage-settings.php?tab=' . urlencode($_POST['return_tab'] ?? 'general'));
    exit;
}

if (!empty($_SESSION['home_cms_flash'])) {
    $notifications = $_SESSION['home_cms_flash'];
    unset($_SESSION['home_cms_flash']);
}

$active_tab = $_GET['tab'] ?? 'general';

// ── Settings ──
$v5 = [];
foreach ($pdo->query("SELECT * FROM ecom_home_settings_v5") as $row) { $v5[$row['setting_key']] = $row['setting_value']; }
$section_order = array_values(array_filter(explode(',', $v5['section_order'] ?? 'slider,category_strip,trending,popular,festive_banner,category_sections')));
$SECTION_LABELS = [
    'slider'            => 'Banner Slider',
    'category_strip'    => 'Category Strip',
    'trending'          => 'Trending / Featured Sections',
    'popular'           => 'Popular Products',
    'festive_banner'    => 'Festive Banner',
    'category_sections' => 'Category Product Sections',
];

// ── Tab 1: Slider (content-style — 10 ready-made look/color templates) ──
$slides = $pdo->query("SELECT * FROM ecom_home_slider ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$SLIDE_TEMPLATES = [
    'tpl-1'  => 'Sunny Yellow',
    'tpl-2'  => 'Mint Green',
    'tpl-3'  => 'Sky Blue',
    'tpl-4'  => 'Coral Pink',
    'tpl-5'  => 'Royal Purple',
    'tpl-6'  => 'Peach Orange',
    'tpl-7'  => 'Ocean Teal',
    'tpl-8'  => 'Rose Red',
    'tpl-9'  => 'Charcoal Dark',
    'tpl-10' => 'Lime Fresh',
];

// ── Tab 2: Category strip ──
$strip_items = $pdo->query("
    SELECT hcs.*, c.name AS cat_name, c.slug AS cat_slug, c.image AS cat_image
    FROM ecom_home_category_strip_v5 hcs
    LEFT JOIN ecom_categories c ON hcs.category_id = c.id
    ORDER BY hcs.sort_order ASC, hcs.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
$strip_mode = $v5['home_strip_mode'] ?? 'all';
$strip_limit = (int)($v5['home_strip_limit'] ?? 10);

// ── Tab 3: Trending sections ──
$trending_sections = $pdo->query("SELECT * FROM ecom_home_trending_sections ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$trending_items_by_section = [];
foreach ($trending_sections as $ts) {
    $ti = $pdo->prepare("
        SELECT tti.*, p.name AS prod_name, p.image AS prod_image, c.name AS cat_name, c.image AS cat_image
        FROM ecom_home_trending_items tti
        LEFT JOIN ecom_products p ON tti.product_id = p.id
        LEFT JOIN ecom_categories c ON tti.category_id = c.id
        WHERE tti.section_id = ? ORDER BY tti.sort_order ASC, tti.id ASC
    ");
    $ti->execute([$ts['id']]);
    $trending_items_by_section[$ts['id']] = $ti->fetchAll(PDO::FETCH_ASSOC);
}

// ── Tab 4: Popular products ──
$popular_mode = $v5['home_popular_mode'] ?? 'best_selling';
$popular_limit = (int)($v5['home_popular_limit'] ?? 10);
$popular_pins = $pdo->query("
    SELECT hpp.*, p.name AS prod_name, p.image AS prod_image, p.price, p.sale_price, p.view_count, p.purchase_count
    FROM ecom_home_popular_pins hpp
    JOIN ecom_products p ON hpp.product_id = p.id
    ORDER BY hpp.sort_order ASC, hpp.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Tab 5: Festive banner ──
$festive = $pdo->query("SELECT * FROM ecom_home_festive_banner WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Tab 6: Category sections ──
$category_sections = $pdo->query("
    SELECT hcs2.*, c.name AS cat_name, c.slug AS cat_slug
    FROM ecom_home_category_sections hcs2
    JOIN ecom_categories c ON hcs2.category_id = c.id
    ORDER BY hcs2.sort_order ASC, hcs2.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
$category_section_pins_by_id = [];
foreach ($category_sections as $cs) {
    $csp = $pdo->prepare("
        SELECT csp.*, p.name AS prod_name, p.image AS prod_image
        FROM ecom_home_category_section_pins csp
        JOIN ecom_products p ON csp.product_id = p.id
        WHERE csp.category_section_id = ? ORDER BY csp.sort_order ASC, csp.id ASC
    ");
    $csp->execute([$cs['id']]);
    $category_section_pins_by_id[$cs['id']] = $csp->fetchAll(PDO::FETCH_ASSOC);
}

// ── Tab 7: Typography ──
$typography = [];
foreach ($pdo->query("SELECT * FROM ecom_home_typography") as $row) { $typography[$row['setting_key']] = $row; }

// ── Shared lookups ──
$categories = $pdo->query("SELECT id, name, slug, image FROM ecom_categories WHERE status='active' ORDER BY serial ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$categories_used_in_sections = array_column($category_sections, 'category_id');
$categories_available_for_sections = array_filter($categories, fn($c) => !in_array($c['id'], $categories_used_in_sections));
$product_search_list = $pdo->query("SELECT id, name, image FROM ecom_products WHERE status='active' ORDER BY name ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$hide_out_of_stock = ($v5['hide_out_of_stock'] ?? '0') === '1';

// ── Header info (location + delivery-time shown next to the logo, desktop header) ──
$home_settings = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM ecom_home_settings") as $row) {
    $home_settings[$row['setting_key']] = $row['setting_value'];
}
$show_location_hdr      = ($home_settings['show_location'] ?? '1') === '1';
$show_delivery_info_hdr = ($home_settings['show_delivery_info'] ?? '1') === '1';
$delivery_label_hdr     = $home_settings['delivery_label'] ?? "We're open";
$delivery_time_text_hdr = $home_settings['delivery_time_text'] ?? '';
$biz_for_header = $pdo->query("SELECT location, business_hours FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

$page_title = 'Homepage Customizer';
$page_subtitle = 'Manage every section shown on your storefront homepage — live and dynamic, just like the WordPress Customizer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/components/ecom-head.php'; ?>
<style>
/* ═══════════════════════════ Homepage Customizer — WP-Customizer-style shell ═══════════════════════════ */
.hpc-topbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.65rem; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
.hpc-topbar h1 { font-size: 1.25rem; font-weight: 800; margin: 0; color: var(--gray-800); }
.hpc-topbar p { margin: 0.15rem 0 0; font-size: 0.825rem; color: var(--gray-500); }

.hpc-shell { display: flex; align-items: flex-start; gap: 0; background: #fff; border: 1px solid var(--gray-200); border-radius: 0.75rem; overflow: hidden; min-height: 640px; }

.hpc-tabs { flex: 0 0 260px; max-width: 260px; border-right: 1px solid var(--gray-200); background: #fafaff; padding: 0.85rem 0.6rem; display: flex; flex-direction: column; gap: 0.2rem; }
.hpc-tab-btn { display: flex; align-items: center; gap: 0.7rem; width: 100%; padding: 0.7rem 0.85rem; border-radius: 0.55rem; border: none; background: none; font-size: 0.875rem; font-weight: 600; color: var(--gray-600); text-decoration: none; text-align: left; cursor: pointer; transition: background .12s ease, color .12s ease; }
.hpc-tab-btn i.tab-ic { width: 18px; text-align: center; color: var(--gray-400); flex-shrink: 0; }
.hpc-tab-btn .chev { margin-left: auto; opacity: 0; font-size: 0.75rem; transition: opacity .12s ease; }
.hpc-tab-btn:hover { background: #f1edff; color: var(--gray-800); }
.hpc-tab-btn:hover i.tab-ic { color: var(--primary); }
.hpc-tab-btn.active { background: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(124,58,237,.25); }
.hpc-tab-btn.active i.tab-ic { color: #fff; }
.hpc-tab-btn.active .chev { opacity: 1; }
.hpc-tab-hide-badge { margin-left: auto; font-size: 0.65rem; font-weight: 700; background: var(--gray-200); color: var(--gray-600); padding: 0.1rem 0.4rem; border-radius: 9999px; }
.hpc-tab-btn.active .hpc-tab-hide-badge { background: rgba(255,255,255,.25); color: #fff; }

.hpc-panel { flex: 1 1 auto; min-width: 0; padding: 1.75rem 2rem; }
.hpc-panel-header { margin-bottom: 1.5rem; display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.hpc-panel-header h2 { font-size: 1.15rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--gray-800); }
.hpc-panel-header h2 i { color: var(--primary); }
.hpc-panel-header p { margin: 0.3rem 0 0; font-size: 0.85rem; color: var(--gray-500); }

.hpc-section-block { margin-bottom: 1.75rem; }
.hpc-section-block:last-child { margin-bottom: 0; }
.hpc-section-title { font-size: 0.95rem; font-weight: 700; color: var(--gray-800); margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.5rem; }

.hpc-check-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.9rem 1.5rem; }
.hpc-check-card { display: flex; align-items: center; gap: 0.65rem; padding: 0.85rem 1rem; border: 1px solid var(--gray-200); border-radius: 0.55rem; background: #fff; cursor: pointer; transition: border-color .12s ease, background .12s ease; }
.hpc-check-card:hover { border-color: var(--primary); background: #faf8ff; }
.hpc-check-card input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--primary); flex-shrink: 0; cursor: pointer; }
.hpc-check-card label { font-size: 0.875rem; font-weight: 600; color: var(--gray-700); margin: 0; cursor: pointer; }

.hpc-save-bar { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--gray-100); }
.hpc-save-bar .btn-primary { padding: 0.6rem 1.5rem; font-weight: 700; }

.hpc-hint { font-size: 0.775rem; color: var(--gray-500); margin-top: 0.3rem; }
.hpc-recommend { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-weight: 700; background: #eef2ff; color: #3730a3; padding: 0.25rem 0.6rem; border-radius: 0.4rem; margin-top: 0.35rem; }

@media (max-width: 900px) {
    .hpc-shell { flex-direction: column; }
    .hpc-tabs { flex: 1 1 auto; max-width: 100%; flex-direction: row; overflow-x: auto; border-right: none; border-bottom: 1px solid var(--gray-200); }
    .hpc-tab-btn { flex-shrink: 0; }
    .hpc-tab-btn .chev { display: none; }
    .hpc-panel { padding: 1.25rem 1rem; }
    .hpc-check-grid { grid-template-columns: 1fr; }
}

.home-item-row { display: flex; gap: 1rem; align-items: center; border: 1px solid var(--gray-200); border-radius: 0.5rem; padding: 0.85rem; margin-bottom: 0.75rem; background: #fff; }
.home-item-thumb { width: 90px; height: 60px; border-radius: 0.4rem; object-fit: cover; background: var(--gray-50); flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.home-item-thumb-pair { display: flex; gap: 0.4rem; flex-shrink: 0; }
.home-item-thumb-pair .home-item-thumb { width: 68px; }
.home-item-thumb-pair span { position: absolute; font-size: 0.6rem; }
.home-item-icon-thumb { width: 52px; height: 52px; border-radius: 0.6rem; object-fit: cover; background: var(--gray-50); flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.home-item-icon-thumb.circle-preview-c1 { background:#2f6fed; color:#fff; border-radius: 50%; }
.home-item-icon-thumb.circle-preview-c2 { background:#dff2e3; border-radius: 50%; }
.home-item-icon-thumb.circle-preview-c3 { background:#fdf1d8; border-radius: 50%; }
.home-item-icon-thumb.circle-preview-c4 { background:#fbe4ea; border-radius: 50%; }
.home-item-icon-thumb.circle-preview-c5 { background:#222; color:#fff; border-radius: 50%; }
.home-item-icon-thumb.circle-preview-c6 { background:#e5f6e8; border-radius: 50%; }
.home-item-icon-thumb.circle-preview-c7 { background:#eaf1ff; border-radius: 50%; }
.home-item-body { flex: 1; min-width: 0; }
.home-item-actions { display: flex; gap: 0.35rem; flex-shrink: 0; }
.home-item-row.inactive { opacity: 0.5; }
.hpc-mini-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; color: var(--gray-400); letter-spacing: 0.02em; }
.hpc-subsection-card { border: 1px solid var(--gray-200); border-radius: 0.6rem; padding: 1rem 1.1rem; margin-bottom: 1rem; background: #fdfdff; }
.hpc-subsection-card .hpc-subsection-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.75rem; }
.hpc-subsection-card .hpc-subsection-head h4 { font-size: 0.9rem; font-weight: 700; margin: 0; color: var(--gray-800); }
.order-handle-row { display: flex; align-items: center; gap: 0.6rem; padding: 0.6rem 0.85rem; border: 1px solid var(--gray-200); border-radius: 0.5rem; margin-bottom: 0.5rem; background: #fff; }
.order-handle-row .drag-num { width: 24px; height: 24px; border-radius: 50%; background: var(--primary-lighter); color: var(--primary); font-size: 0.75rem; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.order-handle-row .name { flex: 1; font-size: 0.85rem; font-weight: 600; color: var(--gray-700); }

/* ===== Slide templates — must match the gradient list in shop/includes/shop-header.php ===== */
.hpc-template-picker { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.3rem; }
.hpc-template-opt { display: flex; align-items: center; gap: 0.4rem; border: 2px solid var(--gray-200); border-radius: 0.5rem; padding: 0.4rem 0.6rem 0.4rem 0.4rem; cursor: pointer; font-size: 0.78rem; font-weight: 600; color: var(--gray-700); }
.hpc-template-opt input { margin: 0; }
.hpc-template-opt:has(input:checked) { border-color: var(--primary); background: var(--primary-lighter); }
.hpc-template-swatch { width: 22px; height: 22px; border-radius: 5px; flex-shrink: 0; }
.hpc-slide-preview { display: flex; align-items: center; justify-content: center; }
.hpc-template-opt.tpl-1 .hpc-template-swatch, .hpc-slide-preview.tpl-1  { background: linear-gradient(120deg,#ffe27a,#ffd23f); }
.hpc-template-opt.tpl-2 .hpc-template-swatch, .hpc-slide-preview.tpl-2  { background: linear-gradient(120deg,#bfe9c9,#e8f8ec); }
.hpc-template-opt.tpl-3 .hpc-template-swatch, .hpc-slide-preview.tpl-3  { background: linear-gradient(120deg,#cfe3ff,#eef6ff); }
.hpc-template-opt.tpl-4 .hpc-template-swatch, .hpc-slide-preview.tpl-4  { background: linear-gradient(120deg,#ffd0d8,#ffe9ee); }
.hpc-template-opt.tpl-5 .hpc-template-swatch, .hpc-slide-preview.tpl-5  { background: linear-gradient(120deg,#e0d4ff,#f2ecff); }
.hpc-template-opt.tpl-6 .hpc-template-swatch, .hpc-slide-preview.tpl-6  { background: linear-gradient(120deg,#ffd9b3,#fff0e0); }
.hpc-template-opt.tpl-7 .hpc-template-swatch, .hpc-slide-preview.tpl-7  { background: linear-gradient(120deg,#a8e6e0,#e0f7f5); }
.hpc-template-opt.tpl-8 .hpc-template-swatch, .hpc-slide-preview.tpl-8  { background: linear-gradient(120deg,#ffb3ba,#ffe3e5); }
.hpc-template-opt.tpl-9 .hpc-template-swatch, .hpc-slide-preview.tpl-9  { background: linear-gradient(120deg,#3a3f47,#585f6b); }
.hpc-template-opt.tpl-10 .hpc-template-swatch, .hpc-slide-preview.tpl-10 { background: linear-gradient(120deg,#d4f7a0,#eefcd8); }
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
                <a href="/shop/" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> View Homepage</a>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);"><i class="fas fa-moon"></i></button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-<?= $n['type'] === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($n['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <div class="hpc-topbar">
                <div>
                    <h1><i class="fas fa-palette" style="color:var(--primary);"></i> Homepage Customizer</h1>
                    <p>Har section control karo — live aur dynamic, WordPress Customizer jaisa full control.</p>
                </div>
                <a href="/shop/" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> Preview Homepage</a>
            </div>

            <div class="hpc-shell">
                <nav class="hpc-tabs">
                    <a href="?tab=general" class="hpc-tab-btn <?= $active_tab === 'general' ? 'active' : '' ?>"><i class="fas fa-sliders-h tab-ic"></i> General <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=slider" class="hpc-tab-btn <?= $active_tab === 'slider' ? 'active' : '' ?>"><i class="fas fa-images tab-ic"></i> 1. Slider <?php if (($v5['show_slider'] ?? '1') === '0'): ?><span class="hpc-tab-hide-badge">Hidden</span><?php endif; ?> <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=strip" class="hpc-tab-btn <?= $active_tab === 'strip' ? 'active' : '' ?>"><i class="fas fa-th tab-ic"></i> 2. Category Strip <?php if (($v5['show_category_strip'] ?? '1') === '0'): ?><span class="hpc-tab-hide-badge">Hidden</span><?php endif; ?> <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=trending" class="hpc-tab-btn <?= $active_tab === 'trending' ? 'active' : '' ?>"><i class="fas fa-fire tab-ic"></i> 3. Trending Sections <?php if (($v5['show_trending'] ?? '1') === '0'): ?><span class="hpc-tab-hide-badge">Hidden</span><?php endif; ?> <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=popular" class="hpc-tab-btn <?= $active_tab === 'popular' ? 'active' : '' ?>"><i class="fas fa-star tab-ic"></i> 4. Popular Products <?php if (($v5['show_popular'] ?? '1') === '0'): ?><span class="hpc-tab-hide-badge">Hidden</span><?php endif; ?> <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=festive" class="hpc-tab-btn <?= $active_tab === 'festive' ? 'active' : '' ?>"><i class="fas fa-gift tab-ic"></i> 5. Festive Banner <?php if (($v5['show_festive_banner'] ?? '1') === '0'): ?><span class="hpc-tab-hide-badge">Hidden</span><?php endif; ?> <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=catsections" class="hpc-tab-btn <?= $active_tab === 'catsections' ? 'active' : '' ?>"><i class="fas fa-layer-group tab-ic"></i> 6. Category Sections <?php if (($v5['show_category_sections'] ?? '1') === '0'): ?><span class="hpc-tab-hide-badge">Hidden</span><?php endif; ?> <i class="fas fa-chevron-right chev"></i></a>
                    <a href="?tab=typography" class="hpc-tab-btn <?= $active_tab === 'typography' ? 'active' : '' ?>"><i class="fas fa-text-height tab-ic"></i> 7. Typography <i class="fas fa-chevron-right chev"></i></a>
                </nav>

                <div class="hpc-panel">

            <?php if ($active_tab === 'general'): ?>
            <!-- ══════════════ GENERAL TAB ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-sliders-h"></i> General</h2>
                <p>Har section ko show/hide karo, aur unka order set karo. Neeche jo order hai wahi homepage par dikhega.</p>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_general_settings">
                <input type="hidden" name="return_tab" value="general">
                <div class="hpc-section-block">
                    <div class="hpc-section-title"><i class="fas fa-eye"></i> Section Visibility</div>
                    <div class="hpc-check-grid">
                        <div class="hpc-check-card"><input type="checkbox" name="show_slider" id="show_slider" <?= ($v5['show_slider'] ?? '1') === '1' ? 'checked' : '' ?>><label for="show_slider">1. Banner Slider</label></div>
                        <div class="hpc-check-card"><input type="checkbox" name="show_category_strip" id="show_category_strip" <?= ($v5['show_category_strip'] ?? '1') === '1' ? 'checked' : '' ?>><label for="show_category_strip">2. Category Strip</label></div>
                        <div class="hpc-check-card"><input type="checkbox" name="show_trending" id="show_trending" <?= ($v5['show_trending'] ?? '1') === '1' ? 'checked' : '' ?>><label for="show_trending">3. Trending / Featured Sections</label></div>
                        <div class="hpc-check-card"><input type="checkbox" name="show_popular" id="show_popular" <?= ($v5['show_popular'] ?? '1') === '1' ? 'checked' : '' ?>><label for="show_popular">4. Popular Products</label></div>
                        <div class="hpc-check-card"><input type="checkbox" name="show_festive_banner" id="show_festive_banner" <?= ($v5['show_festive_banner'] ?? '1') === '1' ? 'checked' : '' ?>><label for="show_festive_banner">5. Festive Banner</label></div>
                        <div class="hpc-check-card"><input type="checkbox" name="show_category_sections" id="show_category_sections" <?= ($v5['show_category_sections'] ?? '1') === '1' ? 'checked' : '' ?>><label for="show_category_sections">6. Category Product Sections</label></div>
                    </div>
                </div>
                <div class="hpc-section-block">
                    <div class="hpc-section-title"><i class="fas fa-box"></i> Products</div>
                    <div class="hpc-check-card" style="max-width:420px;">
                        <input type="checkbox" name="hide_out_of_stock" id="hide_out_of_stock" <?= $hide_out_of_stock ? 'checked' : '' ?>>
                        <label for="hide_out_of_stock">Out-of-stock products storewide hide karo</label>
                    </div>
                </div>
                <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
            </form>

            <div class="hpc-section-block" style="margin-top:2rem;">
                <div class="hpc-section-title"><i class="fas fa-map-marker-alt"></i> Header Info (location + delivery time)</div>
                <p class="hpc-hint" style="margin-bottom:1rem;">Yeh logo ke bagal me header par dikhta hai — location pin aur "delivery available" wala time. Address / business hours <a href="/admin/ecommerce/business-settings.php">Business Settings</a> se aata hai, yahan sirf show/hide aur label control hota hai.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="save_header_info">
                    <input type="hidden" name="return_tab" value="general">
                    <div class="hpc-check-grid">
                        <div class="hpc-check-card">
                            <input type="checkbox" name="show_location" id="show_location" <?= $show_location_hdr ? 'checked' : '' ?>>
                            <label for="show_location">Location dikhao (header par)</label>
                        </div>
                        <div class="hpc-check-card">
                            <input type="checkbox" name="show_delivery_info" id="show_delivery_info" <?= $show_delivery_info_hdr ? 'checked' : '' ?>>
                            <label for="show_delivery_info">Delivery time dikhao (header par)</label>
                        </div>
                    </div>
                    <?php if (empty($biz_for_header['location'])): ?>
                    <p class="hpc-hint" style="color:#b45309;margin-top:.5rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Location abhi <a href="/admin/ecommerce/business-settings.php">Business Settings</a> me set nahi hai — location block header par tab tak nahi dikhega, chahe toggle "on" ho.
                    </p>
                    <?php endif; ?>
                    <div class="hpc-check-grid" style="margin-top:1rem;">
                        <div style="max-width:420px;">
                            <label for="delivery_label" style="font-weight:600;font-size:.85rem;display:block;margin-bottom:.35rem;">Delivery label text</label>
                            <input type="text" name="delivery_label" id="delivery_label" class="form-control" placeholder="e.g. We're open / Earliest Home Delivery available" value="<?= htmlspecialchars($delivery_label_hdr) ?>">
                        </div>
                        <div style="max-width:420px;">
                            <label for="delivery_time_text" style="font-weight:600;font-size:.85rem;display:block;margin-bottom:.35rem;">Delivery time text</label>
                            <input type="text" name="delivery_time_text" id="delivery_time_text" class="form-control" placeholder="e.g. Today 12:00 PM - 03:00 PM" value="<?= htmlspecialchars($delivery_time_text_hdr) ?>">
                            <p class="hpc-hint" style="margin-top:.35rem;">
                                Yahan khaali chhodoge to <a href="/admin/ecommerce/business-settings.php">Business Settings</a> ke "Business Hours" wala text apne aap use hoga<?= !empty($biz_for_header['business_hours']) ? ' (abhi: "' . htmlspecialchars($biz_for_header['business_hours']) . '")' : '' ?>. Yahan kuch likhoge to sirf header ke is delivery-time text ke liye wahi use hoga, Business Settings wala change nahi hoga.
                            </p>
                        </div>
                    </div>
                    <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
                </form>
            </div>

            <div class="hpc-section-block" style="margin-top:2rem;">
                <div class="hpc-section-title"><i class="fas fa-arrows-alt-v"></i> Section Order</div>
                <p class="hpc-hint" style="margin-bottom:1rem;">Upar-neeche arrow se order badlo — homepage par sections isi order mein dikhenge.</p>
                <form method="POST" id="sectionOrderForm">
                    <input type="hidden" name="action" value="save_section_order">
                    <input type="hidden" name="return_tab" value="general">
                    <div id="sectionOrderList">
                        <?php foreach ($section_order as $i => $key): if (!isset($SECTION_LABELS[$key])) continue; ?>
                        <div class="order-handle-row" data-key="<?= htmlspecialchars($key) ?>">
                            <span class="drag-num"><?= $i + 1 ?></span>
                            <span class="name"><?= htmlspecialchars($SECTION_LABELS[$key]) ?></span>
                            <input type="hidden" name="section_order[]" value="<?= htmlspecialchars($key) ?>">
                            <div class="home-item-actions">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveOrderRow(this,-1)"><i class="fas fa-chevron-up"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveOrderRow(this,1)"><i class="fas fa-chevron-down"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Order</button></div>
                </form>
            </div>
            <script>
            function moveOrderRow(btn, dir) {
                const row = btn.closest('.order-handle-row');
                const list = document.getElementById('sectionOrderList');
                const rows = Array.from(list.children);
                const idx = rows.indexOf(row);
                const swapIdx = idx + dir;
                if (swapIdx < 0 || swapIdx >= rows.length) return;
                if (dir < 0) list.insertBefore(row, rows[swapIdx]);
                else list.insertBefore(rows[swapIdx], row);
                Array.from(list.children).forEach((r, i) => { r.querySelector('.drag-num').textContent = i + 1; });
            }
            </script>
            <?php endif; ?>

            <?php if ($active_tab === 'slider'): ?>
            <!-- ══════════════ TAB 1 — SLIDER (content-style, like demo-home.html) ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-images"></i> 1. Banner Slider</h2>
                <p>Yeh ab demo jaisa <strong>content slider</strong> hai — koi banner image upload nahi karni. Heading, chhoti line, button aur ek icon/emoji daalo, aur neeche se ek ready-made template (color/look) chuno. Mobile-desktop dono par automatically sahi size me dikhega.</p>
            </div>

            <div class="hpc-subsection-card">
                <div class="hpc-subsection-head"><h4><i class="fas fa-plus-circle"></i> Naya slide add karo</h4></div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_slide">
                    <input type="hidden" name="return_tab" value="slider">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Badge / top tag (optional)</label>
                            <input type="text" class="form-control" name="badge_text" placeholder="Deal of the day">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Heading *</label>
                            <input type="text" class="form-control" name="heading" placeholder="The Great Kitchen Fest" required>
                            <div class="hpc-recommend">Do line tak theek dikhta hai, jyada lamba na likho.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Chhoti line (subtext)</label>
                            <input type="text" class="form-control" name="subtext" placeholder="Best range. Best offers.">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Button text</label>
                            <input type="text" class="form-control" name="button_text" placeholder="SHOP NOW" value="SHOP NOW">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Button link (click karne par kahan jaye)</label>
                            <input type="text" class="form-control" name="link" placeholder="/shop/category.php?slug=... ya #">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Icon (emoji)</label>
                            <input type="text" class="form-control" name="icon_emoji" placeholder="🍳" maxlength="10">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Icon image (optional)</label>
                            <input type="file" class="form-control" name="icon_image" accept="image/*">
                            <div class="hpc-recommend">Emoji ki jagah apni image dikhani ho to yaha upload karo.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Template (look/color)</label>
                            <div class="hpc-template-picker">
                                <?php foreach ($SLIDE_TEMPLATES as $tkey => $tlabel): ?>
                                <label class="hpc-template-opt <?= $tkey ?>">
                                    <input type="radio" name="template" value="<?= $tkey ?>" <?= $tkey === 'tpl-1' ? 'checked' : '' ?>>
                                    <span class="hpc-template-swatch"></span>
                                    <span class="hpc-template-name"><?= htmlspecialchars($tlabel) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Slide</button></div>
                </form>
            </div>

            <div class="hpc-section-title" style="margin-top:1.5rem;"><i class="fas fa-list"></i> Current Slides (<?= count($slides) ?>)</div>
            <?php foreach ($slides as $s): ?>
            <div class="home-item-row <?= $s['status'] !== 'active' ? 'inactive' : '' ?>">
                <div class="home-item-thumb-pair">
                    <div class="home-item-thumb hpc-slide-preview <?= htmlspecialchars($s['template'] ?: 'tpl-1') ?>">
                        <?php if (!empty($s['icon_image'])): ?><img src="/<?= htmlspecialchars($s['icon_image']) ?>" style="width:100%;height:100%;object-fit:cover;"><?php elseif (!empty($s['icon_emoji'])): ?><span style="font-size:20px;"><?= htmlspecialchars($s['icon_emoji']) ?></span><?php else: ?><i class="fas fa-image text-muted"></i><?php endif; ?>
                    </div>
                </div>
                <div class="home-item-body">
                    <div class="fw-semibold"><?= htmlspecialchars($s['heading'] ?: ('Slide #' . $s['id'])) ?> <?= $s['status'] !== 'active' ? '<span class="badge bg-secondary">Hidden</span>' : '' ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($s['subtext'] ?? '') ?> — Link: <?= htmlspecialchars($s['link']) ?></div>
                </div>
                <div class="home-item-actions">
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_slide"><input type="hidden" name="return_tab" value="slider"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-up"></i></button></form>
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_slide"><input type="hidden" name="return_tab" value="slider"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-down"></i></button></form>
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_slide"><input type="hidden" name="return_tab" value="slider"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye<?= $s['status'] === 'active' ? '-slash' : '' ?>"></i></button></form>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSlide<?= $s['id'] ?>"><i class="fas fa-edit"></i></button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this slide?')"><input type="hidden" name="action" value="delete_slide"><input type="hidden" name="return_tab" value="slider"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
                </div>
            </div>
            <div class="modal fade" id="editSlide<?= $s['id'] ?>" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit_slide"><input type="hidden" name="return_tab" value="slider"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <div class="modal-header"><h5 class="modal-title">Edit Slide #<?= $s['id'] ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <label class="form-label">Badge / top tag</label>
                            <input type="text" class="form-control mb-2" name="badge_text" value="<?= htmlspecialchars($s['badge_text'] ?? '') ?>">
                            <label class="form-label">Heading *</label>
                            <input type="text" class="form-control mb-2" name="heading" value="<?= htmlspecialchars($s['heading'] ?? '') ?>" required>
                            <label class="form-label">Chhoti line (subtext)</label>
                            <input type="text" class="form-control mb-2" name="subtext" value="<?= htmlspecialchars($s['subtext'] ?? '') ?>">
                            <label class="form-label">Button text</label>
                            <input type="text" class="form-control mb-2" name="button_text" value="<?= htmlspecialchars($s['button_text'] ?? '') ?>">
                            <label class="form-label">Button link</label>
                            <input type="text" class="form-control mb-2" name="link" value="<?= htmlspecialchars($s['link']) ?>">
                            <label class="form-label">Icon (emoji)</label>
                            <input type="text" class="form-control mb-2" name="icon_emoji" value="<?= htmlspecialchars($s['icon_emoji'] ?? '') ?>" maxlength="10">
                            <label class="form-label">Icon image (naya upload karo replace karne ke liye)</label>
                            <input type="file" class="form-control mb-3" name="icon_image" accept="image/*">
                            <label class="form-label">Template</label>
                            <div class="hpc-template-picker">
                                <?php foreach ($SLIDE_TEMPLATES as $tkey => $tlabel): ?>
                                <label class="hpc-template-opt <?= $tkey ?>">
                                    <input type="radio" name="template" value="<?= $tkey ?>" <?= ($s['template'] ?: 'tpl-1') === $tkey ? 'checked' : '' ?>>
                                    <span class="hpc-template-swatch"></span>
                                    <span class="hpc-template-name"><?= htmlspecialchars($tlabel) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
                    </form>
                </div></div>
            </div>
            <?php endforeach; ?>
            <?php if (!$slides): ?><p class="text-muted">Koi slide nahi hai abhi. Upar se add karo.</p><?php endif; ?>
            <?php endif; ?>

            <?php if ($active_tab === 'strip'): ?>
            <!-- ══════════════ TAB 2 — CATEGORY STRIP ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-th"></i> 2. Category Strip</h2>
                <p>Slider ke neeche wali icon-row. Har icon ki apni category logo+naam hai, click karne par category khulti hai.</p>
            </div>

            <div class="hpc-subsection-card">
                <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.85rem;"><i class="fas fa-cog"></i> Mode</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="save_strip_settings">
                    <input type="hidden" name="return_tab" value="strip">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Kya dikhana hai</label>
                            <select class="form-select" name="strip_mode">
                                <option value="all" <?= $strip_mode === 'all' ? 'selected' : '' ?>>Saari categories (auto — by default)</option>
                                <option value="manual" <?= $strip_mode === 'manual' ? 'selected' : '' ?>>Manual — neeche jo add kiya wahi, isi order mein</option>
                                <option value="auto_popular" <?= $strip_mode === 'auto_popular' ? 'selected' : '' ?>>Automatic — jo category sabse jyada visit ho rahi hai wo pehle</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Kitna show karna hai</label>
                            <input type="number" class="form-control" name="strip_limit" value="<?= $strip_limit ?>" min="1" max="30">
                        </div>
                        <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Save</button></div>
                    </div>
                </form>
            </div>

            <div class="hpc-subsection-card">
                <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.85rem;"><i class="fas fa-plus-circle"></i> Manual item add karo (sirf "Manual" mode ke liye)</h4>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_strip_item">
                    <input type="hidden" name="return_tab" value="strip">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Real category (optional)</label>
                            <select class="form-select" name="category_id">
                                <option value="">— None / custom tile —</option>
                                <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label small">Emoji</label><input type="text" class="form-control" name="custom_emoji" placeholder="🛒"></div>
                        <div class="col-md-2"><label class="form-label small">Custom naam</label><input type="text" class="form-control" name="custom_label" placeholder="Switch"></div>
                        <div class="col-md-2"><label class="form-label small">Custom image</label><input type="file" class="form-control" name="custom_image" accept="image/*"></div>
                        <div class="col-md-2"><label class="form-label small">Custom link</label><input type="text" class="form-control" name="custom_link" placeholder="#"></div>
                    </div>
                    <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add</button></div>
                </form>
            </div>

            <div class="hpc-section-title"><i class="fas fa-list"></i> Manual items list (<?= count($strip_items) ?>)</div>
            <?php foreach ($strip_items as $it):
                $label = $it['custom_label'] ?: ($it['cat_name'] ?? '');
                $img = $it['custom_image'] ?: ($it['cat_image'] ?? '');
            ?>
            <div class="home-item-row">
                <?php if ($it['custom_emoji']): ?><div class="home-item-icon-thumb"><?= htmlspecialchars($it['custom_emoji']) ?></div>
                <?php elseif ($img): ?><img src="/<?= htmlspecialchars($img) ?>" class="home-item-icon-thumb">
                <?php else: ?><div class="home-item-icon-thumb"><i class="fas fa-image text-muted"></i></div><?php endif; ?>
                <div class="home-item-body">
                    <div class="fw-semibold"><?= htmlspecialchars($label) ?></div>
                    <div class="small text-muted"><?= $it['category_id'] ? 'Real category: ' . htmlspecialchars($it['cat_name']) : 'Custom tile' ?></div>
                </div>
                <div class="home-item-actions">
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_strip_item"><input type="hidden" name="return_tab" value="strip"><input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-up"></i></button></form>
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_strip_item"><input type="hidden" name="return_tab" value="strip"><input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-down"></i></button></form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Remove?')"><input type="hidden" name="action" value="delete_strip_item"><input type="hidden" name="return_tab" value="strip"><input type="hidden" name="id" value="<?= $it['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$strip_items): ?><p class="text-muted">Koi manual item nahi hai. "All categories" mode mein ye list use nahi hoti.</p><?php endif; ?>
            <?php endif; ?>

            <?php if ($active_tab === 'trending'): ?>
            <!-- ══════════════ TAB 3 — TRENDING / FEATURED SECTIONS ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-fire"></i> 3. Trending / Featured Sections</h2>
                <p>Jaise demo mein "Fresh items" / "Snacks & Drinks" hai — multiple sections bana sakte ho, har ek mein product/category/brand/manual tiles.</p>
            </div>

            <div class="hpc-subsection-card">
                <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.85rem;"><i class="fas fa-plus-circle"></i> Naya section banao</h4>
                <form method="POST" class="d-flex gap-2">
                    <input type="hidden" name="action" value="add_trending_section">
                    <input type="hidden" name="return_tab" value="trending">
                    <input type="text" class="form-control" name="title" placeholder="Jaise: Fresh items, Snacks & Drinks" required>
                    <button type="submit" class="btn btn-primary text-nowrap"><i class="fas fa-plus"></i> Add Section</button>
                </form>
            </div>

            <?php foreach ($trending_sections as $ts): $items = $trending_items_by_section[$ts['id']] ?? []; ?>
            <div class="hpc-subsection-card <?= $ts['status'] !== 'active' ? 'inactive' : '' ?>">
                <div class="hpc-subsection-head">
                    <form method="POST" class="d-flex gap-2 align-items-center flex-grow-1">
                        <input type="hidden" name="action" value="rename_trending_section">
                        <input type="hidden" name="return_tab" value="trending">
                        <input type="hidden" name="id" value="<?= $ts['id'] ?>">
                        <input type="text" class="form-control form-control-sm" style="max-width:280px;font-weight:700;" name="title" value="<?= htmlspecialchars($ts['title']) ?>">
                        <button class="btn btn-sm btn-outline-secondary" title="Rename"><i class="fas fa-check"></i></button>
                    </form>
                    <div class="home-item-actions">
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_trending_section"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $ts['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-up"></i></button></form>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_trending_section"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $ts['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-down"></i></button></form>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_trending_section"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $ts['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Show/Hide"><i class="fas fa-eye<?= $ts['status'] === 'active' ? '-slash' : '' ?>"></i></button></form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this whole section?')"><input type="hidden" name="action" value="delete_trending_section"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $ts['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="border rounded p-2 mb-2" style="background:#fff;">
                    <input type="hidden" name="action" value="add_trending_item">
                    <input type="hidden" name="return_tab" value="trending">
                    <input type="hidden" name="section_id" value="<?= $ts['id'] ?>">
                    <div class="row g-2">
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" name="item_type" onchange="this.closest('form').querySelectorAll('.tt-field').forEach(f=>f.style.display='none'); this.closest('form').querySelector('.tt-'+this.value).style.display='block';">
                                <option value="manual">Manual (name+image+link)</option>
                                <option value="product">Product</option>
                                <option value="category">Category</option>
                                <option value="brand">Brand</option>
                            </select>
                        </div>
                        <div class="col-md-3 tt-field tt-product" style="display:none;">
                            <select class="form-select form-select-sm" name="product_id">
                                <option value="">— Product chuno —</option>
                                <?php foreach ($product_search_list as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 tt-field tt-category" style="display:none;">
                            <select class="form-select form-select-sm" name="category_id">
                                <option value="">— Category chuno —</option>
                                <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 tt-field tt-manual tt-brand" style="display:block;">
                            <input type="text" class="form-control form-control-sm" name="custom_label" placeholder="Naam">
                        </div>
                        <div class="col-md-2 tt-field tt-manual tt-brand" style="display:block;">
                            <input type="file" class="form-control form-control-sm" name="custom_image" accept="image/*">
                        </div>
                        <div class="col-md-2 tt-field tt-manual tt-brand" style="display:block;">
                            <input type="text" class="form-control form-control-sm" name="custom_link" placeholder="Link / URL">
                        </div>
                        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-plus"></i></button></div>
                    </div>
                </form>

                <?php foreach ($items as $it):
                    $label = $it['custom_label'] ?: ($it['prod_name'] ?? $it['cat_name'] ?? '');
                    $img = $it['custom_image'] ?: ($it['prod_image'] ?? $it['cat_image'] ?? '');
                ?>
                <div class="home-item-row" style="margin-bottom:0.5rem;">
                    <?php if ($img): ?><img src="/<?= htmlspecialchars($img) ?>" class="home-item-thumb"><?php else: ?><div class="home-item-thumb"><i class="fas fa-image text-muted"></i></div><?php endif; ?>
                    <div class="home-item-body">
                        <div class="fw-semibold"><?= htmlspecialchars($label) ?></div>
                        <div class="small text-muted text-capitalize"><?= htmlspecialchars($it['item_type']) ?></div>
                    </div>
                    <div class="home-item-actions">
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_trending_item"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-up"></i></button></form>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_trending_item"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $it['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-down"></i></button></form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove?')"><input type="hidden" name="action" value="delete_trending_item"><input type="hidden" name="return_tab" value="trending"><input type="hidden" name="id" value="<?= $it['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$items): ?><p class="text-muted small mb-0">Koi item nahi. Upar se add karo.</p><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (!$trending_sections): ?><p class="text-muted">Koi section nahi hai. Upar se naya banao.</p><?php endif; ?>
            <?php endif; ?>

            <?php if ($active_tab === 'popular'): ?>
            <!-- ══════════════ TAB 4 — POPULAR PRODUCTS ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-star"></i> 4. Popular Products</h2>
                <p>Best-selling ya most-viewed pehle dikhao, ya khud manually order set karo aur specific products pin karo.</p>
            </div>

            <div class="hpc-subsection-card">
                <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.85rem;"><i class="fas fa-cog"></i> Sort Mode</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="save_popular_settings">
                    <input type="hidden" name="return_tab" value="popular">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Order kaise set ho</label>
                            <select class="form-select" name="popular_mode">
                                <option value="best_selling" <?= $popular_mode === 'best_selling' ? 'selected' : '' ?>>Best-selling pehle (jo sabse jyada bika)</option>
                                <option value="most_viewed" <?= $popular_mode === 'most_viewed' ? 'selected' : '' ?>>Most-viewed pehle (jispe jyada views aaye)</option>
                                <option value="manual" <?= $popular_mode === 'manual' ? 'selected' : '' ?>>Manual — sirf pinned products, isi order mein</option>
                            </select>
                            <p class="hpc-hint">Best-selling/Most-viewed mode mein, pinned products hamesha sabse pehle aayenge, baaki auto-fill honge.</p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Kitna show karna hai</label>
                            <input type="number" class="form-control" name="popular_limit" value="<?= $popular_limit ?>" min="1" max="50">
                        </div>
                        <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Save</button></div>
                    </div>
                </form>
            </div>

            <div class="hpc-subsection-card">
                <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.85rem;"><i class="fas fa-thumbtack"></i> Product pin karo</h4>
                <form method="POST" class="d-flex gap-2">
                    <input type="hidden" name="action" value="add_popular_pin">
                    <input type="hidden" name="return_tab" value="popular">
                    <select class="form-select" name="product_id" required>
                        <option value="">— Product chuno —</option>
                        <?php foreach ($product_search_list as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary text-nowrap"><i class="fas fa-thumbtack"></i> Pin</button>
                </form>
            </div>

            <div class="hpc-section-title"><i class="fas fa-list"></i> Pinned products (<?= count($popular_pins) ?>) — is order mein sabse pehle dikhenge</div>
            <?php foreach ($popular_pins as $pp): ?>
            <div class="home-item-row">
                <?php if ($pp['prod_image']): ?><img src="/<?= htmlspecialchars($pp['prod_image']) ?>" class="home-item-thumb"><?php else: ?><div class="home-item-thumb"><i class="fas fa-image text-muted"></i></div><?php endif; ?>
                <div class="home-item-body">
                    <div class="fw-semibold"><?= htmlspecialchars($pp['prod_name']) ?></div>
                    <div class="small text-muted">Views: <?= (int)$pp['view_count'] ?> · Sold: <?= (int)$pp['purchase_count'] ?></div>
                </div>
                <div class="home-item-actions">
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_popular_pin"><input type="hidden" name="return_tab" value="popular"><input type="hidden" name="id" value="<?= $pp['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-up"></i></button></form>
                    <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_popular_pin"><input type="hidden" name="return_tab" value="popular"><input type="hidden" name="id" value="<?= $pp['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-down"></i></button></form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Unpin?')"><input type="hidden" name="action" value="delete_popular_pin"><input type="hidden" name="return_tab" value="popular"><input type="hidden" name="id" value="<?= $pp['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-thumbtack"></i></button></form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$popular_pins): ?><p class="text-muted">Koi product pin nahi hai.</p><?php endif; ?>
            <?php endif; ?>

            <?php if ($active_tab === 'festive'): ?>
            <!-- ══════════════ TAB 5 — FESTIVE BANNER ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-gift"></i> 5. Festive Banner</h2>
                <p>Offer wala image-only banner. Mobile aur desktop ke liye ALAG image upload karo, warna responsive nahi dikhega.</p>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_festive_banner">
                <input type="hidden" name="return_tab" value="festive">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Desktop image</label>
                        <?php if (!empty($festive['image_desktop'])): ?><div class="mb-2"><img src="/<?= htmlspecialchars($festive['image_desktop']) ?>" style="max-width:280px;border-radius:8px;"></div><?php endif; ?>
                        <input type="file" class="form-control" name="image_desktop" accept="image/*">
                        <div class="hpc-recommend"><i class="fas fa-desktop"></i> Recommended: 1400 × 300px (wide banner)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mobile image</label>
                        <?php if (!empty($festive['image_mobile'])): ?><div class="mb-2"><img src="/<?= htmlspecialchars($festive['image_mobile']) ?>" style="max-width:280px;border-radius:8px;"></div><?php endif; ?>
                        <input type="file" class="form-control" name="image_mobile" accept="image/*">
                        <div class="hpc-recommend"><i class="fas fa-mobile-alt"></i> Recommended: 700 × 350px</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Link (optional)</label>
                        <input type="text" class="form-control" name="link" value="<?= htmlspecialchars($festive['link'] ?? '#') ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="hpc-check-card w-100">
                            <input type="checkbox" name="status" id="festive_status" <?= (($festive['status'] ?? 'inactive') === 'active') ? 'checked' : '' ?>>
                            <label for="festive_status">Banner show karo</label>
                        </div>
                    </div>
                </div>
                <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button></div>
            </form>
            <?php endif; ?>

            <?php if ($active_tab === 'catsections'): ?>
            <!-- ══════════════ TAB 6 — CATEGORY SECTIONS ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-layer-group"></i> 6. Category Product Sections</h2>
                <p>Har category ka apna product-row. Naye add karo, kitna dikhana hai set karo, aur order control karo (latest / best-selling / most-viewed / manual pin).</p>
            </div>

            <?php if ($categories_available_for_sections): ?>
            <div class="hpc-subsection-card">
                <h4 style="font-size:0.9rem;font-weight:700;margin-bottom:0.85rem;"><i class="fas fa-plus-circle"></i> Naya category section add karo</h4>
                <form method="POST" class="d-flex gap-2">
                    <input type="hidden" name="action" value="add_category_section">
                    <input type="hidden" name="return_tab" value="catsections">
                    <select class="form-select" name="category_id" required>
                        <option value="">— Category chuno —</option>
                        <?php foreach ($categories_available_for_sections as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary text-nowrap"><i class="fas fa-plus"></i> Add</button>
                </form>
            </div>
            <?php endif; ?>

            <?php foreach ($category_sections as $cs): $pins = $category_section_pins_by_id[$cs['id']] ?? []; ?>
            <div class="hpc-subsection-card <?= $cs['status'] !== 'active' ? 'inactive' : '' ?>">
                <div class="hpc-subsection-head">
                    <h4><i class="fas fa-folder"></i> <?= htmlspecialchars($cs['cat_name']) ?></h4>
                    <div class="home-item-actions">
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_category_section"><input type="hidden" name="return_tab" value="catsections"><input type="hidden" name="id" value="<?= $cs['id'] ?>"><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-up"></i></button></form>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="move_category_section"><input type="hidden" name="return_tab" value="catsections"><input type="hidden" name="id" value="<?= $cs['id'] ?>"><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-down"></i></button></form>
                        <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_category_section"><input type="hidden" name="return_tab" value="catsections"><input type="hidden" name="id" value="<?= $cs['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="Show/Hide"><i class="fas fa-eye<?= $cs['status'] === 'active' ? '-slash' : '' ?>"></i></button></form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove this category section?')"><input type="hidden" name="action" value="delete_category_section"><input type="hidden" name="return_tab" value="catsections"><input type="hidden" name="id" value="<?= $cs['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button></form>
                    </div>
                </div>
                <form method="POST" class="row g-2 align-items-end mb-2">
                    <input type="hidden" name="action" value="save_category_section_settings">
                    <input type="hidden" name="return_tab" value="catsections">
                    <input type="hidden" name="id" value="<?= $cs['id'] ?>">
                    <div class="col-md-5">
                        <label class="form-label small">Product order</label>
                        <select class="form-select form-select-sm" name="sort_mode">
                            <option value="latest" <?= $cs['sort_mode'] === 'latest' ? 'selected' : '' ?>>Latest pehle</option>
                            <option value="best_selling" <?= $cs['sort_mode'] === 'best_selling' ? 'selected' : '' ?>>Best-selling pehle</option>
                            <option value="most_viewed" <?= $cs['sort_mode'] === 'most_viewed' ? 'selected' : '' ?>>Most-viewed pehle</option>
                            <option value="manual" <?= $cs['sort_mode'] === 'manual' ? 'selected' : '' ?>>Manual — neeche pinned products, isi order mein</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Kitna show karna hai</label>
                        <input type="number" class="form-control form-control-sm" name="product_limit" value="<?= $cs['product_limit'] ?>" min="1" max="50">
                    </div>
                    <div class="col-md-4"><button class="btn btn-sm btn-primary w-100"><i class="fas fa-save"></i> Save</button></div>
                </form>

                <?php if ($cs['sort_mode'] === 'manual'): ?>
                <form method="POST" class="d-flex gap-2 mb-2">
                    <input type="hidden" name="action" value="add_category_section_pin">
                    <input type="hidden" name="return_tab" value="catsections">
                    <input type="hidden" name="category_section_id" value="<?= $cs['id'] ?>">
                    <select class="form-select form-select-sm" name="product_id" required>
                        <option value="">— Product pin karo —</option>
                        <?php foreach ($product_search_list as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-thumbtack"></i></button>
                </form>
                <?php foreach ($pins as $pin): ?>
                <div class="home-item-row" style="margin-bottom:0.4rem;padding:0.5rem 0.7rem;">
                    <?php if ($pin['prod_image']): ?><img src="/<?= htmlspecialchars($pin['prod_image']) ?>" class="home-item-thumb" style="width:52px;height:40px;"><?php else: ?><div class="home-item-thumb" style="width:52px;height:40px;"><i class="fas fa-image text-muted"></i></div><?php endif; ?>
                    <div class="home-item-body small fw-semibold"><?= htmlspecialchars($pin['prod_name']) ?></div>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Unpin?')"><input type="hidden" name="action" value="delete_category_section_pin"><input type="hidden" name="return_tab" value="catsections"><input type="hidden" name="id" value="<?= $pin['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fas fa-thumbtack"></i></button></form>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (!$category_sections): ?><p class="text-muted">Koi category section nahi hai. Upar se add karo.</p><?php endif; ?>
            <?php endif; ?>

            <?php if ($active_tab === 'typography'): ?>
            <!-- ══════════════ TAB 7 — TYPOGRAPHY ══════════════ -->
            <div class="hpc-panel-header">
                <h2><i class="fas fa-text-height"></i> 7. Typography</h2>
                <p>Text sizes control karo — mobile aur desktop dono ke liye alag (responsive). Product title ab bada aur clear dikhega.</p>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save_typography">
                <input type="hidden" name="return_tab" value="typography">
                <?php
                $TYPE_LABELS = [
                    'product_title' => 'Product Title (card ke andar product ka naam)',
                    'product_price' => 'Product Price (₹ amount)',
                    'section_heading' => 'Section Heading (jaise "Popular Products")',
                    'category_strip_label' => 'Category Strip Label (icon ke neeche naam)',
                    'trending_label' => 'Trending Section Item Label',
                ];
                foreach ($TYPE_LABELS as $key => $label):
                    $row = $typography[$key] ?? ['desktop_px' => 14, 'mobile_px' => 13];
                ?>
                <div class="hpc-subsection-card">
                    <h4 style="font-size:0.88rem;font-weight:700;margin-bottom:0.75rem;"><?= htmlspecialchars($label) ?></h4>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small"><i class="fas fa-desktop"></i> Desktop size (px)</label>
                            <input type="number" class="form-control" name="<?= $key ?>_desktop" value="<?= (int)$row['desktop_px'] ?>" min="8" max="60">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small"><i class="fas fa-mobile-alt"></i> Mobile size (px)</label>
                            <input type="number" class="form-control" name="<?= $key ?>_mobile" value="<?= (int)$row['mobile_px'] ?>" min="8" max="60">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="hpc-save-bar"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Typography</button></div>
            </form>
            <?php endif; ?>

                </div>
            </div>

        </div>
    </main>
</div>

<?php include_once ADMIN_PATH . '/components/admin_footer.php'; ?>
<?php include __DIR__ . '/components/ecom-scripts.php'; ?>
</body>
</html>
