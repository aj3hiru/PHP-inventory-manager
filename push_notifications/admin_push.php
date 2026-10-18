<?php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

// 1. Security Check
 if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/page_not_found.php';
    exit;
}

// 2. Handle Logging (AJAX Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_push') {
    try {
        $log_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $log_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        $user_id = $_SESSION['user_id'];
        
        $title = $_POST['title'] ?? 'No Title';
        $post_id = !empty($_POST['post_id']) ? (int)$_POST['post_id'] : 'N/A';
        
        $log_desc = "Sent Push Notification: " . mb_strimwidth($title, 0, 50, "...") . " (Linked Post ID: " . $post_id . ")";
        
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'push_send', ?, ?, ?)");
        $stmt->execute([$user_id, $log_desc, $log_ip, $log_ua]);
        
        echo json_encode(['saved' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['saved' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// 3. Fetch Posts
$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.content, p.slug, m.file_path AS banner_image
    FROM posts p 
    LEFT JOIN media m ON p.featured_image_id = m.id
    WHERE p.status = 'published'
    ORDER BY p.date DESC LIMIT 100
");
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pushToken = $config['push_secret'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Push Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
    	html, body {
    overflow-x: hidden;
}
        body { background-color: #f0f2f5; font-family: 'Inter', sans-serif; }
        .card { border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border-radius: 16px; }
        .card-header { background: white; border-bottom: 1px solid #f0f0f0; padding: 1.5rem; border-radius: 16px 16px 0 0 !important; }
        
        /* Post List in Modal */
        .post-list-item { cursor: pointer; transition: all 0.2s; border-left: 4px solid transparent; }
        .post-list-item:hover { background-color: #f8f9fa; border-left-color: #0d6efd; }
        .post-thumb-sm { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; }

        /* --- SMARTPHONE MOCKUP CSS --- */
        .smartphone-frame {
            width: 300px;
            height: 600px;
            background: #111;
            border-radius: 40px;
            box-shadow: 0 0 0 10px #333, 0 20px 50px rgba(0,0,0,0.2);
            position: relative;
            margin: 0 auto;
            overflow: hidden;
            border: 4px solid #444;
        }
        
        /* Screen Wallpaper */
        .smartphone-screen {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            padding-top: 60px; /* Space for notches/time */
        }

        /* Top Notch/Camera */
        .smartphone-notch {
            position: absolute; top: 0; left: 50%; transform: translateX(-50%);
            width: 120px; height: 25px; background: #111;
            border-radius: 0 0 15px 15px; z-index: 5;
        }

        /* Time Mockup */
        .lock-screen-time {
            text-align: center; color: rgba(255,255,255,0.8);
            font-size: 52px; font-weight: 300; margin-bottom: 20px;
            font-family: 'Roboto', sans-serif;
        }
        .lock-screen-date {
            text-align: center; color: rgba(255,255,255,0.8);
            font-size: 14px; margin-top: -10px; margin-bottom: 30px;
        }

        /* Notification Card (Android Style) */
        .notif-card {
            background: rgba(255, 255, 255, 0.95);
            margin: 0 15px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            animation: slideIn 0.3s ease-out;
            font-family: 'Roboto', sans-serif;
        }
        @keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .notif-header {
            padding: 10px 12px 5px;
            display: flex; align-items: center; justify-content: space-between;
            font-size: 11px; color: #555;
        }
        .notif-app-info { display: flex; align-items: center; gap: 5px; }
        .notif-icon { width: 16px; height: 16px; background: #0d6efd; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px; }

        .notif-body { padding: 5px 12px 12px; }
        .notif-title { font-weight: 700; font-size: 14px; color: #222; margin-bottom: 2px; line-height: 1.2; }
        .notif-desc { font-size: 12px; color: #444; line-height: 1.3; margin-bottom: 8px; }
        
        .notif-big-img {
            width: 100%; height: 120px; object-fit: cover;
            border-radius: 8px; margin-top: 5px; display: block;
        }

        /* Compose Side - Selected Item Preview */
        .selected-item-box {
            background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px;
            padding: 10px; display: flex; align-items: center; gap: 15px;
            display: none; /* Hidden by default */
        }
        .selected-item-img { width: 60px; height: 60px; object-fit: cover; border-radius: 6px; }
    </style>
</head>
<body>
	
<div class="container py-5">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">Push Notification Manager</h4>
        
        </div>
        <a href="/manage.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <div class="row g-5">
        
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0 fw-bold text-primary"><i class="bi bi-pencil-square"></i> Compose Notification</h6></div>
                <div class="card-body">
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-uppercase text-muted">1. Select Post</label>
                        
                        <div class="input-group mb-3">
                            <button class="btn btn-primary w-100 py-2" data-bs-toggle="modal" data-bs-target="#postModal">
                                <i class="bi bi-search"></i> Search & Select Post
                            </button>
                        </div>

                        <div id="composePreviewBox" class="selected-item-box">
                            <img id="composeImg" src="" class="selected-item-img">
                            <div>
                                <div class="small text-muted text-uppercase" style="font-size: 10px;">Linked Post</div>
                                <div id="composeTitle" class="fw-bold text-dark" style="font-size: 14px; line-height: 1.2;"></div>
                            </div>
                        </div>

                        <input type="hidden" id="selectedPostId">
                        <input type="hidden" id="selectedUrl">
                        <input type="hidden" id="selectedImage">
                    </div>

                    <hr class="my-4" style="opacity: 0.1;">

                    <div class="mb-4">
    <label class="form-label fw-bold small text-uppercase text-muted">2. Notification Details</label>
    
    <div class="mb-3">
        <label class="small text-secondary fw-bold">Target URL <span class="text-danger">*</span></label>
        <input type="url" id="customUrl" class="form-control" placeholder="https://example.com/my-page">
    </div>

    <div class="mb-3">
        <label class="small text-secondary fw-bold">Title</label>
        <input type="text" id="customTitle" class="form-control" placeholder="Notification Title">
    </div>

    <div class="mb-3">
        <label class="small text-secondary fw-bold">Message Body</label>
        <textarea id="customBody" class="form-control" rows="2" placeholder="Short description..."></textarea>
    </div>

    <div class="mb-3">
        <label class="small text-secondary fw-bold">Banner Image URL</label>
        <input type="text" id="customImage" class="form-control" placeholder="https://...">
    </div>
</div>


                    <div class="d-grid mt-auto">
                        <button id="sendBtn" class="btn btn-success py-3 fw-bold" disabled>
                            <i class="bi bi-send-fill me-2"></i> Send to All Subscribers
                        </button>
                        <button id="viewLogsBtn" class="btn btn-outline-info ms-2 mt-2">
    <i class="bi bi-journal-text"></i> View Live Progress
</button>
                    </div>

                    <div id="statusMsg" class="mt-3"></div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="d-flex flex-column align-items-center sticky-top" style="top: 20px;">
                <h6 class="text-muted small fw-bold mb-3 text-uppercase"><i class="bi bi-phone"></i> Live Lock Screen Preview</h6>
                
                <div class="smartphone-frame">
                    <div class="smartphone-notch"></div>
                    
                    <div class="smartphone-screen">
                        <div class="lock-screen-time"><?= date('H:i') ?></div>
                        <div class="lock-screen-date"><?= date('l, F j') ?></div>

                        <div class="notif-card">
                            <div class="notif-header">
                                <div class="notif-app-info">
                                    <div class="notif-icon"><i class="bi bi-bell-fill"></i></div>
                                    <span class="fw-bold">CareerDiksha</span>
                                </div>
                                <span>now <i class="bi bi-chevron-down ms-1"></i></span>
                            </div>
                            <div class="notif-body">
                                <div class="notif-title" id="prevTitle">Welcome!</div>
                                <div class="notif-desc" id="prevBody">Select a post to see how it looks here.</div>
                                <img id="prevImg" src="" class="notif-big-img" style="display:none;">
                            </div>
                        </div>
                    </div>
                </div>

                <p class="text-center text-muted small mt-3 px-4" style="font-size: 11px;">
                    *Preview renders roughly how it appears on modern Android devices.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="postModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div class="input-group">
                    <input type="text" id="searchInput" class="form-control" placeholder="Type title to search..." autofocus autocomplete="off">
                    <button class="btn btn-outline-primary" type="button" id="btnSearchTrigger">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="list-group list-group-flush mt-2" id="postList">
                    <?php foreach ($posts as $post): 
                        $fullUrl = postUrl($post['slug'], $post['id']);
                        if (strpos($fullUrl, 'http') !== 0) $fullUrl = rtrim($site_url, '/') . '/' . ltrim($fullUrl, '/');
                        
                        $img = $post['banner_image'] ? '/' . $post['banner_image'] : '/assets/img/seo_og_default.png';
                        
                        $payload = json_encode([
                            'id' => $post['id'],
                            'title' => $post['title'],
                              'body'  => 'New government job alert! Check it out.',
                            'image' => $img,
                            'url' => $fullUrl
                        ], JSON_UNESCAPED_SLASHES);

                        $safePayload = htmlspecialchars($payload, ENT_QUOTES, 'UTF-8');
                    ?>
                    <button type="button" class="list-group-item list-group-item-action post-list-item d-flex gap-3 py-3 border-bottom" 
                            data-search="<?= strtolower(htmlspecialchars($post['title'])) ?>"
                            onclick="selectPost(<?= $safePayload ?>)">
                        <img src="<?= $img ?>" class="post-thumb-sm" onerror="this.src='https://via.placeholder.com/50'">
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 14px; line-height: 1.2; margin-bottom: 2px;">
                                <?= htmlspecialchars($post['title']) ?>
                            </div>
                            <div class="small text-muted">ID: <?= $post['id'] ?></div>
                        </div>
                    </button>
                    <?php endforeach; ?>
                    <div id="noResults" class="text-center py-4 text-muted" style="display:none;">
                        <i class="bi bi-emoji-frown fs-4"></i><br>No posts found
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PUSH_TOKEN = <?= json_encode($pushToken) ?>;

// Elements
const composePreviewBox = document.getElementById('composePreviewBox');
const composeImg = document.getElementById('composeImg');
const composeTitle = document.getElementById('composeTitle');

// --- NEW: Added references to your new visible inputs ---
const customUrl = document.getElementById('customUrl');
const customImage = document.getElementById('customImage'); 
const customTitle = document.getElementById('customTitle');
const customBody = document.getElementById('customBody');

const prevTitle = document.getElementById('prevTitle');
const prevBody = document.getElementById('prevBody');
const prevImg = document.getElementById('prevImg');

const sendBtn = document.getElementById('sendBtn');
const searchInput = document.getElementById('searchInput');
const postList = document.getElementById('postList');
const noResults = document.getElementById('noResults');

// Hidden ID for logging purposes
const selectedPostId = document.getElementById('selectedPostId');


const loader = document.createElement('div');
loader.id = 'searchLoader';
loader.style.display = 'none';
loader.style.textAlign = 'center';
loader.style.padding = '20px';
loader.innerHTML = '<i class="bi bi-arrow-repeat fs-3 text-primary"></i>';

document.getElementById('postList').before(loader);

function showLoader() {
    loader.style.display = 'block';
}

function hideLoader() {
    loader.style.display = 'none';
}



// 1. Search Logic 
async function performSearch() {
    const q = searchInput.value.trim();
    postList.innerHTML = '';
    noResults.style.display = 'none';

    if (!q) return;
    
    showLoader();

    const res = await fetch('/api/6n4a0d7n8v.php?q=' + encodeURIComponent(q));
    const posts = await res.json();
    
    hideLoader();

    if (!posts.length) {
        noResults.style.display = 'block';
        return;
    }

    posts.forEach(p => {
        const payload = {
            id: p.id,
            title: p.title,
            body: 'New government job alert! Check it out.',
            image: p.image,
            url: p.url
        };

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'list-group-item list-group-item-action post-list-item d-flex gap-3 py-3 border-bottom';
        btn.onclick = () => selectPost(payload);

        btn.innerHTML = `
            <img src="${p.image}" class="post-thumb-sm" onerror="this.src='https://via.placeholder.com/50'">
            <div>
                <div class="fw-bold text-dark" style="font-size:14px;line-height:1.2">${p.title}</div>
                <div class="small text-muted">ID: ${p.id}</div>
            </div>
        `;

        postList.appendChild(btn);
    });
}

document.getElementById('btnSearchTrigger').addEventListener('click', performSearch);

searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        performSearch();
    }
});


// 2. Select Logic (UPDATED to fill visible inputs)
function selectPost(data) {
    // A. Fill the Visible Inputs (User can edit these later)
    customUrl.value = data.url;
    customTitle.value = data.title;
    customBody.value = data.body;
    customImage.value = data.image; // Fill the banner input
    
    // B. Set hidden ID for database logging
    selectedPostId.value = data.id;
    
    // C. Update "Compose" Container Preview (The box below search button)
    composeImg.src = data.image;
    composeTitle.textContent = data.title;
    composePreviewBox.style.display = 'flex';
    
    // D. Force Update Live Smartphone Preview
    updateLivePreview();
    
    // E. Enable Button
    sendBtn.disabled = false;
    
    // F. Close Modal
    const modalEl = document.getElementById('postModal');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) {
        modalInstance.hide();
    }
}

// 3. Live Preview Logic (UPDATED to listen to Image and URL inputs)
// We add listeners to customImage as well now
[customTitle, customBody, customImage].forEach(el => {
    if(el) el.addEventListener('input', updateLivePreview);
});

function updateLivePreview() {
    prevTitle.textContent = customTitle.value || 'Notification Title';
    prevBody.textContent = customBody.value || 'Notification body text...';
    
    // Read directly from the visible input
    const imgUrl = customImage.value;
    
    if (imgUrl && imgUrl.trim() !== '') {
        prevImg.src = imgUrl;
        prevImg.style.display = 'block';
    } else {
        prevImg.style.display = 'none';
    }
    
    // B. Validate Inputs (Enable Button Logic)
    // If Title and URL have text, enable the button. Otherwise disable it.
    if (customTitle.value.trim() !== "" && customUrl.value.trim() !== "") {
        sendBtn.disabled = false;
    } else {
        sendBtn.disabled = true;
    }
}

// 4. Send Logic (UPDATED to read from visible inputs)
function showResult(msg, type) {
    const statusDiv = document.getElementById('statusMsg');
    const div = document.createElement('div');
    div.className = `alert alert-${type} alert-dismissible fade show mt-2 shadow-sm`;
    div.innerHTML = `${msg} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    statusDiv.appendChild(div);
}

sendBtn.addEventListener('click', async () => {
    // Get values from the visible text boxes
    const title = customTitle.value;
    const body = customBody.value;
    const url = customUrl.value;       // <--- Reads from your new input
    const image = customImage.value;   // <--- Reads from your new input
    const postId = selectedPostId.value;

    // Validation: Check if Title and URL exist
    if(!title || !url) return alert("Title and Target URL are required.");
    
    if(!confirm("Are you sure you want to send this push notification?")) return;

    const statusMsg = document.getElementById('statusMsg');
    statusMsg.innerHTML = '';
    sendBtn.disabled = true;
    const originalBtnContent = sendBtn.innerHTML;
    sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
    
    const payload = { title, body, url, image };

    try {
        const res = await fetch('send-push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Push-Token': PUSH_TOKEN },
            body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        
        if (data.success) {
        showResult(`<b>Success:</b> Background process started! Tracking in logs...`, 'success');
        saveLog(title, body, postId);
    } else if (data.error) {
        showResult(`Error: ${data.error}`, 'danger');
    }

} catch (e) {
    showResult(`Network Error: ${e.message}`, 'danger');
} finally {
     
    sendBtn.disabled = false;
    sendBtn.innerHTML = originalBtnContent;
    }
});

async function saveLog(title, body, postId) {
    const formData = new FormData();
    formData.append('action', 'log_push');
    formData.append('title', title);
    formData.append('post_id', postId);
    await fetch(window.location.href, { method: 'POST', body: formData });
}


// View Logs Redirect Logic
const viewLogsBtn = document.getElementById('viewLogsBtn');

viewLogsBtn.addEventListener('click', () => {
    // Ye PUSH_TOKEN wahi hai jo aapke existing JS me define hai
    const logUrl = `view-logs.php?token=${PUSH_TOKEN}`;
    
    // Naye tab mein logs kholne ke liye
    window.open(logUrl, '_blank');
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>