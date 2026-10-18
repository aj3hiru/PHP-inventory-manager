<?php $current_admin_page = basename($_SERVER['PHP_SELF']); ?>
<style>
/* No underlines on links anywhere in the admin panel — hover still shows intent via color */
.main-content a { text-decoration: none; }
.main-content a:hover { text-decoration: none; }
/* Thin, premium scrollbar for the sidebar nav — applies everywhere this
   partial is included, so it's consistent across every admin page. */
.sidebar-nav { scrollbar-width: thin; scrollbar-color: var(--gray-200, #e5e7eb) transparent; }
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-track { background: transparent; }
.sidebar-nav::-webkit-scrollbar-thumb { background: var(--gray-200, #e5e7eb); border-radius: 2px; }
.sidebar-nav::-webkit-scrollbar-thumb:hover { background: var(--gray-300, #d1d5db); }

/* Scoped styles for disabled/coming-soon nav links so this partial looks right
   even on pages that don't already define these classes in their own <style>. */
.sidebar-nav .nav-link.disabled { color: var(--gray-300, #d1d5db); cursor: default; }
.sidebar-nav .nav-link.disabled:hover { background: transparent; color: var(--gray-300, #d1d5db); }
.sidebar-nav .soon-badge { margin-left: auto; font-size: 0.625rem; font-weight: 700; background: var(--gray-100, #f3f4f6); color: var(--gray-400, #9ca3af); padding: 0.15rem 0.5rem; border-radius: 9999px; }
.sidebar-nav .nav-parent-row { display: flex; align-items: center; margin-bottom: 0.25rem; }
.sidebar-nav .nav-parent-row .nav-link { flex: 1; margin-bottom: 0; }
.sidebar-nav .nav-expand-btn { background: none; border: none; color: var(--gray-400, #9ca3af); width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; cursor: pointer; border-radius: var(--radius, 0.5rem); transition: transform 0.2s, background 0.15s; }
.sidebar-nav .nav-expand-btn:hover { background: var(--gray-50, #f9fafb); }
.sidebar-nav .nav-expand-btn.open i { transform: rotate(180deg); }
.sidebar-nav .nav-expand-btn i { transition: transform 0.2s; font-size: 0.75rem; }
.sidebar-nav .nav-submenu { overflow: hidden; max-height: 0; transition: max-height 0.25s ease; }
.sidebar-nav .nav-submenu.open { max-height: 600px; }
.sidebar-nav .nav-submenu .nav-link { padding-left: 2.75rem; font-size: 0.875rem; }

/* User profile card now lives in the page header (top-nav), not the
   sidebar footer — hidden globally here so no page needs its own override. */
.sidebar-footer { display: none !important; }

.header-user { display: flex; align-items: center; gap: 0.7rem; padding: 0.4rem 0.75rem 0.4rem 0.4rem; border-radius: 999px; background: var(--gray-50); cursor: pointer; transition: background 0.15s; border: none; }
.header-user:hover { background: var(--gray-100); }
.header-user .avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9375rem; flex-shrink: 0; }
.header-user .info { text-align: left; line-height: 1.25; }
.header-user .name { font-weight: 700; font-size: 0.875rem; color: var(--gray-900); white-space: nowrap; }
.header-user .role { font-size: 0.75rem; color: var(--gray-500); }
@media (max-width: 480px) { .header-user .info { display: none; } .header-user { padding: 0.3rem; } }

/* Self-contained dropdown — does NOT rely on Bootstrap's CSS/JS, so it
   renders and behaves identically on every admin page whether or not
   that page happens to load Bootstrap. */
.header-user-wrap { position: relative; display: inline-block; }
.header-user-menu {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 12px;
    padding: 0.35rem;
    min-width: 190px;
    background: #fff;
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 0.5rem;
    box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,.15);
    list-style: none;
    z-index: 1000;
}
.header-user-menu.show { display: block; }
.header-user-menu::before {
    content: "";
    position: absolute;
    top: -6px;
    right: 18px;
    width: 12px;
    height: 12px;
    background: #fff;
    border-left: 1px solid rgba(0,0,0,.15);
    border-top: 1px solid rgba(0,0,0,.15);
    transform: rotate(45deg);
    border-radius: 2px 0 0 0;
}
.header-user-menu li { margin: 0; padding: 0; }
.header-user-menu .dropdown-item {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.55rem 0.65rem; border-radius: 0.4rem;
    font-size: 0.875rem; line-height: 1.2;
    color: var(--gray-800, #1f2937); text-decoration: none;
}
.header-user-menu .dropdown-item:hover { background: var(--gray-50, #f9fafb); }
.header-user-menu .dropdown-item i { width: 16px; text-align: center; flex-shrink: 0; margin: 0; }
.header-user-menu .dropdown-divider { margin: 0.25rem 0.35rem; border: none; border-top: 1px solid var(--gray-100, #f3f4f6); }
</style>
<aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/admin/dashboard.php" class="brand">
                <div class="brand-icon">
                    <i class="fas fa-cube"></i>
                </div>
                <span><?= htmlspecialchars($site_name) ?></span>
            </a>
            <button class="close-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-times"></i>
            </button>
        </div>

   <nav class="sidebar-nav">

    <!-- 1. DASHBOARD -->
    <div class="nav-section">
        <div class="nav-title">Main</div>

        <a href="/admin/dashboard.php" class="nav-link<?= ($current_admin_page === 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/admin/blog/') === false) ? ' active' : '' ?>">
            <i class="fas fa-home"></i>
            Dashboard
        </a>
    </div>

    <!-- 2. BILLING -->
    <?php if (!empty($permissions['ecommerce']['manage_billing'])): ?>
    <div class="nav-section">
        <div class="nav-title">Billing</div>

        <a href="/admin/ecommerce/billing.php" class="nav-link<?= $current_admin_page === 'billing.php' ? ' active' : '' ?>">
            <i class="fas fa-cash-register"></i>
            Billing / POS
        </a>

        <a href="/admin/ecommerce/sales-history.php" class="nav-link<?= $current_admin_page === 'sales-history.php' ? ' active' : '' ?>">
            <i class="fas fa-history"></i>
            Sales History
        </a>
    </div>
    <?php endif; ?>

    <!-- 3. ALL PRODUCTS (parent, default OPEN — everything product-related nests here) -->
    <?php if (!empty($permissions['ecommerce']['manage_products'])): ?>
    <?php $products_open = true; // always open by default, until the user closes it ?>
    <div class="nav-section">
        <div class="nav-title">Manage Products</div>

        <div class="nav-parent-row">
            <a href="/admin/ecommerce/products.php" class="nav-link<?= $current_admin_page === 'products.php' ? ' active' : '' ?>">
                <i class="fas fa-boxes"></i>
                All Products
            </a>
            <button type="button" class="nav-expand-btn<?= $products_open ? ' open' : '' ?>" onclick="toggleNavSubmenu(this, 'submenu-products')">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        <div class="nav-submenu<?= $products_open ? ' open' : '' ?>" id="submenu-products">
            <a href="/admin/ecommerce/add-product-form.php" class="nav-link<?= $current_admin_page === 'add-product-form.php' ? ' active' : '' ?>">
                <i class="fas fa-plus-square"></i>
                Add Product
            </a>
            <a href="/admin/ecommerce/brands.php" class="nav-link<?= $current_admin_page === 'brands.php' ? ' active' : '' ?>">
                <i class="fas fa-copyright"></i>
                Brands
            </a>
            <a href="/admin/ecommerce/stock-out-products.php" class="nav-link<?= $current_admin_page === 'stock-out-products.php' ? ' active' : '' ?>">
                <i class="fas fa-box-open"></i>
                Stock Out Products
            </a>
            <a href="/admin/ecommerce/campaign-offer.php" class="nav-link<?= $current_admin_page === 'campaign-offer.php' ? ' active' : '' ?>">
                <i class="fas fa-percent"></i>
                Campaign Offer
            </a>
            <a href="/admin/ecommerce/csv-import-export.php" class="nav-link<?= $current_admin_page === 'csv-import-export.php' ? ' active' : '' ?>">
                <i class="fas fa-file-csv"></i>
                CSV Import &amp; Export
            </a>
            <a href="/admin/ecommerce/product-reviews.php" class="nav-link<?= $current_admin_page === 'product-reviews.php' ? ' active' : '' ?>">
                <i class="fas fa-star-half-alt"></i>
                Product Reviews
            </a>
            <a href="/admin/ecommerce/barcode-print.php" class="nav-link<?= $current_admin_page === 'barcode-print.php' ? ' active' : '' ?>">
                <i class="fas fa-barcode"></i>
                Print Barcodes
            </a>
            <a href="/admin/ecommerce/product-tags.php" class="nav-link<?= $current_admin_page === 'product-tags.php' ? ' active' : '' ?>">
                <i class="fas fa-tags"></i>
                Badge Tags &amp; Item Types
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- 4. MANAGE CATEGORY -->
    <?php if (!empty($permissions['ecommerce']['manage_categories'])): ?>
    <?php $cat_group_open = in_array($current_admin_page, ['categories.php', 'subcategories.php'], true); ?>
    <div class="nav-section">
        <div class="nav-title">Manage Category</div>

        <div class="nav-parent-row">
            <a href="/admin/ecommerce/categories.php" class="nav-link<?= $current_admin_page === 'categories.php' ? ' active' : '' ?>">
                <i class="fas fa-list"></i>
                Categories
            </a>
            <button type="button" class="nav-expand-btn<?= $cat_group_open ? ' open' : '' ?>" onclick="toggleNavSubmenu(this, 'submenu-categories')">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        <div class="nav-submenu<?= $cat_group_open ? ' open' : '' ?>" id="submenu-categories">
            <a href="/admin/ecommerce/subcategories.php" class="nav-link<?= $current_admin_page === 'subcategories.php' ? ' active' : '' ?>">
                <i class="fas fa-list-ul"></i>
                Sub Categories
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- 5. MANAGE ORDERS -->
    <?php if (!empty($permissions['ecommerce']['manage_orders'])): ?>
    <?php $orders_open = in_array($current_admin_page, ['orders.php', 'order-view.php'], true); ?>
    <div class="nav-section">
        <div class="nav-title">Manage Orders</div>

        <div class="nav-parent-row">
            <a href="/admin/ecommerce/orders.php" class="nav-link<?= ($current_admin_page === 'orders.php' && empty($_GET['type'])) ? ' active' : '' ?>">
                <i class="fas fa-receipt"></i>
                All Orders
            </a>
            <button type="button" class="nav-expand-btn<?= $orders_open ? ' open' : '' ?>" onclick="toggleNavSubmenu(this, 'submenu-orders')">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        <div class="nav-submenu<?= $orders_open ? ' open' : '' ?>" id="submenu-orders">
            <a href="/admin/ecommerce/orders.php?type=Pending" class="nav-link<?= ($current_admin_page === 'orders.php' && ($_GET['type'] ?? '') === 'Pending') ? ' active' : '' ?>">
                <i class="fas fa-hourglass-half"></i>
                Pending Orders
            </a>
            <a href="/admin/ecommerce/orders.php?type=In+Progress" class="nav-link<?= ($current_admin_page === 'orders.php' && ($_GET['type'] ?? '') === 'In Progress') ? ' active' : '' ?>">
                <i class="fas fa-truck-loading"></i>
                Progress Orders
            </a>
            <a href="/admin/ecommerce/orders.php?type=Delivered" class="nav-link<?= ($current_admin_page === 'orders.php' && ($_GET['type'] ?? '') === 'Delivered') ? ' active' : '' ?>">
                <i class="fas fa-truck"></i>
                Delivered Orders
            </a>
            <a href="/admin/ecommerce/orders.php?type=Canceled" class="nav-link<?= ($current_admin_page === 'orders.php' && ($_GET['type'] ?? '') === 'Canceled') ? ' active' : '' ?>">
                <i class="fas fa-ban"></i>
                Canceled Orders
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- 6. CUSTOMERS -->
    <?php if (!empty($permissions['ecommerce']['manage_customers'])): ?>
    <div class="nav-section">
        <div class="nav-title">Customers</div>

        <a href="/admin/ecommerce/customers.php" class="nav-link<?= in_array($current_admin_page, ['customers.php', 'customer-profile.php'], true) ? ' active' : '' ?>">
            <i class="fas fa-user-friends"></i>
            Customer List
        </a>
    </div>
    <?php endif; ?>

    <!-- 7. DISCOUNTS -->
    <?php if (!empty($permissions['ecommerce']['manage_coupons'])): ?>
    <div class="nav-section">
        <div class="nav-title">Discounts</div>

        <a href="/admin/ecommerce/coupons.php" class="nav-link<?= $current_admin_page === 'coupons.php' ? ' active' : '' ?>">
            <i class="fas fa-percentage"></i>
            Set Coupons
        </a>
    </div>
    <?php endif; ?>

    <!-- 8. SETTINGS -->
    <?php if (!empty($permissions['ecommerce']['manage_payment'])): ?>
    <div class="nav-section">
        <div class="nav-title">Settings</div>

        <a href="/admin/ecommerce/payment.php" class="nav-link<?= $current_admin_page === 'payment.php' ? ' active' : '' ?>">
            <i class="fas fa-credit-card"></i>
            Payment
        </a>

        <a href="/admin/ecommerce/business-settings.php" class="nav-link<?= $current_admin_page === 'business-settings.php' ? ' active' : '' ?>">
            <i class="fas fa-building"></i>
            Business Setting
        </a>

        <a href="/admin/ecommerce/homepage-settings.php" class="nav-link<?= $current_admin_page === 'homepage-settings.php' ? ' active' : '' ?>">
            <i class="fas fa-home"></i>
            Homepage Settings
        </a>

        <?php if (!empty($permissions['ecommerce']['manage_products'])): ?>
        <a href="/admin/ecommerce/tax-settings.php" class="nav-link<?= $current_admin_page === 'tax-settings.php' ? ' active' : '' ?>">
            <i class="fas fa-receipt"></i>
            GST / Tax Settings
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 8b. DUE -->
    <?php if (!empty($permissions['ecommerce']['manage_credits'])): ?>
    <div class="nav-section">
        <div class="nav-title">Due</div>

        <a href="/admin/ecommerce/due.php" class="nav-link<?= $current_admin_page === 'due.php' ? ' active' : '' ?>">
            <i class="fas fa-hand-holding-usd"></i>
            Due
        </a>
    </div>
    <?php endif; ?>

    <!-- 9. FILE MANAGER (general utility) -->
    <?php if (!empty($permissions['files']['access_file_manager'])): ?>
    <div class="nav-section">
        <div class="nav-title">Media</div>

        <a href="/admin/file-manager.php" class="nav-link<?= $current_admin_page === 'file-manager.php' ? ' active' : '' ?>">
            <i class="fas fa-images"></i>
            File Manager
        </a>
    </div>
    <?php endif; ?>

    <!-- 10. MARKETING -->
    <?php if (!empty($permissions['push_notifications']['send'])): ?>
    <div class="nav-section">
        <div class="nav-title">Marketing</div>

        <a href="/push_notifications/push-manager.php" class="nav-link<?= $current_admin_page === 'push-manager.php' ? ' active' : '' ?>">
            <i class="fas fa-bell"></i>
            Push Notifications
        </a>
    </div>
    <?php endif; ?>

    <!-- 11. BLOG (everything blog-related, closed by default) -->
    <?php
        $in_blog_folder = strpos($_SERVER['PHP_SELF'], '/admin/blog/') !== false;
        $blog_open = $in_blog_folder;
        $has_any_blog_permission = !empty(array_filter($permissions['blogs'] ?? [])) || !empty($permissions['analytics']['view_basic']);
    ?>
    <?php if ($has_any_blog_permission): ?>
    <div class="nav-section">
        <div class="nav-parent-row">
            <div class="nav-title" style="flex:1; padding-top:0.4rem;">Blog</div>
            <button type="button" class="nav-expand-btn<?= $blog_open ? ' open' : '' ?>" onclick="toggleNavSubmenu(this, 'submenu-blog')">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
        <div class="nav-submenu<?= $blog_open ? ' open' : '' ?>" id="submenu-blog">
            <a href="/admin/blog/dashboard.php" class="nav-link<?= ($current_admin_page === 'dashboard.php' && $in_blog_folder) ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-tachometer-alt"></i>
                Blog Dashboard
            </a>

            <a href="/admin/blog/blogs-manager.php" class="nav-link<?= $current_admin_page === 'blogs-manager.php' ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-newspaper"></i>
                Blog Posts
            </a>

            <?php if (!empty($permissions['blogs']['manage_categories'])): ?>
            <a href="/admin/blog/categories-manager.php" class="nav-link<?= $current_admin_page === 'categories-manager.php' ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-list"></i>
                Category Manager
            </a>
            <?php endif; ?>

            <?php if (!empty($permissions['blogs']['manage_tags'])): ?>
            <a href="/admin/blog/tag-manager.php" class="nav-link<?= $current_admin_page === 'tag-manager.php' ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-tags"></i>
                Tag Manager
            </a>
            <?php endif; ?>

            <?php if (!empty($permissions['blogs']['manage_comments'])): ?>
            <a href="/admin/blog/comments-manager.php" class="nav-link<?= $current_admin_page === 'comments-manager.php' ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-comments"></i>
                Comments
            </a>
            <?php endif; ?>

            <?php if (!empty($permissions['analytics']['view_basic'])): ?>
            <a href="/admin/blog/analytics.php" class="nav-link<?= $current_admin_page === 'analytics.php' ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-chart-line"></i>
                Analytics
            </a>
            <?php endif; ?>

            <a href="/admin/blog/ads-manager.php" class="nav-link<?= $current_admin_page === 'ads-manager.php' ? ' active' : '' ?>" style="padding-left:1.1rem;">
                <i class="fas fa-ad"></i>
                Ads Manager
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- 12. SYSTEM (general admin tools, kept at the very bottom) -->
    <div class="nav-section">
        <div class="nav-title">System</div>

        <?php if (!empty($permissions['settings']['maintenance_mode'])): ?>
        <a href="/admin/cache-manager.php" class="nav-link<?= $current_admin_page === 'cache-manager.php' ? ' active' : '' ?>">
            <i class="fas fa-bolt"></i>
            Cache Manager
        </a>
        <?php endif; ?>

        <?php if (!empty($permissions['security']['view_logs'])): ?>
        <a href="/admin/activity-logs.php" class="nav-link<?= $current_admin_page === 'activity-logs.php' ? ' active' : '' ?>">
            <i class="fas fa-history"></i>
            Activity Logs
        </a>
        <?php endif; ?>

        <?php if (!empty($permissions['users']['create'])): ?>
        <a href="/admin/user-manager.php" class="nav-link<?= $current_admin_page === 'user-manager.php' ? ' active' : '' ?>">
            <i class="fas fa-user"></i>
            Users Manager
        </a>
        <?php endif; ?>

        <a href="/admin/api/logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt"></i>
            Logout
        </a>
    </div>

</nav>    

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar">
                    <?= strtoupper(substr($username, 0, 1)) ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($username) ?></div>
                    <div class="user-role"><?= ucfirst(htmlspecialchars($_SESSION['role'])) ?></div>
                </div>
            </div>
        </div>

        <script>
        // Expand/collapse a nested sidebar submenu, remembering the
        // user's choice (localStorage) so it stays that way across pages —
        // it only changes when they actually click the arrow.
        function toggleNavSubmenu(btn, submenuId) {
            var submenu = document.getElementById(submenuId);
            if (!submenu) return;
            var isOpen = submenu.classList.toggle('open');
            btn.classList.toggle('open', isOpen);
            try { localStorage.setItem('nav_' + submenuId, isOpen ? '1' : '0'); } catch (e) {}
        }

        // Apply any saved open/closed preference on load (falls back to the
        // server-rendered default — e.g. Products stays open — if nothing
        // has been saved yet).
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.sidebar-nav .nav-submenu[id]').forEach(function (el) {
                var saved = null;
                try { saved = localStorage.getItem('nav_' + el.id); } catch (e) {}
                if (saved === null) return;
                var isOpen = saved === '1';
                el.classList.toggle('open', isOpen);
                var btn = el.previousElementSibling ? el.previousElementSibling.querySelector('.nav-expand-btn') : null;
                if (btn) btn.classList.toggle('open', isOpen);
            });

            var activeLink = document.querySelector('.sidebar-nav .nav-link.active');
            if (activeLink) {
                activeLink.scrollIntoView({ block: 'nearest' });
            }
        });
        </script>
    </aside>
