<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

$page_title = 'Shop';
include __DIR__ . '/includes/shop-header.php';
include __DIR__ . '/includes/product-card.php';

$q = trim($_GET['q'] ?? '');

// ── Search mode: simple results grid, no homepage sections ──────────────────
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' " . outOfStockFilterSql($pdo) . " AND name LIKE ? ORDER BY created_at DESC LIMIT 60");
    $stmt->execute(["%$q%"]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="section">
        <h2>Search results for "<?= htmlspecialchars($q) ?>"</h2>
        <?php if (empty($products)): ?>
            <div class="text-center py-5" style="color:var(--muted);">
                <i class="fas fa-box-open fa-3x mb-3"></i>
                <p>No products found for your search.</p>
            </div>
        <?php else: ?>
            <div class="product-grid"><?php foreach ($products as $p) { renderCmartProductCard($p); } ?></div>
        <?php endif; ?>
    </section>
    <?php include __DIR__ . '/includes/shop-footer.php'; ?>
    <?php exit; ?>
<?php } ?>

<?php
// ═══════════════════════════════════════════════════════════════════════════
// Homepage v5 — driven entirely by the new Homepage Customizer (7 tabs).
// Guarded with try/catch so a fresh install (v5 migration not yet run) still
// renders a usable homepage instead of a fatal error.
// ═══════════════════════════════════════════════════════════════════════════
$home_v5_missing = false;
$v5 = [];
$strip_colors = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'];

try {
    foreach ($pdo->query("SELECT * FROM ecom_home_settings_v5") as $row) { $v5[$row['setting_key']] = $row['setting_value']; }

    $typography = [];
    foreach ($pdo->query("SELECT * FROM ecom_home_typography") as $row) { $typography[$row['setting_key']] = $row; }

    $section_order = array_values(array_filter(explode(',', $v5['section_order'] ?? 'slider,category_strip,trending,popular,festive_banner,category_sections')));
    $show = [
        'slider'            => ($v5['show_slider'] ?? '1') === '1',
        'category_strip'    => ($v5['show_category_strip'] ?? '1') === '1',
        'trending'          => ($v5['show_trending'] ?? '1') === '1',
        'popular'           => ($v5['show_popular'] ?? '1') === '1',
        'festive_banner'    => ($v5['show_festive_banner'] ?? '1') === '1',
        'category_sections' => ($v5['show_category_sections'] ?? '1') === '1',
    ];

    // ── 1. Slider ──
    $slides = $show['slider'] ? $pdo->query("SELECT * FROM ecom_home_slider WHERE status='active' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

    // ── 2. Category strip ──
    $strip_mode = $v5['home_strip_mode'] ?? 'all';
    $strip_limit = max(1, (int)($v5['home_strip_limit'] ?? 10));
    $strip_display = [];
    if ($show['category_strip']) {
        if ($strip_mode === 'manual') {
            $rows = $pdo->query("
                SELECT hcs.*, c.name AS cat_name, c.slug AS cat_slug, c.image AS cat_image
                FROM ecom_home_category_strip_v5 hcs
                LEFT JOIN ecom_categories c ON hcs.category_id = c.id
                WHERE hcs.status='active' ORDER BY hcs.sort_order ASC, hcs.id ASC LIMIT $strip_limit
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $strip_display[] = [
                    'label' => $r['custom_label'] ?: ($r['cat_name'] ?? ''),
                    'emoji' => $r['custom_emoji'],
                    'image' => $r['custom_image'] ?: ($r['cat_image'] ?? ''),
                    'link'  => $r['custom_link'] ?: (!empty($r['cat_slug']) ? '/shop/category.php?slug=' . urlencode($r['cat_slug']) : '#'),
                ];
            }
        } elseif ($strip_mode === 'auto_popular') {
            $rows = $pdo->query("SELECT id, name, slug, image FROM ecom_categories WHERE status='active' ORDER BY view_count DESC, serial ASC LIMIT $strip_limit")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $c) {
                $strip_display[] = ['label' => $c['name'], 'emoji' => null, 'image' => $c['image'], 'link' => '/shop/category.php?slug=' . urlencode($c['slug'])];
            }
        } else { // 'all'
            $rows = $pdo->query("SELECT id, name, slug, image FROM ecom_categories WHERE status='active' ORDER BY serial ASC, name ASC LIMIT $strip_limit")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $c) {
                $strip_display[] = ['label' => $c['name'], 'emoji' => null, 'image' => $c['image'], 'link' => '/shop/category.php?slug=' . urlencode($c['slug'])];
            }
        }
    }

    // ── 3. Trending / featured sections ──
    $trending_sections = $show['trending'] ? $pdo->query("SELECT * FROM ecom_home_trending_sections WHERE status='active' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) : [];
    $trending_items_by_section = [];
    foreach ($trending_sections as $ts) {
        $ti = $pdo->prepare("
            SELECT tti.*, p.name AS prod_name, p.slug AS prod_slug, p.image AS prod_image,
                   c.name AS cat_name, c.slug AS cat_slug, c.image AS cat_image
            FROM ecom_home_trending_items tti
            LEFT JOIN ecom_products p ON tti.product_id = p.id
            LEFT JOIN ecom_categories c ON tti.category_id = c.id
            WHERE tti.section_id = ? AND tti.status='active' ORDER BY tti.sort_order ASC, tti.id ASC
        ");
        $ti->execute([$ts['id']]);
        $trending_items_by_section[$ts['id']] = $ti->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── 4. Popular products ──
    $popular_products = [];
    if ($show['popular']) {
        $popular_mode = $v5['home_popular_mode'] ?? 'best_selling';
        $popular_limit = max(1, (int)($v5['home_popular_limit'] ?? 10));
        $oos = outOfStockFilterSql($pdo);

        $pins = $pdo->query("
            SELECT p.* FROM ecom_home_popular_pins hpp
            JOIN ecom_products p ON hpp.product_id = p.id
            WHERE hpp.status='active' AND p.status='active' $oos
            ORDER BY hpp.sort_order ASC, hpp.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($popular_mode === 'manual') {
            $popular_products = array_slice($pins, 0, $popular_limit);
        } else {
            $popular_products = $pins;
            $have_ids = array_column($pins, 'id');
            $remaining = $popular_limit - count($popular_products);
            if ($remaining > 0) {
                $orderCol = $popular_mode === 'most_viewed' ? 'view_count' : 'purchase_count';
                $exclude_sql = $have_ids ? ('AND id NOT IN (' . implode(',', array_fill(0, count($have_ids), '?')) . ')') : '';
                $fill = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' $oos $exclude_sql ORDER BY $orderCol DESC, created_at DESC LIMIT $remaining");
                $fill->execute($have_ids);
                $popular_products = array_merge($popular_products, $fill->fetchAll(PDO::FETCH_ASSOC));
            }
            $popular_products = array_slice($popular_products, 0, $popular_limit);
        }
    }

    // ── 5. Festive banner ──
    $festive = $show['festive_banner'] ? ($pdo->query("SELECT * FROM ecom_home_festive_banner WHERE id=1 AND status='active'")->fetch(PDO::FETCH_ASSOC) ?: null) : null;

    // ── 6. Category product sections ──
    $category_sections = $show['category_sections'] ? $pdo->query("
        SELECT hcs2.*, c.name AS cat_name, c.slug AS cat_slug
        FROM ecom_home_category_sections hcs2
        JOIN ecom_categories c ON hcs2.category_id = c.id
        WHERE hcs2.status='active' AND c.status='active'
        ORDER BY hcs2.sort_order ASC, hcs2.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) : [];
    $category_section_products = [];
    foreach ($category_sections as $cs) {
        $limit = max(1, (int)$cs['product_limit']);
        $oos = outOfStockFilterSql($pdo);
        if ($cs['sort_mode'] === 'manual') {
            $pinStmt = $pdo->prepare("
                SELECT p.* FROM ecom_home_category_section_pins csp
                JOIN ecom_products p ON csp.product_id = p.id
                WHERE csp.category_section_id = ? AND p.status='active' $oos
                ORDER BY csp.sort_order ASC, csp.id ASC LIMIT $limit
            ");
            $pinStmt->execute([$cs['id']]);
            $category_section_products[$cs['id']] = $pinStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $orderCol = $cs['sort_mode'] === 'best_selling' ? 'purchase_count' : ($cs['sort_mode'] === 'most_viewed' ? 'view_count' : 'created_at');
            $catStmt = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' $oos AND category_id = ? ORDER BY $orderCol DESC LIMIT $limit");
            $catStmt->execute([$cs['category_id']]);
            $category_section_products[$cs['id']] = $catStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} catch (Throwable $e) {
    $home_v5_missing = true;
    $section_order = [];
    $show = [];
    $slides = [];
    $strip_display = [];
    $trending_sections = [];
    $trending_items_by_section = [];
    $popular_products = [];
    $festive = null;
    $category_sections = [];
    $category_section_products = [];
    $typography = [];
}
?>

<?php if ($home_v5_missing): ?>
<div class="text-center py-5" style="color:var(--muted);">
    <i class="fas fa-database fa-3x mb-3"></i>
    <p><strong>Homepage Customizer v5 abhi set up nahi hai.</strong><br>
    <code>homepage_v5_migration.sql</code> aur <code>homepage_v5_seed.sql</code> database par chalao, phir <strong>Admin → Homepage Customizer</strong> se configure karo.</p>
</div>
<?php else: ?>

<?php if (!empty($typography)): ?>
<style>
:root {
    <?php foreach ($typography as $key => $row): ?>
    --type-<?= htmlspecialchars($key) ?>-desktop: <?= (int)$row['desktop_px'] ?>px;
    --type-<?= htmlspecialchars($key) ?>-mobile: <?= (int)$row['mobile_px'] ?>px;
    <?php endforeach; ?>
}
.product-card .title, .card2 .title2 { font-size: var(--type-product_title-desktop, 14px); }
.price-row .sp, .card2 .price2 .sp2 { font-size: var(--type-product_price-desktop, 16px); }
.section h2, .design-gallery-title { font-size: var(--type-section_heading-desktop, 22px); }
.icon-strip .strip-item span { font-size: var(--type-category_strip_label-desktop, 12px); }
.cat-cards .ccard p { font-size: var(--type-trending_label-desktop, 17px); }
@media (max-width: 640px) {
    .product-card .title, .card2 .title2 { font-size: var(--type-product_title-mobile, 13px) !important; }
    .price-row .sp, .card2 .price2 .sp2 { font-size: var(--type-product_price-mobile, 14px) !important; }
    .section h2, .design-gallery-title { font-size: var(--type-section_heading-mobile, 18px) !important; }
    .icon-strip .strip-item span { font-size: var(--type-category_strip_label-mobile, 11px) !important; }
    .cat-cards .ccard p { font-size: var(--type-trending_label-mobile, 15px) !important; }
}
</style>
<?php endif; ?>

<?php foreach ($section_order as $section_key):
    if (empty($show[$section_key])) continue;
?>

    <?php if ($section_key === 'slider' && !empty($slides)): ?>
    <!-- 1. BANNER SLIDER (content-style — heading/subtext/button/icon, same as shop/demo-home.html) -->
    <div class="banner-slider" id="bannerSlider">
        <?php foreach ($slides as $i => $sl): ?>
        <div class="slide <?= htmlspecialchars($sl['template'] ?: 'tpl-1') ?><?= $i === 0 ? ' active' : '' ?>">
            <?php if (!empty($sl['badge_text'])): ?><span class="badge"><?= htmlspecialchars($sl['badge_text']) ?></span><?php endif; ?>
            <div class="slide-copy">
                <h2><?= nl2br(htmlspecialchars($sl['heading'] ?? '')) ?></h2>
                <?php if (!empty($sl['subtext'])): ?><p><?= htmlspecialchars($sl['subtext']) ?></p><?php endif; ?>
                <?php if (!empty($sl['button_text'])): ?><a href="<?= htmlspecialchars($sl['link'] ?: '#') ?>" class="pill-btn"><?= htmlspecialchars($sl['button_text']) ?></a><?php endif; ?>
            </div>
            <?php if (!empty($sl['icon_image'])): ?>
            <div class="slide-visual"><img src="/<?= htmlspecialchars($sl['icon_image']) ?>" alt=""></div>
            <?php elseif (!empty($sl['icon_emoji'])): ?>
            <div class="slide-visual"><?= htmlspecialchars($sl['icon_emoji']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (count($slides) > 1): ?>
        <div class="slider-dots">
            <?php foreach ($slides as $i => $sl): ?><button class="dot<?= $i === 0 ? ' active' : '' ?>" aria-label="Slide <?= $i + 1 ?>"></button><?php endforeach; ?>
            <span class="counter" id="slideCounter">1/<?= count($slides) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($section_key === 'category_strip' && !empty($strip_display)): ?>
    <!-- 2. CATEGORY STRIP -->
    <div class="icon-strip">
        <?php foreach ($strip_display as $i => $it): ?>
        <a href="<?= htmlspecialchars($it['link'] ?: '#') ?>" class="strip-item">
            <div class="circle <?= $strip_colors[$i % count($strip_colors)] ?>">
                <?php if (!empty($it['emoji'])): ?>
                    <?= htmlspecialchars($it['emoji']) ?>
                <?php elseif (!empty($it['image'])): ?>
                    <img src="/<?= htmlspecialchars($it['image']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:14px;">
                <?php else: ?>
                    🛒
                <?php endif; ?>
            </div>
            <span><?= htmlspecialchars($it['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php elseif ($section_key === 'trending' && !empty($trending_sections)): ?>
    <!-- 3. TRENDING / FEATURED SECTIONS (e.g. "Fresh items", "Snacks & Drinks") -->
    <?php foreach ($trending_sections as $ts):
        $items = $trending_items_by_section[$ts['id']] ?? [];
        if (empty($items)) continue;
    ?>
    <div class="cat-row-title"><?= htmlspecialchars($ts['title']) ?></div>
    <div class="cat-cards">
        <?php foreach ($items as $it):
            $label = $it['custom_label'] ?: ($it['prod_name'] ?? $it['cat_name'] ?? '');
            $img   = $it['custom_image'] ?: ($it['prod_image'] ?? $it['cat_image'] ?? '');
            $link  = $it['custom_link'] ?: (!empty($it['prod_slug']) ? '/shop/product.php?slug=' . urlencode($it['prod_slug']) : (!empty($it['cat_slug']) ? '/shop/category.php?slug=' . urlencode($it['cat_slug']) : '#'));
            if ($label === '') continue;
        ?>
        <a href="<?= htmlspecialchars($link) ?>" class="ccard">
            <div class="thumb">
                <?php if (!empty($img)): ?><img src="/<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($label) ?>"><?php else: ?><span>🛒</span><?php endif; ?>
            </div>
            <p><?= htmlspecialchars($label) ?></p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php elseif ($section_key === 'popular' && !empty($popular_products)): ?>
    <!-- 4. POPULAR PRODUCTS -->
    <section class="section">
        <h2>Popular Products</h2>
        <div class="product-grid">
            <?php foreach ($popular_products as $p) { renderCmartProductCard($p); } ?>
        </div>
    </section>

    <?php elseif ($section_key === 'festive_banner' && $festive): ?>
    <!-- 5. FESTIVE BANNER (separate desktop/mobile images) -->
    <div class="festive-banner-v5">
        <a href="<?= htmlspecialchars($festive['link'] ?: '#') ?>">
            <?php if (!empty($festive['image_desktop'])): ?><img src="/<?= htmlspecialchars($festive['image_desktop']) ?>" class="festive-img-desktop" alt="Festive offer"><?php endif; ?>
            <?php if (!empty($festive['image_mobile'])): ?><img src="/<?= htmlspecialchars($festive['image_mobile']) ?>" class="festive-img-mobile" alt="Festive offer"><?php endif; ?>
        </a>
    </div>

    <?php elseif ($section_key === 'category_sections' && !empty($category_sections)): ?>
    <!-- 6. CATEGORY PRODUCT SECTIONS -->
    <?php foreach ($category_sections as $cs):
        $products = $category_section_products[$cs['id']] ?? [];
        if (empty($products)) continue;
    ?>
    <section class="section">
        <div class="sec-title-row">
            <h2><?= htmlspecialchars($cs['cat_name']) ?></h2>
            <a href="/shop/category.php?slug=<?= urlencode($cs['cat_slug']) ?>" class="sec-more-circle" aria-label="View more <?= htmlspecialchars($cs['cat_name']) ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="product-grid auto-cat-grid">
            <?php foreach ($products as $p) { renderCmartProductCard($p); } ?>
        </div>
    </section>
    <?php endforeach; ?>

    <?php endif; ?>
<?php endforeach; ?>

<?php if (empty($slides) && empty($strip_display) && empty($trending_sections) && empty($popular_products) && !$festive && empty($category_sections)): ?>
<div class="text-center py-5" style="color:var(--muted);">
    <i class="fas fa-store fa-3x mb-3"></i>
    <p>Homepage abhi khaali hai. <strong>Admin → Homepage Customizer</strong> se content add karo.</p>
</div>
<?php endif; ?>

<?php endif; // home_v5_missing ?>

<style>
.btn-see-more { display: inline-block; background: #fff; border: 1.5px solid var(--green); color: var(--green-dark); font-weight: 700; font-size: 13px; padding: 10px 26px; border-radius: 30px; text-decoration: none; }
.btn-see-more:hover { background: var(--green-light); }
@media (max-width: 640px) {
    .auto-cat-grid > .product-card:nth-child(n+6) { display: none; }
}
.festive-banner-v5 { margin: 0 32px 36px; }
.festive-banner-v5 img { width: 100%; height: auto; border-radius: 12px; display: block; }
.festive-img-mobile { display: none; }
@media (max-width: 640px) {
    .festive-banner-v5 { margin: 0 16px 24px; }
    .festive-img-desktop { display: none; }
    .festive-img-mobile { display: block; }
}
</style>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
