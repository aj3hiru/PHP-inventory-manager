<header>
  <div class="container" style="display:flex;align-items:center;gap:5px;">
  <a href="/" title="<?= SITE_NAME ?> Home" aria-label="Go to <?= SITE_NAME ?> homepage" class="brand-link">
<svg width="100" height="37.5" viewBox="0 0 400 150" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#F6AD55;stop-opacity:1"/><stop offset="100%" style="stop-color:#48BB78;stop-opacity:1"/></linearGradient></defs><rect x="20" y="50" width="300" height="80" fill="#1A365D"/><text x="35" y="110" font-family="Arial,sans-serif" font-size="50" font-weight="900" fill="white" letter-spacing="-1">EDUMINT</text><rect x="260" y="20" width="80" height="80" fill="url(#grad1)"/><text x="275" y="78" font-family="Arial,sans-serif" font-size="45" font-weight="bold" fill="white">24</text></svg>
</a>
<nav aria-label="Main Menu">
  <ul>
    <li><a href="/">Home</a></li><li><a href="/categories">Job Categories</a></li><li><a href="/state-jobs">State Wise Jobs</a></li><li><a href="/about-us">About Us</a></li><li><a href="/contact-us">Contact Us</a></li><li><a href="#" class="dark-mode-toggle">Dark Mode</a></li>
  </ul>
</nav>
<div class="header-actions">
	<button
    id="push-notify-btn"
    class="search-btn"
    aria-label="Enable Notifications"
    style="display:none"
    onclick="localStorage.getItem('push_subscribed')==='true'?alert('Notifications already enabled'):(document.querySelector('.push-popup')?.remove(),localStorage.removeItem('push_last_closed'),window._showPushPopup())"
>
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:auto">
    <path d="M10.268 21a2 2 0 0 0 3.464 0"/>
    <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>
    <!-- red alert dot -->
    <circle cx="19" cy="5" r="3" fill="#e53935" stroke="none"/>
</svg>
</button>

	<button class="search-btn" aria-label="Search" id="searchToggle">
                <svg xmlns="http://www.w3.org/2000/svg" height="24" width="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="display: block; margin: auto;">
    <circle cx="12" cy="12" r="7" stroke-width="2" />
    <line x1="17" y1="17" x2="21" y2="21" stroke-width="2" />
</svg>
            </button>
<button class="menu-toggle" id="menuToggle" aria-label="Open Menu">&#9776;</button>
</div>

    <form action="/search" method="GET" class="search-overlay" id="searchForm">
    <div class="search-container">
        
        <div class="input-wrapper">
            <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            
            <input type="text" id="searchBox" name="q" placeholder="e.g. SSC, UPSC, Railway..." required autocomplete="off">
    <button id="voiceBtn" class="voice-icon" type="button" aria-label="Voice search"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19v3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><rect x="9" y="2" width="6" height="13" rx="3"/></svg></button>
        </div>
        <button type="button" class="close-search" id="searchClose" aria-label="Close Search">
            &#10005;
        </button>
    </div>
</form> 

        
  </div>
</header>
<div class="overlay" id="overlay" role="presentation"></div>
<aside class="sidebar" id="sidebar" aria-label="Mobile Navigation" aria-hidden="true">
  <div class="sidebar-top">
    <div class="sidebar-actions">
      <button class="sidebar-icon-btn" id="closeBtn" type="button" aria-label="Close Menu">
        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <span class="sidebar-icon-btn sidebar-icon-btn--badge" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 21s6-6.2 6-10.5A6 6 0 0 0 6 10.5C6 14.8 12 21 12 21zM12 12.5a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
      </span>
      <span class="sidebar-weather">
        <span>Weather</span>
        <svg viewBox="0 0 24 24" width="16" height="14"><circle cx="7" cy="8" r="3"/><path d="M5 18h11a4 4 0 0 0 .5-7.97A6 6 0 0 0 5.1 12.1 3.5 3.5 0 0 0 5 18Z"/></svg>
      </span>
      <button class="sidebar-icon-btn sidebar-icon-btn--search" type="button" aria-label="Search" onclick="document.getElementById('searchToggle')?.click()">
        <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </button>
    </div>

    <div class="sidebar-account">
      <div class="sidebar-welcome">
        <span class="sidebar-avatar">
          <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4.5 5-6 8-6s6.5 1.5 8 6"/></svg>
        </span>
        <span>Welcome! to <?= SITE_NAME ?></span>
      </div>
      <a href="/login" class="sidebar-signin">Sign In / Register</a>
    </div>

    <div class="sidebar-preferences">
      <span class="sidebar-edition">Edition</span>
      <button type="button" class="sidebar-pref-select"><span>🇮🇳 IN</span><svg viewBox="0 0 24 24" width="13" height="13"><path d="m5 9 7 7 7-7"/></svg></button>
      <button type="button" class="sidebar-pref-select"><span>English</span><svg viewBox="0 0 24 24" width="13" height="13"><path d="m5 9 7 7 7-7"/></svg></button>
      <span class="sidebar-plus">EDU<sup>+</sup></span>
    </div>
  </div>

  <nav class="sidebar-nav" role="navigation" aria-label="Mobile Menu">
    <a class="sidebar-nav-item" href="/">
      <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg></span>
      <span>Home</span>
    </a>
    <a class="sidebar-nav-item" href="/categories">
      <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/></svg></span>
      <span>Job Categories</span>
    </a>
    <a class="sidebar-nav-item" href="/state-jobs">
      <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 6l6-2 6 2 6-2v14l-6 2-6-2-6 2V6z"/><path d="M9 4v14M15 6v14"/></svg></span>
      <span>State Wise Jobs</span>
    </a>
    <a class="sidebar-nav-item" href="/about-us">
      <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7h.01"/></svg></span>
      <span>About Us</span>
    </a>
    <a class="sidebar-nav-item" href="/contact-us">
      <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg></span>
      <span>Contact Us</span>
    </a>
    <button type="button" class="sidebar-nav-item sidebar-nav-toggle dark-mode-toggle">
      <span class="sidebar-nav-icon"><svg viewBox="0 0 24 24"><path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/></svg></span>
      <span>Dark Mode</span>
    </button>
  </nav>

  <div class="sidebar-social">
    <p class="sidebar-social-title">Follow Us on Social Media</p>
    <div class="sidebar-social-row">
      <a href="https://whatsapp.com/channel/0029VasVjh8GOj9v3DgoWa3V" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="WhatsApp (opens in new tab)">
        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 4a8 8 0 0 0-6.9 12l-1 3.6 3.7-1A8 8 0 1 0 12 4Zm4.6 11.4c-.2.6-1.1 1.1-1.6 1.2-.4.05-1 .07-1.6-.1-.35-.1-.8-.25-1.4-.5-2.4-1.05-4-3.5-4.1-3.65-.12-.16-1-1.3-1-2.5 0-1.2.6-1.8.85-2.05.2-.4.35-.4.5-.4h.4c.15 0 .3 0 .45.35.15.35.55 1.35.6 1.45.05.1.1.2 0 .35s-.15.25-.25.4c-.1.1-.2.25-.3.35-.1.1-.2.25-.1.45.15.25.6 1 1.3 1.6.9.8 1.6 1.05 1.9 1.15.25.1.4.1.55-.05.15-.15.6-.7.75-.95.15-.25.3-.2.5-.1.2.1 1.35.65 1.6.75.25.1.4.15.45.25.05.1.05.6-.15 1.15Z"/></svg>
      </a>
      <a href="#" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="Facebook (opens in new tab)">
        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M13.5 21v-7.2h2.4l.35-2.8h-2.75V9.2c0-.8.22-1.35 1.38-1.35H16.4V5.35C16.1 5.32 15.1 5.2 13.9 5.2c-2.4 0-4.05 1.47-4.05 4.15v2.65H7.4v2.8h2.45V21h3.65Z"/></svg>
      </a>
      <a href="#" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="YouTube (opens in new tab)">
        <svg viewBox="0 0 24 24" width="20" height="20"><rect x="3.5" y="6.5" width="17" height="11" rx="3" fill="none" stroke="#fff" stroke-width="1.6"/><path fill="#fff" d="M10.3 9.6v4.8l4.3-2.4-4.3-2.4Z"/></svg>
      </a>
      <a href="https://www.instagram.com/careerdiksha" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="Instagram (opens in new tab)">
        <svg viewBox="0 0 24 24" width="20" height="20"><rect x="4.5" y="4.5" width="15" height="15" rx="4.5" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="12" cy="12" r="3.4" fill="none" stroke="#fff" stroke-width="1.6"/><circle cx="16.3" cy="7.7" r="1" fill="#fff"/></svg>
      </a>
      <a href="#" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="LinkedIn (opens in new tab)">
        <svg viewBox="0 0 24 24" width="20" height="20"><rect x="5.3" y="10.2" width="2.6" height="8"/><circle cx="6.6" cy="6.9" r="1.5"/><path d="M10.5 10.2h2.5v1.3c.5-.85 1.5-1.5 2.8-1.5 2.1 0 3.3 1.4 3.3 4v4.2h-2.6v-3.8c0-1-.4-1.8-1.4-1.8-.85 0-1.4.6-1.6 1.1-.1.25-.1.55-.1.85v3.65h-2.6v-8Z"/></svg>
      </a>
      <a href="https://x.com/careerdiksha" target="_blank" rel="noopener noreferrer" class="sidebar-social-link" aria-label="X / Twitter (opens in new tab)">
        <svg viewBox="0 0 24 24" width="20" height="20"><rect x="11" y="4" width="2" height="16" rx="1" transform="rotate(45 12 12)"/><rect x="11" y="4" width="2" height="16" rx="1" transform="rotate(-45 12 12)"/></svg>
      </a>
    </div>
  </div>
</aside>
<script id="msb-sp-c">
(()=>{
  const m=document.getElementById("menuToggle"),c=document.getElementById("closeBtn"),s=document.getElementById("sidebar"),o=document.getElementById("overlay");
  if(!m||!s||!o)return;
  const setMenu=open=>{
    s.classList.toggle("active",open);
    o.classList.toggle("active",open);
    s.setAttribute("aria-hidden",String(!open));
    m.setAttribute("aria-expanded",String(open));
    document.body.style.overflow=open?"hidden":"";
  };
  m.addEventListener("click",()=>setMenu(true));
  c&&c.addEventListener("click",()=>setMenu(false));
  o.addEventListener("click",()=>setMenu(false));
  document.addEventListener("keydown",e=>{if(e.key==="Escape")setMenu(false)});
  s.addEventListener("click",e=>{if(e.target.closest(".sidebar-nav-item:not(.sidebar-nav-toggle)"))setMenu(false)});
})();
const st=document.getElementById("searchToggle"),sc=document.getElementById("searchClose"),hd=document.querySelector("header"),inp=document.querySelector(".search-slide-panel input"),ts=()=>{hd.classList.toggle("search-active")&&setTimeout(()=>inp.focus(),400)};[st,sc].forEach(e=>e&&(e.onclick=ts)),document.addEventListener("copy",e=>e.preventDefault());
(()=>{const S=window.SpeechRecognition||window.webkitSpeechRecognition;if(!S)return;const r=new S;r.lang="en-IN";const i=document.getElementById("searchBox"),b=document.getElementById("voiceBtn"),p=i.placeholder;b.onclick=()=>{i.placeholder="Listening...";b.classList.add("listening");try{r.start()}catch(e){}};r.onresult=e=>{i.value=e.results[0][0].transcript;i.placeholder=p;b.classList.remove("listening");i.form?.submit()};r.onend=()=>{b.classList.remove("listening");i.placeholder=p}})();</script>

<!-- Site Navigation Schema -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "@id": "<?= htmlspecialchars(SITE_URL) ?>#site-navigation",
  "name": "Main Navigation Menu",
  "itemListElement": [
    {
      "@type": "SiteNavigationElement",
      "position": 1,
      "name": "Home",
      "url": "<?= htmlspecialchars(SITE_URL) ?>"
    },
    {
      "@type": "SiteNavigationElement",
      "position": 2,
      "name": "Categories",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/categories"
    },
    {
      "@type": "SiteNavigationElement",
      "position": 3,
      "name": "About Us",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/about-us"
    },
    {
      "@type": "SiteNavigationElement",
      "position": 4,
      "name": "Contact Us",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/contact-us"
    }
  ]
}
</script>