<?php
require_once __DIR__ . '/includes/config.php';

$notFound='<!DOCTYPE html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"><title>Document Not Found • EduMint24</title><meta name="robots" content="noindex, follow"><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel=stylesheet><style>body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#f8f9fa;font-family:"Nunito",system-ui,sans-serif;flex-direction:column;color:#444;text-align:center}.brand{font-size:18px;color:#888;margin-bottom:20px;font-weight:500}h2{font-size:28px;margin:0 0 12px;font-weight:700}p{margin:10px 0 0}a{color:#007bff;text-decoration:none;font-weight:600}a:hover{text-decoration:underline}</style></head><body><div class=brand>EduMint24 Secure PDF Viewer</div><h2>Document Not Found!</h2><p><a href=https://EduMint24.co.in>Back to Home</a></p></body></html>';

$id = $_GET['id'] ?? null;
if (!$id) { die($notFound); }

try {    
    $stmt = $pdo->prepare("SELECT file_path FROM media WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) { die($notFound); }
    $realFilePath = $row['file_path'];

    if (!file_exists($realFilePath)) {
        die("Error: Source file does not exist on disk.");
    }
} catch (\PDOException $e) {
    die($notFound);
}

if (isset($_GET['stream']) && $_GET['stream'] == '1') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($realFilePath));
    header('Content-Disposition: inline; filename="protected.pdf"');
    readfile($realFilePath);
    exit;
}

 $title = "PDF Document - EduMint24";
 $description  = "Download or view the shared PDF document on EduMint24.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $title ?></title>
    <meta name="description" content="<?= $description ?>">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="<?= $title ?>">
    <meta property="og:description" content="<?= $description ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="https://EduMint24.co.in/documents/<?= $id ?>">
    <meta property="og:site_name" content="EduMint24">
            	
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';</script>
    
    <style>
    * { box-sizing: border-box; }
    body { 
        background-color: #525659; margin: 0; padding: 0; 
        height: 100vh; overflow: hidden; font-family: sans-serif;
        user-select: none; -webkit-user-select: none;
    }
    #toolbar {
        position: fixed; top: 0; left: 0; right: 0; height: 50px;
        background: #323639; color: white;
        display: flex; align-items: center; justify-content: center; gap: 2px;
        z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    }
    button { 
        cursor: pointer; padding: 8px 10px; background: #444; color: #fff; 
        border: 1px solid #222; border-radius: 4px; font-size: 14px; display: flex; 
    align-items: center; 
    justify-content: center; 
    }
    button:hover { background: #555; }
    .page-info { font-size: 12px; margin: 0 5px; white-space: nowrap; }
    .zoom-display { font-size: 13px; color: #aaa; width: 45px; text-align: center;}
    .search-container { display: flex; align-items: center; margin-left: 5px; }
    #searchInput {
        padding: 6px 8px; background: #444; color: #fff; border: 1px solid #222; 
        border-radius: 4px 0 0 4px; font-size: 14px; width: 90px;
    }
    
    #searchInput:focus {outline:none;border: 1px solid #D7D7D7}
    .search-btn { border-radius: 0 4px 4px 0; padding: 8px; }
    
    #pdf-scroll-container {
        margin-top: 50px; height: calc(100vh - 50px); width: 100%;
        background-color: #525659; display: block; overflow-y: auto; 
        text-align: center; padding: 20px 0; 
        touch-action: pan-x pan-y;
        -webkit-overflow-scrolling: touch;
    }
    
    .pdf-page-wrapper { 
        position: relative; box-shadow: 0 4px 15px rgba(0,0,0,0.5); 
        line-height: 0; display: inline-block; vertical-align: top;
        margin-bottom: 20px; background-color: white;
        transition: width 0.1s ease, height 0.1s ease;
    }
    
    .pdf-page-wrapper.loading::before {
        content: "Loading...";
        position: absolute; top: 50%; left: 50%; 
        transform: translate(-50%, -50%);
        color: #888; font-size: 16px;
    }

    canvas { 
        display: block; background: white; 
        width: 100%; height: 100%;
    }
    
    .shield {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        z-index: 50; cursor: default;
    }
    .watermark {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%) rotate(-45deg);
        color: rgba(255, 0, 0, 0.1); font-size: 8vw; font-weight: bold; 
        pointer-events: none; z-index: 60; white-space: nowrap;
    }
    #loader {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(255,255,255,0.9); z-index: 2000;
        display: flex; justify-content: center; align-items: center;
        color: black; flex-direction: column; gap: 15px;
    }
    .spinner {
        border: 2px solid #f3f3f3; border-top: 2px solid #3498db; 
        border-radius: 50%; width: 30px; height: 30px;
        animation: spin 0.5s linear infinite;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    
    .search-highlight {
    position: absolute; 
    background-color: rgba(255, 255, 0, 0.45) !important;
    border: 1.5px solid #ffcc00;
    border-radius: 2px;
    pointer-events: none; 
    z-index: 100;
    mix-blend-mode: multiply;
}


/* Naya Floating Search UI ka CSS */
.search-nav-floating {
    position: fixed;
    bottom: 40px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(40, 40, 40, 0.95);
    color: white;
    padding: 6px 8px;
    border-radius: 50px;
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 2500;
    box-shadow: 0 8px 25px rgba(0,0,0,0.5);
    border: 1px solid #555;
    backdrop-filter: blur(5px);
}
.search-nav-floating button {
    background: #555;
    border: none;
    color: white;
    width: 25px;
    height: 25px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
}
.search-nav-floating button:hover { background: #777; }
.search-nav-floating #match-counter {
    font-size: 12px;
    min-width: 30px;
    text-align: center;
}

    .footer-brand {
        position: fixed; bottom: 8px; right: 12px; font-size: 11px; 
        color: #999; z-index: 999; pointer-events: none; opacity: 0.7;
    }
    .match-overlay {
        position: fixed; top: 60px; right: 20px; background-color: rgba(0, 0, 0, 0.8);
        color: white; padding: 10px 20px; border-radius: 5px; z-index: 1000;
        font-family: sans-serif; font-size: 14px; transition: opacity 0.5s ease;
    }
    button:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body oncontextmenu="return false;">

    <div id="loader">
        <div class="spinner"></div>
        <div>Loading Document...</div>
    </div>
    
    <div id="toolbar">
        <span class="page-info"><span id="page_num">--</span> / <span id="page_count">--</span></span>
        
        <button id="zoomOut"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg></button>
        <span id="zoomPercent" class="zoom-display">--%</span>
        <button id="zoomIn"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg></button>
        
        <button id="fitWidthBtn" title="Fit to Width">
    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="white" viewBox="0 0 16 16">
        <path d="M1 8a.5.5 0 0 1 .5-.5h13a.5.5 0 0 1 0 1h-13A.5.5 0 0 1 1 8zM7.646.146a.5.5 0 0 1 .708 0l2 2a.5.5 0 0 1-.708.708L8.5 1.707V5.5a.5.5 0 0 1-1 0V1.707L6.354 2.854a.5.5 0 1 1-.708-.708l2-2zM8 10a.5.5 0 0 1 .5.5v3.793l1.146-1.147a.5.5 0 0 1 .708.708l-2 2a.5.5 0 0 1-.708 0l-2-2a.5.5 0 0 1 .708-.708L7.5 14.293V10.5A.5.5 0 0 1 8 10z"/>
    </svg>
</button>

        
        <button id="fullScreenBtn" title="Full Screen">
    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="white" viewBox="0 0 16 16">
        <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z"/>
    </svg>
</button>

        <div class="search-container">
            <input type="text" id="searchInput" placeholder="Find...">
            <button id="searchBtn" class="search-btn">
                <svg xmlns="http://www.w3.org/2000/svg" height="12" width="12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <circle cx="12" cy="12" r="7" stroke-width="2" />
                    <line x1="17" y1="17" x2="21" y2="21" stroke-width="2" />
                </svg>
            </button>
             
        </div>
    </div>

    <div id="match-overlay" class="match-overlay" style="display: none;">
        <span id="match-message">Searching...</span>
    </div>

    <div id="pdf-scroll-container">
        </div>
    
    <div class="footer-brand">Powered by <strong>EduMint24</strong> Secure PDF Viewer</div>
    
    <div id="search-nav-overlay" class="search-nav-floating" style="display: none;">
    <button id="floatPrev">&lt;</button>
    <span id="match-counter">0 / 0</span>
    <button id="floatNext">&gt;</button>
</div>


<script>
    const searchIcon = '<svg height="12" width="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="7" stroke-width="2" /><line x1="17" y1="17" x2="21" y2="21" stroke-width="2" /></svg>';
    const clearIcon = '<svg height="12" width="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>';	
    const docId = "<?php echo $id; ?>";
    const url = "?id=" + docId + "&stream=1";
    
    let pdfDoc = null;
    let scale = 0.4;
    let basePageDimensions = null;
    let renderQueue = new Set();
    let renderTimeout = null;
    let allMatches = [];
    let currentMatchIndex = -1;
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const pageNum = parseInt(entry.target.dataset.pageNum);
                requestPageRender(pageNum);
                document.getElementById('page_num').textContent = pageNum;
            }
        });
    }, { root: document.getElementById('pdf-scroll-container'), rootMargin: "200px", threshold: 0.01 });

    pdfjsLib.getDocument(url).promise.then(async function(pdfDoc_) {
        pdfDoc = pdfDoc_;
        document.getElementById('page_count').textContent = pdfDoc.numPages;
        
        const page1 = await pdfDoc.getPage(1);
        const viewport = page1.getViewport({scale: 1.0});
        basePageDimensions = { width: viewport.width, height: viewport.height, ratio: viewport.width / viewport.height };
        
        setupPlaceholders();
        document.getElementById('loader').style.display = 'none';
        
    }).catch(function(error) {
        document.getElementById('loader').innerHTML = "Error loading document.";
    });

    function setupPlaceholders() {
        const container = document.getElementById('pdf-scroll-container');
        container.innerHTML = '';
        
        const scaledWidth = basePageDimensions.width * scale;
        const scaledHeight = basePageDimensions.height * scale;
        
        for (let num = 1; num <= pdfDoc.numPages; num++) {
            const wrapper = document.createElement('div');
            wrapper.className = 'pdf-page-wrapper loading';
            wrapper.id = 'page-wrapper-' + num;
            wrapper.dataset.pageNum = num;
            
            wrapper.style.width = Math.floor(scaledWidth) + 'px';
            wrapper.style.height = Math.floor(scaledHeight) + 'px';

            const watermark = document.createElement('div');
            watermark.className = 'watermark';
            watermark.textContent = 'EduMint24';
            wrapper.appendChild(watermark);

            const shield = document.createElement('div');
            shield.className = 'shield';
            wrapper.appendChild(shield);

            const canvas = document.createElement('canvas');
            canvas.id = 'canvas-' + num;
            wrapper.appendChild(canvas);

            container.appendChild(wrapper);
            observer.observe(wrapper);
        }
        updateZoomDisplay();
    }

    function requestPageRender(pageNum) {
        if (renderQueue.has(pageNum)) return;
        
        const wrapper = document.getElementById('page-wrapper-' + pageNum);
        const canvas = document.getElementById('canvas-' + pageNum);
        
        if (canvas.width > 0 && Math.abs(canvas.width - (basePageDimensions.width * scale * window.devicePixelRatio)) < 5) {
            return; 
        }

        renderQueue.add(pageNum);
        renderSpecificPage(pageNum).then(() => {
            renderQueue.delete(pageNum);
            wrapper.classList.remove('loading');
        });
    }

    async function renderSpecificPage(num) {
        const page = await pdfDoc.getPage(num);
        const canvas = document.getElementById('canvas-' + num);
        const wrapper = document.getElementById('page-wrapper-' + num);
        
        const pixelRatio = window.devicePixelRatio || 1;
        const viewport = page.getViewport({scale: scale});
        const ctx = canvas.getContext('2d');
        
        canvas.width = Math.floor(viewport.width * pixelRatio);
        canvas.height = Math.floor(viewport.height * pixelRatio);
        
        wrapper.style.width = Math.floor(viewport.width) + "px";
        wrapper.style.height = Math.floor(viewport.height) + "px";

        const transform = [pixelRatio, 0, 0, pixelRatio, 0, 0];
        await page.render({canvasContext: ctx, viewport: viewport, transform: transform}).promise;
    }


    function updateZoomDisplay() {
        document.getElementById('zoomPercent').textContent = Math.round(scale * 100) + '%';
    }

    document.getElementById('zoomIn').addEventListener('click', () => applyZoom(scale + 0.2));
    document.getElementById('zoomOut').addEventListener('click', () => applyZoom(scale - 0.2));

    const scrollContainer = document.getElementById('pdf-scroll-container');
    let initialPinchDist = 0;
    let initialScale = 1;
    let lastPinchScale = 1;

    scrollContainer.addEventListener('touchstart', (e) => {
        if (e.touches.length === 2) {
            e.preventDefault();
            initialPinchDist = Math.hypot(
                e.touches[0].pageX - e.touches[1].pageX,
                e.touches[0].pageY - e.touches[1].pageY
            );
            initialScale = scale;
            lastPinchScale = scale;
        }
    }, { passive: false });

    scrollContainer.addEventListener('touchmove', (e) => {
        if (e.touches.length === 2 && initialPinchDist > 0) {
            e.preventDefault();
            const currentDist = Math.hypot(
                e.touches[0].pageX - e.touches[1].pageX,
                e.touches[0].pageY - e.touches[1].pageY
            );
            
            const delta = currentDist / initialPinchDist;
            let tempScale = initialScale * delta;
            
            if (tempScale < 0.4) tempScale = 0.4;
            if (tempScale > 3.0) tempScale = 3.0;
            
            lastPinchScale = tempScale;
            document.getElementById('zoomPercent').textContent = Math.round(tempScale * 100) + '%';
            
            const scaledWidth = basePageDimensions.width * tempScale;
            const scaledHeight = basePageDimensions.height * tempScale;
            const wrappers = document.querySelectorAll('.pdf-page-wrapper');
            wrappers.forEach(w => {
                w.style.width = Math.floor(scaledWidth) + 'px';
                w.style.height = Math.floor(scaledHeight) + 'px';
            });
        }
    }, { passive: false });

    scrollContainer.addEventListener('touchend', (e) => {
        if (initialPinchDist > 0 && e.touches.length < 2) {
            applyZoom(lastPinchScale);
            initialPinchDist = 0;
        }
    });

    function applyZoom(newScale) {
        if (newScale < 0.4) newScale = 0.4;
        if (newScale > 3.0) newScale = 3.0;
        
        const container = document.getElementById('pdf-scroll-container');
        const oldScrollTop = container.scrollTop;
        const oldScrollHeight = container.scrollHeight;
        const scrollRatio = oldScrollTop / oldScrollHeight;
        
        scale = newScale;
        updateZoomDisplay();

        const scaledWidth = basePageDimensions.width * scale;
        const scaledHeight = basePageDimensions.height * scale;
        
        const wrappers = document.querySelectorAll('.pdf-page-wrapper');
        wrappers.forEach(w => {
            w.style.width = Math.floor(scaledWidth) + 'px';
            w.style.height = Math.floor(scaledHeight) + 'px';
        });

        container.scrollTop = container.scrollHeight * scrollRatio;

        clearTimeout(renderTimeout);
        renderTimeout = setTimeout(() => {
            wrappers.forEach(w => {
                const rect = w.getBoundingClientRect();
                if (rect.top < window.innerHeight && rect.bottom > 0) {
                    requestPageRender(parseInt(w.dataset.pageNum));
                }
            });
            
            if (allMatches.length > 0 && currentMatchIndex !== -1) {
                const match = allMatches[currentMatchIndex];
                pdfDoc.getPage(match.pageNum).then(p => {
                    p.getTextContent().then(c => {
                        highlightOnSpecificPage(match.term, match.pageNum, c);
                    });
                });
            }
        }, 300);
    }

    async function performSearch() {
        const rawTerm = document.getElementById('searchInput').value.trim();
        const loader = document.getElementById('loader');
        const searchBtn = document.getElementById('searchBtn');
        const floatingNav = document.getElementById('search-nav-overlay');

        clearHighlights();
        allMatches = [];
        currentMatchIndex = -1;
        floatingNav.style.display = 'none';

        if (!rawTerm) {
            searchBtn.innerHTML = searchIcon;
            return;
        }

        const escapedTerm = rawTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const searchRegex = new RegExp(`\\b${escapedTerm}\\b`, 'i');

        if (loader) loader.style.display = 'flex';
        
        for (let p = 1; p <= pdfDoc.numPages; p++) {
            const page = await pdfDoc.getPage(p);
            const content = await page.getTextContent();
            if (content.items.some(item => searchRegex.test(item.str))) {
                allMatches.push({ pageNum: p, term: rawTerm });
            }
        }

        if (loader) loader.style.display = 'none';

        if (allMatches.length > 0) {
            currentMatchIndex = 0;
            searchBtn.innerHTML = clearIcon;
            floatingNav.style.display = 'flex';
            jumpToMatch(0);
        } else {
            searchBtn.innerHTML = searchIcon;
            alert("No matches found for: " + rawTerm);
        }
    }

    function clearSearch() {
        document.getElementById('searchInput').value = '';
        document.getElementById('searchBtn').innerHTML = searchIcon;
        document.getElementById('search-nav-overlay').style.display = 'none';
        allMatches = [];
        currentMatchIndex = -1;
        clearHighlights();
    }

    function jumpToMatch(index) {
        if (index < 0 || index >= allMatches.length) return;
        const match = allMatches[index];
        const pageNum = match.pageNum;
        const wrapper = document.getElementById('page-wrapper-' + pageNum);
        
        document.getElementById('match-counter').textContent = `${index + 1} / ${allMatches.length}`;

        if(wrapper) {
            requestPageRender(pageNum);
            wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            pdfDoc.getPage(pageNum).then(p => {
                p.getTextContent().then(c => {
                    highlightOnSpecificPage(match.term, pageNum, c);
                });
            });
        }
    }

    function highlightOnSpecificPage(term, pageNum, content) {
        clearHighlights();
        const wrapper = document.getElementById('page-wrapper-' + pageNum);
        
        pdfDoc.getPage(pageNum).then(page => {
            const viewport = page.getViewport({scale: scale});
            const searchRegex = new RegExp(`\\b${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'i');

            content.items.forEach(item => {
                if (searchRegex.test(item.str)) {
                    const tx = item.transform;
                    let fontSize = Math.sqrt(tx[0]*tx[0] + tx[1]*tx[1]);
                    
                    const rect = viewport.convertToViewportRectangle([
                        tx[4], 
                        tx[5] - (fontSize * 0.15), 
                        tx[4] + item.width, 
                        tx[5] + fontSize * 0.9
                    ]);
                    
                    const div = document.createElement('div');
                    div.className = 'search-highlight';
                    div.style.left = Math.min(rect[0], rect[2]) + 'px';
                    div.style.top = Math.min(rect[1], rect[3]) + 'px';
                    div.style.width = (Math.abs(rect[2] - rect[0]) + 1) + 'px';
                    div.style.height = (Math.abs(rect[3] - rect[1]) + 1) + 'px';
                    wrapper.appendChild(div);
                }
            });
        });
    }

    function clearHighlights() {
        const els = document.querySelectorAll('.search-highlight');
        els.forEach(el => el.remove());
    }
    
    document.getElementById('floatNext').addEventListener('click', () => {
        if (allMatches.length === 0) return;
        currentMatchIndex = (currentMatchIndex + 1) % allMatches.length;
        jumpToMatch(currentMatchIndex);
    });

    document.getElementById('floatPrev').addEventListener('click', () => {
        if (allMatches.length === 0) return;
        currentMatchIndex = (currentMatchIndex - 1 + allMatches.length) % allMatches.length;
        jumpToMatch(currentMatchIndex);
    });

    document.getElementById('searchInput').addEventListener("keypress", (e) => {
        if (e.key === "Enter") performSearch();
    });

    document.getElementById('searchBtn').addEventListener('click', function() {
        if (this.innerHTML.includes('path')) {
            clearSearch();
        } else {
            performSearch();
        }
    });
    
        document.getElementById('fullScreenBtn').addEventListener('click', () => {
        const docElm = document.documentElement;
        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
            if (docElm.requestFullscreen) docElm.requestFullscreen();
            else if (docElm.webkitRequestFullscreen) docElm.webkitRequestFullscreen();
        } else {
            if (document.exitFullscreen) document.exitFullscreen();
            else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
        }
    });

    document.addEventListener('fullscreenchange', handleFsUI);
    document.addEventListener('webkitfullscreenchange', handleFsUI);

    function handleFsUI() {
        const btn = document.getElementById('fullScreenBtn');
        const isFs = document.fullscreenElement || document.webkitFullscreenElement;
        btn.style.background = isFs ? '#007bff' : '#444';
    }
    
    
 document.getElementById('fitWidthBtn').addEventListener('click', () => {
    if (!basePageDimensions) return;
    const container = document.getElementById('pdf-scroll-container');
    const padding = 40; 
    const availableWidth = container.clientWidth - padding;
    const newScale = availableWidth / basePageDimensions.width;
    applyZoom(newScale);
});


    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (['s', 'p', 'u'].includes(e.key.toLowerCase()))) {
            e.preventDefault();
        }
        if (e.key === "Escape") {
            if (allMatches.length > 0) clearSearch();
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
            }
        }
    });    
</script>
</body>
</html>