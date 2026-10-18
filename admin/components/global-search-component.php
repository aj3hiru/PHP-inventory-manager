<div class="gsearch-wrap">
    <i class="fas fa-search gsearch-icon"></i>
    <input type="text" id="gsearchInput" class="gsearch-input" placeholder="Order ID, receipt no., name, mobile…" autocomplete="off">
    <div class="gsearch-results" id="gsearchResults"></div>
</div>

<style>
.gsearch-wrap { position: relative; width: 260px; max-width: 42vw; }
.gsearch-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--gray-400, #9ca3af); font-size: 0.8rem; }
.gsearch-input {
    width: 100%;
    padding: 0.55rem 0.75rem 0.55rem 2.1rem;
    border: var(--bs-border-width) solid var(--bs-border-color);
    border-radius: 5px;
    font-size: 0.875rem;
    background: var(--gray-50, #f9fafb);
    transition: all 0.15s;
}
.gsearch-input:focus { outline: none; border-color: var(--primary, #7c3aed); }
.gsearch-results { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #fff; border: 1px solid var(--gray-200, #e5e7eb); border-radius: 0.6rem; box-shadow: 0 10px 25px rgba(0,0,0,.1); max-height: 340px; overflow-y: auto; z-index: 500; display: none; }
.gsearch-results .item { display: flex; flex-direction: column; padding: 0.6rem 0.9rem; cursor: pointer; border-bottom: 1px solid var(--gray-100, #f3f4f6); }
.gsearch-results .item:last-child { border-bottom: none; }
.gsearch-results .item:hover { background: var(--gray-50, #f9fafb); }
.gsearch-results .item .t { font-weight: 600; font-size: 0.875rem; color: var(--gray-900, #111827); display: flex; align-items: center; gap: 0.4rem; }
.gsearch-results .item .s { font-size: 0.75rem; color: var(--gray-500, #6b7280); margin-top: 1px; }
.gsearch-results .empty { padding: 0.9rem; text-align: center; color: var(--gray-400, #9ca3af); font-size: 0.85rem; }
@media (max-width: 767px) { .gsearch-wrap { display: none; } }
</style>

<script>
(function () {
    const input = document.getElementById('gsearchInput');
    const results = document.getElementById('gsearchResults');
    if (!input) return;
    let debounceTimer = null;

    input.addEventListener('input', function () {
        const q = this.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < 2) { results.style.display = 'none'; return; }

        debounceTimer = setTimeout(function () {
            fetch('/admin/ecommerce/global-search.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (!data.length) {
                        results.innerHTML = '<div class="empty">No matches for "' + q.replace(/</g, '') + '"</div>';
                    } else {
                        results.innerHTML = data.map(r => `
                            <div class="item" onclick="window.location.href='${r.url}'">
                                <div class="t"><i class="fas ${r.type === 'order' ? 'fa-receipt' : (r.type === 'receipt' ? 'fa-hand-holding-usd' : 'fa-user')}" style="color:var(--primary,#7c3aed);font-size:0.75rem;"></i> ${r.title}</div>
                                <div class="s">${r.sub}</div>
                            </div>
                        `).join('');
                    }
                    results.style.display = 'block';
                });
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.gsearch-wrap')) results.style.display = 'none';
    });
})();
</script>
