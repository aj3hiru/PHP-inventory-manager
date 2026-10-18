<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM ecom_categories WHERE slug = ? AND status = 'active'");
$stmt->execute([$slug]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) { http_response_code(404); exit('Category not found.'); }

// "Most visited category first" (Homepage Customizer -> Category Strip,
// auto_popular mode) needs a per-category view counter.
try { $pdo->prepare("UPDATE ecom_categories SET view_count = view_count + 1 WHERE id = ?")->execute([$category['id']]); } catch (Throwable $e) {}

$page_title = $category['name'];
include __DIR__ . '/includes/shop-header.php';
include __DIR__ . '/includes/product-card.php';

$subcats = $pdo->prepare("SELECT id, name, slug FROM ecom_subcategories WHERE category_id = ? AND status = 'active' ORDER BY name ASC");
$subcats->execute([$category['id']]);
$subcats = $subcats->fetchAll(PDO::FETCH_ASSOC);

$sub_slug = $_GET['sub'] ?? '';
$active_subcat_id = null;
if ($sub_slug !== '') {
    foreach ($subcats as $sc) { if ($sc['slug'] === $sub_slug) { $active_subcat_id = $sc['id']; break; } }
}

$PAGE_SIZE = 20;

$oos_filter = outOfStockFilterSql($pdo);
if ($active_subcat_id) {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ecom_products WHERE status='active' $oos_filter AND subcategory_id = ?");
    $count_stmt->execute([$active_subcat_id]);
    $stmt = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' $oos_filter AND subcategory_id = ? ORDER BY created_at DESC LIMIT $PAGE_SIZE");
    $stmt->execute([$active_subcat_id]);
} else {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ecom_products WHERE status='active' $oos_filter AND category_id = ?");
    $count_stmt->execute([$category['id']]);
    $stmt = $pdo->prepare("SELECT * FROM ecom_products WHERE status='active' $oos_filter AND category_id = ? ORDER BY created_at DESC LIMIT $PAGE_SIZE");
    $stmt->execute([$category['id']]);
}
$total_products = (int) $count_stmt->fetchColumn();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$has_more = $total_products > count($products);
?>

<section class="section">
    <nav aria-label="breadcrumb" style="font-size:13px; color:var(--muted); margin-bottom:12px;">
        <a href="/" style="color:var(--muted);">Home</a> / <span style="color:#333;"><?= htmlspecialchars($category['name']) ?></span>
    </nav>

    <h2><?= htmlspecialchars($category['name']) ?></h2>

    <?php if (!empty($subcats)): ?>
    <div class="mb-3">
        <a href="/shop/category.php?slug=<?= urlencode($slug) ?>" class="cat-chip<?= !$active_subcat_id ? ' active' : '' ?>">All</a>
        <?php foreach ($subcats as $sc): ?>
        <a href="/shop/category.php?slug=<?= urlencode($slug) ?>?sub=<?= urlencode($sc['slug']) ?>" class="cat-chip<?= $active_subcat_id === $sc['id'] ? ' active' : '' ?>"><?= htmlspecialchars($sc['name']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($products)): ?>
        <div class="text-center py-5" style="color:var(--muted);">
            <i class="fas fa-box-open fa-3x mb-3"></i>
            <p>No products in this category yet.</p>
        </div>
    <?php else: ?>
    <div class="product-grid" id="categoryProductGrid">
        <?php foreach ($products as $p) { renderCmartProductCard($p); } ?>
    </div>
    <?php if ($has_more): ?>
    <div class="text-center mt-4">
        <button type="button" class="btn-see-more" id="seeMoreBtn"
            data-category-id="<?= $category['id'] ?>"
            data-subcategory-id="<?= $active_subcat_id ?: '' ?>"
            data-offset="<?= count($products) ?>">
            See More <i class="fas fa-chevron-down"></i>
        </button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</section>

<style>
.btn-see-more { background: #fff; border: 1.5px solid var(--green); color: var(--green-dark); font-weight: 700; font-size: 13px; padding: 10px 26px; border-radius: 30px; }
.btn-see-more:hover { background: var(--green-light); }
.btn-see-more:disabled { opacity: 0.6; }
</style>

<script>
document.addEventListener('click', function (e) {
    const btn = e.target.closest('#seeMoreBtn');
    if (!btn) return;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Loading…';

    const params = new URLSearchParams({
        action: 'load_more_category_products',
        category_id: btn.dataset.categoryId,
        subcategory_id: btn.dataset.subcategoryId,
        offset: btn.dataset.offset
    });

    fetch('/shop/ajax.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('categoryProductGrid').insertAdjacentHTML('beforeend', data.html);
                document.dispatchEvent(new Event('shopContentUpdated'));
                btn.dataset.offset = parseInt(btn.dataset.offset) + data.count;
                if (data.has_more) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                } else {
                    btn.remove();
                }
            } else {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = originalText; });
});
</script>

<?php include __DIR__ . '/includes/shop-footer.php'; ?>
