<?php
/**
 * Shared storefront header — Cmart-style design, fully wired to real data.
 * Expects (all optional): $page_title
 * Provides: $shop_customer, $cart_count, $cart_total, $shop_categories, $shop_biz
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$shop_customer = null;
if (!empty($_SESSION['customer_id'])) {
    $cstmt = $pdo->prepare("SELECT id, name, email FROM ecom_customers WHERE id = ? AND status = 'active'");
    $cstmt->execute([$_SESSION['customer_id']]);
    $shop_customer = $cstmt->fetch(PDO::FETCH_ASSOC);
    if (!$shop_customer) { unset($_SESSION['customer_id'], $_SESSION['customer_name']); }
}

// Cart lives in the database (ecom_cart_items), not in $_SESSION — this is
// what makes it survive a refresh and follow a logged-in customer across
// devices. See cart-helpers.php for the full read/write logic.
require_once __DIR__ . '/cart-helpers.php';
$cart_count = cartCount($pdo);
$cart_total = cartTotal($pdo);

$shop_categories = $pdo->query("SELECT id, name, slug FROM ecom_categories WHERE status='active' ORDER BY serial ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$shop_biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$shop_biz_name = $shop_biz['business_name'] ?: $site_name;
$shop_biz_location = $shop_biz['location'] ?: '';

// Homepage Customizer → General settings (header behaviour). Guarded so the
// storefront still renders even before the customizer migration has run.
$home_settings = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM ecom_home_settings") as $row) {
        $home_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $e) {
    $home_settings = [];
}
$shop_delivery_label = $home_settings['delivery_label'] ?? "We're open";
$shop_search_placeholder = $home_settings['search_placeholder'] ?? 'Search for products';
$shop_show_location = ($home_settings['show_location'] ?? '1') === '1';
$shop_show_delivery_info = ($home_settings['show_delivery_info'] ?? '1') === '1';
// Homepage Customization > General > Header Info can override the delivery-time text shown
// next to the clock icon; if left blank there, Business Settings' business_hours is used.
$shop_delivery_time_text = trim($home_settings['delivery_time_text'] ?? '') !== ''
    ? trim($home_settings['delivery_time_text'])
    : ($shop_biz['business_hours'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? htmlspecialchars($page_title) . ' - ' : '' ?><?= htmlspecialchars($shop_biz_name) ?></title>
<?php if (!empty($shop_biz['seo_description'])): ?><meta name="description" content="<?= htmlspecialchars($shop_biz['seo_description']) ?>"><?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>

  /* =====================================================================
     This block is a byte-faithful port of demo-home.html's own <style>,
     because that demo file was itself built by porting this site's real
     header/nav/mobile-drawer markup into it (see the "ported from user's
     real site header.php" comments further down this file) — so its CSS
     is the correct, complete stylesheet for the whole storefront, not
     just the homepage. Do not hand-edit class rules above the
     "Compatibility layer" comment below; port future demo revisions here
     wholesale instead, the same way, so drift like this doesn't happen
     again.
     ===================================================================== */
  :root{
    --green:#2e8b3d;
    --green-dark:#1f6b2c;
    --green-light:#eaf7ea;
    --orange:#ff7a00;
    --text:#1f2328;
    --muted:#6b7280;
    --border:#e5e7eb;
    --bg:#f7f8f7;
  }
  *{box-sizing:border-box;margin:0;padding:0;}
  body{
    font-family:'Segoe UI', Arial, Helvetica, sans-serif;
    color:var(--text);
    background:#fff;
  }
  a{text-decoration:none;color:inherit;}
  ul{list-style:none;}
  img{max-width:100%;display:block;}
  button{font-family:inherit;cursor:pointer;border:none;}

  /* ===== Header ===== */
  .topbar{
    display:flex;
    align-items:center;
    gap:20px;
    padding:12px 32px;
    border-bottom:1px solid var(--border);
    height:76px;
  }
  .hamburger-mobile{display:none;}
  .logo{
    display:flex;
    align-items:center;
    gap:6px;
    flex-shrink:0;
  }
  .logo .mark{height:44px;width:auto;max-width:150px;flex-shrink:0;display:block;border-radius:4px;object-fit:contain;}
  .logo-text{line-height:1;}
  .logo-text .brand{
    font-size:26px;
    font-weight:800;
    color:var(--green-dark);
    letter-spacing:0.2px;
  }
  .logo-text .tagline{
    font-size:13px;
    color:var(--orange);
    font-style:italic;
    font-weight:700;
    margin-top:-2px;
    position:relative;
  }
  .logo-text .tagline svg{width:56px;height:8px;display:block;margin-top:-3px;}

  .location{
    display:flex;
    align-items:center;
    gap:6px;
    padding:6px 12px;
    background:var(--green-light);
    border-radius:8px;
    font-size:14px;
    white-space:nowrap;
    flex-shrink:0;
    height:44px;
  }
  .location .pin{color:var(--green);width:18px;height:18px;flex-shrink:0;}
  .location .addr{font-weight:700;display:flex;align-items:center;gap:4px;}
  .location .addr svg{width:12px;height:12px;}
  .location .city{color:var(--muted);display:block;font-size:12px;}

  .delivery-info{font-size:13px;white-space:nowrap;flex-shrink:0;color:#333;}
  .delivery-info .hl{color:var(--green);font-weight:700;}
  .delivery-info .slot{font-weight:700;display:flex;align-items:center;gap:5px;margin-top:3px;}
  .delivery-info .slot svg{width:14px;height:14px;color:var(--orange);}

  .search-wrap{
    flex:1;
    min-width:200px;
    display:flex;
    height:44px;
  }
  .search-wrap input{
    flex:1;
    width:100%;
    padding:0 16px;
    border:1px solid var(--border);
    border-right:none;
    border-radius:6px 0 0 6px;
    font-size:14px;
    outline:none;
    color:#333;
  }
  .search-wrap input::placeholder{color:#8a8a8a;}
  .search-wrap input::placeholder b{color:#333;}
  .search-wrap button{
    background:var(--green);
    color:#fff;
    padding:0 26px;
    border-radius:0 6px 6px 0;
    font-weight:700;
    font-size:13px;
    letter-spacing:0.4px;
  }
  .header-actions{
    display:flex;
    align-items:center;
    gap:26px;
    white-space:nowrap;
    flex-shrink:0;
  }
  .header-actions .item{
    display:flex;
    align-items:center;
    gap:7px;
    font-size:14px;
    font-weight:600;
    color:#333;
  }
  .header-actions .icon{
    width:22px;height:22px;color:var(--green);flex-shrink:0;
  }
  .cart-badge{
    display:flex;align-items:center;gap:6px;
    position:relative;
  }
  .cart-badge .count{
    position:absolute;top:-9px;left:13px;
    background:#fdd835;color:#333;
    font-size:10px;font-weight:700;
    border-radius:50%;width:16px;height:16px;
    display:flex;align-items:center;justify-content:center;
  }

  /* ===== Nav ===== */
  .navbar{
    display:flex;
    flex-wrap:nowrap;
    align-items:center;
    justify-content:flex-start;
    gap:32px;
    padding:14px 32px;
    border-bottom:1px solid var(--border);
    overflow-x:auto;
    font-size:14px;
    font-weight:600;
  }
  /* Bootstrap ships its own .navbar component (display:flex;justify-content:space-between)
     which collides with our plain nav — the rules above pin the layout back to a simple
     left-aligned row so Bootstrap's navbar defaults can never spread/center these links. */
  .navbar .all-cat{
    display:flex;align-items:center;gap:8px;
    flex-shrink:0;
  }
  .navbar .all-cat svg{width:18px;height:18px;}
  .navbar a{color:#333;flex-shrink:0;padding-bottom:4px;}
  .navbar a:hover{color:var(--green);}
  .navbar a.active{color:#111;text-decoration:underline;text-underline-offset:6px;}

  /* ===== Mobile header ===== */
  .mobile-topbar{display:none;}

  /* ===== Hero ===== */
  .hero{
    background:linear-gradient(135deg,#dbeeff 0%,#eef6ff 60%,#ffffff 100%);
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:50px 60px;
    flex-wrap:wrap;
    gap:20px;
  }
  .hero-text h1{
    font-size:44px;
    font-weight:800;
    color:#1a1a1a;
  }
  .hero-text .sub{
    margin-top:14px;
    font-size:18px;
    color:#333;
    border-top:2px solid var(--green-dark);
    padding-top:10px;
    display:inline-block;
  }
  .hero-text .shop-now{
    display:inline-block;
    margin-top:26px;
    background:var(--green);
    color:#fff;
    padding:12px 28px;
    border-radius:4px;
    font-weight:700;
    font-size:14px;
  }
  .hero-icons{
    display:flex;
    gap:18px;
    align-items:flex-end;
  }
  .hero-icons .icon-box{
    width:120px;height:120px;
    background:#fff;
    border-radius:16px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 8px 20px rgba(0,0,0,0.08);
    font-size:52px;
  }

  /* ===== Banner Slider ===== */
  /* Content-style slider — same markup/behaviour as shop/demo-home.html:
     admin fills in heading/subtext/button/icon (Homepage Settings -> Banner
     Slider) and picks one of 10 ready-made templates below, no banner image
     upload needed. Sizes match the demo exactly (170px desktop / 130px
     mobile / 200px tablet). */
  .banner-slider{
    position:relative;
    margin:16px 0;
    height:150px;
    border-radius:12px;
    overflow:hidden;
    touch-action:pan-y;
  }
  .banner-slider .slide{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:14px 44px;
    opacity:0;
    pointer-events:none;
    transition:opacity .5s ease;
  }
  .banner-slider .slide.active{opacity:1;pointer-events:auto;}
  .slide.tpl-1 {background:linear-gradient(120deg,#ffe27a,#ffd23f);}
  .slide.tpl-2 {background:linear-gradient(120deg,#bfe9c9,#e8f8ec);}
  .slide.tpl-3 {background:linear-gradient(120deg,#cfe3ff,#eef6ff);}
  .slide.tpl-4 {background:linear-gradient(120deg,#ffd0d8,#ffe9ee);}
  .slide.tpl-5 {background:linear-gradient(120deg,#e0d4ff,#f2ecff);}
  .slide.tpl-6 {background:linear-gradient(120deg,#ffd9b3,#fff0e0);}
  .slide.tpl-7 {background:linear-gradient(120deg,#a8e6e0,#e0f7f5);}
  .slide.tpl-8 {background:linear-gradient(120deg,#ffb3ba,#ffe3e5);}
  .slide.tpl-9 {background:linear-gradient(120deg,#3a3f47,#585f6b);}
  .slide.tpl-9 .slide-copy h2, .slide.tpl-9 .slide-copy p{color:#fff;}
  .slide.tpl-10{background:linear-gradient(120deg,#d4f7a0,#eefcd8);}
  .banner-slider .slide .badge{
    position:absolute;
    top:12px;right:18px;
    background:#fff;
    border-radius:8px;
    padding:5px 11px;
    font-size:10px;
    font-weight:700;
    color:#666;
    box-shadow:0 4px 10px rgba(0,0,0,0.08);
  }
  .slide-copy{
    max-width:62%;
    min-width:0;
  }
  .slide-copy h2{
    font-size:24px;
    font-weight:800;
    color:#7a2b0a;
    line-height:1.15;
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;
    overflow:hidden;
  }
  .banner-slider p{
    margin-bottom:0 !important;
  }
  .slide-copy p{
    margin-top:6px;
    font-size:13px;
    color:#7a2b0a;
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:1;
    overflow:hidden;
  }
  .slide-copy .pill-btn{
    display:inline-block;
    margin-top:6px;
    background:#c62828;
    color:#fff;
    padding:8px 20px;
    border-radius:30px;
    font-weight:700;
    font-size:12px;
    text-decoration:none;
  }
  .slide-copy .pill-btn:hover,
  .slide-copy .pill-btn:focus,
  .slide-copy .pill-btn:visited{
    color:#fff;
    text-decoration:none;
  }
  .slide-visual{
    flex-shrink:0;
    width:110px;height:110px;
    border-radius:50%;
    background:rgba(255,255,255,0.35);
    display:flex;align-items:center;justify-content:center;
    font-size:52px;
    overflow:hidden;
  }
  .slide-visual img{width:100%;height:100%;object-fit:cover;border-radius:50%;}

  .slider-dots{
    position:absolute;
    bottom:16px;left:50%;
    transform:translateX(-50%);
    display:flex;
    align-items:center;
    gap:8px;
  }
  .slider-dots .dot{
    width:22px;height:22px;
    padding:0;
    border:none;
    background:none;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
  }
  .slider-dots .dot::before{
    content:"";
    width:8px;height:8px;
    border-radius:50%;
    background:rgba(0,0,0,0.25);
  }
  .slider-dots .dot.active::before{
    background:#333;
  }
  .slider-dots .counter{
    background:rgba(0,0,0,0.65);
    color:#fff;
    font-size:12px;
    font-weight:700;
    padding:4px 12px;
    border-radius:20px;
  }

  /* ===== Centered page container (avoids full-bleed oversized layout on large screens) ===== */
  .page-container{
    max-width:1360px;
    margin:0 auto;
    padding:0 32px;
  }
  .page-container .banner-slider,
  .page-container .icon-strip,
  .page-container .cat-row-title,
  .page-container .cat-cards,
  .page-container .festive-banner,
  .page-container .section,
  .page-container .design-gallery-title,
  .page-container .design-label,
  .page-container .card2-grid{
    padding-left:0;
    padding-right:0;
    margin-left:0;
    margin-right:0;
  }

  /* ===== Icon strip scroller ===== */
  .icon-strip{
    display:flex;
    justify-content:center;
    gap:22px;
    padding:22px 0;
    overflow-x:auto;
    scrollbar-width:none;
  }
  .icon-strip::-webkit-scrollbar{display:none;}
  .icon-strip .strip-item{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:8px;
    flex-shrink:0;
    width:76px;
  }
  .icon-strip .strip-item .circle{
    width:52px;height:52px;
    border-radius:14px;
    display:flex;align-items:center;justify-content:center;
    font-size:22px;
  }
  .icon-strip .strip-item span{
    font-size:12px;
    font-weight:600;
    color:#333;
    text-align:center;
  }
  .c1{background:#2f6fed;color:#fff;}
  .c2{background:#dff2e3;}
  .c3{background:#fdf1d8;}
  .c4{background:#fbe4ea;}
  .c5{background:#222;color:#fff;}
  .c6{background:#e5f6e8;}
  .c7{background:#eaf1ff;}

  /* ===== Category card rows ===== */
  .cat-row-title{
    font-size:20px;
    font-weight:800;
    padding:20px 0 4px;
  }
  .cat-cards{
    display:grid;
    grid-template-columns:repeat(8,1fr);
    gap:20px 16px;
    padding:12px 0 30px;
  }
  .cat-cards .ccard{
    width:auto;
    text-align:center;
  }
  .cat-cards .ccard .thumb{
    width:100%;
    aspect-ratio:1/1;
    background:#eaf2fb;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    gap:4px;
    margin-bottom:10px;
  }
  .cat-cards .ccard .thumb span{font-size:30px;}
  .cat-cards .ccard .thumb img{
    width:100%;
    height:100%;
    object-fit:cover;
    border-radius:12px;
  }
  .cat-cards .ccard p{
    font-size:17px;
    font-weight:700;
    color:#222;
    line-height:1.3;
  }

  /* ===== Popular Categories ===== */
  .section{
    padding:36px 32px;
  }
  .section h2{
    font-size:22px;
    font-weight:800;
    margin-bottom:20px;
  }
  .sec-title-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:20px;
  }
  .sec-title-row h2{margin-bottom:0;}
  .sec-more-circle{
    flex-shrink:0;
    width:32px;
    height:32px;
    border-radius:50%;
    background:var(--bg);
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--green);
    font-size:13px;
    text-decoration:none;
    transition:background .15s ease;
  }
  .sec-more-circle:hover{background:var(--green-light);color:var(--green-dark);}
  .cat-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(120px,1fr));
    gap:18px;
  }
  .cat-card{
    text-align:center;
  }
  .cat-card .box{
    background:var(--green-light);
    border:1px solid var(--border);
    border-radius:8px;
    aspect-ratio:1/1;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:36px;
    margin-bottom:8px;
  }
  .cat-card span{font-size:13px;font-weight:600;color:#333;}

  /* ===== Festive banner ===== */
  .festive-banner{
    margin:0 32px 36px;
    background:linear-gradient(120deg,#fdf3e3,#fffaf0);
    border-radius:12px;
    padding:40px;
    text-align:center;
    position:relative;
    overflow:hidden;
  }
  .festive-banner h2{
    color:#8a3b12;
    font-size:30px;
    letter-spacing:1px;
  }
  .festive-banner .leaf{
    position:absolute;
    font-size:60px;
    top:10px;
    opacity:0.85;
  }
  .festive-banner .leaf.left{left:20px;transform:scaleX(-1);}
  .festive-banner .leaf.right{right:20px;}

  /* ===== Product grid ===== */
  .product-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(230px,1fr));
    gap:20px;
  }
  .product-card{
    border:1px solid var(--border);
    border-radius:8px;
    padding:16px;
    display:flex;
    flex-direction:column;
    gap:10px;
  }
  .product-card .img-wrap{
    height:130px;
    display:flex;align-items:center;justify-content:center;
    background:#fafafa;
    border-radius:6px;
    font-size:44px;
    overflow:hidden;
  }
  .product-card .img-wrap img{width:100%;height:100%;object-fit:cover;border-radius:6px;font-size:11px;color:var(--muted);}
  .product-card .title{font-size:14px;font-weight:600;line-height:1.4;white-space:normal;overflow-wrap:break-word;overflow:visible;}
  .off-badge, .qty-box, .price-row .prices{white-space:normal;overflow-wrap:break-word;overflow:visible;}
  .cat-cards .ccard p{white-space:normal;overflow-wrap:break-word;overflow:visible;}
  .price-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
  }
  .price-row .prices{font-size:13px;}
  .price-row .prices .label{color:var(--muted);font-size:11px;margin-right:4px;}
  .price-row .mrp{text-decoration:line-through;color:var(--muted);margin-right:6px;}
  .price-row .sp{font-weight:800;font-size:16px;}
  .off-badge{
    background:var(--green-light);
    color:var(--green-dark);
    font-weight:700;
    font-size:13px;
    padding:7px 10px;
    border-radius:4px;
    text-align:center;
    line-height:1.2;
    white-space:nowrap;
    flex:0 0 auto;
  }
  .off-badge small{display:inline;font-weight:600;font-size:11px;margin-left:3px;}
  .qty-cart{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:8px 10px;
  }
  .qty-box{
    border:1px solid var(--border);
    border-radius:6px;
    padding:8px 8px;
    font-size:13px;
    width:76px;
    height:44px;
    flex-shrink:0;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    justify-content:center;
  }
  .qty-box small{display:block;color:var(--muted);font-size:9px;line-height:1.2;overflow-wrap:break-word;}
  .qty-select{
    border:1px solid var(--border);
    border-radius:6px;
    padding:0 26px 0 10px;
    font-size:13px;
    font-weight:600;
    color:var(--text);
    height:44px;
    flex:0 0 auto;
    background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%236b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>') no-repeat right 8px center/14px 14px;
    -webkit-appearance:none;
    -moz-appearance:none;
    appearance:none;
    cursor:pointer;
    font-family:inherit;
  }
  .qty-select:focus{outline:2px solid var(--green);outline-offset:1px;}

  /* ===== Custom qty dropdown (replaces the native OS picker sheet) =====
     The real <select class="qty-select"> stays in the DOM (hidden) so all
     existing JS (handleQtyChange, add-to-cart variant lookup) keeps
     working untouched — this just draws a fully custom, same-styled
     trigger + option list on top of it, so the open list looks like the
     rest of the site instead of the phone/OS's native picker sheet. */
  .qty-select.js-enhanced{
    position:absolute;
    width:1px;height:1px;
    padding:0;margin:-1px;
    overflow:hidden;
    clip:rect(0,0,0,0);
    white-space:nowrap;
    border:0;
  }
  .qdrop{
    position:relative;
    flex:0 0 auto;
  }
  .qdrop-trigger{
    border:1px solid var(--border);
    border-radius:6px;
    padding:0 26px 0 10px;
    font-size:13px;
    font-weight:600;
    color:var(--text);
    height:44px;
    background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%236b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>') no-repeat right 8px center/14px 14px;
    display:flex;
    align-items:center;
    cursor:pointer;
    font-family:inherit;
    width:100%;
    text-align:left;
  }
  .qdrop-trigger:focus{outline:2px solid var(--green);outline-offset:1px;}
  .qdrop.open .qdrop-trigger{border-color:var(--green);}
  .qdrop.open .qdrop-trigger{background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%232e8b3d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>');}
  .qdrop-list{
    position:absolute;
    top:calc(100% + 6px);
    left:0;
    min-width:120px;
    max-width:220px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:8px;
    box-shadow:0 8px 24px rgba(0,0,0,0.12);
    padding:6px;
    z-index:50;
    display:none;
    max-height:220px;
    overflow-y:auto;
  }
  .qdrop.open .qdrop-list{display:block;}
  .qdrop-option{
    padding:10px 12px;
    font-size:13.5px;
    font-weight:600;
    color:var(--text);
    border-radius:6px;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
  }
  .qdrop-option:hover{background:var(--green-light);}
  .qdrop-option.selected{background:var(--green-light);color:var(--green-dark);}
  .qdrop-option.selected::after{
    content:"";
    width:7px;height:12px;
    flex-shrink:0;
    border-right:2px solid var(--green-dark);
    border-bottom:2px solid var(--green-dark);
    transform:rotate(45deg);
    margin-top:-2px;
  }
  @media (max-width:640px){
    .qdrop-trigger{height:36px !important;padding:0 22px 0 8px !important;font-size:10.5px !important;background-size:12px 12px !important;background-position:right 6px center !important;}
    .qdrop-list{min-width:100px;}
  }

  .add-cart{
    flex:1 1 140px;
    min-width:0;
    height:44px;
    background:var(--green);
    color:#fff;
    border-radius:6px;
    font-weight:700;
    font-size:12.5px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    padding:0 6px;
  }
  .add-cart svg{width:16px;height:16px;flex-shrink:0;fill:none;stroke:#fff;stroke-width:2;}

  /* ===== Qty stepper — replaces "ADD TO CART" once the item is in the cart.
     Same footprint/height as .add-cart so the layout never jumps. ===== */
  .qty-stepper{
    flex:1 1 140px;
    min-width:0;
    height:44px;
    background:var(--green);
    border-radius:6px;
    display:flex;
    align-items:stretch;
    overflow:hidden;
  }
  .qty-stepper button{
    flex:0 0 38px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:18px;
    font-weight:800;
    line-height:1;
    background:transparent;
    border:none;
    padding:0;
    margin:0;
    cursor:pointer;
    font-family:inherit;
  }
  .qty-stepper button:active{background:rgba(0,0,0,0.12);}
  .qty-stepper button:disabled{opacity:0.5;cursor:not-allowed;}
  .qty-stepper .qty-stepper-count{
    flex:1 1 auto;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-weight:700;
    font-size:14px;
    min-width:0;
  }
  .qty-stepper.updating{pointer-events:none;}



  /* ===== 5 alternate card designs — image always left, full-width bottom action row ===== */
  .design-gallery-title{
    font-size:22px;
    font-weight:800;
    padding:26px 32px 4px;
  }
  .design-label{
    font-size:15px;
    font-weight:700;
    color:var(--green-dark);
    padding:18px 32px 10px;
  }
  .card2-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(240px,1fr));
    gap:18px;
    padding:0 32px 10px;
  }
  .card2{
    border:1px solid var(--border);
    border-radius:10px;
    padding:14px;
    display:flex;
    flex-direction:column;
    gap:12px;
  }
  .card2 .top2{display:flex;gap:14px;}
  .card2 .img2{
    width:100px;height:100px;flex-shrink:0;
    background:#fafafa;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:38px;position:relative;
  }
  .card2 .img2 img{width:100%;height:100%;object-fit:cover;border-radius:8px;}
  .card2 .info2{flex:1;min-width:0;display:flex;flex-direction:column;gap:6px;justify-content:center;}
  .card2 .title2{font-size:14px;font-weight:600;line-height:1.35;overflow-wrap:break-word;color:#1a1a1a;}
  .card2 .price2{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13px;}
  .card2 .price2 .mrp2{text-decoration:line-through;color:var(--muted);}
  .card2 .price2 .sp2{font-weight:800;font-size:16px;color:#111;}
  .card2 .offer2{
    display:inline-block;width:fit-content;
    background:var(--green-light);color:var(--green-dark);
    font-weight:700;font-size:11px;padding:3px 8px;border-radius:4px;
  }
  .card2 .bottom2{display:flex;align-items:center;gap:10px;width:100%;}
  .card2 .qty2{
    border:1px solid var(--border);border-radius:6px;
    padding:9px 12px;font-size:12.5px;width:110px;height:44px;flex-shrink:0;text-align:left;
    line-height:1.3;overflow:hidden;display:flex;flex-direction:column;justify-content:center;
  }
  .card2 .qty2 small{display:block;color:var(--muted);font-size:10.5px;overflow-wrap:break-word;margin-top:2px;}
  .card2 .cart2{
    flex:0 0 auto;
    width:150px;
    height:44px;
    min-width:0;background:var(--green);color:#fff;border-radius:6px;
    font-weight:700;font-size:12.5px;display:flex;
    align-items:center;justify-content:center;gap:5px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .card2 .cart2 svg{width:16px;height:16px;flex-shrink:0;fill:none;stroke:#fff;stroke-width:2;}
  .card2 .qty-stepper{flex:0 0 auto;width:150px;height:44px;}

  /* Design 2 — ribbon discount badge on image */
  .card2.design2 .ribbon{
    position:absolute;top:8px;left:-6px;
    background:#c62828;color:#fff;font-size:10px;font-weight:700;
    padding:3px 9px;border-radius:2px 6px 6px 2px;
    box-shadow:0 2px 4px rgba(0,0,0,.2);
  }

  /* Design 4 — two-tone card, shaded bottom action strip */
  .card2.design4{padding:0;overflow:hidden;gap:0;}
  .card2.design4 .top2{padding:14px;}
  .card2.design4 .bottom2{
    background:var(--green-light);
    padding:12px 14px;margin:0;
  }
  .card2.design4 .qty2{background:#fff;}

  /* Design 5 — compact chip style */
  .card2.design5 .top2{gap:10px;}
  .card2.design5 .img2{width:70px;height:70px;font-size:28px;}
  .card2.design5 .title2{font-size:13px;}
  .card2.design5 .price2{gap:6px;}
  .card2.design5 .offer2{font-size:10px;padding:2px 6px;}
  .card2.design5 .bottom2{gap:6px;}
  .card2.design5 .qty2{display:flex;align-items:center;justify-content:center;font-weight:700;min-width:60px;}
  .card2.design5 .cart2{font-size:12px;}

  @media (min-width:641px) and (max-width:900px){
    .card2-grid{grid-template-columns:repeat(2,1fr);}
  }
  @media (max-width:640px){
    .card2-grid{grid-template-columns:1fr !important;padding:0 16px 8px;}
    .design-gallery-title{padding:22px 16px 4px;font-size:19px;}
    .design-label{padding:14px 16px 8px;}
    .page-container{padding:0 16px;}
    .card2 .cart2{flex:1;width:auto;}
    .card2 .qty-stepper{flex:1;width:auto;}
  }

  /* ===================================================================
     Ported from the user's real site (components/header.php sidebar +
     assets/css/app.css footer) — used to power the mobile nav drawer
     and the real site footer in this static UI demo.
     =================================================================== */

  /* ---- Mobile nav drawer (sidebar) ---- */
  #sidebar{
    --drawer-accent:#7c3aed;
    --drawer-ink:#1d1d1f;
    position:fixed;top:0;left:0;
    width:min(380px,88vw);max-width:100vw;height:100dvh;
    background:#ffffff;
    box-shadow:0 16px 40px rgba(124,58,237,0.18);
    transition:transform .25s ease;
    z-index:1001;
    display:flex;flex-direction:column;
    will-change:transform;
    transform:translateX(-101%);
    color:var(--drawer-ink);
  }
  #sidebar.active{transform:translateX(0);}
  .sidebar-top{flex:0 0 auto;border-bottom:1px solid #ebe5ff;}
  .sidebar-actions{
    display:grid;grid-template-columns:auto auto minmax(0,1fr) auto;
    align-items:center;gap:8px;padding:12px 16px;
  }
  .sidebar-icon-btn{
    flex:0 0 auto;display:grid;place-items:center;
    width:34px;height:34px;border:1px solid #ebe5ff;border-radius:999px;
    background:none;color:var(--drawer-ink);cursor:pointer;
    transition:color .15s ease,border-color .15s ease;
  }
  .sidebar-icon-btn svg{fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;}
  .sidebar-icon-btn--badge svg{fill:#fff;stroke:none;}
  .sidebar-icon-btn:hover{color:var(--drawer-accent);border-color:var(--drawer-accent);}
  .sidebar-icon-btn--static{cursor:default;}
  .sidebar-icon-btn--search{border-color:transparent;}
  .sidebar-icon-btn--badge{border-color:transparent;background:var(--drawer-accent);color:#fff;}
  .sidebar-icon-btn--badge:hover{color:#fff;opacity:.85;}
  .sidebar-weather{
    display:inline-flex;align-items:center;justify-content:center;gap:4px;
    min-width:0;height:34px;padding:4px 12px;border:1px solid #999;border-radius:14px;
    color:var(--drawer-ink);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }
  .sidebar-weather svg{fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;}
  .sidebar-account{padding:8px 20px 16px;}
  .sidebar-welcome{display:flex;align-items:center;gap:12px;margin-bottom:16px;font-size:15px;font-weight:700;color:var(--drawer-ink);}
  .sidebar-avatar{display:grid;place-items:center;flex:0 0 auto;width:44px;height:44px;border-radius:999px;background:#f0f0f0;color:var(--drawer-accent);}
  .sidebar-avatar svg{fill:none;stroke:currentColor;stroke-width:1.8;}
  .sidebar-signin{
    display:block;width:100%;padding:12px;border:none;border-radius:8px;
    background:var(--drawer-accent);color:#fff;font-size:15px;font-weight:700;
    text-align:center;text-decoration:none;cursor:pointer;transition:opacity .15s ease;
  }
  .sidebar-signin:hover{opacity:.9;}
  .sidebar-preferences{display:flex;align-items:center;gap:8px;padding:12px 20px;background:#fafafa;overflow:hidden;}
  .sidebar-edition{flex:0 0 auto;font-size:12px;font-weight:700;color:#4b5563;}
  .sidebar-pref-select{
    display:inline-flex;align-items:center;justify-content:space-between;gap:4px;
    flex:1 1 0;min-width:0;padding:4px 8px;border:1px solid #333;border-radius:4px;
    background:#fff;color:var(--drawer-ink);font-size:12px;cursor:pointer;
  }
  .sidebar-pref-select span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sidebar-pref-select svg{fill:none;stroke:currentColor;stroke-width:2;}
  .sidebar-plus{flex:0 0 auto;font-family:Georgia,serif;font-size:17px;font-weight:700;color:var(--drawer-ink);}
  .sidebar-plus sup{color:var(--drawer-accent);font-size:.6em;}
  .sidebar-nav{flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px 12px;}
  .sidebar-nav-item{
    display:flex;align-items:center;gap:12px;width:100%;padding:12px;
    border:none;border-radius:0;background:none;color:var(--drawer-ink);
    font-family:inherit;font-size:15px;font-weight:400;text-align:left;
    text-decoration:none;cursor:pointer;
  }
  .sidebar-nav-item:hover, .sidebar-nav-item:focus-visible{color:var(--drawer-accent);}
  .sidebar-nav-icon{display:grid;place-items:center;flex:0 0 auto;width:22px;height:22px;color:inherit;}
  .sidebar-nav-icon svg{width:100%;height:100%;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
  .sidebar-nav-toggle{margin-top:8px;padding-top:16px;border-top:1px solid #ebe5ff;}
  .sidebar-social{flex:0 0 auto;padding:12px 20px 16px;border-top:1px solid #ebe5ff;background:#f7f7f7;text-align:center;}
  .sidebar-social-title{margin:0 0 8px;font-size:13px;color:#555;}
  .sidebar-social-row{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;row-gap:12px;}
  .sidebar-social-link{
    display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;
    border-radius:999px;border:none;background:var(--drawer-accent);
    text-decoration:none;transition:opacity .15s ease,transform .15s ease;
  }
  .sidebar-social-link svg{fill:#fff;}
  .sidebar-social-link:hover{opacity:.85;transform:translateY(-2px);}
  @media (min-width:769px){ #sidebar, #overlay{display:none !important;} }

  /* ---- Overlay backdrop ---- */
  #overlay{
    position:fixed;top:0;left:0;width:100%;height:100vh;
    background:rgba(15,23,42,0.55);
    opacity:0;visibility:hidden;
    transition:opacity .25s ease,visibility .25s ease;
    z-index:999;
  }
  #overlay.active{opacity:1;visibility:visible;}

  /* ---- Real site footer ---- */
  .site-footer{
    --f-space-2:8px;--f-space-3:12px;--f-space-4:16px;--f-space-6:24px;--f-space-8:32px;
    --f-text-xs:12px;--f-text-sm:14px;--f-text-lg:18px;
    --f-weight-medium:500;--f-weight-semibold:600;
    --f-radius-full:9999px;--f-container-lg:1140px;
    position:relative;background:#241a3d;
    padding-top:50px;overflow:hidden;margin-top:20px;
  }
  .footer-wave-top{display:none;}
  .footer-inner{position:relative;z-index:1;max-width:var(--f-container-lg);margin:0 auto;padding:var(--f-space-8) var(--f-space-6) var(--f-space-8);}
  .footer-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--f-space-6);padding-bottom:var(--f-space-6);border-bottom:1px solid rgba(255,255,255,0.1);}
  .footer-brand-wrap{display:flex;flex-direction:column;gap:8px;}
  .footer-brand{font-size:var(--f-text-lg);font-weight:var(--f-weight-semibold);color:#fff;letter-spacing:-0.01em;line-height:1.2;}
  .footer-tagline{max-width:320px;font-size:var(--f-text-sm);line-height:1.7;color:rgba(255,255,255,0.72);margin:0;}
  .footer-nav ul{list-style:none;display:flex;flex-wrap:wrap;gap:var(--f-space-2) var(--f-space-6);align-items:center;margin:0;padding:0;}
  .footer-nav a{font-size:var(--f-text-sm);font-weight:var(--f-weight-medium);color:rgba(255,255,255,0.7);text-decoration:none;}
  .footer-nav a:hover{color:#fff;}
  .footer-bottom{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:var(--f-space-3);padding-top:var(--f-space-6);}
  .footer-copy{font-size:var(--f-text-xs);color:rgba(255,255,255,0.5);letter-spacing:0.01em;margin:0;}
  .footer-credit{
    display:inline-flex;align-items:center;gap:var(--f-space-2);font-size:var(--f-text-xs);
    color:#fff;text-decoration:none;padding:5px 12px;border:1px solid rgba(255,255,255,0.35);
    border-radius:var(--f-radius-full);background:rgba(255,255,255,0.12);transition:all .25s ease;
  }
  .footer-credit svg{width:13px;height:13px;flex-shrink:0;}
  .footer-credit:hover{scale:0.95;color:#fff;}
  @media (max-width:640px){
    .footer-top{flex-direction:column;align-items:flex-start;}
    .footer-nav ul{gap:var(--f-space-2) var(--f-space-4);}
    .footer-bottom{flex-direction:column;align-items:center;}
  }

  /* ===== Professional multi-column footer content ===== */
  .footer-main{
    display:grid;
    grid-template-columns:1.3fr 1fr 1fr 1.2fr;
    gap:36px 40px;
    padding-bottom:36px;
    border-bottom:1px solid rgba(255,255,255,0.1);
  }
  .footer-col h4{
    color:#fff;
    font-size:15px;
    font-weight:700;
    margin-bottom:16px;
    letter-spacing:.2px;
  }
  .footer-about p{
    font-size:13.5px;
    line-height:1.7;
    color:rgba(255,255,255,0.65);
    max-width:280px;
    margin-bottom:18px;
  }
  .footer-about .f-logo{
    display:flex;align-items:center;gap:10px;margin-bottom:14px;
  }
  .footer-about .f-logo img{height:38px;width:auto;max-width:130px;border-radius:4px;object-fit:contain;}
  .footer-about .f-logo span{color:#fff;font-size:18px;font-weight:800;}
  .footer-social-row{display:flex;gap:10px;}
  .footer-social-row a{
    width:34px;height:34px;border-radius:50%;
    background:rgba(255,255,255,0.08);
    display:flex;align-items:center;justify-content:center;
    border:1px solid rgba(255,255,255,0.12);
    transition:background .2s ease,transform .2s ease;
  }
  .footer-social-row a svg{width:15px;height:15px;fill:#fff;}
  .footer-social-row a:hover{background:var(--green);transform:translateY(-2px);}
  .footer-links{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:11px;}
  .footer-links a{
    font-size:13.5px;color:rgba(255,255,255,0.65);
    display:inline-flex;align-items:center;gap:7px;
  }
  .footer-links a:hover{color:#fff;}
  .footer-links a::before{
    content:"";width:5px;height:5px;border-radius:50%;
    background:var(--green);flex-shrink:0;
  }
  .footer-contact-list{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:14px;}
  .footer-contact-list li{
    display:flex;align-items:flex-start;gap:10px;
    font-size:13.5px;color:rgba(255,255,255,0.7);line-height:1.5;
  }
  .footer-contact-list svg{width:17px;height:17px;flex-shrink:0;margin-top:1px;color:var(--green);}

  @media (max-width:900px){
    .footer-main{grid-template-columns:1fr 1fr;gap:28px;}
  }
  @media (max-width:560px){
    .footer-main{grid-template-columns:1fr 1fr;gap:16px 14px;}
    .footer-col h4{font-size:13.5px;margin-bottom:10px;}
    .footer-links{gap:8px;}
    .footer-links a{font-size:12.5px;}
  }

  /* ===== Responsive ===== */
  @media (max-width:900px){
    .navbar{padding:10px 16px;}
    .hero{padding:30px 24px;flex-direction:column;text-align:center;}
    .hero-text .sub{display:block;}
    .section{padding:24px 16px;}
    .festive-banner{margin:0 16px 24px;}
    .cat-cards{grid-template-columns:repeat(6,1fr);}
  }
  @media (max-width:900px){
    .topbar{display:none;}
    .navbar{display:none;}

    .mobile-topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:14px 16px;
      border-bottom:1px solid var(--border);
    }
    .mobile-topbar .hamburger-mobile{
      display:block;
      width:24px;height:24px;color:#333;
    }
    .mobile-topbar .logo .mark{height:34px;width:auto;max-width:110px;}
    .mobile-topbar .logo-text .brand{font-size:20px;}
    .mobile-topbar .logo-text .tagline{font-size:11px;}
    .mobile-topbar .logo-text .tagline svg{width:44px;}
    .mobile-actions{
      display:flex;
      align-items:center;
      gap:18px;
    }
    .mobile-actions .icon{width:24px;height:24px;color:var(--green);}
    .mobile-actions .cart-badge .count{top:-8px;left:14px;}

    .mobile-search{
      padding:12px 16px 16px;
      border-bottom:1px solid var(--border);
    }
    .mobile-search .box{
      display:flex;
      align-items:center;
      gap:10px;
      background:#eee;
      border-radius:6px;
      padding:12px 14px;
    }
    .mobile-search svg{width:18px;height:18px;color:#666;flex-shrink:0;}
    .mobile-search span{color:#8a8a8a;font-size:14px;}
    .mobile-search span b{color:#333;font-weight:700;}
  }
  @media (min-width:901px){
    .mobile-topbar, .mobile-search{display:none;}
  }

  @media (max-width:640px){
    .hero-text h1{font-size:28px;}
    .hero-icons{display:none;}
    .cat-grid{grid-template-columns:repeat(4,1fr);}

    .banner-slider{margin:12px 16px;height:115px;border-radius:10px;}
    .banner-slider .slide{padding:0 16px;}
    .slide-copy h2{font-size:17px;line-height:1.1;}
    .slide-copy p{font-size:11px;margin-top:4px;}
    .slide-copy .pill-btn{padding:6px 14px;font-size:10.5px;margin-top:4px;}
    .slide-visual{width:56px;height:56px;font-size:26px;}
    .banner-slider .slide .badge{font-size:8px;padding:4px 7px;top:8px;right:8px;}

    .icon-strip{padding:16px;gap:16px;justify-content:flex-start;}
    .icon-strip .strip-item{width:64px;}
    .icon-strip .strip-item .circle{width:50px;height:50px;font-size:22px;}

    .cat-row-title{padding:16px 16px 4px;font-size:17px;}
    .cat-cards{padding:8px 16px 18px;gap:14px 8px;grid-template-columns:repeat(4,1fr);}
    .cat-cards .ccard .thumb{border-radius:8px;}
    .cat-cards .ccard .thumb img{border-radius:8px;}
    .cat-cards .ccard .thumb span{font-size:16px;}
    .cat-cards .ccard p{font-size:11px;}

    /* ===== Product grid: 2 per row on mobile (Flipkart/Myntra style) ===== */
    .product-grid{
      grid-template-columns:repeat(2,1fr) !important;
      gap:10px !important;
    }
    .product-card{
      padding:8px !important;
      gap:6px !important;
      border-radius:8px;
    }
    .product-card .img-wrap{
      height:90px !important;
      font-size:26px;
    }
    .product-card .title{
      font-size:12px !important;
      -webkit-line-clamp:2;
      display:-webkit-box;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .price-row{
      gap:4px !important;
    }
    .price-row .prices{font-size:11px !important;}
    .price-row .prices .label{font-size:9px !important;}
    .price-row .sp{font-size:13px !important;}
    .off-badge{
      font-size:10px !important;
      padding:5px 8px !important;
    }
    .off-badge small{font-size:9px !important;}
    .qty-cart{
      gap:6px !important;
    }
    .qty-box{
      width:auto !important;
      flex:1 1 auto;
      min-width:50px;
      height:36px !important;
      padding:4px 6px !important;
      font-size:10.5px !important;
    }
    .qty-box small{font-size:8px !important;}
    .qty-select{
      flex:1 1 auto;
      min-width:60px;
      height:36px !important;
      padding:0 22px 0 8px !important;
      font-size:10.5px !important;
      background-size:12px 12px !important;
      background-position:right 6px center !important;
    }
    .add-cart{
      flex:1 1 100% !important;
      height:36px !important;
      font-size:11px !important;
      padding:0 4px !important;
    }
    .add-cart svg{width:13px;height:13px;}
  }

  /* Small tablets: keep product grid readable */
  @media (min-width:641px) and (max-width:900px){
    .product-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));}
  }

  /* ===== Tablet (641px–900px): banner/category sizing between phone-compact and full desktop ===== */
  @media (min-width:641px) and (max-width:900px){
    .banner-slider{height:175px;margin:16px 24px;}
    .banner-slider .slide{padding:0 32px;}
    .slide-copy h2{font-size:26px;}
    .slide-copy p{font-size:13px;}
    .slide-visual{width:110px;height:110px;font-size:52px;}

    .icon-strip{padding:20px 24px;gap:20px;}
    .icon-strip .strip-item{width:72px;}
    .icon-strip .strip-item .circle{width:56px;height:56px;font-size:24px;}

    .cat-row-title{padding:20px 24px 4px;}
    .cat-cards{padding:10px 24px 24px;}

    .festive-banner{margin:0 24px 30px;}
    .section{padding:30px 24px;}
    .design-gallery-title{padding:24px 24px 4px;}
    .design-label{padding:16px 24px 8px;}
    .card2-grid{padding:0 24px 10px;}
  }

/* =====================================================================
   Compatibility layer — product.php, cart.php, checkout.php, account.php
   and wishlist.php still use these generic component classes/variables.
   Kept separate from the Cmart theme above so neither collides with it.
   ===================================================================== */
:root {
    --primary: #2e8b3d;
    --primary-dark: #1f6b2c;
    --primary-light: #eaf7ea;
    --primary-lighter: #eaf7ea;
    --success: #10b981;
    --danger: #ef4444;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-700: #374151;
    --gray-900: #111827;
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
}
.shop-container { max-width: 1200px; margin: 0 auto; padding: 1.75rem 1.25rem; }
.section-title { font-size: 1.35rem; font-weight: 800; margin-bottom: 1rem; }
.btn-shop-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-shop-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); color: #fff; }
.btn-outline-shop { border: 1px solid var(--primary); color: var(--primary); background: #fff; }
.btn-outline-shop:hover { background: var(--primary-lighter); color: var(--primary); }
.cat-chip { display: inline-flex; align-items: center; padding: 0.5rem 1rem; border-radius: 9999px; background: #fff; border: 1px solid var(--gray-200); color: var(--gray-700); font-weight: 500; font-size: 0.875rem; margin: 0 0.4rem 0.6rem 0; text-decoration: none; }
.cat-chip:hover, .cat-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
</style>
</head>
<body>

  <!-- ============ DESKTOP HEADER ============ -->
  <header class="topbar">
    <a href="/" class="logo" aria-label="<?= htmlspecialchars($shop_biz_name) ?> home">
      <?php if (!empty($shop_biz['logo'])): ?>
        <img class="mark" src="/<?= htmlspecialchars($shop_biz['logo']) ?>" alt="<?= htmlspecialchars($shop_biz_name) ?> logo">
      <?php else: ?>
        <div class="logo-text"><span class="brand"><?= htmlspecialchars($shop_biz_name) ?></span></div>
      <?php endif; ?>
    </a>

    <?php if ($shop_show_location && $shop_biz_location): ?>
    <div class="location">
      <svg class="pin" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C7.6 2 4 5.6 4 10c0 5.4 8 12 8 12s8-6.6 8-12c0-4.4-3.6-8-8-8zm0 11a3 3 0 110-6 3 3 0 010 6z"/></svg>
      <div>
        <span class="addr"><?= htmlspecialchars(mb_strimwidth($shop_biz_location, 0, 12, '')) ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </span>
        <?php if (!empty($shop_biz['address'])): ?>
        <span class="city"><?= htmlspecialchars(mb_strimwidth(trim($shop_biz['address']), 0, 18, '...')) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($shop_show_delivery_info && !empty($shop_delivery_time_text)): ?>
    <div class="delivery-info">
      <span class="hl"><?= htmlspecialchars($shop_delivery_label) ?></span>
      <div class="slot">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        <?= htmlspecialchars($shop_delivery_time_text) ?>
      </div>
    </div>
    <?php endif; ?>

    <form class="search-wrap" action="/" method="GET">
      <input type="text" name="q" placeholder="<?= htmlspecialchars($shop_search_placeholder) ?>" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
      <button type="submit">SEARCH</button>
    </form>

    <div class="header-actions">
      <?php if ($shop_customer): ?>
      <a href="/shop/account.php" class="item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span class="label"><?= htmlspecialchars(explode(' ', $shop_customer['name'])[0]) ?></span>
      </a>
      <?php else: ?>
      <a href="/shop/login.php" class="item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
        <span class="label">Login</span>
      </a>
      <?php endif; ?>
      <a href="/shop/wishlist.php" class="item">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>
      </a>
      <a href="/shop/cart.php" class="item cart-badge">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg>
        <span class="count"><?= $cart_count ?></span>
        <span class="label">₹<?= number_format($cart_total, 0) ?></span>
      </a>
    </div>
  </header>

  <nav class="navbar">
    <a href="/" <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'class="active"' : '' ?>>All Categories</a>
    <?php foreach ($shop_categories as $cat): ?>
    <a href="/shop/category.php?slug=<?= urlencode($cat['slug']) ?>" <?= (($_GET['slug'] ?? '') === $cat['slug']) ? 'class="active"' : '' ?>><?= htmlspecialchars($cat['name']) ?></a>
    <?php endforeach; ?>
  </nav>

  <!-- ============ MOBILE HEADER ============ -->
  <div class="mobile-topbar">
    <svg class="hamburger-mobile" id="menuToggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    <a href="/" class="logo" aria-label="<?= htmlspecialchars($shop_biz_name) ?> home">
      <?php if (!empty($shop_biz['logo'])): ?>
        <img class="mark" src="/<?= htmlspecialchars($shop_biz['logo']) ?>" alt="<?= htmlspecialchars($shop_biz_name) ?> logo">
      <?php else: ?>
        <span class="brand" style="font-size:20px;font-weight:800;color:var(--green-dark);"><?= htmlspecialchars($shop_biz_name) ?></span>
      <?php endif; ?>
    </a>
    <div class="mobile-actions">
      <a href="/shop/wishlist.php"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg></a>
      <a href="/shop/cart.php" class="cart-badge">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg>
        <span class="count"><?= $cart_count ?></span>
      </a>
      <a href="<?= $shop_customer ? '/shop/account.php' : '/shop/login.php' ?>"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg></a>
    </div>
  </div>
  <form class="mobile-search" action="/" method="GET">
    <div class="box">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" name="q" placeholder="<?= htmlspecialchars($shop_search_placeholder) ?>" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="border:none;outline:none;background:none;font-size:13px;flex:1;color:#333;">
    </div>
  </form>

  <!-- ===== Centered content container ===== -->
  <div class="page-container">
