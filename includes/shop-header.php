<?php
/**
 * Shared storefront header. Expects (all optional):
 *   $page_title, $shop_customer (array|null, current logged-in customer)
 * Provides: $cart_count (int), $cart (array of product_id => qty from session)
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$shop_customer = null;
if (!empty($_SESSION['customer_id'])) {
    $cstmt = $pdo->prepare("SELECT id, name, email FROM ecom_customers WHERE id = ? AND status = 'active'");
    $cstmt->execute([$_SESSION['customer_id']]);
    $shop_customer = $cstmt->fetch(PDO::FETCH_ASSOC);
    if (!$shop_customer) { unset($_SESSION['customer_id'], $_SESSION['customer_name']); }
}

$cart = $_SESSION['shop_cart'] ?? [];
$cart_count = array_sum($cart);

$shop_categories = $pdo->query("SELECT id, name, slug FROM ecom_categories WHERE status='active' ORDER BY serial ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$shop_biz = $pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$shop_biz_name = $shop_biz['business_name'] ?: $site_name;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? htmlspecialchars($page_title) . ' - ' : '' ?><?= htmlspecialchars($shop_biz_name) ?> Shop</title>
<?php if (!empty($shop_biz['seo_description'])): ?><meta name="description" content="<?= htmlspecialchars($shop_biz['seo_description']) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --danger: #ef4444;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-700: #374151;
    --gray-900: #111827;
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
}
* { box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: var(--gray-50); color: var(--gray-900); margin: 0; }
a { text-decoration: none; }

.shop-header { background: #fff; border-bottom: 1px solid var(--gray-200); position: sticky; top: 0; z-index: 200; }
.shop-header-inner { max-width: 1200px; margin: 0 auto; padding: 0.9rem 1.25rem; display: flex; align-items: center; gap: 1.5rem; }
.shop-brand { display: flex; align-items: center; gap: 0.6rem; font-size: 1.25rem; font-weight: 800; color: var(--primary); flex-shrink: 0; }
.shop-brand .icon { width: 38px; height: 38px; border-radius: 0.6rem; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; display: flex; align-items: center; justify-content: center; }
.shop-nav-links { display: flex; align-items: center; gap: 1.5rem; flex: 1; }
.shop-nav-links a { color: var(--gray-700); font-weight: 500; font-size: 0.9375rem; }
.shop-nav-links a:hover { color: var(--primary); }
.shop-nav-links .dropdown-menu { border-radius: var(--radius); }
.shop-header-actions { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; }
.shop-icon-btn { position: relative; width: 42px; height: 42px; border-radius: 50%; background: var(--gray-50); display: flex; align-items: center; justify-content: center; color: var(--gray-700); font-size: 1.05rem; }
.shop-icon-btn:hover { background: var(--gray-100); color: var(--primary); }
.shop-badge { position: absolute; top: -3px; right: -3px; background: var(--danger); color: #fff; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.shop-account-link { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.9375rem; color: var(--gray-700); }
.shop-account-link:hover { color: var(--primary); }

.shop-container { max-width: 1200px; margin: 0 auto; padding: 1.75rem 1.25rem; }

.btn-shop-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-shop-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); color: #fff; }
.btn-outline-shop { border: 1px solid var(--primary); color: var(--primary); background: #fff; }
.btn-outline-shop:hover { background: var(--primary-lighter); color: var(--primary); }

.product-card { background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; transition: box-shadow .15s, transform .15s; height: 100%; display: flex; flex-direction: column; }
.product-card:hover { box-shadow: 0 10px 25px rgba(0,0,0,.08); transform: translateY(-2px); }
.product-card .thumb { aspect-ratio: 1/1; background: var(--gray-50); display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; }
.product-card .thumb img { width: 100%; height: 100%; object-fit: cover; }
.product-card .thumb .badge-corner { position: absolute; top: 0.6rem; left: 0.6rem; }
.product-card .wish-btn { position: absolute; top: 0.6rem; right: 0.6rem; width: 34px; height: 34px; border-radius: 50%; background: #fff; border: none; display: flex; align-items: center; justify-content: center; color: var(--gray-500); box-shadow: 0 2px 6px rgba(0,0,0,.15); }
.product-card .wish-btn.active, .product-card .wish-btn:hover { color: var(--danger); }
.product-card .body { padding: 1rem; display: flex; flex-direction: column; flex: 1; }
.product-card .name { font-weight: 600; font-size: 0.9375rem; color: var(--gray-900); margin-bottom: 0.3rem; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.product-card .price-row { margin-top: auto; padding-top: 0.5rem; display: flex; align-items: baseline; gap: 0.5rem; }
.product-card .price-now { font-weight: 700; color: var(--gray-900); font-size: 1.05rem; }
.product-card .price-old { text-decoration: line-through; color: var(--gray-400); font-size: 0.85rem; }

.section-title { font-size: 1.35rem; font-weight: 800; margin-bottom: 1rem; }
.cat-chip { display: inline-flex; align-items: center; padding: 0.5rem 1rem; border-radius: 9999px; background: #fff; border: 1px solid var(--gray-200); color: var(--gray-700); font-weight: 500; font-size: 0.875rem; margin: 0 0.4rem 0.6rem 0; }
.cat-chip:hover, .cat-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }

.shop-footer { background: #111827; color: #d1d5db; margin-top: 3rem; padding: 2.5rem 1.25rem 1.5rem; }
.shop-footer .inner { max-width: 1200px; margin: 0 auto; }
.shop-footer h6 { color: #fff; font-weight: 700; margin-bottom: 0.75rem; }
.shop-footer a { color: #9ca3af; }
.shop-footer a:hover { color: #fff; }
.shop-footer .bottom { border-top: 1px solid #374151; margin-top: 1.5rem; padding-top: 1.25rem; text-align: center; font-size: 0.85rem; color: #6b7280; }

@media (max-width: 767px) {
    .shop-nav-links { display: none; }
}
</style>
</head>
<body>

<header class="shop-header">
    <div class="shop-header-inner">
        <a href="/shop/" class="shop-brand">
            <?php
            $header_mode = $shop_biz['site_header_display'] ?? 'both';
            $has_logo = !empty($shop_biz['logo']);
            if (($header_mode === 'logo' || $header_mode === 'both') && $has_logo):
                $header_logo_w = min((int)($shop_biz['logo_display_width'] ?? 40), 48);
            ?>
                <img src="/<?= htmlspecialchars($shop_biz['logo']) ?>" style="height:<?= $header_logo_w ?>px;width:auto;border-radius:0.4rem;">
            <?php else: ?>
                <div class="icon"><i class="fas fa-store"></i></div>
            <?php endif; ?>
            <?php if ($header_mode === 'title' || $header_mode === 'both' || !$has_logo): ?>
                <span><?= htmlspecialchars($shop_biz_name) ?></span>
            <?php endif; ?>
        </a>

        <nav class="shop-nav-links">
            <a href="/shop/">Home</a>
            <div class="dropdown">
                <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">Categories</a>
                <ul class="dropdown-menu">
                    <?php foreach ($shop_categories as $cat): ?>
                    <li><a class="dropdown-item" href="/shop/category.php?slug=<?= urlencode($cat['slug']) ?>"><?= htmlspecialchars($cat['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </nav>

        <form action="/shop/index.php" method="GET" class="d-none d-md-block" style="flex:1; max-width:340px;">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search products…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        </form>

        <div class="shop-header-actions">
            <?php if ($shop_customer): ?>
            <a href="/shop/wishlist.php" class="shop-icon-btn" title="Wishlist"><i class="far fa-heart"></i></a>
            <?php endif; ?>
            <a href="/shop/cart.php" class="shop-icon-btn" title="Cart">
                <i class="fas fa-shopping-cart"></i>
                <?php if ($cart_count > 0): ?><span class="shop-badge"><?= $cart_count ?></span><?php endif; ?>
            </a>

            <?php if ($shop_customer): ?>
            <div class="dropdown">
                <a href="#" class="shop-account-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle fa-lg"></i> <span class="d-none d-md-inline"><?= htmlspecialchars(explode(' ', $shop_customer['name'])[0]) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="/shop/account.php"><i class="fas fa-user me-2"></i> My Account</a></li>
                    <li><a class="dropdown-item" href="/shop/account.php#orders"><i class="fas fa-box me-2"></i> My Orders</a></li>
                    <li><a class="dropdown-item" href="/shop/wishlist.php"><i class="fas fa-heart me-2"></i> Wishlist</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="/shop/logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
            <?php else: ?>
            <a href="/shop/login.php" class="btn btn-shop-primary btn-sm">Login</a>
            <?php endif; ?>
        </div>
    </div>
</header>
