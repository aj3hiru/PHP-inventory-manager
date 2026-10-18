<?php
/**
 * Shared cart helpers.
 *
 * WHERE THE CART LIVES
 * ---------------------
 * - Guest (not logged in):  database table `ecom_cart_items`, one row per
 *   line, keyed by the PHP session id (`session_id` column). This replaces
 *   the old $_SESSION['shop_cart'] array so the cart survives exactly as
 *   long as the session does — no behavior change for guests.
 * - Logged-in customer:     same table, keyed by `customer_id` instead of
 *   `session_id`. This is what makes the cart follow the customer to any
 *   device/browser they log into.
 *
 * The moment a guest logs in, cartMergeGuestIntoAccount() (called from
 * login.php/register.php) moves their session-keyed rows onto their
 * customer_id, adding quantities together if the same line already exists
 * in their account cart from another device.
 *
 * Every OTHER file in the codebase (ajax.php, cart.php, checkout.php,
 * shop-header.php) never touches $_SESSION['shop_cart'] or the DB directly
 * any more — they only call the functions below, so this file is the one
 * place cart storage logic lives.
 *
 * Cart line shape (identical to the old session-array shape, so downstream
 * code needed no changes beyond swapping the storage calls):
 *   ['product_id' => int, 'variant_id' => int, 'qty' => int]
 * addressed by a composite string key: "<product_id>" or "<product_id>_v<variant_id>".
 */

if (!function_exists('cartKey')) {
    function cartKey(int $product_id, int $variant_id = 0): string {
        return $variant_id > 0 ? $product_id . '_v' . $variant_id : (string)$product_id;
    }
}

/**
 * Identifies whose cart we're reading/writing right now: the logged-in
 * customer's, or (for guests) the current PHP session's.
 * Returns ['customer_id' => int|null, 'session_id' => string|null] — exactly
 * one of the two is non-null.
 */
if (!function_exists('cartOwner')) {
    function cartOwner(): array {
        if (!empty($_SESSION['customer_id'])) {
            return ['customer_id' => (int)$_SESSION['customer_id'], 'session_id' => null];
        }
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        return ['customer_id' => null, 'session_id' => session_id()];
    }
}

/**
 * Normalizes one raw row/entry into ['product_id','variant_id','qty'], or
 * null if it's not a usable line (qty <= 0, malformed, etc).
 */
if (!function_exists('normalizeCartEntry')) {
    function normalizeCartEntry($key, $value): ?array {
        if (is_array($value)) {
            $qty = (int)($value['qty'] ?? 0);
            if ($qty <= 0) return null;
            return [
                'product_id' => (int)($value['product_id'] ?? $key),
                'variant_id' => (int)($value['variant_id'] ?? 0),
                'qty'        => $qty,
            ];
        }
        if (is_numeric($value) && (int)$value > 0) {
            return ['product_id' => (int)$key, 'variant_id' => 0, 'qty' => (int)$value];
        }
        return null;
    }
}

/**
 * Reads the raw cart rows for the current owner (customer or guest session)
 * from the database, normalized to ['key' => ['product_id','variant_id','qty']].
 */
if (!function_exists('cartRawRows')) {
    function cartRawRows(PDO $pdo): array {
        $owner = cartOwner();
        if ($owner['customer_id'] !== null) {
            $st = $pdo->prepare("SELECT product_id, variant_id, qty FROM ecom_cart_items WHERE customer_id = ?");
            $st->execute([$owner['customer_id']]);
        } else {
            $st = $pdo->prepare("SELECT product_id, variant_id, qty FROM ecom_cart_items WHERE session_id = ?");
            $st->execute([$owner['session_id']]);
        }
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = cartKey((int)$r['product_id'], (int)$r['variant_id']);
            $rows[$key] = ['product_id' => (int)$r['product_id'], 'variant_id' => (int)$r['variant_id'], 'qty' => (int)$r['qty']];
        }
        return $rows;
    }
}

if (!function_exists('cartCount')) {
    function cartCount(PDO $pdo): int {
        $total = 0;
        foreach (cartRawRows($pdo) as $entry) { $total += $entry['qty']; }
        return $total;
    }
}

/**
 * For the floating "View Cart" bar: when the cart has exactly one distinct
 * product/variant line in it, returns that line's unit label (e.g. "250g",
 * "Size M") if it has one — so the bar can say "1 item (250g)" instead of
 * just "1 item". Returns null whenever the cart is empty, has more than one
 * distinct line, or that single line has no variant (plain product, no
 * unit to show) — the bar falls back to plain count/price in all of those
 * cases, since a single label wouldn't accurately describe a mixed cart.
 */
if (!function_exists('cartSingleLineUnitLabel')) {
    function cartSingleLineUnitLabel(PDO $pdo): ?string {
        $rows = cartRawRows($pdo);
        if (count($rows) !== 1) return null;
        $entry = reset($rows);
        if (empty($entry['variant_id'])) return null;
        $st = $pdo->prepare("SELECT label FROM ecom_product_variants WHERE id = ?");
        $st->execute([$entry['variant_id']]);
        $label = $st->fetchColumn();
        return $label !== false && $label !== '' ? $label : null;
    }
}

/**
 * Sets (or removes, if $qty <= 0) the quantity for one product/variant line
 * for the current owner. This is an absolute set, not an add — callers that
 * want "add N more" should read the current qty first (see cartAdd()).
 */
if (!function_exists('cartSetQty')) {
    function cartSetQty(PDO $pdo, int $product_id, int $variant_id, int $qty): void {
        $owner = cartOwner();
        if ($qty <= 0) {
            if ($owner['customer_id'] !== null) {
                $pdo->prepare("DELETE FROM ecom_cart_items WHERE customer_id = ? AND product_id = ? AND variant_id = ?")
                    ->execute([$owner['customer_id'], $product_id, $variant_id]);
            } else {
                $pdo->prepare("DELETE FROM ecom_cart_items WHERE session_id = ? AND product_id = ? AND variant_id = ?")
                    ->execute([$owner['session_id'], $product_id, $variant_id]);
            }
            return;
        }
        if ($owner['customer_id'] !== null) {
            $pdo->prepare("
                INSERT INTO ecom_cart_items (customer_id, product_id, variant_id, qty)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = VALUES(qty)
            ")->execute([$owner['customer_id'], $product_id, $variant_id, $qty]);
        } else {
            $pdo->prepare("
                INSERT INTO ecom_cart_items (session_id, product_id, variant_id, qty)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = VALUES(qty)
            ")->execute([$owner['session_id'], $product_id, $variant_id, $qty]);
        }
    }
}

/**
 * Adds $qty_delta more of a product/variant to the current owner's cart
 * (creating the line if it doesn't exist yet). Returns the new total qty
 * for that line, so callers can apply a stock cap before saving if needed.
 */
if (!function_exists('cartAdd')) {
    function cartAdd(PDO $pdo, int $product_id, int $variant_id, int $qty_delta): int {
        $key = cartKey($product_id, $variant_id);
        $existing = cartRawRows($pdo)[$key] ?? null;
        $new_qty = ($existing['qty'] ?? 0) + $qty_delta;
        cartSetQty($pdo, $product_id, $variant_id, $new_qty);
        return $new_qty;
    }
}

if (!function_exists('cartRemove')) {
    function cartRemove(PDO $pdo, string $key): void {
        $entry = cartRawRows($pdo)[$key] ?? null;
        if (!$entry) return;
        cartSetQty($pdo, $entry['product_id'], $entry['variant_id'], 0);
    }
}

/** Empties the current owner's cart entirely (used right after checkout). */
if (!function_exists('cartClear')) {
    function cartClear(PDO $pdo): void {
        $owner = cartOwner();
        if ($owner['customer_id'] !== null) {
            $pdo->prepare("DELETE FROM ecom_cart_items WHERE customer_id = ?")->execute([$owner['customer_id']]);
        } else {
            $pdo->prepare("DELETE FROM ecom_cart_items WHERE session_id = ?")->execute([$owner['session_id']]);
        }
    }
}

/**
 * Called once, right after a successful login (or registration-auto-login),
 * BEFORE $_SESSION['customer_id'] is trusted elsewhere but AFTER it has been
 * set — merges whatever the guest had in their session-keyed cart into the
 * customer's account cart (adding quantities together on any line that
 * already exists there from a previous session/device), then clears the
 * guest rows so they aren't left orphaned in the table.
 *
 * Safe to call even if the guest cart is empty (no-op) or if this session
 * never had a cart at all.
 */
if (!function_exists('cartMergeGuestIntoAccount')) {
    function cartMergeGuestIntoAccount(PDO $pdo, int $customer_id, string $session_id): void {
        if ($session_id === '') return;

        $st = $pdo->prepare("SELECT product_id, variant_id, qty FROM ecom_cart_items WHERE session_id = ?");
        $st->execute([$session_id]);
        $guest_rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (empty($guest_rows)) return;

        foreach ($guest_rows as $row) {
            $pdo->prepare("
                INSERT INTO ecom_cart_items (customer_id, product_id, variant_id, qty)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
            ")->execute([$customer_id, (int)$row['product_id'], (int)$row['variant_id'], (int)$row['qty']]);
        }

        $pdo->prepare("DELETE FROM ecom_cart_items WHERE session_id = ?")->execute([$session_id]);
    }
}

/**
 * Resolves the cart against the database: product name/image/slug/type,
 * plus the correct unit price — the variant's price if a size was chosen,
 * otherwise the product's own price.
 */
if (!function_exists('getCartLines')) {
    function getCartLines(PDO $pdo): array {
        $cart = cartRawRows($pdo);
        if (empty($cart)) return [];

        $product_ids = array_values(array_unique(array_column($cart, 'product_id')));
        $variant_ids = array_values(array_unique(array_filter(array_column($cart, 'variant_id'))));

        $products_by_id = [];
        if (!empty($product_ids)) {
            $in = implode(',', array_fill(0, count($product_ids), '?'));
            $st = $pdo->prepare("SELECT * FROM ecom_products WHERE id IN ($in) AND status = 'active'");
            $st->execute($product_ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $products_by_id[$row['id']] = $row; }
        }

        $variants_by_id = [];
        if (!empty($variant_ids)) {
            $in = implode(',', array_fill(0, count($variant_ids), '?'));
            $st = $pdo->prepare("SELECT * FROM ecom_product_variants WHERE id IN ($in)");
            $st->execute($variant_ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $variants_by_id[$row['id']] = $row; }
        }

        $lines = [];
        foreach ($cart as $key => $entry) {
            $pid = (int)($entry['product_id'] ?? 0);
            $vid = (int)($entry['variant_id'] ?? 0);
            $qty = (int)($entry['qty'] ?? 0);
            if ($qty <= 0 || !isset($products_by_id[$pid])) continue;
            $product = $products_by_id[$pid];
            $variant = $vid > 0 ? ($variants_by_id[$vid] ?? null) : null;

            if ($variant) {
                $price = (float)$variant['price'];
                $sale  = (!empty($variant['sale_price']) && (float)$variant['sale_price'] > 0 && (float)$variant['sale_price'] < $price) ? (float)$variant['sale_price'] : null;
                $stock = $variant['stock_qty'];
                $label = $variant['label'];
            } else {
                $price = (float)$product['price'];
                $sale  = (!empty($product['sale_price']) && (float)$product['sale_price'] > 0 && (float)$product['sale_price'] < $price) ? (float)$product['sale_price'] : null;
                $stock = $product['stock_qty'] ?? null;
                $label = null;
            }
            $unit_price = $sale ?? $price;

            $lines[] = [
                'key'           => $key,
                'product_id'    => $pid,
                'variant_id'    => $vid,
                'variant_label' => $label,
                'name'          => $product['name'],
                'slug'          => $product['slug'],
                'image'         => $product['image'],
                'product_type'  => $product['product_type'],
                'qty'           => $qty,
                'unit_price'    => $unit_price,
                'mrp'           => $price,
                'line_total'    => $unit_price * $qty,
                'stock_qty'     => $stock,
            ];
        }
        return $lines;
    }
}

if (!function_exists('cartTotal')) {
    function cartTotal(PDO $pdo): float {
        $total = 0.0;
        foreach (getCartLines($pdo) as $l) { $total += $l['line_total']; }
        return $total;
    }
}
