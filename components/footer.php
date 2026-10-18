<!-- ── WAVE + FOOTER ── -->
<footer class="site-footer" role="contentinfo">
	
  <div class="footer-wave-top" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg"
         viewBox="0 0 1440 70" preserveAspectRatio="none">
      <path d="M0,55 C240,10 480,70 720,40 C960,10 1200,65 1440,45 L1440,0 L0,0 Z"
            fill="#faf7ff"/>       
    </svg>
  </div>

  <div class="footer-inner">

    <div class="footer-top">

      <div class="footer-brand-wrap">
  <div class="footer-brand"><?= SITE_NAME ?></div>
  <p class="footer-tagline">
    <?= htmlspecialchars(SITE_FOOTER_TAGLINE, ENT_QUOTES, 'UTF-8') ?>
  </p>
</div>

      <nav class="footer-nav" aria-label="Footer Navigation">
        <ul>
          <li><a href="/terms-of-service">Terms</a></li>
          <li><a href="/privacy-policy">Privacy Policy</a></li>
          <li><a href="/editorial-policy">Editorial Policy</a></li>
          <li><a href="/cookie-policy">Cookies Policy</a></li>
        </ul>
      </nav>

    </div>

    <div class="footer-bottom">
      <p class="footer-copy">&copy; 2026 <?= SITE_NAME ?>. All rights reserved.</p>

      <a class="footer-credit"
         href="https://www.instagram.com/immanish.40"
         target="_blank" rel="noopener noreferrer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="16 18 22 12 16 6"/>
          <polyline points="8 6 2 12 8 18"/>
        </svg>
        Designed &amp; Developed by <b>Manish Dhaker</b>
      </a>
    </div>

  </div>
</footer>