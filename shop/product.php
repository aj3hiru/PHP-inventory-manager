<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM ecom_products WHERE slug = ? AND status = 'active'");
$stmt->execute([$slug]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) { http_response_code(404); exit('Product not found.'); }

// Popularity tracking: every real product-page view counts toward the
// "Popular Products" auto-ranking (manual pins + views/purchases) on the homepage.
$pdo->prepare("UPDATE ecom_products SET view_count = view_count + 1 WHERE id = ?")->execute([$product['id']]);

// Per-customer view log — powers "Recommended for you" below (which
// categories has this customer actually been looking at). Guests have no
// customer_id, so nothing is logged for them — they see trending/popular
// products in that section instead, which is the intended fallback.
if (!empty($_SESSION['customer_id'])) {
    $pdo->prepare("INSERT INTO ecom_product_views (customer_id, product_id, category_id) VALUES (?, ?, ?)")
        ->execute([(int)$_SESSION['customer_id'], $product['id'], $product['category_id']]);
}

$gallery = $pdo->prepare("SELECT image FROM ecom_product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
$gallery->execute([$product['id']]);
$gallery_images = array_column($gallery->fetchAll(PDO::FETCH_ASSOC), 'image');

$slides = [];
if (!empty($product['image'])) $slides[] = $product['image'];
foreach ($gallery_images as $g) { $slides[] = $g; }
if (empty($slides)) $slides[] = null; // will render the placeholder icon

// ── Verified-purchase reviews (Flipkart-style) ───────────────────────────────
// "Write a Review" only shows for a customer who has a Delivered order
// containing this product AND hasn't already reviewed that specific order.
// Each qualifying order can be reviewed exactly once — reviewing again means
// buying the product again (a new Delivered order with no review yet).
$reviewable_order_id = null;
if (!empty($_SESSION['customer_id'])) {
    $rstmt = $pdo->prepare("
        SELECT oi.order_id
        FROM ecom_order_items oi
        INNER JOIN ecom_orders o ON o.id = oi.order_id
        WHERE oi.product_id = ?
          AND o.customer_id = ?
          AND o.order_status = 'Delivered'
          AND oi.order_id NOT IN (
              SELECT order_id FROM ecom_product_reviews
              WHERE product_id = ? AND order_id IS NOT NULL
          )
        ORDER BY o.id DESC
        LIMIT 1
    ");
    $rstmt->execute([$product['id'], (int)$_SESSION['customer_id'], $product['id']]);
    $reviewable_order_id = $rstmt->fetchColumn() ?: null;
}

// ── Handle review submission (logged-in customers, verified purchase only) ──
$review_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['customer_id']) && isset($_POST['review_text'])) {
    $submitted_order_id = (int)($_POST['order_id'] ?? 0);

    // Re-check eligibility server-side (never trust the posted order_id
    // alone) — must be one of this customer's Delivered orders for this
    // product, and not already reviewed.
    $check = $pdo->prepare("
        SELECT oi.order_id
        FROM ecom_order_items oi
        INNER JOIN ecom_orders o ON o.id = oi.order_id
        WHERE oi.order_id = ? AND oi.product_id = ? AND o.customer_id = ? AND o.order_status = 'Delivered'
          AND oi.order_id NOT IN (
              SELECT order_id FROM ecom_product_reviews WHERE product_id = ? AND order_id IS NOT NULL
          )
    ");
    $check->execute([$submitted_order_id, $product['id'], (int)$_SESSION['customer_id'], $product['id']]);
    $eligible_order_id = $check->fetchColumn();

    if (!$eligible_order_id) {
        $review_msg = 'You can only review a product you\'ve purchased and received — and only once per order.';
    } else {
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $text   = trim($_POST['review_text'] ?? '');
        $cstmt = $pdo->prepare("SELECT name FROM ecom_customers WHERE id = ?");
        $cstmt->execute([$_SESSION['customer_id']]);
        $cname = $cstmt->fetchColumn() ?: 'Customer';

        $pdo->prepare("INSERT INTO ecom_product_reviews (product_id, customer_id, order_id, customer_name, rating, review_text, status) VALUES (?,?,?,?,?,?,'pending')")
            ->execute([$product['id'], (int)$_SESSION['customer_id'], $eligible_order_id, $cname, $rating, $text]);
        $review_msg = 'Thanks! Your review has been submitted and will appear once approved.';
        $reviewable_order_id = null; // just used up — recompute would show none left
    }
}

$page_title = $product['name'];
include __DIR__ . '/includes/shop-header.php';
include __DIR__ . '/includes/product-card.php';

$brand = null;
if (!empty($product['brand_id'])) {
    $b = $pdo->prepare("SELECT name FROM ecom_brands WHERE id = ?");
    $b->execute([$product['brand_id']]);
    $brand = $b->fetchColumn();
}

$reviews = $pdo->prepare("SELECT * FROM ecom_product_reviews WHERE product_id = ? AND status = 'approved' ORDER BY created_at DESC");
$reviews->execute([$product['id']]);
$reviews = $reviews->fetchAll(PDO::FETCH_ASSOC);
$avg_rating = count($reviews) ? array_sum(array_column($reviews, 'rating')) / count($reviews) : 0;

// ── Specifications (admin-filled key/value rows) — Brand is prepended
// automatically since it's already its own product field, not a manual row. ──
$specs = [];
if ($brand) { $specs[] = ['spec_key' => 'Brand', 'spec_value' => $brand]; }
try {
    $sp = $pdo->prepare("SELECT spec_key, spec_value FROM ecom_product_specs WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $sp->execute([$product['id']]);
    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $row) { $specs[] = $row; }
} catch (Throwable $e) { /* table not migrated yet — page still works, just no specs shown */ }

// ── "More from this category" — same subcategory if set, else same
// category; ranked by best-selling then most-viewed, so the strongest
// products in that category show first (not just newest). ──────────────────
$related = [];
try {
    if (!empty($product['subcategory_id'])) {
        $rel = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' AND subcategory_id = ? AND id != ? ORDER BY purchase_count DESC, view_count DESC LIMIT 12");
        $rel->execute([$product['subcategory_id'], $product['id']]);
        $related = $rel->fetchAll(PDO::FETCH_ASSOC);
    }
    if (empty($related) && !empty($product['category_id'])) {
        $rel = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' AND category_id = ? AND id != ? ORDER BY purchase_count DESC, view_count DESC LIMIT 12");
        $rel->execute([$product['category_id'], $product['id']]);
        $related = $rel->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $related = []; }

// ── "Recommended for you" — logged-in customers: look at which categories
// this customer has actually been viewing (most-viewed first), pull the
// strongest products from those categories, skipping the current product
// and anything already shown above in "More from this category". Guests
// (and customers with no view history yet) fall back to sitewide trending —
// same best-seller/most-viewed ranking, just not personalized. ─────────────
$recommended = [];
$exclude_ids = array_merge([$product['id']], array_column($related, 'id'));
try {
    if (!empty($_SESSION['customer_id'])) {
        $top_cats_stmt = $pdo->prepare("
            SELECT category_id, COUNT(*) AS views FROM ecom_product_views
            WHERE customer_id = ? AND category_id IS NOT NULL
            GROUP BY category_id ORDER BY views DESC LIMIT 5
        ");
        $top_cats_stmt->execute([(int)$_SESSION['customer_id']]);
        $top_cat_ids = array_column($top_cats_stmt->fetchAll(PDO::FETCH_ASSOC), 'category_id');

        if (!empty($top_cat_ids)) {
            $in_cats = implode(',', array_fill(0, count($top_cat_ids), '?'));
            $in_excl = implode(',', array_fill(0, count($exclude_ids), '?'));
            $rec = $pdo->prepare("
                SELECT * FROM ecom_products
                WHERE status='active' AND category_id IN ($in_cats) AND id NOT IN ($in_excl)
                ORDER BY purchase_count DESC, view_count DESC LIMIT 12
            ");
            $rec->execute(array_merge($top_cat_ids, $exclude_ids));
            $recommended = $rec->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    if (empty($recommended)) {
        // Guest, or a logged-in customer with no view history yet — trending sitewide.
        $in_excl = implode(',', array_fill(0, count($exclude_ids), '?'));
        $rec = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' AND id NOT IN ($in_excl) ORDER BY purchase_count DESC, view_count DESC LIMIT 12");
        $rec->execute($exclude_ids);
        $recommended = $rec->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $recommended = []; }

$variants = getProductVariants($pdo, (int)$product['id']);
$disp = resolveDisplayPricing($product, $variants);
$price = $disp['price'];
$sale  = $disp['sale'];
$out_of_stock = $product['product_type'] === 'physical' && $disp['stock'] !== null && (int)$disp['stock'] <= 0;
$off_pct = $sale && $price > 0 ? round((1 - $sale / $price) * 100) : 0;

// ── Share — absolute URL to this exact product page, for the native share
// sheet and the WhatsApp/copy-link fallback buttons. ────────────────────────
$share_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$share_url = $share_scheme . $_SERVER['HTTP_HOST'] . '/shop/product.php?slug=' . rawurlencode($product['slug']);
$share_text = $product['name'] . ' — ' . $shop_biz_name;
?>
<style>
  /* =====================================================================
     PRODUCT DETAIL PAGE — Flipkart-style redesign
     Scoped under .pdp so nothing here leaks into other pages.
     ===================================================================== */
  .pdp{ max-width:1200px; margin:0 auto; padding:16px 16px 32px; }
  .pdp-crumb{ font-size:12.5px; color:var(--muted); margin-bottom:14px; display:flex; gap:6px; flex-wrap:wrap; }
  .pdp-crumb a{ color:var(--muted); }
  .pdp-crumb a:hover{ color:var(--green); }
  .pdp-crumb .sep{ color:var(--gray-300,#d1d5db); }
  .pdp-crumb .current{ color:var(--text); font-weight:600; }

  .pdp-grid{ display:grid; grid-template-columns:minmax(0,440px) 1fr; gap:40px; align-items:start; }

  /* ---------- Gallery ---------- */
  .pdp-gallery{ display:flex; gap:12px; position:sticky; top:16px; }
  .pdp-thumbs{ display:flex; flex-direction:column; gap:10px; flex-shrink:0; width:64px; }
  .pdp-thumb{
    width:64px; height:64px; border-radius:8px; overflow:hidden; cursor:pointer;
    border:2px solid var(--border); flex-shrink:0; transition:border-color .15s ease;
    background:var(--gray-50,#f9fafb); display:flex; align-items:center; justify-content:center;
  }
  .pdp-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
  .pdp-thumb.active{ border-color:var(--green); }
  .pdp-thumb:hover{ border-color:var(--green); }

  .pdp-main-wrap{ flex:1; min-width:0; }
  .pdp-main{
    position:relative; width:100%; aspect-ratio:1/1; border-radius:12px; overflow:hidden;
    border:1px solid var(--border); background:var(--gray-50,#f9fafb);
    display:flex; align-items:center; justify-content:center;
    touch-action:pan-y;
  }
  .pdp-main img{ width:100%; height:100%; object-fit:contain; display:block; }
  .pdp-main .placeholder-icon{ font-size:64px; color:var(--muted); }
  .pdp-dots{ display:none; }

  /* Mobile/tablet: swipeable main image, with a horizontal row of small
     tappable thumbnails underneath — same pattern as Flipkart's product
     page (not a vertical stack, and not full-width dots). */
  @media (max-width:900px){
    .pdp-gallery{ position:static; flex-direction:column; }
    .pdp-main-wrap{ order:1; }
    .pdp-thumbs{
      order:2; flex-direction:row; width:auto; overflow-x:auto; scrollbar-width:none;
      gap:8px; margin-top:10px; padding-bottom:2px;
    }
    .pdp-thumbs::-webkit-scrollbar{ display:none; }
    .pdp-thumb{ width:56px; height:56px; }
    .pdp-dots{ display:none; }
  }

  /* ---------- Info column ---------- */
  .pdp-info{ min-width:0; }
  .pdp-title{ font-size:22px; font-weight:700; line-height:1.35; color:var(--text); margin-bottom:8px; }
  .pdp-rating-row{ display:flex; align-items:center; gap:10px; margin-bottom:16px; }
  .pdp-rating-badge{
    display:inline-flex; align-items:center; gap:4px; background:var(--green); color:#fff;
    font-size:12.5px; font-weight:700; padding:3px 8px; border-radius:4px;
  }
  .pdp-rating-badge svg{ width:11px; height:11px; }
  .pdp-review-count{ font-size:13px; color:var(--muted); }

  /* ---------- Price block (Flipkart-style bold) ---------- */
  .pdp-price-block{ margin-bottom:18px; }
  .pdp-price-row{ display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; }
  .pdp-sp{ font-size:30px; font-weight:800; color:var(--text); letter-spacing:-0.3px; }
  .pdp-mrp{ font-size:16px; color:var(--muted); text-decoration:line-through; }
  .pdp-off{ font-size:16px; font-weight:700; color:var(--green-dark); }
  .pdp-tax-note{ font-size:12.5px; color:var(--muted); margin-top:4px; }

  /* ---------- Variant selector ---------- */
  .pdp-section{ margin-bottom:20px; }
  .pdp-section-label{ font-size:13.5px; font-weight:700; color:var(--text); margin-bottom:8px; display:block; text-transform:uppercase; letter-spacing:.3px; }

  /* ---------- Share row ---------- */
  .pdp-share-row{ display:flex; align-items:center; gap:10px; margin:0 0 20px; flex-wrap:wrap; }
  .pdp-share-label{ font-size:12.5px; color:var(--muted); font-weight:600; margin-right:2px; }
  .pdp-share-btn{
    width:36px; height:36px; border-radius:50%; border:1px solid var(--border); background:#fff;
    display:flex; align-items:center; justify-content:center; color:var(--text); font-size:14.5px;
    cursor:pointer; transition:transform .12s ease, background .12s ease, border-color .12s ease, color .12s ease;
    text-decoration:none;
  }
  .pdp-share-btn:hover{ transform:translateY(-1px); border-color:var(--green); color:var(--green-dark); }
  .pdp-share-btn.whatsapp:hover{ background:#e9fbf0; border-color:#25D366; color:#25D366; }
  .pdp-share-btn.copied{ background:var(--green); border-color:var(--green); color:#fff; }
  .pdp-share-toast{
    position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(16px); opacity:0;
    background:#1f2937; color:#fff; font-size:13px; font-weight:600; padding:10px 18px; border-radius:24px;
    box-shadow:0 6px 20px rgba(0,0,0,.2); pointer-events:none; transition:opacity .2s ease, transform .2s ease; z-index:9999;
    white-space:nowrap;
  }
  .pdp-share-toast.show{ opacity:1; transform:translateX(-50%) translateY(0); }

  /* ---------- Stock line ---------- */
  .pdp-stock{ font-size:14px; font-weight:600; margin-bottom:14px; }
  .pdp-stock.in{ color:var(--green-dark); }
  .pdp-stock.out{ color:#dc2626; }

  /* ---------- Unit dropdown + Qty + Add-to-Cart — ONE LINE, always.
     Each piece is allowed to shrink (min-width:0) before the row wraps, so
     on a narrow phone the dropdown/qty/button all get a little narrower
     together instead of any one of them overflowing or dropping to a 2nd line. ---------- */
  .pdp-actions{ display:flex; gap:8px; align-items:stretch; flex-wrap:nowrap; margin-bottom:22px; }
  .pdp-unit-select.qty-select{
    flex:0 1 110px; min-width:0; height:44px !important;
  }
  /* The unit/size dropdown here is styled via the shared .qty-select /
     .qdrop-trigger classes (also used on small product-card grids), which
     shrink to a 10.5px font on mobile for THOSE cards — too small once the
     same class is reused on this full-width product page. Force a readable
     size here regardless of screen width. */
  #pdpActionsRow .qty-select,
  #pdpActionsRow .qdrop-trigger{
    font-size:13px !important; padding:0 22px 0 8px !important;
  }
  @media (max-width:640px){
    #pdpActionsRow .qty-select,
    #pdpActionsRow .qdrop-trigger{
      font-size:12.5px !important; height:44px !important; padding:0 22px 0 8px !important;
      background-size:14px 14px !important; background-position:right 6px center !important;
    }
  }
  .pdp-qty-group{
    display:flex; align-items:center; border:1px solid var(--border); border-radius:8px; overflow:hidden; height:44px; flex:0 0 auto;
  }
  .pdp-qty-group button{ width:32px; height:100%; background:var(--gray-50,#f9fafb); font-size:16px; font-weight:700; color:var(--text); border:none; cursor:pointer; flex-shrink:0; }
  .pdp-qty-group button:hover{ background:var(--green-light); }
  .pdp-qty-group input{
    width:34px; height:100%; border:none; border-left:1px solid var(--border); border-right:1px solid var(--border);
    text-align:center; font-size:14px; font-weight:700; -moz-appearance:textfield; min-width:0;
  }
  .pdp-qty-group input::-webkit-outer-spin-button, .pdp-qty-group input::-webkit-inner-spin-button{ -webkit-appearance:none; margin:0; }

  .pdp-btn{
    height:44px; padding:0 8px; border-radius:8px; font-weight:700; font-size:13.5px;
    display:flex; align-items:center; justify-content:center; gap:6px; border:none; cursor:pointer;
    flex:1 1 auto; min-width:0; white-space:nowrap; overflow:hidden; transition:opacity .15s ease, background .15s ease;
  }
  .pdp-btn-primary{ background:var(--orange,#ff7a00); color:#fff; }
  .pdp-btn-primary:hover{ opacity:.92; }
  .pdp-btn-primary:disabled{ background:var(--gray-300,#d1d5db); color:var(--muted); cursor:not-allowed; }

  /* Very narrow phones: drop the "Add to Cart" text, keep just the icon,
     so the row never needs to shrink the unit/qty pieces into uselessness. */
  @media (max-width:360px){
    .pdp-btn-primary span.btn-text{ display:none; }
    .pdp-btn-primary{ flex:0 0 44px; padding:0; }
  }

  /* ---------- Description ---------- */
  .pdp-desc{ font-size:13px; line-height:1.6; color:var(--muted); margin:0; }
  .pdp-desc-top{ margin-bottom:16px; }

  /* ---------- Specifications — plain bordered card with the heading
     sitting cleanly inside it (no fieldset/legend — that made the label
     look half-cut, sitting right on top of the border). ---------- */
  .pdp-specs-card{
    border:1px solid var(--border); border-radius:10px; margin:0 0 22px; padding:14px 14px 2px;
  }
  .pdp-specs-title{
    font-size:12px; font-weight:700; color:var(--green-dark); text-transform:uppercase; letter-spacing:.4px;
    margin:0 0 10px;
  }
  .pdp-specs{ list-style:none; padding:0; margin:0; }
  .pdp-specs li{
    display:flex; gap:14px; font-size:13px; line-height:1.5; padding:7px 0; border-bottom:1px solid var(--border);
  }
  .pdp-specs li:last-child{ border-bottom:none; }
  .pdp-specs li .k{ flex:0 0 140px; color:var(--muted); font-weight:600; }
  .pdp-specs li .v{ flex:1; color:var(--text); }
  @media (max-width:480px){
    .pdp-specs-card{ padding:12px 10px 2px; }
    .pdp-specs li .k{ flex:0 0 108px; }
  }

  /* ---------- Sliders (related / recommended) — 2-per-row on mobile,
     horizontally swipeable/scrollable, more columns visible on desktop. ---------- */
  .pdp-slider-wrap{ position:relative; }
  .pdp-slider{
    display:flex; gap:14px; overflow-x:auto; scroll-behavior:smooth; scrollbar-width:none; padding-bottom:4px;
  }
  .pdp-slider::-webkit-scrollbar{ display:none; }
  .pdp-slide{ flex:0 0 auto; width:210px; }
  .pdp-slider-arrow{
    position:absolute; top:50%; transform:translateY(-50%); width:36px; height:36px; border-radius:50%;
    background:#fff; border:1px solid var(--border); box-shadow:0 2px 8px rgba(0,0,0,.12);
    display:flex; align-items:center; justify-content:center; cursor:pointer; z-index:3; color:var(--text);
  }
  .pdp-slider-arrow.left{ left:-14px; }
  .pdp-slider-arrow.right{ right:-14px; }
  .pdp-slider-arrow:hover{ background:var(--green-light); }
  @media (max-width:900px){
    .pdp-slider-arrow{ display:none; } /* mobile: swipe instead of arrows */
    .pdp-slide{ width:calc(50% - 7px); } /* exactly 2 per row visible */
  }

  /* ---------- Section headings (reviews, related) ----------
     Every one of these headings always gets top spacing + a divider —
     each sits as the first (and only) <h2> inside its own wrapper, so a
     plain :first-of-type rule would strip the divider off more than one
     of them at once. The single exception is "Customer Reviews", which
     directly follows the gallery/info grid and gets a plain gap instead
     of a second divider line right under it. */
  .pdp-h2{ font-size:19px; font-weight:800; color:var(--text); margin:0 0 16px; padding-top:28px; border-top:1px solid var(--border); }
  .pdp-reviews-section > .pdp-h2{ border-top:none; padding-top:8px; }

  .pdp-review-item{ padding:16px 0; border-bottom:1px solid var(--border); }
  .pdp-review-item:last-child{ border-bottom:none; }
  .pdp-review-top{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:6px; }
  .pdp-review-name{ font-weight:700; font-size:14px; color:var(--text); }
  .pdp-review-stars{ color:#f59e0b; font-size:12.5px; white-space:nowrap; }
  .pdp-review-text{ font-size:13.5px; color:var(--muted); line-height:1.6; }

  .pdp-review-form{
    max-width:480px; margin-top:20px; background:#fff; border:1px solid var(--border); border-radius:12px;
    padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.03);
  }
  .pdp-review-form h3{ font-size:15px; font-weight:700; margin-bottom:4px; color:var(--text); }
  .pdp-verified-note{ font-size:12px; font-weight:600; color:var(--green-dark); margin:0 0 14px; }
  .pdp-verified-note i{ margin-right:4px; }

  /* Star-rating picker — 5 clickable stars instead of a plain <select>.
     A hidden native <select> still carries the actual value the form
     submits, so no server-side changes were needed; it's just visually
     replaced. Hover previews the rating, click locks it in. */
  .pdp-star-picker{ display:flex; align-items:center; gap:4px; margin-bottom:16px; }
  .pdp-star-picker button{
    background:none; border:none; padding:2px; cursor:pointer; font-size:22px; line-height:1;
    color:var(--gray-300,#d1d5db); transition:color .12s ease, transform .1s ease;
  }
  .pdp-star-picker button:hover{ transform:scale(1.12); }
  .pdp-star-picker button.filled{ color:#f59e0b; }
  .pdp-star-picker select{ display:none; }
  .pdp-star-picker-label{ font-size:12.5px; color:var(--muted); margin-left:6px; min-width:64px; }

  .pdp-review-form textarea{
    width:100%; border:1px solid var(--border); border-radius:8px; padding:11px 12px; font-size:13.5px;
    font-family:inherit; margin-bottom:14px; color:var(--text); resize:vertical; min-height:88px;
    transition:border-color .15s ease;
  }
  .pdp-review-form textarea:focus{ outline:none; border-color:var(--green); }
  .pdp-review-submit{
    height:42px; padding:0 22px; border-radius:8px; background:var(--green); color:#fff; font-weight:700;
    font-size:13.5px; border:none; cursor:pointer; transition:background .15s ease;
  }
  .pdp-review-submit:hover{ background:var(--green-dark); }
  .pdp-login-note{
    font-size:13.5px; color:var(--muted); margin-top:16px; background:var(--gray-50,#f9fafb);
    border:1px solid var(--border); border-radius:8px; padding:12px 14px;
  }
  .pdp-login-note a{ color:var(--green-dark); font-weight:600; }

  /* ---------- Mobile layout adjustments ---------- */
  @media (max-width:900px){
    .pdp-grid{ grid-template-columns:1fr; gap:24px; }
    .pdp-title{ font-size:18px; }
    .pdp-sp{ font-size:24px; }
    .pdp-h2{ font-size:17px; }
    .pdp-actions{ margin-bottom:12px; }
    /* keep content from hiding behind the floating "View Cart" bar once it appears */
    .pdp{ padding-bottom:84px; }
  }

  @media (max-width:480px){
    .pdp{ padding-left:12px; padding-right:12px; }
    .pdp-thumb{ width:52px; height:52px; }
  }
</style>

<div class="pdp">
    <nav class="pdp-crumb" aria-label="breadcrumb">
        <a href="/">Home</a>
        <?php if (!empty($product['category_id'])):
            $catStmt = $pdo->prepare("SELECT name, slug FROM ecom_categories WHERE id = ?");
            $catStmt->execute([$product['category_id']]);
            $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
            if ($cat): ?>
        <span class="sep">/</span>
        <a href="/shop/category.php?slug=<?= urlencode($cat['slug']) ?>"><?= htmlspecialchars($cat['name']) ?></a>
        <?php endif; endif; ?>
        <span class="sep">/</span>
        <span class="current"><?= htmlspecialchars($product['name']) ?></span>
    </nav>

    <div class="pdp-grid">
        <!-- ============ GALLERY ============ -->
        <div class="pdp-gallery">
            <?php if (count($slides) > 1): ?>
            <div class="pdp-thumbs">
                <?php foreach ($slides as $i => $s): if (!$s) continue; ?>
                <div class="pdp-thumb<?= $i === 0 ? ' active' : '' ?>" onclick="pdpSetMainImage('/<?= htmlspecialchars($s) ?>', <?= $i ?>)">
                    <img src="/<?= htmlspecialchars($s) ?>" alt="">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="pdp-main-wrap">
                <div class="pdp-main" id="pdpMainImageBox">
                    <?php if ($slides[0]): ?>
                        <img id="pdpMainImage" src="/<?= htmlspecialchars($slides[0]) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                    <?php else: ?>
                        <i class="fas fa-image placeholder-icon"></i>
                    <?php endif; ?>
                </div>
                <?php if (count($slides) > 1): ?>
                <div class="pdp-dots" id="pdpDots">
                    <?php foreach ($slides as $i => $s): if (!$s) continue; ?>
                    <span class="dot<?= $i === 0 ? ' active' : '' ?>"></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ INFO ============ -->
        <div class="pdp-info">
            <h1 class="pdp-title"><?= htmlspecialchars($product['name']) ?></h1>

            <?php if (count($reviews) > 0): ?>
            <div class="pdp-rating-row">
                <span class="pdp-rating-badge"><?= number_format($avg_rating, 1) ?> <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                <span class="pdp-review-count"><?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($product['description'])):
                // Flipkart-style description: one flowing paragraph, not a
                // stacked bullet list. Admins often type each point on its
                // own line in the textarea (with emojis, "•", "-" etc as
                // bullets) — collapse all of that down to plain spaced-out
                // text so it reads as a single paragraph here, regardless
                // of how it was typed in the admin panel.
                $desc_flat = preg_replace('/\s*\R+\s*/u', ' ', trim($product['description']));
                $desc_flat = preg_replace('/^[•\-\*\x{2022}]\s*/u', '', $desc_flat);
                $desc_flat = preg_replace('/\s*[•\x{2022}]\s*/u', ' ', $desc_flat);
                $desc_flat = trim(preg_replace('/\s{2,}/', ' ', $desc_flat));
            ?>
            <p class="pdp-desc pdp-desc-top"><?= htmlspecialchars($desc_flat) ?></p>
            <?php endif; ?>

            <div class="pdp-price-block" id="pdpPriceBlock">
                <div class="pdp-price-row">
                    <span class="pdp-sp" id="pdpSalePrice">₹<?= number_format($sale ?? $price, 2) ?></span>
                    <span class="pdp-mrp<?= !$sale ? ' d-none' : '' ?>" id="pdpMrp"><?= $sale ? '₹' . number_format($price, 2) : '' ?></span>
                    <span class="pdp-off<?= (!$sale || $off_pct <= 0) ? ' d-none' : '' ?>" id="pdpOffPct"><?= ($sale && $off_pct > 0) ? $off_pct . '% off' : '' ?></span>
                </div>
                <div class="pdp-tax-note">inclusive of all taxes</div>
            </div>

            <?php if ($product['product_type'] === 'physical'): ?>
            <p class="pdp-stock <?= $out_of_stock ? 'out' : 'in' ?>" id="pdpStockLine"><?= $out_of_stock ? 'Out of Stock' : 'In Stock' . ($disp['stock'] !== null ? ' (' . (int)$disp['stock'] . ' available)' : '') ?></p>
            <?php endif; ?>

            <!-- Unit/size + qty stepper + Add to Cart — kept on ONE line even on
                 narrow phones: each piece shrinks (dropdown/qty first) before the
                 button ever wraps, so nothing overflows or breaks to 2 lines. -->
            <div class="pdp-actions" id="pdpActionsRow">
                <?php if (!empty($variants)): ?>
                <select id="pdpVariantSelect" class="qty-select pdp-unit-select" onchange="pdpVariantChange(this)">
                    <?php foreach ($variants as $v):
                        $vp = (float)$v['price'];
                        $vs = (!empty($v['sale_price']) && (float)$v['sale_price'] > 0 && (float)$v['sale_price'] < $vp) ? (float)$v['sale_price'] : null;
                        $vstock = $v['stock_qty'];
                        $vsel = ((int)$v['id'] === (int)$disp['variant_id']) ? 'selected' : '';
                    ?>
                    <option value="<?= (int)$v['id'] ?>" data-mrp="<?= $vp ?>" data-sp="<?= $vs ?? $vp ?>" data-stock="<?= $vstock === null ? '' : (int)$vstock ?>" <?= $vsel ?>><?= htmlspecialchars($v['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <div class="pdp-qty-group<?= $out_of_stock ? ' d-none' : '' ?>" id="pdpQtyGroup">
                    <button type="button" onclick="stepQty(-1)">−</button>
                    <input type="number" id="qtyInput" value="1" min="1">
                    <button type="button" onclick="stepQty(1)">+</button>
                </div>
                <button class="pdp-btn pdp-btn-primary" id="pdpAddBtn" data-product-id="<?= $product['id'] ?>" data-variant-id="<?= (int)$disp['variant_id'] ?>" onclick="addToCart(this.dataset.productId, document.getElementById('qtyInput').value, this.dataset.variantId)" <?= $out_of_stock ? 'disabled' : '' ?>><i class="fas fa-cart-plus"></i> <span class="btn-text"><?= $out_of_stock ? 'Out of Stock' : 'Add to Cart' ?></span></button>
            </div>

            <!-- Share row — native device share sheet (Web Share API) when
                 the browser/OS supports it, else falls back to WhatsApp +
                 copy-link icon buttons. -->
            <div class="pdp-share-row">
                <span class="pdp-share-label">Share:</span>
                <button type="button" class="pdp-share-btn native" id="pdpShareNative" title="Share" aria-label="Share this product" onclick="pdpNativeShare()">
                    <i class="fas fa-share-alt"></i>
                </button>
                <a class="pdp-share-btn whatsapp" id="pdpShareWhatsapp" title="Share on WhatsApp" aria-label="Share on WhatsApp" target="_blank" rel="noopener noreferrer" href="#">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <button type="button" class="pdp-share-btn" id="pdpShareCopy" title="Copy link" aria-label="Copy product link" onclick="pdpCopyShareLink(this)">
                    <i class="fas fa-link"></i>
                </button>
            </div>
            <div class="pdp-share-toast" id="pdpShareToast">Link copied!</div>

            <?php if (!empty($specs)): ?>
            <div class="pdp-specs-card">
                <h3 class="pdp-specs-title">Specifications</h3>
                <ul class="pdp-specs">
                    <?php foreach ($specs as $s): ?>
                    <li><span class="k"><?= htmlspecialchars($s['spec_key']) ?></span><span class="v"><?= htmlspecialchars($s['spec_value']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ REVIEWS ============
         Whole section stays hidden unless there's something to show: either
         existing reviews, or this customer has a verified purchase they can
         review. No "you're not eligible" messaging — same as Flipkart, the
         section simply doesn't exist for a visitor/product with neither. -->
    <?php if (!empty($reviews) || $reviewable_order_id): ?>
    <div class="pdp-reviews-section">
        <h2 class="pdp-h2">Customer Reviews</h2>

        <?php if ($review_msg): ?><div class="alert alert-success"><?= htmlspecialchars($review_msg) ?></div><?php endif; ?>

        <?php foreach ($reviews as $r): ?>
            <div class="pdp-review-item">
                <div class="pdp-review-top">
                    <span class="pdp-review-name"><?= htmlspecialchars($r['customer_name']) ?></span>
                    <span class="pdp-review-stars"><?php for ($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= $r['rating'] ? 'fas' : 'far' ?> fa-star"></i><?php endfor; ?></span>
                </div>
                <?php if (!empty($r['review_text'])): ?><p class="pdp-review-text"><?= htmlspecialchars($r['review_text']) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($reviewable_order_id): ?>
        <form method="POST" class="pdp-review-form" id="pdpReviewForm">
            <h3>Write a Review</h3>
            <p class="pdp-verified-note"><i class="fas fa-check-circle"></i> Verified purchase — Order #<?= (int)$reviewable_order_id ?></p>
            <input type="hidden" name="order_id" value="<?= (int)$reviewable_order_id ?>">
            <div class="pdp-star-picker" id="pdpStarPicker">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="<?= $i <= 5 ? 'filled' : '' ?>" data-value="<?= $i ?>" aria-label="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>"><i class="fas fa-star"></i></button>
                <?php endfor; ?>
                <span class="pdp-star-picker-label" id="pdpStarLabel">Excellent</span>
                <select name="rating" id="pdpRatingSelect" required>
                    <option value="5" selected>Excellent</option>
                    <option value="4">Good</option>
                    <option value="3">Average</option>
                    <option value="2">Poor</option>
                    <option value="1">Terrible</option>
                </select>
            </div>
            <textarea name="review_text" placeholder="Share your experience…"></textarea>
            <button type="submit" class="pdp-review-submit">Submit Review</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($related)): ?>
    <h2 class="pdp-h2">More from this Category</h2>
    <div class="pdp-slider-wrap">
        <button class="pdp-slider-arrow left" onclick="pdpScrollSlider('pdpRelatedSlider', -1)" aria-label="Scroll left"><i class="fas fa-chevron-left"></i></button>
        <div class="pdp-slider" id="pdpRelatedSlider">
            <?php foreach ($related as $rp): ?>
            <div class="pdp-slide"><?php renderCmartProductCard($rp); ?></div>
            <?php endforeach; ?>
        </div>
        <button class="pdp-slider-arrow right" onclick="pdpScrollSlider('pdpRelatedSlider', 1)" aria-label="Scroll right"><i class="fas fa-chevron-right"></i></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($recommended)): ?>
    <h2 class="pdp-h2"><?= !empty($_SESSION['customer_id']) ? 'Recommended for You' : 'Recommended' ?></h2>
    <div class="pdp-slider-wrap">
        <button class="pdp-slider-arrow left" onclick="pdpScrollSlider('pdpRecommendedSlider', -1)" aria-label="Scroll left"><i class="fas fa-chevron-left"></i></button>
        <div class="pdp-slider" id="pdpRecommendedSlider">
            <?php foreach ($recommended as $rp): ?>
            <div class="pdp-slide"><?php renderCmartProductCard($rp); ?></div>
            <?php endforeach; ?>
        </div>
        <button class="pdp-slider-arrow right" onclick="pdpScrollSlider('pdpRecommendedSlider', 1)" aria-label="Scroll right"><i class="fas fa-chevron-right"></i></button>
    </div>
    <?php endif; ?>
</div>

<script>
function stepQty(delta) {
    const input = document.getElementById('qtyInput');
    input.value = Math.max(1, parseInt(input.value || 1) + delta);
}

// ── Gallery: click a thumbnail (desktop) to swap the main image ──
function pdpSetMainImage(src, index) {
    document.getElementById('pdpMainImage').src = src;
    document.querySelectorAll('.pdp-thumb').forEach((t, i) => t.classList.toggle('active', i === index));
    document.querySelectorAll('#pdpDots .dot').forEach((d, i) => d.classList.toggle('active', i === index));
}

// ── Gallery: swipe left/right on the main image to move through photos (mobile) ──
(function () {
    const box = document.getElementById('pdpMainImageBox');
    const thumbs = document.querySelectorAll('.pdp-thumb');
    if (!box || thumbs.length < 2) return;

    let startX = 0;
    box.addEventListener('touchstart', e => { startX = e.changedTouches[0].screenX; }, { passive: true });
    box.addEventListener('touchend', e => {
        const delta = e.changedTouches[0].screenX - startX;
        if (Math.abs(delta) < 40) return;

        let idx = Array.from(thumbs).findIndex(t => t.classList.contains('active'));
        if (idx === -1) idx = 0;
        idx = delta < 0 ? Math.min(idx + 1, thumbs.length - 1) : Math.max(idx - 1, 0);

        const target = thumbs[idx];
        pdpSetMainImage(target.querySelector('img').src, idx);
    }, { passive: true });
})();

// ── Size/Unit selector — updates price + stock live. ──
function pdpVariantChange(sel) {
    const opt = sel.selectedOptions[0];
    if (!opt) return;
    const mrp = parseFloat(opt.dataset.mrp), sp = parseFloat(opt.dataset.sp), stock = opt.dataset.stock;
    const off = mrp > sp ? Math.round(mrp - sp) : 0;
    const offPct = mrp > 0 ? Math.round((off / mrp) * 100) : 0;

    const spText = '₹' + sp.toFixed(2);
    const mrpText = '₹' + mrp.toFixed(2);

    document.getElementById('pdpSalePrice').textContent = spText;
    const mrpEl = document.getElementById('pdpMrp'), offEl = document.getElementById('pdpOffPct');
    if (off > 0) {
        mrpEl.textContent = mrpText; mrpEl.classList.remove('d-none');
        offEl.textContent = offPct + '% off'; offEl.classList.remove('d-none');
    } else {
        mrpEl.classList.add('d-none'); offEl.classList.add('d-none');
    }

    const isOut = stock !== '' && Number(stock) <= 0;
    const btnHtml = isOut
        ? '<span class="btn-text">Out of Stock</span>'
        : '<i class="fas fa-cart-plus"></i> <span class="btn-text">Add to Cart</span>';

    const addBtn = document.getElementById('pdpAddBtn');
    addBtn.dataset.variantId = opt.value;
    addBtn.disabled = isOut;
    addBtn.innerHTML = btnHtml;
    document.getElementById('pdpQtyGroup').classList.toggle('d-none', isOut);

    const stockLine = document.getElementById('pdpStockLine');
    if (stockLine) {
        stockLine.className = 'pdp-stock ' + (isOut ? 'out' : 'in');
        stockLine.textContent = isOut ? 'Out of Stock' : 'In Stock' + (stock !== '' ? ' (' + stock + ' available)' : '');
    }
}

// ── "More from this Category" / "Recommended for You" sliders ──
function pdpScrollSlider(id, direction) {
    const el = document.getElementById(id);
    if (!el) return;
    el.scrollBy({ left: direction * (el.clientWidth * 0.8), behavior: 'smooth' });
}

// ── Share — native device share sheet where supported, WhatsApp deep link +
// copy-to-clipboard as fallbacks so every browser/OS has a working option. ──
const PDP_SHARE_URL  = <?= json_encode($share_url) ?>;
const PDP_SHARE_TEXT = <?= json_encode($share_text) ?>;

(function () {
    const nativeBtn = document.getElementById('pdpShareNative');
    const waLink = document.getElementById('pdpShareWhatsapp');
    if (waLink) {
        waLink.href = 'https://wa.me/?text=' + encodeURIComponent(PDP_SHARE_TEXT + ' ' + PDP_SHARE_URL);
    }
    // Web Share API isn't available on every browser (notably desktop
    // Chrome/Firefox without a share target) — hide the native button there
    // so people aren't shown a button that does nothing; WhatsApp + copy-link
    // still cover those cases.
    if (nativeBtn && !navigator.share) {
        nativeBtn.style.display = 'none';
    }
})();

function pdpNativeShare() {
    if (navigator.share) {
        navigator.share({ title: PDP_SHARE_TEXT, text: PDP_SHARE_TEXT, url: PDP_SHARE_URL }).catch(() => {});
    } else {
        pdpCopyShareLink(document.getElementById('pdpShareCopy'));
    }
}

function pdpShareToast(msg) {
    const toast = document.getElementById('pdpShareToast');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => toast.classList.remove('show'), 1800);
}

function pdpCopyShareLink(btn) {
    const done = () => {
        pdpShareToast('Link copied!');
        if (btn) {
            const icon = btn.querySelector('i');
            btn.classList.add('copied');
            if (icon) icon.className = 'fas fa-check';
            setTimeout(() => {
                btn.classList.remove('copied');
                if (icon) icon.className = 'fas fa-link';
            }, 1500);
        }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(PDP_SHARE_URL).then(done).catch(() => {
            const ta = document.createElement('textarea');
            ta.value = PDP_SHARE_URL; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            done();
        });
    } else {
        const ta = document.createElement('textarea');
        ta.value = PDP_SHARE_URL; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
    }
}

// ── "Write a Review" star picker — 5 clickable stars standing in for the
// rating <select> (kept, hidden, as the actual form value so no backend
// change was needed). Click sets the rating; hovering previews it. ──
(function () {
    const picker = document.getElementById('pdpStarPicker');
    if (!picker) return;
    const stars = Array.from(picker.querySelectorAll('button'));
    const select = document.getElementById('pdpRatingSelect');
    const label = document.getElementById('pdpStarLabel');
    const labels = { 5: 'Excellent', 4: 'Good', 3: 'Average', 2: 'Poor', 1: 'Terrible' };

    function paint(value) {
        stars.forEach(s => s.classList.toggle('filled', Number(s.dataset.value) <= value));
        label.textContent = labels[value] || '';
    }

    paint(Number(select.value) || 5);

    stars.forEach(star => {
        star.addEventListener('mouseenter', () => paint(Number(star.dataset.value)));
        star.addEventListener('click', () => {
            select.value = star.dataset.value;
            paint(Number(star.dataset.value));
        });
    });
    picker.addEventListener('mouseleave', () => paint(Number(select.value)));
})();
</script>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
