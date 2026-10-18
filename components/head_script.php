<!-- SEO Meta Tags -->
<meta name="description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($seo_canonical) ?>">

<!-- Open Graph Tags (for social sharing) -->
<meta property="og:type" content="<?= htmlspecialchars($seo['type'], ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($seo_canonical) ?>">
<meta property="og:image" content="<?= htmlspecialchars($seo['image'], ENT_QUOTES, 'UTF-8') ?>">

<!-- Twitter Card Tags -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($seo['image'], ENT_QUOTES, 'UTF-8') ?>">

<!-- Robots Meta -->
<meta name="robots" content="<?= htmlspecialchars($seo['robots'], ENT_QUOTES, 'UTF-8') ?>">
	
<meta name="theme-color" content="<?= htmlspecialchars(THEME_COLOR, ENT_QUOTES, 'UTF-8') ?>">
	
<link rel="icon" href="/assets/icons/favicon.ico" type="image/x-icon">
<link rel="alternate" type="application/rss+xml" title="<?= SITE_NAME ?> RSS Feed" href="/rss.xml" />

<script id="dm">!function(){let e="1"===localStorage.dm,a=!1,t=!1,r=()=>new Promise((e,t)=>{let r=document.createElement("script");r.src="/assets/js/darkreader.min.js",r.onload=()=>{a=!0,e()},r.onerror=t,document.head.appendChild(r)}),n=()=>DarkReader.enable({brightness:100,contrast:100,sepia:10}),l=()=>DarkReader.disable();async function o(o){if(o.preventDefault(),t)return;t=!0;let d=document.querySelectorAll(".dark-mode-toggle");d.forEach(e=>e.classList.add("loading"));try{a||await r(),e=!e,localStorage.dm=e?"1":"0",e?n():l()}finally{setTimeout(()=>{d.forEach(e=>e.classList.remove("loading")),t=!1},600)}}e&&(a?n():r().then(n)),document.addEventListener("DOMContentLoaded",()=>{document.querySelectorAll(".dark-mode-toggle").forEach(e=>e.onclick=o)})}();</script>



<style id="critical">
:root {

  /* ─── FONTS ─────────────────────────────────── */
  --font-ui:        "Inter", "Segoe UI", Arial, sans-serif;
  --font-body:      "Roboto", sans-serif;
  --font-heading:   "Inter", sans-serif;
  --font-mono:      "Fira Code", "Courier New", monospace; /* for code blocks */

  --text-xs:    12px;
  --text-sm:    13px;
  --text-base:  15px;
  --text-md:    17px;
  --text-lg:    20px;
  --text-xl:    24px;
  --text-2xl:   30px;
  --text-3xl:   38px;

  --weight-normal:   400;
  --weight-medium:   500;
  --weight-semibold: 600;
  --weight-bold:     700;

  --leading-tight:  1.3;
  --leading-base:   1.6;
  --leading-loose:  1.8;

  --letter-tight:  -0.02em;
  --letter-normal:  0;
  --letter-wide:    0.04em;


  /* ─── BRAND COLORS ───────────────────────────── */
  --color-primary:        #7c3aed;
  --color-primary-hover:  #6d28d9;
  --color-primary-active: #5b21b6;
  --color-primary-light:  #ede9fe;
  --color-primary-soft:   #f5f3ff;

  --color-accent:         #c084fc;
  --color-accent-hover:   #a855f7;

  --color-secondary:      #4c1d95;


  /* ─── TEXT COLORS ────────────────────────────── */
  --text-primary:   #111827;
  --text-body:      #1f2937;
  --text-secondary: #4b5563;
  --text-muted:     #6b7280;
  --text-faint:     #9ca3af;
  --text-white:     #ffffff;
  --text-link:      #7c3aed;
  --text-link-hover:#5b21b6;


  /* ─── BACKGROUNDS ────────────────────────────── */
  --bg-page:        #faf7ff;
  --bg-card:        #ffffff;
  --bg-card-hover:  #fcfbff;
  --bg-header:      rgba(255, 255, 255, 0.92);
  --bg-sidebar:     #ffffff;
  --bg-subtle:      #f3f0ff;
  --bg-overlay:     rgba(15, 23, 42, 0.55);
  --bg-code:        #1e1b4b;  /* inline code / code block bg */
  --bg-tag:         #ede9fe;


  /* ─── BORDERS ────────────────────────────────── */
  --border-light:   #ebe5ff;
  --border-default: #d8ccff;
  --border-input:   #d8b4fe;
  --border-focus:   #7c3aed;
  --border-width:   1px;
  --border-radius-xs:   4px;
  --border-radius-sm:   8px;
  --border-radius-md:   14px;
  --border-radius-lg:   20px;
  --border-radius-full: 999px;


  /* ─── SHADOWS ────────────────────────────────── */
  --shadow-xs: 0 1px 4px rgba(124, 58, 237, 0.06);
  --shadow-sm: 0 2px 8px rgba(124, 58, 237, 0.08);
  --shadow-md: 0 8px 24px rgba(124, 58, 237, 0.12);
  --shadow-lg: 0 16px 40px rgba(124, 58, 237, 0.18);


  /* ─── SPACING ────────────────────────────────── */
  --space-1:  4px;
  --space-2:  8px;
  --space-3:  12px;
  --space-4:  16px;
  --space-5:  20px;
  --space-6:  24px;
  --space-8:  32px;
  --space-10: 40px;
  --space-12: 48px;
  --space-16: 64px;


  /* ─── LAYOUT ─────────────────────────────────── */
  --container-sm:     760px;   /* article / reading width */
  --container-md:     1000px;
  --container-lg:     1200px;
  --container-xl:     1400px;

  --header-height:    72px;
  --sidebar-width:    300px;
  --content-gap:      32px;    /* gap between main + sidebar */


  /* ─── GRADIENTS ──────────────────────────────── */
  --gradient-primary: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
  --gradient-soft:    linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
  --gradient-dark:    linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%);
  --gradient-hero:    linear-gradient(160deg, #f5f3ff 0%, #ede9fe 60%, #faf7ff 100%);


  /* ─── TRANSITIONS ────────────────────────────── */
  --ease-fast:   0.15s ease;
  --ease-normal: 0.25s ease;
  --ease-slow:   0.4s ease;


  /* ─── Z-INDEX ────────────────────────────────── */
  --z-base:    1;
  --z-card:    10;
  --z-overlay: 999;
  --z-header:  1000;
  --z-sidebar: 1001;
  --z-modal:   1100;
  --z-toast:   1200;
  --z-search:  2000;


  /* ─── COMPONENTS ─────────────────────────────── */

  /* Buttons */
  --btn-height-sm:  34px;
  --btn-height-md:  42px;
  --btn-height-lg:  50px;
  --btn-padding-sm: 0 14px;
  --btn-padding-md: 0 20px;
  --btn-padding-lg: 0 28px;

  /* Cards */
  --card-padding:   24px;
  --card-gap:       28px;
  --card-radius:    var(--border-radius-md);
  --card-border:    var(--border-light);
  --card-shadow:    var(--shadow-sm);

  /* Inputs */
  --input-height:   44px;
  --input-padding:  0 14px;
  --input-radius:   var(--border-radius-sm);
  --input-bg:       #ffffff;

  /* Badge / Tags */
  --tag-padding:    3px 10px;
  --tag-radius:     var(--border-radius-full);
  --tag-font-size:  var(--text-xs);

  /* Reading */
  --article-width:  680px;   /* max width for article content */
  --article-gap:    var(--space-6);


  /* ─── SCROLLBAR ──────────────────────────────── */
  --scrollbar-width:       6px;
  --scrollbar-track:       #f3f0ff;
  --scrollbar-thumb:       #c4b5fd;
  --scrollbar-thumb-hover: #9f67e8;


  /* ─── SELECTION ──────────────────────────────── */
  --selection-bg:   #c4b5fd;
  --selection-text: #1e1b4b;

}

*,
*::before,
*::after {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

* {
  -webkit-tap-highlight-color: transparent;
}

html {
  scroll-behavior: smooth;
}

body {
  font-family: var(--font-body);
  font-size: var(--text-base);
  line-height: var(--leading-base);
  color: var(--text-body);
  background: var(--bg-page);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

a {
  text-decoration: none;
  color: var(--text-link);
  transition: color var(--ease-fast);
}

a:hover {
  color: var(--text-link-hover);
}

h1, h2, h3, h4, h5, h6 {
  font-family: var(--font-heading);
  color: var(--text-primary);
  line-height: var(--leading-tight);
  letter-spacing: var(--letter-tight);
  font-weight: var(--weight-bold);
}

img {
  max-width: 100%;
  display: block;
}

::selection {
  background: var(--selection-bg);
  color: var(--selection-text);
}

/* Scrollbar */
::-webkit-scrollbar {
  width: var(--scrollbar-width);
}
::-webkit-scrollbar-track {
  background: var(--scrollbar-track);
}
::-webkit-scrollbar-thumb {
  background: var(--scrollbar-thumb);
  border-radius: var(--border-radius-full);
}
::-webkit-scrollbar-thumb:hover {
  background: var(--scrollbar-thumb-hover);
}



/* ── Global Container ──────────────────────────── */
.container {
  max-width: var(--container-lg);
  margin: 0 auto;
  padding: 0 var(--space-4);
}


/* ── Header ────────────────────────────────────── */
header {
  background: var(--bg-header);
  box-shadow: var(--shadow-sm);
  padding: var(--space-3) 0;
  position: relative;
  z-index: var(--z-header);
}

header .container {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: var(--container-xl);
  margin: 0 auto;
  padding: 0 var(--space-2);
}


/* ── Brand ─────────────────────────────────────── */
.brand-link {
  display: flex;
  align-items: center;
  color: var(--color-primary);
}

.brand-link img {
  height: 40px;
  width: auto;
  margin-right: var(--space-2);
}

.brand-title {
  font-family: var(--font-heading);
  color: var(--color-primary);
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
}


/* ── Nav ───────────────────────────────────────── */
nav ul {
  display: flex;
  list-style: none;
}

nav li {
  margin-left: var(--space-5);
  border-radius: var(--border-radius-xs);
  transition: background var(--ease-fast);
}

nav li:hover {
  background: var(--color-primary-soft);
}

nav a {
  display: block;
  padding: var(--space-1) var(--space-2);
  font-weight: var(--weight-semibold);
  color: var(--text-body);
}

nav a:hover {
  color: var(--color-primary);
}


/* ── Menu Toggle (Mobile) ──────────────────────── */
.menu-toggle {
  display: none;
  background: none;
  border: none;
  cursor: pointer;
  padding: var(--space-1);
  font-size: var(--text-xl);
  color: var(--text-body);
}

@media (max-width: 768px) {
  .menu-toggle {
    display: block;
  }

  header nav {
    display: none;
  }
}


/* ── Header Actions ────────────────────────────── */
.header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  transition: opacity var(--ease-normal);
}

.search-btn {
  border: none;
  padding: 0;
  background: none;
  cursor: pointer;
}


/* ── Search Overlay ────────────────────────────── */
.search-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--bg-card);
  z-index: var(--z-search);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition:
    opacity var(--ease-normal),
    visibility var(--ease-normal),
    transform var(--ease-normal);
}

header.search-active .search-overlay {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.search-container {
  width: 100%;
  max-width: var(--container-xl);
  padding: 0 var(--space-4);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.input-wrapper {
  position: relative;
  width: 90%;
  display: flex;
  align-items: center;
}

.input-icon {
  position: absolute;
  left: var(--space-2);
  pointer-events: none;
  color: var(--text-muted);
}

.search-overlay input {
  width: 100%;
  height: var(--input-height);
  border: var(--border-width) dotted var(--border-input);
  padding: 0 var(--space-8) 0 var(--space-8);
  outline: none;
  border-radius: var(--border-radius-sm);
  background: transparent;
  color: var(--color-primary);
  font-size: var(--text-sm);
  font-family: var(--font-body);
}

.search-overlay input::placeholder {
  color: var(--text-faint);
}

.search-overlay input:focus {
  border-color: var(--border-focus);
}

.close-search {
  background: none;
  border: none;
  cursor: pointer;
  padding: var(--space-1);
  font-size: var(--text-lg);
  margin-left: var(--space-2);
  color: var(--text-muted);
}


/* ── Voice Icon ────────────────────────────────── */
.voice-icon {
  border: none;
  background: none;
  position: absolute;
  right: var(--space-2);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.voice-icon::before {
  content: "";
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  background: rgba(124, 58, 237, 0.25);
  opacity: 0;
}

.voice-icon.listening::before {
  animation: listen 1s ease-out infinite;
  opacity: 1;
}

.voice-icon.listening svg {
  animation: micPulse 1s ease-in-out infinite;
}

@keyframes listen {
  0%   { transform: scale(0.7); opacity: 0.6; }
  70%  { transform: scale(1.12); opacity: 0.25; }
  100% { opacity: 0.15; }
}

@keyframes micPulse {
  0%, 100% { transform: scale(1); }
  50%       { transform: scale(1.12); }
}


/* ── Sidebar / Mobile Nav Drawer ───────────────── */
.sidebar {
  --drawer-accent: var(--color-primary);
  --drawer-ink: #1d1d1f;
  position: fixed;
  top: 0;
  left: 0;
  width: min(380px, 88vw);
  max-width: 100vw;
  height: 100dvh;
  background: var(--bg-sidebar);
  box-shadow: var(--shadow-lg);
  transition: transform var(--ease-normal);
  z-index: var(--z-sidebar);
  display: flex;
  flex-direction: column;
  will-change: transform;
  transform: translateX(-101%);
  color: var(--drawer-ink);
}

.sidebar.active {
  transform: translateX(0);
}

.sidebar-top {
  flex: 0 0 auto;
  border-bottom: var(--border-width) solid var(--border-light);
}

/* Row 1: close / location / weather / search */
.sidebar-actions {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-4);
}

.sidebar-icon-btn {
  flex: 0 0 auto;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border: var(--border-width) solid var(--border-light);
  border-radius: var(--border-radius-full);
  background: none;
  color: var(--drawer-ink);
  cursor: pointer;
  transition: color var(--ease-fast), border-color var(--ease-fast);
}

.sidebar-icon-btn svg {
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}

.sidebar-icon-btn--badge svg {
  fill: #fff;
  stroke: none;
}

.sidebar-icon-btn:hover {
  color: var(--drawer-accent);
  border-color: var(--drawer-accent);
}

.sidebar-icon-btn--static {
  cursor: default;
}

.sidebar-icon-btn--search {
  border-color: transparent;
}

.sidebar-icon-btn--badge {
  border-color: transparent;
  background: var(--drawer-accent);
  color: #fff;
}

.sidebar-icon-btn--badge:hover {
  color: #fff;
  opacity: .85;
}

.sidebar-weather {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-1);
  min-width: 0;
  height: 34px;
  padding: var(--space-1) var(--space-3);
  border: var(--border-width) solid #999;
  border-radius: var(--border-radius-md);
  color: var(--drawer-ink);
  font-size: var(--text-xs);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sidebar-weather svg {
  fill: none;
  stroke: currentColor;
  stroke-width: 1.6;
  stroke-linecap: round;
  stroke-linejoin: round;
}

/* Row 2: avatar + welcome + sign in */
.sidebar-account {
  padding: var(--space-2) var(--space-5) var(--space-4);
}

.sidebar-welcome {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
  font-size: var(--text-base);
  font-weight: var(--weight-bold);
  color: var(--drawer-ink);
}

.sidebar-avatar {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 44px;
  height: 44px;
  border-radius: var(--border-radius-full);
  background: #f0f0f0;
  color: var(--drawer-accent);
}

.sidebar-avatar svg {
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
}

.sidebar-signin {
  display: block;
  width: 100%;
  padding: var(--space-3);
  border: none;
  border-radius: var(--border-radius-sm);
  background: var(--drawer-accent);
  color: var(--text-white);
  font-size: var(--text-base);
  font-weight: var(--weight-bold);
  text-align: center;
  text-decoration: none;
  cursor: pointer;
  transition: opacity var(--ease-fast);
}

.sidebar-signin:hover {
  opacity: .9;
}

/* Row 3: edition / language / plus */
.sidebar-preferences {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-5);
  background: #fafafa;
  overflow: hidden;
}

.sidebar-edition {
  flex: 0 0 auto;
  font-size: var(--text-xs);
  font-weight: var(--weight-bold);
  color: var(--text-secondary);
}

.sidebar-pref-select {
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-1);
  flex: 1 1 0;
  min-width: 0;
  padding: var(--space-1) var(--space-2);
  border: var(--border-width) solid #333;
  border-radius: var(--border-radius-xs);
  background: var(--bg-card);
  color: var(--drawer-ink);
  font-size: var(--text-xs);
  cursor: pointer;
}

.sidebar-pref-select span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-pref-select svg {
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
}

.sidebar-plus {
  flex: 0 0 auto;
  font-family: Georgia, serif;
  font-size: var(--text-md);
  font-weight: var(--weight-bold);
  color: var(--drawer-ink);
}

.sidebar-plus sup {
  color: var(--drawer-accent);
  font-size: .6em;
}

.sidebar-nav {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-4) var(--space-3);
}

.sidebar-nav-item {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  width: 100%;
  padding: var(--space-3);
  border: none;
  border-radius: 0;
  background: none;
  color: var(--drawer-ink);
  font-family: inherit;
  font-size: var(--text-base);
  font-weight: var(--weight-normal);
  text-align: left;
  text-decoration: none;
  cursor: pointer;
}

.sidebar-nav-item:hover,
.sidebar-nav-item:focus-visible {
  color: var(--drawer-accent);
}

.sidebar-nav-icon {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 22px;
  height: 22px;
  color: inherit;
}

.sidebar-nav-icon svg {
  width: 100%;
  height: 100%;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.sidebar-nav-toggle {
  margin-top: var(--space-2);
  padding-top: var(--space-4);
  border-top: var(--border-width) solid var(--border-light);
}

.sidebar-nav-toggle.loading {
  opacity: .5;
  pointer-events: none;
}

.sidebar-social {
  flex: 0 0 auto;
  padding: var(--space-3) var(--space-5) var(--space-4);
  border-top: var(--border-width) solid var(--border-light);
  background: #f7f7f7;
  text-align: center;
}

.sidebar-social-title {
  margin: 0 0 var(--space-2);
  font-size: var(--text-sm);
  color: #555;
}

.sidebar-social-row {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--space-2);
  row-gap: var(--space-3);
}

.sidebar-social-link {
  display: grid;
  place-items: center;
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  border-radius: var(--border-radius-full);
  border: none;
  background: var(--drawer-accent);
  text-decoration: none;
  transition: opacity var(--ease-fast), transform var(--ease-fast);
}

.sidebar-social-link svg {
  fill: #fff;
}

.sidebar-social-link:hover {
  opacity: .85;
  transform: translateY(-2px);
}

@media (min-width: 769px) {
  .sidebar,
  .overlay {
    display: none !important;
  }
}



/* ── Overlay (backdrop) ────────────────────────── */
.overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100vh;
  background: var(--bg-overlay);
  opacity: 0;
  visibility: hidden;
  transition: opacity var(--ease-normal), visibility var(--ease-normal);
  z-index: var(--z-overlay);
  will-change: opacity;
}

.overlay.active {
  opacity: 1;
  visibility: visible;
}


/* ── Page Title (main H1) ──────────────────────── */
.page-title {
  font-size: var(--text-xl);
  font-weight: var(--weight-bold);
  color: var(--text-primary);
  padding: var(--space-2) 0;
}


/* ── Global Breadcrumbs ───────────────────────────────── */
.breadcrumbs {
  font-family: var(--font-ui);
  font-size: var(--text-sm);
  margin: var(--space-2) var(--space-4) 0;
}

.breadcrumbs ol {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  list-style: none;
  padding: 0;
  margin: 0;
  color: var(--text-muted);
}

.breadcrumbs li {
  display: flex;
  align-items: center;
  margin-left: 0;
}

.breadcrumbs li:not(:last-child)::after {
  content: '/';
  margin: 0 var(--space-1);
  color: var(--text-faint);
}

.breadcrumbs a {
  color: var(--text-link);
  transition: color var(--ease-fast);
}

.breadcrumbs a:hover {
  color: var(--text-link-hover);
  text-decoration: underline;
}

.breadcrumbs li[aria-current="page"] {
  color: var(--text-secondary);
  font-weight: var(--weight-semibold);
}




/* Quick Links Container (Home and Categories) */
.ql-container {
  position: relative;
  margin-bottom: var(--space-5);
}

.ql-mask {
  max-height: 120px;
  overflow: hidden;
  transition: max-height 0.5s ease-in-out;
  position: relative;
}

.ql-mask.expanded {
  max-height: 2000px;
}

.ql-mask::after {
  content: "";
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 60px;
  background: linear-gradient(to bottom, transparent, var(--bg-page));
  pointer-events: none;
  transition: opacity var(--ease-normal);
}

.ql-mask.expanded::after {
  opacity: 0;
}

.ql-table {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-1);
  list-style: none;
  padding: 0;
  margin: 0 0 var(--space-5) 0;
}

.ql-table li {
  border: var(--border-width) dotted var(--border-default);
  padding: var(--space-2) var(--space-1);
  margin: 0;
  background: var(--bg-subtle);
  text-align: center;
}

.ql-table a {
  color: var(--text-body);
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  display: block;
  width: 100%;
}

.ql-table a:hover {
  background: var(--color-primary-soft);
  color: var(--color-primary);
  text-decoration: underline;
}

.ql-toggle-btn {
  display: block;
  margin: var(--space-2) auto 0;
  background: var(--bg-card);
  border: var(--border-width) dotted var(--color-primary);
  color: var(--color-primary);
  padding: var(--space-2) var(--space-5);
  border-radius: var(--border-radius-full);
  font-size: var(--text-xs);
  cursor: pointer;
  transition: background var(--ease-fast), color var(--ease-fast);
}

.ql-toggle-btn:hover {
  background: var(--color-primary);
  color: var(--text-white);
}

@media (max-width: 768px) {
  .ql-table {
    grid-template-columns: repeat(2, 1fr);
  }
}




/* ── Global Post Grid ─────────────────────────────────── */
.post-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: var(--card-gap);
  margin: 0;
}

/* ── Post Card ─────────────────────────────────── */
.post-card {
  background: var(--bg-card);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  border-radius: var(--border-radius-sm);
  color: inherit;
  will-change: transform;
  transition: box-shadow var(--ease-normal), transform var(--ease-normal);
}

.post-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.post-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
}

/* ── Card Banner ───────────────────────────────── */
.post-banner {
  width: 100%;
  height: 200px;
  overflow: hidden;
  background: var(--bg-subtle);
  position: relative;
}

.post-banner img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform var(--ease-slow);
  will-change: transform;
}

.post-card:hover .post-banner img {
  transform: scale(1.05);
}

/* ── Banner Placeholder (no image) ────────────── */
.post-banner-placeholder {
  width: 100%;
  height: 100%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-white);
  font-size: 48px;
  font-weight: var(--weight-bold);
}

/* ── Card Content ──────────────────────────────── */
.post-card-content {
  padding: var(--card-padding);
}

.post-card-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-3) 0;
  color: var(--text-primary);
  line-height: var(--leading-tight);
}

.post-card-author {
  font-family: var(--font-ui);
  font-size: var(--text-sm);
  color: var(--color-primary);
  font-weight: var(--weight-semibold);
  margin: 0;
}

/* ── Responsive ────────────────────────────────── */
@media (max-width: 768px) {
  .post-grid {
    grid-template-columns: 1fr;
    gap: var(--space-5);
  }
}


/* ── Author Bio (post page: bottom / author page: top) ── */
.author-bio {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-3);
  padding: var(--card-padding);
  background: var(--bg-card);
  border: var(--border-width) solid var(--border-light);
  border-radius: var(--card-radius);
  box-shadow: var(--card-shadow);
}

.author-bio p {
  text-align: justify;
  color: var(--text-secondary);
  line-height: var(--leading-loose);
}

.author-follow-links {
  display:flex;
  gap:14px;
  flex-wrap:wrap;
  margin-top:8px;
}

/* ── Promo Top Bar ─────────────────────────────── */
.promo-top-bar {
  position: sticky;
  top: 0;
  z-index: var(--z-overlay);
  background-color: #111827;
  color: var(--text-white);
  padding: var(--space-1);
  box-shadow: var(--shadow-sm);
  display: flex;
  justify-content: center;
  align-items: center;
  gap: var(--space-1);
  transition: transform var(--ease-normal);
  overflow: hidden;
}

.ptb-message {
  font-size: var(--text-xs);
  font-weight: var(--weight-medium);
  margin: 0;
  letter-spacing: 0.5px;
  min-width: 230px;
  text-align: center;
  display: flex;
  justify-content: center;
}


.ptb-btn {
  background-color: var(--color-primary);
  color: var(--text-white);
  text-decoration: none;
  padding: 2px var(--space-3);
  border-radius: var(--border-radius-full);
  font-size: var(--text-xs);
  font-weight: var(--weight-bold);
  white-space: nowrap;
  transition: background-color var(--ease-fast);
}

.ptb-btn:hover {
  background-color: var(--color-primary-hover);
}

.ptb-close {
  background: transparent;
  border: none;
  color: rgba(255, 255, 255, 0.6);
  font-size: var(--text-sm);
  cursor: pointer;
  padding: 0 var(--space-1);
  margin-left: var(--space-1);
  transition: color var(--ease-fast);
}

.ptb-close:hover {
  color: var(--text-white);
}

.anim-txt {
  display: inline-block;
  transition: transform 0.5s cubic-bezier(0.25, 1, 0.5, 1), opacity 0.5s ease;
  will-change: transform, opacity;
}

.txt-out {
  transform: translateY(-150%) scale(0.95);
  opacity: 0;
}

.txt-in {
  transform: translateY(150%) scale(0.95);
  opacity: 0;
  transition: none;
}

/* No post class show */
.no-post {
	margin: 80px auto;
	text-align: center;
	color: #686868;
	font-style: italic
}


/* PAGE SPECIFIC UI CRITICAL */

/* ── Category Section Header ───────────────────── */
.category-section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-8);
  padding-bottom: 0.2rem;
  border-bottom: var(--border-width) dotted var(--border-dark);
}

.category-section-title {
  font-size: var(--text-xl);
  color: var(--text-primary);
  margin: 0;
}

.view-all-link {
  color: var(--color-primary);
  font-weight: var(--weight-semibold);
  font-size: var(--text-base);
  transition: color var(--ease-fast);
}

.view-all-link:hover {
  color: var(--color-primary-hover);
}

.no-posts-message {
  color: var(--text-secondary);
  text-shadow: 0 0 1px rgba(0, 0, 0, 0.1);
  font-style: italic;
  padding: var(--space-8) 0;
}
</style>


<link rel="stylesheet" href="/assets/css/app.min.css?v=22052026" media="print" onload="this.media='all'">

<noscript><link rel="stylesheet" href="/assets/css/app.min.css?v=22052026"></noscript>

<script id="app" src="/assets/js/app.bundle.min.js?v=22052026" defer></script>


<!-- Organization Schema -->
<?php
$companySameAs = array_values(array_filter([
    SOCIAL_INSTAGRAM,
    SOCIAL_THREADS,
    SOCIAL_LINKEDIN,
    SOCIAL_FACEBOOK,
    SOCIAL_TWITTER,
    SOCIAL_WHATSAPP,
    SOCIAL_TELEGRAM,
    SOCIAL_ARATT,
]));

$org_schema = [
    '@context'      => 'https://schema.org',
    '@type'         => 'Organization',
    '@id'      => SITE_URL . '#organization',
    'name'          => SITE_NAME,
    'url'           => SITE_URL,
    'logo'          => SITE_LOGO,
    'foundingDate'  => FOUNDING_YEAR,
    'description'   => SEO_DEFAULT_DESCRIPTION,
    'address' => [
        '@type'           => 'PostalAddress',
        'addressLocality' => 'Begun, Chittorgarh',
        'addressRegion'   => 'Rajasthan',
        'postalCode'      => '312023',
        'addressCountry'  => 'IN',
    ],
    'contactPoint' => [
        '@type'             => 'ContactPoint',
        'contactType'       => 'Customer Support',
        'availableLanguage' => ['Hindi', 'English'],
        'email'             => CONTACT_EMAIL,
        'telephone'         => PHONE_NUMBER,
        'url'               => SITE_URL . '/contact-us',
    ],
    'sameAs' => $companySameAs,
];
?>
<script type="application/ld+json">
<?= json_encode($org_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>



<!-- Website Schema -->
<?php
$website_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'WebSite',
    '@id'        => SITE_URL . '#website',
    'url'        => SITE_URL,
    'name'       => SITE_NAME,
    'inLanguage' => 'en-IN',
    'publisher'  => [
        '@id' => SITE_URL . '#organization',
    ],
];
?>
<script type="application/ld+json">
<?= json_encode($website_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>