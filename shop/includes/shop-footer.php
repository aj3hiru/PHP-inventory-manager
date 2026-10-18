<?php
// Uses $shop_biz, $shop_biz_name from shop-header.php (already included on every page before this)
$footer_biz = $shop_biz ?? ($pdo->query("SELECT * FROM ecom_business_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: []);
$footer_biz_name = $shop_biz_name ?? ($footer_biz['business_name'] ?: $site_name);
$footer_numbers = !empty($footer_biz['contact_numbers']) ? (json_decode($footer_biz['contact_numbers'], true) ?: []) : [];
$footer_social = !empty($footer_biz['social_media_json']) ? (json_decode($footer_biz['social_media_json'], true) ?: []) : [];
$SOCIAL_ICON_SVG = [
    'facebook'  => '<path d="M13.5 21v-7.2h2.4l.35-2.8h-2.75V9.2c0-.8.22-1.35 1.38-1.35H16.4V5.35C16.1 5.32 15.1 5.2 13.9 5.2c-2.4 0-4.05 1.47-4.05 4.15v2.65H7.4v2.8h2.45V21h3.65Z"/>',
    'instagram' => '<rect x="4.5" y="4.5" width="15" height="15" rx="4.5" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="12" cy="12" r="3.4" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="16.3" cy="7.7" r="1"/>',
    'youtube'   => '<rect x="3.5" y="6.5" width="17" height="11" rx="3" fill="none" stroke="#fff" stroke-width="1.6"/><path fill="#fff" d="M10.3 9.6v4.8l4.3-2.4-4.3-2.4Z"/>',
    'x'         => '<rect x="11" y="4" width="2" height="16" rx="1" transform="rotate(45 12 12)"/><rect x="11" y="4" width="2" height="16" rx="1" transform="rotate(-45 12 12)"/>',
    'linkedin'  => '<rect x="5.3" y="10.2" width="2.6" height="8"/><circle cx="6.6" cy="6.9" r="1.5"/><path d="M10.5 10.2h2.5v1.3c.5-.85 1.5-1.5 2.8-1.5 2.1 0 3.3 1.4 3.3 4v4.2h-2.6v-3.8c0-1-.4-1.8-1.4-1.8-.85 0-1.4.6-1.6 1.1-.1.25-.1.55-.1.85v3.65h-2.6v-8Z"/>',
    'whatsapp'  => '<path d="M12 4a8 8 0 0 0-6.9 12l-1 3.6 3.7-1A8 8 0 1 0 12 4Zm4.6 11.4c-.2.6-1.1 1.1-1.6 1.2-.4.05-1 .07-1.6-.1-.35-.1-.8-.25-1.4-.5-2.4-1.05-4-3.5-4.1-3.65-.12-.16-1-1.3-1-2.5 0-1.2.6-1.8.85-2.05.2-.4.35-.4.5-.4h.4c.15 0 .3 0 .45.35.15.35.55 1.35.6 1.45.05.1.1.2 0 .35s-.15.25-.25.4c-.1.1-.2.25-.3.35-.1.1-.2.25-.1.45.15.25.6 1 1.3 1.6.9.8 1.6 1.05 1.9 1.15.25.1.4.1.55-.05.15-.15.6-.7.75-.95.15-.25.3-.2.5-.1.2.1 1.35.65 1.6.75.25.1.4.15.45.25.05.1.05.6-.15 1.15Z"/>',
];
?>
  </div><!-- /.page-container -->

  <!-- ============ MOBILE NAV DRAWER ============ -->
  <div id="overlay"></div>
  <aside id="sidebar" aria-label="Mobile Navigation" aria-hidden="true">
    <div class="sidebar-top">
      <div class="sidebar-actions">
        <button class="sidebar-icon-btn" id="closeBtn" type="button" aria-label="Close Menu">
          <svg viewBox="0 0 24 24" width="20" height="20"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
      </div>

      <div class="sidebar-account">
        <div class="sidebar-welcome">
          <span class="sidebar-avatar">
            <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4.5 5-6 8-6s6.5 1.5 8 6"/></svg>
          </span>
          <span><?php if ($shop_customer): ?>Welcome, <?= htmlspecialchars(explode(' ', $shop_customer['name'])[0]) ?>!<?php else: ?>Welcome to <?= htmlspecialchars($footer_biz_name) ?>!<?php endif; ?></span>
        </div>
        <?php if (!$shop_customer): ?>
        <a href="/shop/login.php" class="sidebar-signin">Sign In / Register</a>
        <?php else: ?>
        <a href="/logout" class="sidebar-signin">Logout</a>
        <?php endif; ?>
      </div>
    </div>

    <nav class="sidebar-nav" role="navigation" aria-label="Mobile Menu">
      <a class="sidebar-nav-item" href="/">
        <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg></span>
        <span>Home</span>
      </a>
      <a class="sidebar-nav-item" href="/store#categories">
        <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/></svg></span>
        <span>All Categories</span>
      </a>
      <?php if ($shop_customer): ?>
      <a class="sidebar-nav-item" href="/shop/account.php#orders">
        <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 6l6-2 6 2 6-2v14l-6 2-6-2-6 2V6z"/><path d="M9 4v14M15 6v14"/></svg></span>
        <span>My Orders</span>
      </a>
      <a class="sidebar-nav-item" href="/shop/wishlist.php">
        <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg></span>
        <span>Wishlist</span>
      </a>
      <?php endif; ?>
      <a class="sidebar-nav-item" href="/shop/cart.php">
        <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg></span>
        <span>My Cart</span>
      </a>
    </nav>

    <?php if (!empty($footer_social)): ?>
    <div class="sidebar-social">
      <p class="sidebar-social-title">Follow Us on Social Media</p>
      <div class="sidebar-social-row">
        <?php foreach ($footer_social as $s): $icon = $SOCIAL_ICON_SVG[$s['platform']] ?? null; if (!$icon) continue; ?>
        <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="<?= htmlspecialchars(ucfirst($s['platform'])) ?>">
          <svg viewBox="0 0 24 24" width="20" height="20"><?= $icon ?></svg>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </aside>

  <!-- ============ FOOTER ============ -->
  <footer class="site-footer" role="contentinfo">
    <div class="footer-inner">

      <div class="footer-main">

        <div class="footer-col footer-about">
          <div class="f-logo">
            <?php if (!empty($footer_biz['logo'])): ?>
              <img src="/<?= htmlspecialchars($footer_biz['logo']) ?>" alt="<?= htmlspecialchars($footer_biz_name) ?>">
            <?php else: ?>
              <span><?= htmlspecialchars($footer_biz_name) ?></span>
            <?php endif; ?>
          </div>
          <p><?= htmlspecialchars($footer_biz['tagline'] ?: 'Your everyday store — fresh products and daily essentials, delivered to your doorstep.') ?></p>
          <?php if (!empty($footer_social)): ?>
          <div class="footer-social-row">
            <?php foreach ($footer_social as $s): $icon = $SOCIAL_ICON_SVG[$s['platform']] ?? null; if (!$icon) continue; ?>
            <a href="<?= htmlspecialchars($s['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= htmlspecialchars(ucfirst($s['platform'])) ?>">
              <svg viewBox="0 0 24 24"><?= $icon ?></svg>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <div class="footer-col">
          <h4>Quick Links</h4>
          <ul class="footer-links">
            <li><a href="/">Home</a></li>
            <li><a href="/store#categories">All Categories</a></li>
            <li><a href="/shop/cart.php">My Cart</a></li>
            <li><a href="/shop/account.php">My Account</a></li>
          </ul>
        </div>

        <div class="footer-col">
          <h4>Get in Touch</h4>
          <ul class="footer-contact-list">
            <?php if (!empty($footer_numbers[0])): ?>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.8.6 2.7a2 2 0 0 1-.4 2.1L8.1 9.7a16 16 0 0 0 6.2 6.2l1.2-1.2a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.7 2Z"/></svg>
              <span><?= htmlspecialchars($footer_numbers[0]) ?></span>
            </li>
            <?php endif; ?>
            <?php if (!empty($footer_biz['email'])): ?>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
              <span><?= htmlspecialchars($footer_biz['email']) ?></span>
            </li>
            <?php endif; ?>
            <?php if (!empty($footer_biz['address'])): ?>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s6-6.2 6-10.5A6 6 0 0 0 6 10.5C6 14.8 12 21 12 21zM12 12.5a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
              <span><?= htmlspecialchars($footer_biz['address']) ?></span>
            </li>
            <?php endif; ?>
          </ul>
        </div>

        <div class="footer-col">
          <h4>Customer Service</h4>
          <ul class="footer-links">
            <li><a href="/shop/account.php">My Account</a></li>
            <li><a href="/order">Track Order</a></li>
            <?php if (!empty($footer_biz['return_policy'])): ?><li><a href="/store#returns">Returns &amp; Refunds</a></li><?php endif; ?>
          </ul>
        </div>

      </div>

      <div class="footer-bottom">
        <p class="footer-copy">&copy; <?= date('Y') ?> <?= htmlspecialchars($footer_biz_name) ?>. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    const bannerEl = document.getElementById('bannerSlider');
    if (bannerEl) {
      const slides = document.querySelectorAll('#bannerSlider .slide');
      const dots = document.querySelectorAll('.slider-dots .dot');
      const counter = document.getElementById('slideCounter');
      let current = 0;
      let autoTimer = null;

      function goTo(i){
        i = (i + slides.length) % slides.length;
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = i;
        slides[current].classList.add('active');
        dots[current].classList.add('active');
        if (counter) counter.textContent = (current+1) + '/' + slides.length;
      }

      function startAuto(){
        clearInterval(autoTimer);
        autoTimer = setInterval(()=> goTo(current+1), 4000);
      }

      dots.forEach((dot,i)=> dot.addEventListener('click', ()=>{ goTo(i); startAuto(); }));
      if (slides.length) { goTo(0); startAuto(); }

      let touchStartX = 0, touchEndX = 0;
      bannerEl.addEventListener('touchstart', e=>{
        touchStartX = e.changedTouches[0].screenX;
      }, {passive:true});
      bannerEl.addEventListener('touchend', e=>{
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 40 && slides.length){
          if (diff > 0) goTo(current+1); else goTo(current-1);
          startAuto();
        }
      }, {passive:true});
    }
  </script>

  <script>
    (()=>{
      const m = document.getElementById("menuToggle"),
            c = document.getElementById("closeBtn"),
            s = document.getElementById("sidebar"),
            o = document.getElementById("overlay");
      if(!m||!s||!o) return;
      const setMenu = open=>{
        s.classList.toggle("active", open);
        o.classList.toggle("active", open);
        s.setAttribute("aria-hidden", String(!open));
        document.body.style.overflow = open ? "hidden" : "";
      };
      m.addEventListener("click", ()=> setMenu(true));
      c && c.addEventListener("click", ()=> setMenu(false));
      o.addEventListener("click", ()=> setMenu(false));
      document.addEventListener("keydown", e=>{ if(e.key==="Escape") setMenu(false); });
      s.addEventListener("click", e=>{
        if(e.target.closest(".sidebar-nav-item")) setMenu(false);
      });
    })();
  </script>

  <script>
    document.querySelectorAll('.img-wrap img, .thumb img, .img2 img, .f-logo img, .logo .mark').forEach(img => {
      img.addEventListener('error', function(){
        this.style.objectFit = 'contain';
        this.style.padding = '20%';
        this.style.background = '#f0f0f0';
        this.onerror = null;
        this.src = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="%236b7280" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>'
        );
      });
    });
  </script>

  <?php
    // Only on browsing pages — homepage, category/subcategory, brand, search,
    // product — never on the cart/checkout/order flow itself, where a "go to
    // your cart" bar floating over the actual cart (or the order-placement /
    // order-confirmation flow) is redundant and just gets in the way.
    // Matched against the actual PHYSICAL script filename (SCRIPT_FILENAME),
    // not REQUEST_URI/PHP_SELF — those reflect the public-facing URL (which
    // may later become a clean URL like "/cart" with no ".php" in it once the
    // URL restructuring goes live), whereas SCRIPT_FILENAME always resolves
    // to the real file being executed regardless of how the URL is rewritten,
    // so this keeps working correctly under both the old and new URL schemes.
    $fcb_hide_on = ['cart.php', 'checkout.php', 'order.php', 'place-order.php', 'order-success.php', 'order-confirmation.php'];
    $fcb_current_file = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $fcb_show_bar = !in_array($fcb_current_file, $fcb_hide_on, true);
  ?>
  <?php if ($fcb_show_bar): ?>
  <!-- ============ FLOATING "VIEW CART" BAR ============
       Shown only on browsing pages (home, category, subcategory, brand,
       search, product) — hidden on the cart/checkout pages themselves via
       $fcb_show_bar above. Hidden by default — appears (with a slide-up +
       bounce entrance animation) the moment the cart actually has something
       in it: right after an add-to-cart call succeeds, or on page load if
       the cart (database-backed, so it survives refresh and follows a
       logged-in customer across devices) already has items in it from
       before. Tapping it plays a quick ripple/splash, then goes to the cart. -->
  <a href="/shop/cart.php" class="floating-cart-bar" id="floatingCartBar" aria-live="polite">
    <span class="fcb-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L22 7H6"/></svg>
    </span>
    <span class="fcb-info">
      <span class="fcb-count"><span id="fcbCount">0</span> item<span id="fcbCountPlural">s</span><span id="fcbUnitLabel" class="fcb-unit"></span></span>
      <span class="fcb-total">₹<span id="fcbTotal">0</span></span>
    </span>
    <span class="fcb-cta">View Cart <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M9 18l6-6-6-6"/></svg></span>
  </a>
  <style>
    .floating-cart-bar{
      position:fixed; left:50%; bottom:18px; transform:translateX(-50%) translateY(90px);
      width:min(560px, calc(100% - 24px)); z-index:1000;
      display:flex; align-items:center; gap:12px;
      background:linear-gradient(135deg, var(--green-dark,#15803d), var(--green,#16a34a));
      color:#fff; border-radius:16px; padding:12px 18px;
      box-shadow:0 10px 30px rgba(21,128,61,.35), 0 2px 8px rgba(0,0,0,.12);
      text-decoration:none; opacity:0; pointer-events:none;
      transition:transform .38s cubic-bezier(.34,1.56,.64,1), opacity .28s ease;
      overflow:hidden; /* keeps the click-splash ripple clipped to the bar's rounded shape */
    }
    .floating-cart-bar.show{ opacity:1; pointer-events:auto; transform:translateX(-50%) translateY(0); }
    .floating-cart-bar.pulse{ animation:fcbPulse .5s ease; }
    @keyframes fcbPulse{
      0%{ transform:translateX(-50%) translateY(0) scale(1); }
      35%{ transform:translateX(-50%) translateY(0) scale(1.045); }
      100%{ transform:translateX(-50%) translateY(0) scale(1); }
    }
    .fcb-icon{
      width:38px; height:38px; border-radius:50%; background:rgba(255,255,255,.18);
      display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .fcb-icon svg{ width:19px; height:19px; }
    .fcb-info{ display:flex; flex-direction:column; line-height:1.3; flex:1; min-width:0; }
    .fcb-count{ font-size:13px; font-weight:600; opacity:.92; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .fcb-unit{ opacity:.85; }
    .fcb-unit:not(:empty)::before{ content:" · "; }
    .fcb-total{ font-size:18px; font-weight:800; letter-spacing:-.2px; }
    .fcb-cta{
      display:flex; align-items:center; gap:4px; font-size:14.5px; font-weight:700;
      background:rgba(255,255,255,.16); padding:9px 14px; border-radius:10px; flex-shrink:0;
      transition:background .15s ease;
    }
    .floating-cart-bar:hover .fcb-cta{ background:rgba(255,255,255,.26); }
    @media (max-width:480px){
      .floating-cart-bar{ bottom:12px; padding:10px 14px; gap:10px; }
      .fcb-total{ font-size:16px; }
      .fcb-cta{ font-size:13.5px; padding:8px 12px; }
      .fcb-cta span{ display:none; } /* icon-only CTA on very narrow phones */
    }
    /* Product page no longer has a separate sticky bottom bar (removed) —
       the floating "View Cart" bar sits at the same bottom position here
       as everywhere else (home, category, etc.), no extra offset needed. */

    /* Click splash/ripple — a soft circle that expands from the tap point
       and fades out, purely visual (the navigation to /cart still
       happens immediately after). */
    .fcb-ripple{
      position:absolute; border-radius:50%; background:rgba(255,255,255,.55);
      transform:scale(0); pointer-events:none; animation:fcbRipple .55s ease-out forwards;
    }
    @keyframes fcbRipple{
      to{ transform:scale(1); opacity:0; }
    }
  </style>
  <script>
    (function () {
      const bar = document.getElementById('floatingCartBar');
      if (!bar) return;
      const countEl = document.getElementById('fcbCount');
      const pluralEl = document.getElementById('fcbCountPlural');
      const totalEl = document.getElementById('fcbTotal');
      const unitEl = document.getElementById('fcbUnitLabel');

      function renderCartBar(count, total, unitLabel, opts) {
        opts = opts || {};
        count = Number(count) || 0;
        total = Number(total) || 0;
        countEl.textContent = count;
        pluralEl.textContent = count === 1 ? '' : 's';
        totalEl.textContent = total.toLocaleString('en-IN');
        unitEl.textContent = unitLabel || '';
        if (count > 0) {
          const wasHidden = !bar.classList.contains('show');
          bar.classList.add('show');
          if (opts.animate && !wasHidden) {
            // Already visible, quantity just changed — a quick pulse instead
            // of a full re-entrance.
            bar.classList.remove('pulse'); void bar.offsetWidth; bar.classList.add('pulse');
          }
        } else {
          bar.classList.remove('show');
        }
      }
      window.renderFloatingCartBar = function (count, total, opts) {
        // Older call sites (add-to-cart handler) don't know about the unit
        // label — re-fetch it here so it's always accurate without every
        // caller having to pass it through.
        fetch('/shop/ajax.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=cart_summary'
        })
          .then(r => r.json())
          .then(data => renderCartBar(count, total, data.success ? data.unit_label : null, opts))
          .catch(() => renderCartBar(count, total, null, opts));
      };

      // Click splash/ripple, centered on wherever the tap landed.
      bar.addEventListener('click', function (e) {
        const rect = bar.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height) * 1.6;
        const ripple = document.createElement('span');
        ripple.className = 'fcb-ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        bar.appendChild(ripple);
        setTimeout(() => ripple.remove(), 600);
      });

      // On every page load, ask the server what's actually in the cart
      // right now (database-backed — correct even after a refresh, a new
      // tab, or logging in on a different device) and show the bar
      // immediately if it's non-empty. No animation on this first paint,
      // it should just already be there.
      fetch('/shop/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=cart_summary'
      })
        .then(r => r.json())
        .then(data => { if (data.success) renderCartBar(data.cart_count, data.cart_total, data.unit_label); })
        .catch(() => {});
    })();
  </script>
  <?php endif; // $fcb_show_bar ?>

  <!-- ── Add-to-cart wiring (shared across storefront) ──
       Covers every card style: .add-cart (Cmart cards), .cart2 (card2-design
       grids), and a plain "addToCart(productId[, qty[, variantId]])" JS call
       for anywhere that wires a button by hand (e.g. the product page). If
       the button sits inside a card that has a .qty-select dropdown (weight
       / unit picker), the currently-selected variant is sent automatically.

       Once a product is in the cart, its "ADD TO CART" button turns into a
       − qty + stepper (same spot, same size) so the card always reflects
       what's really in the cart — the +/− buttons below update the DB qty
       directly and keep the header cart badge / floating cart bar in sync. -->
  <script>
    function addToCart(productId, qty, variantId) {
      qty = qty || 1;
      variantId = variantId || 0;
      return fetch('/shop/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add_to_cart&product_id=' + encodeURIComponent(productId) + '&qty=' + encodeURIComponent(qty) + '&variant_id=' + encodeURIComponent(variantId)
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) applyCartTotals(data);
        else alert(data.message || 'Could not add to cart.');
        return data;
      })
      .catch(() => ({ success: false }));
    }

    function updateCartQty(cartKey, qty) {
      return fetch('/shop/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_cart_qty&cart_key=' + encodeURIComponent(cartKey) + '&qty=' + encodeURIComponent(qty)
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) applyCartTotals(data);
        else alert(data.message || 'Could not update cart.');
        return data;
      })
      .catch(() => ({ success: false }));
    }

    // Pushes cart_count / cart_total from any ajax.php cart response into
    // the header badge and the floating "View Cart" bar.
    function applyCartTotals(data) {
      document.querySelectorAll('.cart-badge .count').forEach(el => el.textContent = data.cart_count);
      const totalEls = document.querySelectorAll('.cart-badge .label');
      totalEls.forEach(el => { if (el.textContent.trim().startsWith('₹')) el.textContent = '₹' + Number(data.cart_total).toLocaleString('en-IN'); });
      if (window.renderFloatingCartBar) window.renderFloatingCartBar(data.cart_count, data.cart_total, { animate: true });
    }

    // Swaps an "ADD TO CART" button for the − qty + stepper, in place.
    function buildQtyStepper(productId, variantId, cartKey, qty) {
      const stepper = document.createElement('div');
      stepper.className = 'qty-stepper';
      stepper.dataset.productId = productId;
      stepper.dataset.variantId = variantId || 0;
      stepper.dataset.cartKey = cartKey;
      stepper.innerHTML =
        '<button type="button" class="qty-stepper-minus" aria-label="Decrease quantity">−</button>' +
        '<span class="qty-stepper-count">' + qty + '</span>' +
        '<button type="button" class="qty-stepper-plus" aria-label="Increase quantity">+</button>';
      return stepper;
    }

    document.addEventListener('click', function (e) {
      // ── "ADD TO CART" clicked → add it, then morph the button into a stepper ──
      const btn = e.target.closest('.add-cart, .cart2');
      if (btn) {
        e.preventDefault();
        const productId = btn.dataset.productId;
        if (!productId) return;

        // If this card has a size/unit dropdown, use whichever variant is
        // currently selected so the right price/stock gets added.
        const card = btn.closest('.product-card, .card2');
        const variantSelect = card ? card.querySelector('.qty-select') : null;
        const variantId = variantSelect ? (variantSelect.selectedOptions[0]?.dataset.variantId || 0) : (btn.dataset.variantId || 0);

        btn.disabled = true;
        addToCart(productId, 1, variantId).then(data => {
          if (data.success) {
            const stepper = buildQtyStepper(productId, variantId, data.cart_key, data.line_qty);
            btn.replaceWith(stepper);
          } else {
            btn.disabled = false;
          }
        });
        return;
      }

      // ── Stepper +/− clicked → update qty directly, no full re-add ──
      const stepBtn = e.target.closest('.qty-stepper-plus, .qty-stepper-minus');
      if (stepBtn) {
        e.preventDefault();
        const stepper = stepBtn.closest('.qty-stepper');
        if (!stepper || stepper.classList.contains('updating')) return;
        const countEl = stepper.querySelector('.qty-stepper-count');
        const current = parseInt(countEl.textContent, 10) || 0;
        const next = stepBtn.classList.contains('qty-stepper-plus') ? current + 1 : current - 1;

        stepper.classList.add('updating');
        updateCartQty(stepper.dataset.cartKey, next).then(data => {
          stepper.classList.remove('updating');
          if (!data.success) return;
          if (data.line_qty > 0) {
            countEl.textContent = data.line_qty;
          } else {
            // Qty hit 0 — swap the stepper back to "ADD TO CART".
            const isCard2 = stepper.closest('.card2');
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.dataset.productId = stepper.dataset.productId;
            if (stepper.dataset.variantId && stepper.dataset.variantId !== '0') addBtn.dataset.variantId = stepper.dataset.variantId;
            if (isCard2) {
              addBtn.className = 'cart2';
              addBtn.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg>ADD TO CART';
            } else {
              addBtn.className = 'add-cart';
              addBtn.innerHTML = '<svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 3h2l2.6 12.4a2 2 0 002 1.6h9.7a2 2 0 002-1.6L22 7H6"/></svg>ADD TO CART';
            }
            stepper.replaceWith(addBtn);
          }
        });
      }
    });
  </script>

  <!-- ── Size/Unit dropdown wiring (Cmart-style cards) ──
       Updates MRP, price and OFF badge in the card when a different
       weight/pack size is selected, without a page reload. -->
  <script>
    function handleQtyChange(select) {
      const opt = select.selectedOptions[0];
      const card = select.closest('.product-card');
      if (!card || !opt) return;
      const mrp = opt.dataset.mrp, sp = opt.dataset.sp, off = opt.dataset.off;
      const mrpEl = card.querySelector('.mrp');
      const spEl = card.querySelector('.sp');
      const offEl = card.querySelector('.off-badge');
      const hasDiscount = Number(off) > 0;
      if (mrpEl) { mrpEl.textContent = '₹' + mrp; mrpEl.style.display = hasDiscount ? '' : 'none'; }
      if (spEl) spEl.textContent = '₹' + sp;
      if (offEl) { offEl.innerHTML = '₹' + off + '<small>OFF</small>'; offEl.style.display = hasDiscount ? '' : 'none'; }
    }
  </script>

  <!-- ── Custom qty dropdown (replaces the phone/OS native picker sheet) ──
       Finds every real <select class="qty-select">, hides it (but keeps
       it working — handleQtyChange and add-to-cart still read it as
       before), and draws a fully custom trigger+list next to it that
       stays in sync both ways. No native OS picker ever opens. -->
  <script>
    (function () {
      function buildDropdown(select) {
        if (select.classList.contains('js-enhanced')) return;
        select.classList.add('js-enhanced');

        const wrap = document.createElement('div');
        wrap.className = 'qdrop';
        // Carry over any inline width constraint from the original <select>
        // (e.g. the product page sets style="max-width:220px") so the
        // custom dropdown doesn't stretch full-width when its parent isn't
        // a flex row (cards use a flex .qty-cart row; the product page
        // doesn't, so this keeps both looking correctly sized).
        if (select.style.maxWidth) wrap.style.maxWidth = select.style.maxWidth;

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'qdrop-trigger';

        const list = document.createElement('div');
        list.className = 'qdrop-list';
        list.setAttribute('role', 'listbox');

        function renderOptions() {
          list.innerHTML = '';
          Array.from(select.options).forEach(function (opt) {
            const item = document.createElement('div');
            item.className = 'qdrop-option' + (opt.selected ? ' selected' : '');
            item.textContent = opt.textContent;
            item.setAttribute('role', 'option');
            item.addEventListener('click', function () {
              if (select.value !== opt.value) {
                select.value = opt.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
              }
              trigger.textContent = opt.textContent;
              renderOptions();
              closeDropdown();
            });
            list.appendChild(item);
          });
        }

        function openDropdown() {
          document.querySelectorAll('.qdrop.open').forEach(function (o) { if (o !== wrap) o.classList.remove('open'); });
          wrap.classList.add('open');
        }
        function closeDropdown() { wrap.classList.remove('open'); }

        trigger.addEventListener('click', function (e) {
          e.stopPropagation();
          wrap.classList.contains('open') ? closeDropdown() : openDropdown();
        });

        const selected = select.selectedOptions[0];
        trigger.textContent = selected ? selected.textContent : '';
        renderOptions();

        select.insertAdjacentElement('afterend', wrap);
        wrap.appendChild(trigger);
        wrap.appendChild(list);
      }

      function enhanceAll() {
        // .qty-select = the size/unit dropdown on product CARDS (homepage,
        // category grids, etc). #pdpVariantSelect = the same kind of
        // dropdown on the single PRODUCT PAGE — same custom look here too.
        document.querySelectorAll('select.qty-select, select#pdpVariantSelect').forEach(buildDropdown);
      }

      document.addEventListener('DOMContentLoaded', enhanceAll);
      document.addEventListener('click', function () {
        document.querySelectorAll('.qdrop.open').forEach(function (o) { o.classList.remove('open'); });
      });
      // Re-scan after AJAX "load more" inserts new cards.
      document.addEventListener('shopContentUpdated', enhanceAll);
    })();
  </script>

  <!-- ── Wishlist wiring (shared across storefront) ── -->
  <script>
    function toggleWishlist(productId, btn) {
      fetch('/shop/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=toggle_wishlist&product_id=' + encodeURIComponent(productId)
      })
      .then(r => r.json())
      .then(data => {
        if (data.need_login) {
          window.location.href = '/shop/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
          return;
        }
        if (!data.success) {
          if (data.message) alert(data.message);
          return;
        }
        if (btn) {
          btn.classList.toggle('active', data.wishlisted);
          const icon = btn.querySelector('i');
          if (icon) {
            icon.classList.toggle('fas', data.wishlisted);
            icon.classList.toggle('far', !data.wishlisted);
          }
        }
      })
      .catch(() => {});
    }
  </script>

</body>
</html>
