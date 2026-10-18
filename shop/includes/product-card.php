<?php
/**
 * Renders one product card. Requires $pdo and (optionally) $shop_customer
 * to already be available in scope (both are set by shop-header.php).
 */
function renderProductCard($p, $wishlisted_ids = []) {
    $price = (float)$p['price'];
    $sale  = (!empty($p['sale_price']) && (float)$p['sale_price'] > 0 && (float)$p['sale_price'] < $price) ? (float)$p['sale_price'] : null;
    $out_of_stock = $p['product_type'] === 'physical' && (int)($p['stock_qty'] ?? 0) <= 0;
    $is_wished = in_array($p['id'], $wishlisted_ids);
    ?>
    <div class="col-6 col-md-4 col-lg-3 mb-4">
        <div class="product-card">
            <a href="/shop/product.php?slug=<?= urlencode($p['slug']) ?>" class="thumb">
                <?php if (!empty($p['image'])): ?>
                    <img src="/<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                <?php else: ?>
                    <i class="fas fa-image fa-2x text-muted"></i>
                <?php endif; ?>
                <?php if ($p['badge_tag'] !== 'none'):
                    static $badge_tag_cache = null;
                    if ($badge_tag_cache === null) {
                        global $pdo;
                        $badge_tag_cache = [];
                        foreach ($pdo->query("SELECT slug, label, color FROM ecom_product_tags WHERE tag_group='badge'")->fetchAll(PDO::FETCH_ASSOC) as $bt) {
                            $badge_tag_cache[$bt['slug']] = $bt;
                        }
                    }
                    $bt = $badge_tag_cache[$p['badge_tag']] ?? null;
                    if ($bt):
                ?>
                    <span class="badge badge-corner text-uppercase" style="background:<?= htmlspecialchars($bt['color'] ?: '#ef4444') ?>;"><?= htmlspecialchars($bt['label']) ?></span>
                <?php endif; endif; ?>
            </a>
            <button class="wish-btn<?= $is_wished ? ' active' : '' ?>" onclick="toggleWishlist(<?= $p['id'] ?>, this)" title="Add to wishlist">
                <i class="<?= $is_wished ? 'fas' : 'far' ?> fa-heart"></i>
            </button>
            <div class="body">
                <a href="/shop/product.php?slug=<?= urlencode($p['slug']) ?>" class="name"><?= htmlspecialchars($p['name']) ?></a>
                <div class="price-row">
                    <span class="price-now">₹<?= number_format($sale ?? $price, 2) ?></span>
                    <?php if ($sale): ?><span class="price-old">₹<?= number_format($price, 2) ?></span><?php endif; ?>
                </div>
                <?php if ($out_of_stock): ?>
                    <button class="btn btn-sm btn-secondary mt-2" disabled>Out of Stock</button>
                <?php else: ?>
                    <button class="btn btn-shop-primary btn-sm mt-2" onclick="addToCart(<?= $p['id'] ?>)"><i class="fas fa-cart-plus"></i> Add to Cart</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders one product in the Cmart-style card (image-top, MRP/SP/OFF badge,
 * ADD TO CART button). Used on the homepage and anywhere else opting into
 * the new storefront design. If the product has size/unit variants (e.g.
 * 250 g / 500 g / 1 Kg), a dropdown is shown — picking one live-updates the
 * MRP/price/OFF badge and is what gets added to the cart.
 */
function renderCmartProductCard($p) {
    global $pdo;
    $variants = getProductVariants($pdo, (int)$p['id']);
    $disp = resolveDisplayPricing($p, $variants);
    $price = $disp['price'];
    $sale  = $disp['sale'];
    $out_of_stock = $p['product_type'] === 'physical' && $disp['stock'] !== null && (int)$disp['stock'] <= 0;
    $off_amount = $sale ? round($price - $sale) : 0;

    // If this product/variant is already in the cart, render the qty
    // stepper (− N +) straight away instead of "ADD TO CART", so a page
    // refresh (or navigating back) shows the true cart state immediately
    // instead of flashing the wrong button until JS catches up.
    $variant_id_for_cart = (int)($disp['variant_id'] ?? 0);
    $cart_key = cartKey((int)$p['id'], $variant_id_for_cart);
    $in_cart_qty = (int)(cartRawRows($pdo)[$cart_key]['qty'] ?? 0);
    ?>
    <div class="product-card">
        <a href="/shop/product.php?slug=<?= urlencode($p['slug']) ?>" class="img-wrap">
            <?php if (!empty($p['image'])): ?>
                <img src="/<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php else: ?>
                <i class="fas fa-image" style="color:var(--muted);"></i>
            <?php endif; ?>
        </a>
        <a href="/shop/product.php?slug=<?= urlencode($p['slug']) ?>" class="title"><?= htmlspecialchars($p['name']) ?></a>
        <div class="price-row">
            <div class="prices">
                <span class="label">MRP</span>
                <span class="mrp" <?= !$sale ? 'style="display:none;"' : '' ?>>₹<?= number_format($price, 0) ?></span>
                <span class="sp">₹<?= number_format($sale ?? $price, 0) ?></span>
            </div>
        </div>
        <div class="qty-cart">
            <div class="off-badge" <?= (!$sale || $off_amount <= 0) ? 'style="display:none;"' : '' ?>>₹<?= $off_amount ?><small>OFF</small></div>
            <?php if (!empty($variants)): ?>
            <select class="qty-select" onchange="handleQtyChange(this)">
                <?php foreach ($variants as $v):
                    $vp = (float)$v['price'];
                    $vs = (!empty($v['sale_price']) && (float)$v['sale_price'] > 0 && (float)$v['sale_price'] < $vp) ? (float)$v['sale_price'] : null;
                    $voff = $vs ? round($vp - $vs) : 0;
                    $vsel = ((int)$v['id'] === (int)$disp['variant_id']) ? 'selected' : '';
                ?>
                <option value="<?= (int)$v['id'] ?>" data-variant-id="<?= (int)$v['id'] ?>" data-mrp="<?= $vp ?>" data-sp="<?= $vs ?? $vp ?>" data-off="<?= $voff ?>" <?= $vsel ?>><?= htmlspecialchars($v['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($out_of_stock): ?>
                <button class="add-cart" style="background:var(--muted);" disabled>OUT OF STOCK</button>
            <?php elseif ($in_cart_qty > 0): ?>
                <div class="qty-stepper" data-product-id="<?= $p['id'] ?>" data-variant-id="<?= $variant_id_for_cart ?>" data-cart-key="<?= htmlspecialchars($cart_key) ?>">
                    <button type="button" class="qty-stepper-minus" aria-label="Decrease quantity">−</button>
                    <span class="qty-stepper-count"><?= $in_cart_qty ?></span>
                    <button type="button" class="qty-stepper-plus" aria-label="Increase quantity">+</button>
                </div>
            <?php else: ?>
                <button class="add-cart" data-product-id="<?= $p['id'] ?>"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg>ADD TO CART</button>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Renders one product using the "card2" horizontal layout (image left, info
 * right), in one of 4 selectable visual designs:
 *   design1 = Clean Minimal · design2 = Ribbon Discount Badge
 *   design3 = Two-tone (shaded action strip) · design4 = Compact Chip
 */
function renderCard2Product($p, $design = 'design1') {
    global $pdo;
    $variants = getProductVariants($pdo, (int)$p['id']);
    $disp = resolveDisplayPricing($p, $variants);
    $price = $disp['price'];
    $sale  = $disp['sale'];
    $out_of_stock = $p['product_type'] === 'physical' && $disp['stock'] !== null && (int)$disp['stock'] <= 0;
    $off_amount = $sale ? round($price - $sale) : 0;
    $off_pct = $sale && $price > 0 ? round((($price - $sale) / $price) * 100) : 0;
    $default_variant_label = null;
    foreach ($variants as $v) { if ((int)$v['id'] === (int)$disp['variant_id']) { $default_variant_label = $v['label']; break; } }

    // Map the admin-facing design name to the demo's actual CSS class
    $cssClass = ['design1' => 'design1', 'design2' => 'design2', 'design3' => 'design4', 'design4' => 'design5'][$design] ?? 'design1';
    $isCompact = $design === 'design4';

    $variant_id_for_cart = (int)($disp['variant_id'] ?? 0);
    $cart_key = cartKey((int)$p['id'], $variant_id_for_cart);
    $in_cart_qty = (int)(cartRawRows($pdo)[$cart_key]['qty'] ?? 0);
    ?>
    <div class="card2 <?= $cssClass ?>">
        <div class="top2">
            <a href="/shop/product.php?slug=<?= urlencode($p['slug']) ?>" class="img2">
                <?php if (!empty($p['image'])): ?>
                    <img src="/<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                <?php else: ?>
                    <i class="fas fa-image" style="color:var(--muted);"></i>
                <?php endif; ?>
                <?php if ($design === 'design2' && $sale && $off_pct > 0): ?><span class="ribbon"><?= $off_pct ?>% OFF</span><?php endif; ?>
            </a>
            <div class="info2">
                <a href="/shop/product.php?slug=<?= urlencode($p['slug']) ?>" class="title2"><?= htmlspecialchars($p['name']) ?></a>
                <?php if ($isCompact): ?>
                    <div class="price2">
                        <?php if ($sale): ?><span class="mrp2">₹<?= number_format($price, 0) ?></span><?php endif; ?>
                        <span class="sp2">₹<?= number_format($sale ?? $price, 0) ?></span>
                        <?php if ($sale && $off_amount > 0): ?><span class="offer2">₹<?= $off_amount ?> OFF</span><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="price2">
                        <?php if ($sale): ?><span class="mrp2">₹<?= number_format($price, 0) ?></span><?php endif; ?>
                        <span class="sp2">₹<?= number_format($sale ?? $price, 0) ?></span>
                    </div>
                    <?php if ($design !== 'design2' && $sale && $off_amount > 0): ?><span class="offer2">₹<?= $off_amount ?> OFF</span><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="bottom2">
            <?php if ($out_of_stock): ?>
                <div class="qty2">Out of stock</div>
                <button class="cart2" style="background:var(--muted);" disabled>UNAVAILABLE</button>
            <?php else: ?>
                <?php if ($default_variant_label): ?><div class="qty2"><?= htmlspecialchars($default_variant_label) ?></div><?php endif; ?>
                <?php if ($in_cart_qty > 0): ?>
                <div class="qty-stepper" data-product-id="<?= $p['id'] ?>" data-variant-id="<?= $variant_id_for_cart ?>" data-cart-key="<?= htmlspecialchars($cart_key) ?>">
                    <button type="button" class="qty-stepper-minus" aria-label="Decrease quantity">−</button>
                    <span class="qty-stepper-count"><?= $in_cart_qty ?></span>
                    <button type="button" class="qty-stepper-plus" aria-label="Increase quantity">+</button>
                </div>
                <?php else: ?>
                <button class="cart2" data-product-id="<?= $p['id'] ?>" data-variant-id="<?= (int)$disp['variant_id'] ?>"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg>ADD TO CART</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
