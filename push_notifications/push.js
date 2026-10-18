(() => {
  const C = {
    DELAY: 5000,
    RETRY_DELAY: 300000,
    K: { SUB: 'push_subscribed', LAST: 'push_last_closed' },
    CL: { P: 'push-popup', SB: 'success-bubble' },
    E: '/push_notifications/save-subscriptions.php',
    V: 'BHE89PpiPS69Y57UhMN4HTkTl16Vm3Pj1OXZ46CMuPiGuLbVE5ndJLSN3YUjGyWN9zpy7Ac08zRhqyCuCWR3wcI',
    SW: '/sw.js?v=16052026'
  };

  // ── Guard: skip entirely if push not supported ──────────────────
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
  
  // ── Notify button: show only if supported + not yet subscribed ──
const _btn = document.getElementById('push-notify-btn');
if (_btn && localStorage.getItem(C.K.SUB) !== 'true') {
  _btn.style.display = '';
}

  // ── SW: share handler ───────────────────────────────────────────
  navigator.serviceWorker.addEventListener('message', e => {
    if (e.data?.action === 'share' && navigator.share) {
      navigator.share({ title: e.data.title, url: e.data.url }).catch(() => {});
    }
  });

  // ── SW: register once ───────────────────────────────────────────
  navigator.serviceWorker.register(C.SW).catch(() => {});

  // ── Startup: decide when to show popup ─────────────────────────
  const start = () => {
    if (localStorage.getItem(C.K.SUB) === 'true') return;

    const last = localStorage.getItem(C.K.LAST);

    if (last) {
      const wait = C.RETRY_DELAY - (Date.now() - parseInt(last, 10));
      if (wait > 0) { setTimeout(showPopup, wait); return; }
    }

    setTimeout(showPopup, C.DELAY);
  };

  'requestIdleCallback' in window
    ? requestIdleCallback(start, { timeout: 7000 })
    : setTimeout(start, C.DELAY);

  // ── Subscribe: SW + push + save to server ──────────────────────
  async function subscribe() {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: b64(C.V)
    });

    const res = await fetch(C.E, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub)
    });

    if (!res.ok) throw new Error('Server save failed');

    localStorage.setItem(C.K.SUB, 'true');
    
    // ── Hide button after successful subscription ───────────────────
    const _btn = document.getElementById('push-notify-btn');
    if (_btn) _btn.style.display = 'none';

    success();
  }
  
  
  // ── Smooth close helper ─────────────────────────────────────────
function closePopup(popup) {
  popup.classList.remove('show');
  popup.addEventListener('transitionend', () => popup.remove(), { once: true });
}

  // ── Show Popup ──────────────────────────────────────────────────
  function showPopup() {
    if (localStorage.getItem(C.K.SUB) === 'true') return;

    // Don't stack duplicate popups
    if (document.querySelector('.' + C.CL.P)) return;

    const popup = document.createElement('div');
    popup.className = C.CL.P;

    const content = document.createElement('div');
    content.className = 'push-popup-content';

    const close = document.createElement('button');
    close.className = 'pushClose';
    close.textContent = '✕';
    close.setAttribute('aria-label', 'Close');
    close.onclick = () => {
      closePopup(popup);
      localStorage.setItem(C.K.LAST, Date.now());
    };

    const img = document.createElement('img');
    img.src = '/push_notifications/notifications.svg';
    img.alt = '';                  // decorative — aria-hidden would also work
    img.className = 'push-popup-icon';
    img.loading = 'lazy';
    img.width = 100;
    img.height = 100;

    const text = document.createElement('div');
    text.className = 'push-popup-text-group';
    text.innerHTML = `
      <h3 class="push-popup-title">Get Free Govt Job Alerts</h3>
      <p class="push-popup-text">Start receiving daily job alerts</p>
      <div class="push-popup-buttons">
        <button class="push-popup-subscribe">Allow Notifications</button>
      </div>
    `;

    const subBtn = text.querySelector('.push-popup-subscribe');
    subBtn.onclick = async () => {
      closePopup(popup);

      try {
        const perm = await Notification.requestPermission();
        if (perm === 'granted') {
          await subscribe();
        } else {
          // denied or dismissed — either way, set cooldown
          localStorage.setItem(C.K.LAST, Date.now());
        }
      } catch {
        localStorage.setItem(C.K.LAST, Date.now());
      }
    };

    content.append(close, img, text);
    popup.appendChild(content);
    document.body.appendChild(popup);

    requestAnimationFrame(() => {
      requestAnimationFrame(() => popup.classList.add('show'));
    });
  }

  // ── Success bubble ──────────────────────────────────────────────
  function success() {
    const b = document.createElement('div');
    b.className = C.CL.SB;
    b.textContent = 'Notifications Enabled!';
    document.body.appendChild(b);
    setTimeout(() => {
      b.classList.add('fade-out');
      setTimeout(() => b.remove(), 300);
    }, 3000);
  }

  // ── VAPID key decoder ───────────────────────────────────────────
  function b64(s) {
    const p = '='.repeat((4 - s.length % 4) % 4);
    const b = (s + p).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(b), c => c.charCodeAt(0));
  }

  // ── Expose for manual button trigger (global scope) ─────────────
  window._showPushPopup = showPopup;

})();