<?php
/* Shared shell for legacy admin pages. Page-specific content remains in each template. */
?>
<link rel="stylesheet" href="/assets/css/admin-shell.css?v=20260901">
<button class="mobile-menu-toggle" type="button" aria-label="Open menu" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>
<script>
(function () {
  window.toggleSidebar = function () {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('active');
  };
})();
</script>
