<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once __DIR__ . '/includes/product-card.php';
require_once __DIR__ . '/includes/cart-helpers.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // Used by the floating "View Cart" bar on every page load, to ask what's
    // actually in the cart right now (DB-backed — correct after a refresh,
    // in a new tab, or for a customer who added items on another device).
    case 'cart_summary': {
        echo json_encode(['success' => true, 'cart_count' => cartCount($pdo), 'cart_total' => cartTotal($pdo), 'unit_label' => cartSingleLineUnitLabel($pdo)]);
        break;
    }

    case 'add_to_cart': {
        $pid = (int)($_POST['product_id'] ?? 0);
        $vid = (int)($_POST['variant_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        if ($pid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid product.']); exit; }

        $stmt = $pdo->prepare("SELECT id, stock_qty, product_type FROM ecom_products WHERE id = ? AND status = 'active'");
        $stmt->execute([$pid]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) { echo json_encode(['success' => false, 'message' => 'Product not found.']); exit; }

        $stock_limit = null; // null = not tracked / unlimited
        if ($vid > 0) {
            $vst = $pdo->prepare("SELECT id, stock_qty FROM ecom_product_variants WHERE id = ? AND product_id = ?");
            $vst->execute([$vid, $pid]);
            $variant = $vst->fetch(PDO::FETCH_ASSOC);
            if (!$variant) { echo json_encode(['success' => false, 'message' => 'Selected size is no longer available.']); exit; }
            $stock_limit = $variant['stock_qty'];
        } elseif ($p['product_type'] === 'physical') {
            $stock_limit = $p['stock_qty'];
        }

        // cartAdd() reads the current qty for this owner (customer or guest
        // session) and adds $qty to it — works identically whether the
        // person is logged in or not.
        $new_qty = cartAdd($pdo, $pid, $vid, $qty);

        if ($stock_limit !== null && $new_qty > (int)$stock_limit) {
            $new_qty = max(0, (int)$stock_limit);
            if ($new_qty <= 0) {
                cartSetQty($pdo, $pid, $vid, 0);
                echo json_encode(['success' => false, 'message' => 'Out of stock.']); exit;
            }
            cartSetQty($pdo, $pid, $vid, $new_qty);
        }

        echo json_encode(['success' => true, 'cart_count' => cartCount($pdo), 'cart_total' => cartTotal($pdo), 'unit_label' => cartSingleLineUnitLabel($pdo), 'line_qty' => $new_qty, 'cart_key' => cartKey($pid, $vid)]);
        break;
    }

    case 'update_cart_qty': {
        $key = (string)($_POST['cart_key'] ?? '');
        $qty = max(0, (int)($_POST['qty'] ?? 1));
        $existing = cartRawRows($pdo)[$key] ?? null;
        if ($key === '' || !$existing) { echo json_encode(['success' => false, 'message' => 'Item not in cart.']); exit; }
        cartSetQty($pdo, $existing['product_id'], $existing['variant_id'], $qty);
        echo json_encode(['success' => true, 'cart_count' => cartCount($pdo), 'cart_total' => cartTotal($pdo), 'unit_label' => cartSingleLineUnitLabel($pdo), 'line_qty' => $qty, 'cart_key' => $key]);
        break;
    }

    case 'remove_from_cart': {
        $key = (string)($_POST['cart_key'] ?? '');
        cartRemove($pdo, $key);
        echo json_encode(['success' => true, 'cart_count' => cartCount($pdo), 'cart_total' => cartTotal($pdo), 'unit_label' => cartSingleLineUnitLabel($pdo)]);
        break;
    }

    case 'toggle_wishlist': {
        if (empty($_SESSION['customer_id'])) {
            echo json_encode(['success' => false, 'message' => 'Please login first.', 'need_login' => true]); exit;
        }
        $pid = (int)($_POST['product_id'] ?? 0);
        $cid = (int)$_SESSION['customer_id'];

        $check = $pdo->prepare("SELECT id FROM ecom_wishlist WHERE customer_id = ? AND product_id = ?");
        $check->execute([$cid, $pid]);
        if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("DELETE FROM ecom_wishlist WHERE id = ?")->execute([$row['id']]);
            echo json_encode(['success' => true, 'wishlisted' => false]);
        } else {
            $pdo->prepare("INSERT INTO ecom_wishlist (customer_id, product_id) VALUES (?, ?)")->execute([$cid, $pid]);
            echo json_encode(['success' => true, 'wishlisted' => true]);
        }
        break;
    }

    case 'load_more_category_products': {
        $category_id = (int)($_GET['category_id'] ?? 0);
        $subcategory_id = (int)($_GET['subcategory_id'] ?? 0);
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = 20;

        if ($subcategory_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' AND subcategory_id = ? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([$subcategory_id]);
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ecom_products WHERE status='active' AND subcategory_id = ?");
            $count_stmt->execute([$subcategory_id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' AND category_id = ? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([$category_id]);
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ecom_products WHERE status='active' AND category_id = ?");
            $count_stmt->execute([$category_id]);
        }
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = (int) $count_stmt->fetchColumn();

        ob_start();
        foreach ($products as $p) { renderCmartProductCard($p); }
        $html = ob_get_clean();

        echo json_encode([
            'success' => true,
            'html' => $html,
            'count' => count($products),
            'has_more' => ($offset + count($products)) < $total,
        ]);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
