const GlobalPopup = (() => {
    let initialized = false;
    let popupElement = null;

    const css = `
        .gp-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px;font-family:Inter,system-ui,-apple-system,sans-serif}
        .gp-box{width:100%;max-width:360px;background:#fff;border-radius:22px;padding:24px;box-shadow:0 25px 70px rgba(0,0,0,.22);animation:gpPop .25s cubic-bezier(0.34,1.56,0.64,1)}
        @keyframes gpPop{from{transform:scale(.92);opacity:0}to{transform:scale(1);opacity:1}}
        .gp-title{font-size:20px;font-weight:700;color:#111827;margin:0 0 10px}
        .gp-message{font-size:14.5px;color:#4b5563;line-height:1.6;margin:0}
        .gp-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:26px}
        .gp-actions button{min-width:94px;padding:11px 18px;border:none;border-radius:12px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s ease}
        .gp-actions button:hover{transform:translateY(-1.5px)}
        .gp-btn-cancel{background:#eef2f7;color:#374151}
        .gp-btn-cancel:hover{background:#e2e8f0}
        .gp-btn-ok{background:#0d6efd;color:#fff}
        .gp-btn-ok:hover{background:#0b5ed7}
        .gp-btn-danger{background:#dc3545;color:#fff}
        .gp-btn-danger:hover{background:#bb2d3b}
        .gp-btn-success{background:#198754;color:#fff}
        .gp-btn-success:hover{background:#157347}
    `;

    const htmlTemplate = `
        <div class="gp-box">
            <h3 class="gp-title"></h3>
            <p class="gp-message"></p>
            <div class="gp-actions"></div>
        </div>
    `;

    function init() {
        if (initialized) return;
        initialized = true;

        // Inject CSS once
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);

        // Create popup
        popupElement = document.createElement('div');
        popupElement.id = 'globalPopup';
        popupElement.className = 'gp-overlay';
        popupElement.innerHTML = htmlTemplate;
        document.body.appendChild(popupElement);

        // Close on Escape key
        document.addEventListener('keydown', handleEscapeKey);
    }

    function handleEscapeKey(e) {
        if (e.key === 'Escape') {
            const popup = document.getElementById('globalPopup');
            if (popup && popup.style.display === 'flex') {
                popup.style.display = 'none';
            }
        }
    }

    function show(config = {}) {
        init();

        const {
            title = '',
            message = '',
            buttons = [],
            closeOnOverlayClick = false
        } = config;

        const titleEl = popupElement.querySelector('.gp-title');
        const msgEl = popupElement.querySelector('.gp-message');
        const actionsEl = popupElement.querySelector('.gp-actions');

        titleEl.textContent = title;
        msgEl.innerHTML = message || '';
        actionsEl.innerHTML = '';

        return new Promise(resolve => {
            const defaultBtns = [{ text: 'OK', class: 'gp-btn-ok', value: true }];
            const btnsToRender = buttons.length ? buttons : defaultBtns;

            btnsToRender.forEach(btnConfig => {
                const btn = document.createElement('button');
                btn.textContent = btnConfig.text || 'OK';
                btn.className = btnConfig.class || 'gp-btn-ok';

                btn.onclick = () => {
                    close();
                    resolve(btnConfig.value !== undefined ? btnConfig.value : true);
                    if (typeof btnConfig.onClick === 'function') btnConfig.onClick();
                };

                actionsEl.appendChild(btn);
            });

            // Close on overlay click (optional)
            if (closeOnOverlayClick) {
                popupElement.onclick = (e) => {
                    if (e.target === popupElement) {
                        close();
                        resolve(false);
                    }
                };
            } else {
                popupElement.onclick = null;
            }

            popupElement.style.display = 'flex';
        });
    }

    function close() {
        if (popupElement) {
            popupElement.style.display = 'none';
        }
    }

    // Public API
    return {
        show,
        close,
        // Optional: destroy popup completely
        destroy: () => {
            if (popupElement) {
                popupElement.remove();
                popupElement = null;
                initialized = false;
            }
        }
    };
})();