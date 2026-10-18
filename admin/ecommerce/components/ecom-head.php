<?php
/**
 * Shared <head> partial for e-commerce admin pages.
 * Expects $page_title and $page_subtitle to be set before including.
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title><?= htmlspecialchars($page_title ?? 'E-Commerce') ?> - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
!function () {
    let e = localStorage.dm === "1", t = !1, n = !1,
        s = () => new Promise((e, r) => {
            let o = document.createElement("script");
            o.src = "/assets/js/darkreader.min.js";
            o.onload = () => { t = !0; e(); };
            o.onerror = r;
            document.head.appendChild(o);
        }),
        a = () => DarkReader.enable({ brightness: 100, contrast: 100, sepia: 10 }),
        d = () => DarkReader.disable(),
        i = () => {
            document.querySelectorAll(".dark-mode-toggle i").forEach(o => {
                o.classList.add("rotate");
                if (e) { o.classList.remove("fa-moon"); o.classList.add("fa-sun"); }
                else { o.classList.remove("fa-sun"); o.classList.add("fa-moon"); }
                setTimeout(() => o.classList.remove("rotate"), 400);
            });
        };
    e && (t ? a() : s().then(a));
    document.addEventListener("DOMContentLoaded", () => {
        i();
        document.querySelectorAll(".dark-mode-toggle").forEach(o => o.onclick = r);
    });
    async function r(o) {
        o.preventDefault();
        if (n) return;
        n = !0;
        let c = document.querySelectorAll(".dark-mode-toggle");
        c.forEach(e => e.classList.add("loading"));
        try {
            t || await s();
            e = !e;
            localStorage.dm = e ? "1" : "0";
            e ? a() : d();
            i();
        } finally {
            setTimeout(() => { c.forEach(e => e.classList.remove("loading")); n = !1; }, 600);
        }
    }
}();
</script>
<style>
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #3b82f6;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
    --sidebar-width: 280px;
}
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--gray-50); color: var(--gray-800); line-height: 1.5; }

.admin-container { display: grid; grid-template-columns: 1fr; min-height: 100vh; }
@media (min-width: 1024px) { .admin-container { grid-template-columns: var(--sidebar-width) 1fr; } }
.sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-width); background: white; border-right: 1px solid var(--gray-200); z-index: 1000; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); overflow-y: auto; display: flex; flex-direction: column; }
.sidebar.open { transform: translateX(0); }
@media (min-width: 1024px) { .sidebar { position: sticky; transform: translateX(0); height: 100vh; top: 0; } }
.sidebar-header { padding: 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
.brand { display: flex; align-items: center; gap: 0.65rem; font-size: 1.15rem; font-weight: 800; color: var(--primary); text-decoration: none; }
.brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
.close-sidebar { width: 36px; height: 36px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--gray-600); transition: all 0.2s; }
.close-sidebar:hover { background: var(--gray-200); }
@media (min-width: 1024px) { .close-sidebar { display: none; } }
.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section { margin-bottom: 1.5rem; padding: 0 1rem; }
.nav-title { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gray-400); padding: 0 1rem; margin-bottom: 0.75rem; }
.nav-link { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem 1rem; border-radius: var(--radius); color: var(--gray-600); text-decoration: none; font-size: 0.9375rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.25rem; }
.nav-link:hover { background: var(--gray-50); color: var(--gray-900); }
.nav-link.active { background: var(--primary-lighter); color: var(--primary); font-weight: 600; }
.nav-link i { width: 24px; text-align: center; font-size: 1.125rem; }
.sidebar-footer { padding: 1rem; border-top: 1px solid var(--gray-100); }
.user-card { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem; background: var(--gray-50); border-radius: var(--radius-lg); }
.user-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; }
.user-info { flex: 1; min-width: 0; }
.user-name { font-weight: 600; color: var(--gray-900); font-size: 0.9375rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 0.75rem; color: var(--gray-500); }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; visibility: hidden; transition: all 0.3s; }
.sidebar-overlay.active { opacity: 1; visibility: visible; }
@media (min-width: 1024px) { .sidebar-overlay { display: none; } }
.main-content { min-width: 0; }
.top-nav { position: sticky; top: 0; background: white; border-bottom: 1px solid var(--gray-200); padding: 0.450rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; z-index: 100; }
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-700); cursor: pointer; transition: all 0.2s; }
.menu-toggle:hover { background: var(--gray-200); }
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }
.page-heading-mini { display: none; }
@media (min-width: 768px) { .page-heading-mini { display: block; } .sitename-mob { display: none; } .page-heading-mini h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); } .page-heading-mini p { font-size: 0.875rem; color: var(--gray-500); } }
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }
.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }
.alert-floating { border-radius: var(--radius-lg); margin-bottom: 1rem; }
.gd-card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 0.35rem; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,.1); margin-bottom: 1.5rem; }
.gd-card-body { padding: 1.25rem 1.5rem; }
.gd-heading-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
.gd-heading-row h3 { font-weight: 700; color: var(--gray-800); margin: 0; }
.stat-mini-grid { display: flex; gap: 0.75rem; overflow-x: auto; padding-bottom: 0.25rem; margin-bottom: 1rem; }
.stat-mini { flex: 0 0 auto; background: white; border-radius: var(--radius-lg); padding: 1rem 1.25rem; min-width: 150px; box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1); border: 1px solid var(--gray-100); }
.stat-mini .val { font-size: 1.375rem; font-weight: 700; color: var(--gray-900); }
.stat-mini .lbl { font-size: 0.75rem; color: var(--gray-500); margin-top: 0.15rem; }
table.dataTable { border-collapse: collapse !important; width: 100% !important; }
table.dataTable thead th { background: var(--gray-50); font-weight: 700; }
table.dataTable td, table.dataTable th { vertical-align: middle !important; }
table.dataTable img { width: 55px; height: 55px; object-fit: cover; border-radius: 0.25rem; }
.dt-thumb-placeholder { width: 55px; height: 55px; border-radius: 0.25rem; background: var(--gray-100); display: flex; align-items: center; justify-content: center; color: var(--gray-400); }
.status-btn { border: none; border-radius: 0; box-shadow: none !important; font-weight: 500; }
.status-btn.dropdown-toggle::after { vertical-align: 0.15em; }
.dropdown-menu { border-radius: 0; }
.gd-card-body:has(.table-responsive) { padding-bottom: 4rem; }
.status-btn.btn-success { background-color: #1cc88a; }
.status-btn.btn-secondary-status { background-color: #858796; color: #fff; }
.status-btn.btn-warning-status { background-color: #f6c23e; color: #1f2937; }
.status-btn.btn-danger-status { background-color: var(--danger); color: #fff; }
.action-list { display: flex; gap: 0.4rem; }
.action-list .btn { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
.action-list .btn-primary { background-color: #4361ee; border-color: #4361ee; }
.action-list .btn-secondary { background-color: #858796; border-color: #858796; }
.action-list .btn-info { background-color: #36b9cc; border-color: #36b9cc; }
.action-list .btn-danger { background-color: var(--danger); border-color: var(--danger); }
.parent-pill { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.8125rem; font-weight: 600; color: var(--primary); background: var(--primary-lighter); padding: 0.3rem 0.7rem; border-radius: 9999px; }
.popular-toggle-link { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.75rem; font-weight: 700; padding: 0.3rem 0.7rem; border-radius: 9999px; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.popular-yes { background: #fef3c7; color: #92400e; }
.popular-yes:hover { background: #fde68a; }
.popular-no { background: var(--gray-100); color: var(--gray-400); }
.popular-no:hover { background: var(--gray-200); }
.badge-tag { display: inline-flex; align-items: center; font-size: 0.7rem; font-weight: 700; padding: 0.3rem 0.65rem; border-radius: 0.25rem; color: #fff; text-transform: uppercase; }
.badge-tag.tag-new { background: var(--info); }
.badge-tag.tag-best { background: var(--success); }
.badge-tag.tag-hot { background: var(--danger); }
.badge-tag.tag-featured { background: var(--warning); color: #1f2937; }
.badge-tag.tag-none { background: var(--gray-300); color: var(--gray-700); }
.modal-header { background: var(--gray-50); }
.star-rating { color: #f6c23e; }
.form-select:disabled { background-color: var(--gray-100); }
/* Thin, subtle focus state (Bootstrap's default glow is too heavy) */
.form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: none; }
.btn-check:focus + .btn, .btn:focus { box-shadow: none; }
.dataTables_length select { min-width: 75px; padding-right: 1.75rem !important; }
.dataTables_length, .dataTables_filter { margin-bottom: 1rem; }
</style>
