(() => {
  const socialLinks = {
    whatsapp: "https://whatsapp.com/channel/0029VasVjh8GOj9v3DgoWa3V",
    telegram: "https://t.me/careerjyoti1"
  };
  const STORAGE_KEY = 'social_popup_collapsed_until';
  const COLLAPSE_DURATION = 600000;

  if ('requestIdleCallback' in window) {
    requestIdleCallback(initPopup);
  } else {
    setTimeout(initPopup, 0);
  }

  function initPopup() {
    const popup = document.createElement('div');
    popup.className = 's-p';

    const toggleBtn = document.createElement('button');
    toggleBtn.className = 's-t';
    toggleBtn.setAttribute('aria-label', 'Toggle social popup');
    toggleBtn.setAttribute('type', 'button');

    const content = document.createElement('div');
    content.className = 's-c';

    const title = document.createElement('h3');
    title.className = 's-h';
    title.textContent = 'Join Us (3K+)';

    const waLink = createSocialLink(
      socialLinks.whatsapp,
      'wa',
      'WhatsApp',
      createWhatsAppSVG()
    );

    const tgLink = createSocialLink(
      socialLinks.telegram,
      'tg',
      'Telegram',
      createTelegramSVG()
    );

    content.append(title, waLink, tgLink);
    popup.append(toggleBtn, content);
    document.body.appendChild(popup);

    let isCollapsed = false;
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved && Date.now() < +saved) {
        isCollapsed = true;
        popup.classList.add('c');
      }
    } catch (e) {}

    toggleBtn.addEventListener('click', () => {
      isCollapsed = !isCollapsed;
      popup.classList.toggle('c', isCollapsed);
      try {
        if (isCollapsed) {
          localStorage.setItem(STORAGE_KEY, Date.now() + COLLAPSE_DURATION);
        } else {
          localStorage.removeItem(STORAGE_KEY);
        }
      } catch (e) {}
    });

    popup.style.willChange = 'transform';
  }

  function createSVG(pathData, viewBox) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.className = 's-i';
    svg.setAttribute('viewBox', viewBox);
    svg.setAttribute('fill', 'currentColor');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', pathData);
    if (pathData.includes('fill-rule')) {
      path.setAttribute('fill-rule', 'evenodd');
    }
    svg.appendChild(path);
    return svg;
  }

  function createWhatsAppSVG() {
    return createSVG(
      "M19.11 17.205c-.372 0-1.088 1.39-1.518 1.39a.63.63 0 0 1-.315-.1c-.802-.402-1.504-.817-2.163-1.447-.545-.516-1.146-1.29-1.46-1.963a.426.426 0 0 1-.073-.215c0-.33.99-.945.99-1.49 0-.143-.73-2.09-.832-2.335-.143-.372-.214-.487-.6-.487-.187 0-.36-.043-.53-.043-.302 0-.53.115-.746.315-.688.645-1.032 1.318-1.06 2.264v.114c-.015.99.472 1.977 1.017 2.78 1.23 1.82 2.506 3.41 4.554 4.34.616.287 2.035.888 2.722.888.817 0 2.15-.515 2.478-1.318.13-.33.244-.73.244-1.088 0-.058 0-.144-.03-.215-.1-.172-2.434-1.39-2.678-1.39zm-2.908 7.593c-1.747 0-3.48-.53-4.942-1.49L7.793 24.41l1.132-3.337a8.955 8.955 0 0 1-1.72-5.272c0-4.955 4.04-8.995 8.997-8.995S25.2 10.845 25.2 15.8c0 4.958-4.04 8.998-8.998 8.998zm0-19.798c-5.96 0-10.8 4.842-10.8 10.8 0 1.964.53 3.898 1.546 5.574L5 27.176l5.974-1.92a10.807 10.807 0 0 0 16.03-9.455c0-5.958-4.842-10.8-10.802-10.8z",
      "0 0 32 32"
    );
  }

  function createTelegramSVG() {
    return createSVG(
      "M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8.287 5.906q-1.168.486-4.666 2.01-.567.225-.595.442c-.03.243.275.339.69.47l.175.055c.408.133.958.288 1.243.294q.39.01.868-.32 3.269-2.206 3.374-2.23c.05-.012.120-.026.166.016s.042.120.037.141c-.03.129-1.227 1.241-1.846 1.817-.193.18-.33.307-.358.336a8 8 0 0 1-.188.186c-.38.366-.664.640.015 1.088.327.216.589.393.850.571.284.194.568.387.936.629q.140.092.270.187c.331.236.630.448.997.414.214-.020.435-.220.547-.820.265-1.417.786-4.486.906-5.751a1.4 1.4 0 0 0-.013-.315.340.340 0 0 0-.114-.217.530.530 0 0 0-.310-.093c-.30.005-.763.166-2.984 1.09",
      "0 0 16 16"
    );
  }

  function createSocialLink(href, className, text, svg) {
    const a = document.createElement('a');
    a.href = href;
    a.className = `s-b ${className}`;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.appendChild(svg);
    a.appendChild(document.createTextNode(text));
    return a;
  }
})();