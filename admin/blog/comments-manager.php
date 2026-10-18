<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';
// require_once ROOT_PATH . '/mailers/CommentMailer.php';

// Check security 
 if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

$permissions = json_decode($user['permissions'] ?? '{}', true);

if (
    !$user ||
    $user['status'] !== 'active' ||
    empty($permissions['blogs']['manage_comments'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

// ── Moderation support (Pending/Approved) ────────────────────────────────
// Ported from the storytimes Comments Manager. The `comments` table here
// may not have a `status` column yet on every install, so we detect it
// once and degrade gracefully (filter tabs still render, but Pending stays
// empty and a one-click migration link is shown) instead of erroring out.
try {
    $hasCommentStatusCol = (bool)$pdo->query("SHOW COLUMNS FROM comments LIKE 'status'")->fetch();
} catch (Exception $e) {
    $hasCommentStatusCol = false;
}

$filter = in_array($_GET['filter'] ?? '', ['all', 'pending', 'approved'], true) ? $_GET['filter'] : 'all';
$search = trim($_GET['search'] ?? '');

$limit  = 15;
$page   = max(1, (int)($_GET['page'] ?? 1));


function time_ago($datetime) {
    $time_ago        = strtotime($datetime);
    $current_time    = time();
    $time_difference = $current_time - $time_ago;
    $seconds         = $time_difference;
    $minutes         = round($seconds / 60);
    $hours           = round($seconds / 3600);
    $days            = round($seconds / 86400);

    if ($seconds <= 60)      return "Just Now";
    elseif ($minutes <= 60)  return ($minutes == 1)  ? "1 min ago"  : "$minutes mins ago";
    elseif ($hours   <= 24)  return ($hours   == 1)  ? "1 hr ago"   : "$hours hrs ago";
    elseif ($days    <= 7)   return ($days    == 1)  ? "1 day ago"  : "$days days ago";
    else                     return date('j M Y', $time_ago);
}


function get_thread_stats($pdo, $parent_id, $current_stats = ['count' => 0, 'latest_timestamp' => 0]) {
    $stmt = $pdo->prepare("SELECT id, date FROM comments WHERE parent_id = ?");
    $stmt->execute([$parent_id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($children as $child) {
        $current_stats['count']++;
        $child_time = strtotime($child['date']);
        if ($child_time > $current_stats['latest_timestamp']) {
            $current_stats['latest_timestamp'] = $child_time;
        }
        $current_stats = get_thread_stats($pdo, $child['id'], $current_stats);
    }
    return $current_stats;
}


function render_replies($pdo, $parent_id, $page, $post_id, $level = 0, $parent_name = '') {
    global $filter, $search;
    $stmt = $pdo->prepare("SELECT * FROM comments WHERE parent_id = ? ORDER BY date ASC");
    $stmt->execute([$parent_id]);
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$replies) return;

    $marginClass = ($level < 2) ? 'replies-indent' : 'replies-tight';

    echo '<div class="d-flex flex-column gap-2 ' . $marginClass . '">';

    foreach ($replies as $r) {
        $isAdmin = ($r['email'] === 'contact@edumint24.com');

        echo '<div class="reply-item ' . ($isAdmin ? 'reply-item--admin' : '') . '" data-reply-id="' . $r['id'] . '">';
            echo '<div class="reply-header">';
                echo '<div class="reply-meta">';
                    echo '<strong class="reply-name">' . htmlspecialchars($r['name']) . '</strong>';
                    if ($isAdmin) echo '<span class="badge-admin">ADMIN</span>';
                    if ($level > 0) {
                        echo '<span class="reply-to"><i class="fas fa-reply"></i> ' . htmlspecialchars($parent_name) . '</span>';
                    }
                    echo '<span class="reply-time">• ' . time_ago($r['date']) . '</span>';
                echo '</div>';
                echo '<div class="reply-actions-inline">';
                echo '<a href="comments-manager.php?delete=' . $r['id'] . '&page=' . $page . '&filter=' . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : '') . '&token=' . urlencode(csrfToken()) . '" class="btn-delete-reply" onclick="return confirm(\'Delete this reply?\')" title="Delete"><i class="fas fa-times"></i></a>';
                echo '<button type="button" class="btn-edit-reply" onclick="toggleEditForm(' . $r['id'] . ')" title="Edit"><i class="fas fa-pen"></i></button>';
                echo '</div>';
            echo '</div>';

            echo '<div class="reply-content" id="reply-content-' . $r['id'] . '">' . nl2br(htmlspecialchars($r['content'])) . '</div>';
            echo '<button class="btn-reply-link" onclick="toggleReplyForm(' . $r['id'] . ')"><i class="fas fa-reply"></i> Reply</button>';

            echo '<div id="edit-form-' . $r['id'] . '" class="reply-box">
                    <form class="ajax-edit-form" data-comment-id="' . $r['id'] . '">
                        <textarea name="content" class="reply-textarea" rows="2" required>' . htmlspecialchars($r['content']) . '</textarea>
                        <div class="reply-form-actions">
                            <button type="button" class="btn btn-sm-cancel" onclick="toggleEditForm(' . $r['id'] . ')">Cancel</button>
                            <button type="submit" class="btn btn-sm-submit"><i class="fas fa-check"></i> Save</button>
                        </div>
                    </form>
                  </div>';
            echo '<button class="btn-reply-link" onclick="toggleReplyForm(' . $r['id'] . ')"><i class="fas fa-reply"></i> Reply</button>';

            echo '<div id="reply-form-' . $r['id'] . '" class="reply-box">
                    <form class="ajax-reply-form" data-post-id="' . $post_id . '" data-parent-id="' . $r['id'] . '" data-parent-name="' . htmlspecialchars($r['name'], ENT_QUOTES) . '">
                        <textarea name="content" class="reply-textarea" rows="2" placeholder="Reply to ' . htmlspecialchars($r['name']) . '..." required></textarea>
                        <div class="reply-form-actions">
                            <button type="button" class="btn btn-sm-cancel" onclick="toggleReplyForm(' . $r['id'] . ')">Cancel</button>
                            <button type="submit" class="btn btn-sm-submit"><i class="fas fa-paper-plane"></i> Reply</button>
                        </div>
                    </form>
                  </div>';

        echo '</div>';
        render_replies($pdo, $r['id'], $page, $post_id, $level + 1, $r['name']);
    }
    echo '</div>';
}


// ── AJAX Reply Handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_submit'])) {
    $isAjax    = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    requireCsrf($isAjax);
    $parent_id = (int)$_POST['parent_id'];
    $post_id   = (int)$_POST['post_id'];
    $content   = trim($_POST['content']);
    $adminName  = 'Edumint24 Support';
    $adminEmail = 'contact@edumint24.com';

    if ($content !== '' && $parent_id > 0 && $post_id > 0) {
        // 1. Fetch Parent Comment & Post Details (For Mail Algorithm)
        $parentStmt = $pdo->prepare("
            SELECT c.name, c.email, c.content, c.evf, p.slug
            FROM comments c
            JOIN posts p ON c.post_id = p.id
            WHERE c.id = ?
        ");
        $parentStmt->execute([$parent_id]);
        $parentData = $parentStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Insert Admin Reply (always approved — it's the admin talking)
        if ($hasCommentStatusCol) {
            $stmt = $pdo->prepare("INSERT INTO comments (name, email, content, post_id, parent_id, date, evf, status) VALUES (?, ?, ?, ?, ?, NOW(), 1, 'approved')");
            $stmt->execute([$adminName, $adminEmail, $content, $post_id, $parent_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO comments (name, email, content, post_id, parent_id, date, evf) VALUES (?, ?, ?, ?, ?, NOW(), 1)");
            $stmt->execute([$adminName, $adminEmail, $content, $post_id, $parent_id]);
        }

        // --- REPLY LOG START ---
        try {
            $reply_id = $pdo->lastInsertId();
            $log_desc = "Replied to Comment (Parent ID: $parent_id, New ID: $reply_id) on Post ID: $post_id";
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'comment_reply', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], $log_desc, $log_ip, $log_ua]);
        } catch (Exception $e) { error_log($e->getMessage()); }
        // --- REPLY LOG END ---

        // 3. Smart Algorithm: Send Notification if User is Verified
        if ($parentData && $parentData['evf'] == 1) {
            try {
                $postLinkRelative = postUrl($parentData['slug'], $post_id);
                $fullPostLink     = 'https://careerdiksha.co.in/' . ltrim($postLinkRelative, '/');
                $mailer           = new CommentMailer();
                $mailer->sendReplyNotification(
                    $parentData['name'],
                    $parentData['email'],
                    $parentData['content'],
                    $content,
                    $fullPostLink
                );
            } catch (Exception $mailErr) {
                error_log("Failed to send admin reply notification: " . $mailErr->getMessage());
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'     => true,
                'reply_id'    => (int)$reply_id,
                'name'        => $adminName,
                'content'     => $content,
                'time_ago'    => 'Just Now',
                'parent_id'   => $parent_id,
                'parent_name' => $parentData['name'] ?? '',
            ]);
            exit;
        }
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }
    }

    header("Location: /admin/comments-manager.php?page=$page");
    exit;
}


// ── AJAX Edit Comment Handler ─────────────────────────────────────────────
// Lets the admin fix a typo / clean up any comment or reply's text without
// deleting and re-posting it. Same CSRF + AJAX pattern as the reply handler.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_submit'])) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    requireCsrf($isAjax);

    $edit_id      = (int)($_POST['comment_id'] ?? 0);
    $edit_content = trim($_POST['content'] ?? '');

    if ($edit_id > 0 && $edit_content !== '') {
        $stmt = $pdo->prepare("SELECT name, post_id FROM comments WHERE id = ?");
        $stmt->execute([$edit_id]);
        $orig = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($orig) {
            $stmt = $pdo->prepare("UPDATE comments SET content = ? WHERE id = ?");
            $stmt->execute([$edit_content, $edit_id]);

            try {
                $log_desc = "Edited Comment ID: $edit_id (User: {$orig['name']}) on Post ID: {$orig['post_id']}";
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'comment_edit', ?, ?, ?)")
                    ->execute([$_SESSION['user_id'], $log_desc, $log_ip, $log_ua]);
            } catch (Exception $e) { error_log($e->getMessage()); }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'    => true,
                    'comment_id' => $edit_id,
                    'content'    => $edit_content,
                ]);
                exit;
            }
        } elseif ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Comment not found.']);
            exit;
        }
    } elseif ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Comment text cannot be empty.']);
        exit;
    }

    header("Location: /admin/comments-manager.php?page=$page&filter=" . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : ''));
    exit;
}


// ── Approve / Unapprove Handlers ─────────────────────────────────────────────
// Only meaningful once the `status` column exists (see admin/exc_fn/
// run_comment_status_migration.php); otherwise every comment is already
// live, so these are simply no-ops that redirect back.
if (isset($_GET['approve'])) {
    $approve_id = (int)$_GET['approve'];
    if ($hasCommentStatusCol) {
        $pdo->prepare("UPDATE comments SET status = 'approved' WHERE id = ?")->execute([$approve_id]);
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'comment_approve', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Approved Comment ID: $approve_id", $log_ip, $log_ua]);
        } catch (Exception $e) { error_log($e->getMessage()); }
    }
    header("Location: /admin/comments-manager.php?page=$page&filter=" . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : '') . "&msg=approved");
    exit;
}

if (isset($_GET['unapprove'])) {
    $unapprove_id = (int)$_GET['unapprove'];
    if ($hasCommentStatusCol) {
        $pdo->prepare("UPDATE comments SET status = 'pending' WHERE id = ?")->execute([$unapprove_id]);
        try {
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'comment_unapprove', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], "Moved Comment ID: $unapprove_id back to pending", $log_ip, $log_ua]);
        } catch (Exception $e) { error_log($e->getMessage()); }
    }
    header("Location: /admin/comments-manager.php?page=$page&filter=" . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : '') . "&msg=unapproved");
    exit;
}


// ── Delete Handler ──────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    // CSRF check — the delete link now carries a &token=csrfToken() param.
    if (!csrfGetValid()) {
        http_response_code(403);
        exit('Security error: invalid or missing token. Please go back and try again.');
    }

    $del_id = (int)$_GET['delete'];

    $stmt = $pdo->prepare("SELECT name, content, post_id FROM comments WHERE id = ?");
    $stmt->execute([$del_id]);
    $comment_data = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
    $stmt->execute([$del_id]);

    if ($comment_data) {
        try {
            $log_desc = "Deleted Comment ID: $del_id (User: {$comment_data['name']}) from Post ID: {$comment_data['post_id']}";
            $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, 'comment_delete', ?, ?, ?)")
                ->execute([$_SESSION['user_id'], $log_desc, $log_ip, $log_ua]);
        } catch (Exception $e) { error_log($e->getMessage()); }
    }

    header("Location: /admin/comments-manager.php?page=$page&filter=" . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : '') . "&msg=deleted");
    exit;
}


// ── Smart Sort Algorithm (unchanged) ────────────────────────────────────────
$stmt      = $pdo->query("SELECT id, parent_id, date FROM comments");
$all_nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roots_latest_activity = [];
$node_map              = [];

foreach ($all_nodes as $node) {
    $node_map[$node['id']] = $node;
    if ($node['parent_id'] === NULL) {
        $roots_latest_activity[$node['id']] = strtotime($node['date']);
    }
}

foreach ($all_nodes as $node) {
    if ($node['parent_id'] !== NULL) {
        $timestamp = strtotime($node['date']);
        $curr      = $node;
        $safety    = 0;
        while ($curr['parent_id'] !== NULL && $safety < 100) {
            if (isset($node_map[$curr['parent_id']])) {
                $curr = $node_map[$curr['parent_id']];
            } else {
                break;
            }
            $safety++;
        }
        if ($curr['parent_id'] === NULL && isset($roots_latest_activity[$curr['id']])) {
            if ($timestamp > $roots_latest_activity[$curr['id']]) {
                $roots_latest_activity[$curr['id']] = $timestamp;
            }
        }
    }
}

arsort($roots_latest_activity);

// ── Filter tabs + search (ported from storytimes) ────────────────────────
// Applied on top of the activity sort above: we work out which root ids
// match the current filter/search, then slice *that* list for pagination,
// so threads keep sorting by latest activity exactly as before.
$count_all = count($roots_latest_activity);

if ($hasCommentStatusCol) {
    $count_pending  = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE parent_id IS NULL AND status = 'pending'")->fetchColumn();
    $count_approved = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE parent_id IS NULL AND status = 'approved'")->fetchColumn();
} else {
    $count_pending  = 0;
    $count_approved = $count_all;
}

$match_where  = ["c.parent_id IS NULL"];
$match_params = [];

if ($hasCommentStatusCol && $filter !== 'all') {
    $match_where[]  = "c.status = ?";
    $match_params[] = $filter;
} elseif (!$hasCommentStatusCol && $filter === 'pending') {
    // No status column yet => nothing is ever "pending".
    $match_where[] = "1 = 0";
}

if ($search !== '') {
    $match_where[]  = "(c.name LIKE ? OR c.email LIKE ? OR c.content LIKE ?)";
    $s              = '%' . $search . '%';
    $match_params[] = $s;
    $match_params[] = $s;
    $match_params[] = $s;
}

$matching_root_ids = [];
if (!in_array('1 = 0', $match_where, true)) {
    $match_stmt = $pdo->prepare("SELECT id FROM comments c WHERE " . implode(' AND ', $match_where));
    $match_stmt->execute($match_params);
    $matching_root_ids = array_flip(array_map('intval', array_column($match_stmt->fetchAll(PDO::FETCH_ASSOC), 'id')));
}

// Keep the activity-based order, restricted to ids that match the filter/search.
$filtered_root_ids = ($filter === 'all' && $search === '')
    ? array_keys($roots_latest_activity)
    : array_keys(array_intersect_key($roots_latest_activity, $matching_root_ids));

$total  = count($filtered_root_ids);
$pages  = max(1, (int)ceil($total / $limit));
$page   = max(1, min($page, $pages));
$offset = ($page - 1) * $limit;

$paged_root_ids = array_slice($filtered_root_ids, $offset, $limit);

$comments = [];
if (!empty($paged_root_ids)) {
    $placeholders    = implode(',', array_fill(0, count($paged_root_ids), '?'));
    $sql             = "SELECT c.*, p.title AS post_title, p.slug AS post_slug FROM comments c JOIN posts p ON c.post_id = p.id WHERE c.id IN ($placeholders)";
    $stmt            = $pdo->prepare($sql);
    $stmt->execute($paged_root_ids);
    $unsorted_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results_map = [];
    foreach ($unsorted_results as $row) {
        $results_map[$row['id']] = $row;
    }
    foreach ($paged_root_ids as $id) {
        if (isset($results_map[$id])) {
            $comments[] = $results_map[$id];
        }
    }
}

$seo_robots = 'noindex, nofollow, noarchive, nosnippet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="<?= htmlspecialchars($seo_robots) ?>">
<title>Comments Manager - EduMint24 Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
/* Dark mode toggle - identical to dashboard.php / categories-manager.php / file-manager.php */
!function () {
    let e = localStorage.dm === "1", t = !1, n = !1,
        s = () => new Promise((e, r) => {
            let o = document.createElement("script");
            o.src = "/assets/js/darkreader.min.js";
            o.onload = () => { t = !0; e(); };
            o.onerror = r;
            document.head.appendChild(o);
        }),
        a = () => DarkReader.enable({ brightness: 100, contrast: 100, sepia: 10 }),
        d = () => DarkReader.disable(),
        i = () => {
            document.querySelectorAll(".dark-mode-toggle i").forEach(o => {
                o.classList.add("rotate");
                if (e) { o.classList.remove("fa-moon"); o.classList.add("fa-sun"); }
                else { o.classList.remove("fa-sun"); o.classList.add("fa-moon"); }
                setTimeout(() => o.classList.remove("rotate"), 400);
            });
        };
    e && (t ? a() : s().then(a));
    document.addEventListener("DOMContentLoaded", () => {
        i();
        document.querySelectorAll(".dark-mode-toggle").forEach(o => o.onclick = r);
    });
    async function r(o) {
        o.preventDefault();
        if (n) return;
        n = !0;
        let c = document.querySelectorAll(".dark-mode-toggle");
        c.forEach(e => e.classList.add("loading"));
        try {
            t || await s();
            e = !e;
            localStorage.dm = e ? "1" : "0";
            e ? a() : d();
            i();
        } finally {
            setTimeout(() => { c.forEach(e => e.classList.remove("loading")); n = !1; }, 600);
        }
    }
}();
</script>
<style>
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #3b82f6;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    --header-height: 70px;
    --sidebar-width: 280px;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--gray-50);
    color: var(--gray-800);
    line-height: 1.5;
}

/* ── Admin shell: sidebar + top-nav (identical to dashboard.php / categories-manager.php / file-manager.php) ── */
.admin-container {
    display: grid;
    grid-template-columns: 1fr;
    min-height: 100vh;
}
@media (min-width: 1024px) {
    .admin-container { grid-template-columns: var(--sidebar-width) 1fr; }
}

.sidebar {
    position: fixed;
    top: 0; left: 0; bottom: 0;
    width: var(--sidebar-width);
    background: white;
    border-right: 1px solid var(--gray-200);
    z-index: 1000;
    transform: translateX(-100%);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.sidebar.open { transform: translateX(0); }
@media (min-width: 1024px) {
    .sidebar { position: sticky; transform: translateX(0); height: 100vh; top: 0; }
}

.sidebar-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.brand {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--primary);
    text-decoration: none;
}
.brand-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    border-radius: var(--radius-lg);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 1.25rem;
}
.close-sidebar {
    width: 36px; height: 36px;
    border: none;
    background: var(--gray-100);
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    color: var(--gray-600);
    transition: all 0.2s;
}
.close-sidebar:hover { background: var(--gray-200); }
@media (min-width: 1024px) { .close-sidebar { display: none; } }

.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section { margin-bottom: 1.5rem; padding: 0 1rem; }
.nav-title {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--gray-400);
    padding: 0 1rem;
    margin-bottom: 0.75rem;
}
.nav-link {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1rem;
    border-radius: var(--radius);
    color: var(--gray-600);
    text-decoration: none;
    font-size: 0.9375rem;
    font-weight: 500;
    transition: all 0.2s;
    margin-bottom: 0.25rem;
}
.nav-link:hover { background: var(--gray-50); color: var(--gray-900); }
.nav-link.active { background: var(--primary-lighter); color: var(--primary); font-weight: 600; }
.nav-link i { width: 24px; text-align: center; font-size: 1.125rem; }

.sidebar-footer { padding: 1rem; border-top: 1px solid var(--gray-100); }
.user-card {
    display: flex; align-items: center; gap: 0.875rem;
    padding: 0.875rem;
    background: var(--gray-50);
    border-radius: var(--radius-lg);
}
.user-avatar {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1rem;
}
.user-info { flex: 1; min-width: 0; }
.user-name {
    font-weight: 600; color: var(--gray-900); font-size: 0.9375rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.user-role { font-size: 0.75rem; color: var(--gray-500); }

.sidebar-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
    opacity: 0; visibility: hidden;
    transition: all 0.3s;
}
.sidebar-overlay.active { opacity: 1; visibility: visible; }
@media (min-width: 1024px) { .sidebar-overlay { display: none; } }

.main-content { min-width: 0; }

.top-nav {
    position: sticky; top: 0;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    padding: 0.450rem;
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem;
    z-index: 100;
}
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle {
    width: 40px; height: 40px;
    border: none;
    background: var(--gray-100);
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.125rem;
    color: var(--gray-700);
    cursor: pointer;
    transition: all 0.2s;
}
.menu-toggle:hover { background: var(--gray-200); }
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }

.page-heading { display: none; }
@media (min-width: 768px) {
    .page-heading { display: block; }
    .sitename-mob { display: none; }
    .page-heading h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); }
    .page-heading p { font-size: 0.875rem; color: var(--gray-500); }
}

.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.stat-pills { display: none; }
@media (min-width: 768px) { .stat-pills { display: flex; gap: 0.75rem; } }
.stat-pill {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--gray-100);
    border-radius: var(--radius);
    font-size: 0.875rem; font-weight: 500;
    color: var(--gray-700);
}
.stat-pill i { color: var(--primary); }

.icon-btn {
    width: 40px; height: 40px;
    border: none;
    background: transparent;
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.125rem;
    color: var(--gray-600);
    cursor: pointer;
    position: relative;
    transition: all 0.2s;
}
.icon-btn:hover { background: var(--gray-100); }
.icon-btn .badge {
    position: absolute; top: 6px; right: 6px;
    width: 8px; height: 8px;
    background: var(--danger);
    border-radius: 50%;
    border: 2px solid white;
}

/* ── Content wrapper ─────────────────────────────────────────────────── */
.content-wrapper {
    padding: 1.5rem;
    max-width: 1600px;
    margin: 0 auto;
}

@media (max-width: 640px) {
    .content-wrapper { padding: 1rem; }
}

.total-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 1rem;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gray-700);
    box-shadow: var(--shadow-sm);
}

.total-badge i { color: var(--primary); }

/* ── Alert ───────────────────────────────────────────────────────────── */
.alert {
    padding: 1rem 1.25rem;
    border-radius: var(--radius-lg);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.875rem;
    font-size: 0.9375rem;
    border: 1px solid transparent;
}

.alert-success {
    background: #ecfdf5;
    color: #065f46;
    border-color: #a7f3d0;
}

.alert-info {
    background: #eff6ff;
    color: #1e40af;
    border-color: #bfdbfe;
}

.alert-warning {
    background: #fffbeb;
    color: #92400e;
    border-color: #fde68a;
}

.alert i { font-size: 1.125rem; }

/* ── Filter bar (ported from storytimes Comments Manager) ─────────────── */
.cm-filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.cm-filter-tabs {
    display: flex;
    align-items: center;
    gap: 0.125rem;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: 0.25rem;
    box-shadow: var(--shadow-sm);
}

.cm-filter-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.5rem 0.9rem;
    border-radius: var(--radius);
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--gray-500);
    transition: all 0.15s;
    white-space: nowrap;
}

.cm-filter-tab:hover { color: var(--gray-800); background: var(--gray-50); }
.cm-filter-tab.active { background: var(--primary); color: white; }

.cm-filter-tab .count {
    background: rgba(0,0,0,0.08);
    color: inherit;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    min-width: 20px;
    text-align: center;
}

.cm-filter-tab.active .count { background: rgba(255,255,255,0.25); }

.cm-search-form {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.cm-search-input {
    padding: 0.5rem 0.9rem;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    font-size: 0.875rem;
    font-family: inherit;
    color: var(--gray-800);
    background: white;
    width: 220px;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.cm-search-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}

.cm-search-btn {
    padding: 0.5rem 0.9rem;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.cm-search-btn:hover { background: var(--primary-dark); }

.cm-search-clear {
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius);
    background: var(--gray-100);
    color: var(--gray-600);
    text-decoration: none;
    font-size: 0.8125rem;
    font-weight: 600;
}
.cm-search-clear:hover { background: var(--gray-200); }

@media (max-width: 540px) {
    .cm-search-input { width: 150px; }
    .cm-filter-tab .label { display: none; }
}

/* ── Status badge ────────────────────────────────────────────────────── */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    margin-left: 0.375rem;
}
.status-approved { background: #d1fae5; color: #065f46; }
.status-pending  { background: #fef3c7; color: #92400e; }

.comment-card.is-pending { border-left-color: var(--warning); }

/* ── Empty state ─────────────────────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: var(--radius-xl);
    border: 1px solid var(--gray-100);
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-300);
    display: block;
    margin-bottom: 1rem;
}

.empty-state p {
    color: var(--gray-500);
    font-size: 1rem;
}

/* ── Comment list ────────────────────────────────────────────────────── */
.comments-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* ── Comment card ────────────────────────────────────────────────────── */
.comment-card {
    background: white;
    border-radius: var(--radius-xl);
    border: 1px solid var(--gray-100);
    border-left: 4px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: all 0.2s;
}

.comment-card:hover {
    border-left-color: var(--primary);
    box-shadow: var(--shadow-md);
}

.comment-card-body { padding: 1.25rem; }

/* ── Comment header row ──────────────────────────────────────────────── */
.comment-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.comment-author {
    display: flex;
    align-items: flex-start;
    gap: 0.875rem;
    flex: 1;
    min-width: 0;
}

.author-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
    flex-shrink: 0;
}

.author-info { min-width: 0; }

.author-name {
    font-weight: 600;
    color: var(--gray-900);
    font-size: 0.9375rem;
    display: flex;
    align-items: center;
    gap: 0.375rem;
    flex-wrap: wrap;
}

.badge-verified {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--info);
}

.author-email {
    font-size: 0.8125rem;
    color: var(--gray-500);
    margin-top: 0.125rem;
}

.comment-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.375rem;
    flex-wrap: wrap;
}

.post-link {
    text-decoration: none;
    color: var(--gray-500);
    font-size: 0.8125rem;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    transition: color 0.2s;
}

.post-link:hover { color: var(--primary); }

.meta-dot { color: var(--gray-300); font-size: 0.75rem; }

.time-label {
    font-size: 0.8125rem;
    color: var(--gray-400);
}

/* ── Dropdown menu ───────────────────────────────────────────────────── */
.dropdown { position: relative; }

.dropdown-toggle {
    width: 34px;
    height: 34px;
    border: 1px solid var(--gray-200);
    background: var(--gray-50);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--gray-500);
    transition: all 0.2s;
    flex-shrink: 0;
}

.dropdown-toggle:hover {
    background: var(--gray-100);
    color: var(--gray-700);
}

.dropdown-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    min-width: 160px;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    z-index: 50;
    overflow: hidden;
    display: none;
}

.dropdown-menu.open { display: block; }

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.625rem 1rem;
    font-size: 0.875rem;
    color: var(--gray-700);
    text-decoration: none;
    transition: background 0.15s;
}

.dropdown-item:hover { background: var(--gray-50); }
.dropdown-item.danger { color: var(--danger); }
.dropdown-item.danger:hover { background: #fef2f2; }
button.dropdown-item {
    width: 100%;
    background: none;
    border: none;
    font-family: inherit;
    cursor: pointer;
    text-align: left;
}

/* ── Comment body ────────────────────────────────────────────────────── */
.comment-body {
    color: var(--gray-700);
    font-size: 0.9375rem;
    line-height: 1.6;
    margin-bottom: 1rem;
    padding: 0.875rem 1rem;
    background: var(--gray-50);
    border-radius: var(--radius-lg);
}

/* ── Action bar ──────────────────────────────────────────────────────── */
.comment-actions {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 0.5rem 1rem;
    border-radius: var(--radius);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.2s;
    white-space: nowrap;
    text-decoration: none;
}

.btn-reply {
    background: var(--primary-lighter);
    color: var(--primary);
    border-color: var(--primary-light);
}

.btn-reply:hover {
    background: var(--primary-light);
}

.btn-view-replies {
    background: white;
    color: var(--gray-600);
    border-color: var(--gray-200);
}

.btn-view-replies:hover {
    background: var(--gray-50);
    color: var(--gray-800);
}

.btn-view-replies.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

/* ── Root reply form ─────────────────────────────────────────────────── */
.reply-box { display: none; }

.root-reply-box {
    margin-top: 1rem;
    padding: 1rem;
    background: var(--primary-lighter);
    border-radius: var(--radius-lg);
    border: 1px solid var(--primary-light);
}

.root-reply-box .reply-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--primary);
    margin-bottom: 0.5rem;
    display: block;
}

.reply-textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius);
    font-size: 0.9375rem;
    font-family: inherit;
    color: var(--gray-800);
    background: white;
    resize: vertical;
    transition: border-color 0.2s, box-shadow 0.2s;
    display: block;
}

.reply-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}

.reply-form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 0.625rem;
}

.btn-sm-cancel {
    background: white;
    color: var(--gray-600);
    border: 1px solid var(--gray-200);
    padding: 0.4rem 0.875rem;
    border-radius: var(--radius);
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-sm-cancel:hover {
    background: var(--gray-100);
}

.btn-sm-submit {
    background: var(--primary);
    color: white;
    border: 1px solid transparent;
    padding: 0.4rem 0.875rem;
    border-radius: var(--radius);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.btn-sm-submit:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
}

.btn-sm-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* ── Nested replies ──────────────────────────────────────────────────── */
.replies-container {
    display: none;
    margin-top: 1rem;
}

.replies-container.open { display: block; }

.replies-indent { margin-left: 1rem; }
.replies-tight  { margin-top: 0.5rem; }

.reply-item {
    padding: 0.75rem 1rem;
    border-radius: var(--radius-lg);
    background: var(--gray-50);
    border-left: 3px solid var(--gray-200);
    font-size: 0.9rem;
}

.reply-item + .reply-item,
.reply-item + .d-flex { margin-top: 0.5rem; }

.reply-item--admin {
    border-left-color: var(--success) !important;
    background: #f0fdf4 !important;
}

.d-flex { display: flex; }
.flex-column { flex-direction: column; }
.gap-2 { gap: 0.5rem; }

.reply-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.375rem;
}

.reply-meta {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    flex-wrap: wrap;
    font-size: 0.875rem;
}

.reply-name { font-weight: 600; color: var(--gray-800); }

.badge-admin {
    background: #d1fae5;
    color: #065f46;
    font-size: 0.6rem;
    font-weight: 700;
    padding: 0.1rem 0.4rem;
    border-radius: 9999px;
    letter-spacing: 0.05em;
}

.reply-to {
    font-size: 0.8rem;
    color: var(--gray-400);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.reply-time { color: var(--gray-400); font-size: 0.8rem; }

.reply-content {
    color: var(--gray-600);
    font-size: 0.875rem;
    margin-bottom: 0.375rem;
    line-height: 1.5;
}

.btn-reply-link {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--primary);
    padding: 0;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    transition: opacity 0.2s;
}

.btn-reply-link:hover { opacity: 0.7; }

.btn-delete-reply {
    color: var(--danger);
    text-decoration: none;
    font-size: 0.875rem;
    opacity: 0.6;
    transition: opacity 0.2s;
    padding: 0.25rem;
    border-radius: var(--radius);
}

.btn-delete-reply:hover { opacity: 1; background: #fef2f2; }

.reply-actions-inline { display: flex; align-items: center; gap: 0.125rem; }

.btn-edit-reply {
    color: var(--gray-500);
    background: none;
    border: none;
    cursor: pointer;
    font-size: 0.8125rem;
    opacity: 0.6;
    transition: opacity 0.2s;
    padding: 0.25rem;
    border-radius: var(--radius);
}
.btn-edit-reply:hover { opacity: 1; color: var(--primary); background: var(--primary-lighter); }

/* ── Inline reply form (nested) ──────────────────────────────────────── */
.reply-box {
    margin-top: 0.625rem;
    padding: 0.75rem;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
}

/* ── Pagination ──────────────────────────────────────────────────────── */
/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.375rem;
    margin-top: 2rem;
    flex-wrap: wrap;
}

.page-link {
    min-width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius);
    font-size: 0.9375rem;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s;
    border: 1px solid var(--gray-200);
    background: white;
    color: var(--gray-700);
    padding: 0 0.75rem;
}

.page-link:hover {
    background: var(--gray-50);
    border-color: var(--gray-300);
}

.page-link.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.page-link.disabled {
    color: var(--gray-300);
    cursor: not-allowed;
    pointer-events: none;
}

/* ── Loading spinner on submit ───────────────────────────────────────── */
@keyframes spin {
    to { transform: rotate(360deg); }
}

.spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.4);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

/* ── Dark mode toggle ────────────────────────────────────────────────── */
.dark-mode-toggle i {
    transition: transform .4s ease, opacity .3s ease;
}
.dark-mode-toggle i.rotate { transform: rotate(180deg); }
</style>
</head>

<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob">EduMint24</span>
                <div class="page-heading">
                    <h1>Comments Manager</h1>
                    <p>Manage discussion threads across your blog</p>
                </div>
            </div>

            <div class="nav-right">
                <div class="stat-pills">
                    <div class="stat-pill">
                        <i class="fas fa-layer-group"></i>
                        <span><?= $count_all ?> Threads</span>
                    </div>
                </div>
                <button class="icon-btn dark-mode-toggle" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

    <?php if (!$hasCommentStatusCol): ?>
        <div class="alert alert-warning">
            <i class="fas fa-triangle-exclamation"></i>
            Moderation isn't set up yet on this database — every comment currently goes live instantly.
            <a href="exc_fn/run_comment_status_migration.php" style="margin-left:auto;font-weight:700;">Run one-time setup &rarr;</a>
        </div>
    <?php endif; ?>

    <?php
        $msg_map = [
            'deleted'    => ['type' => 'success', 'icon' => 'fa-check-circle',  'text' => 'Comment deleted successfully.'],
            'approved'   => ['type' => 'success', 'icon' => 'fa-check-circle',  'text' => 'Comment approved.'],
            'unapproved' => ['type' => 'info',    'icon' => 'fa-rotate-left',   'text' => 'Comment moved back to pending.'],
        ];
        $m = $msg_map[$_GET['msg'] ?? ''] ?? null;
    ?>
    <?php if ($m): ?>
        <div class="alert alert-<?= $m['type'] ?>" id="deleteAlert">
            <i class="fas <?= $m['icon'] ?>"></i>
            <?= $m['text'] ?>
        </div>
    <?php endif; ?>

    <!-- Filter tabs + search (ported from storytimes Comments Manager) -->
    <div class="cm-filter-bar">
        <div class="cm-filter-tabs">
            <?php
                $tab_base = 'comments-manager.php' . ($search !== '' ? '?search=' . urlencode($search) . '&' : '?');
                $tabs = [
                    'all'      => ['label' => 'All',      'count' => $count_all],
                    'pending'  => ['label' => 'Pending',   'count' => $count_pending],
                    'approved' => ['label' => 'Approved',  'count' => $count_approved],
                ];
                foreach ($tabs as $key => $t):
                    $active = ($filter === $key) ? ' active' : '';
            ?>
                <a href="<?= $tab_base ?>filter=<?= $key ?>" class="cm-filter-tab<?= $active ?>">
                    <span class="label"><?= $t['label'] ?></span>
                    <span class="count"><?= $t['count'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <form class="cm-search-form" method="GET" action="comments-manager.php">
            <?php if ($filter !== 'all'): ?>
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <?php endif; ?>
            <input type="search" name="search" class="cm-search-input"
                   placeholder="Search comments…"
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="cm-search-btn"><i class="fas fa-search"></i></button>
            <?php if ($search !== ''): ?>
                <a href="comments-manager.php<?= $filter !== 'all' ? '?filter=' . urlencode($filter) : '' ?>" class="cm-search-clear">
                    <i class="fas fa-xmark"></i> Clear
                </a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($comments)): ?>
        <div class="empty-state">
            <i class="fas fa-comment-slash"></i>
            <p><?= ($filter !== 'all' || $search !== '') ? 'No comments match this filter.' : 'No comments yet. Clean slate!' ?></p>
        </div>
    <?php else: ?>

        <div class="comments-list">
            <?php foreach ($comments as $c): ?>
                <?php
                    $init_stats   = ['count' => 0, 'latest_timestamp' => strtotime($c['date'])];
                    $thread_stats = get_thread_stats($pdo, $c['id'], $init_stats);
                    $total_replies    = $thread_stats['count'];
                    $display_date_str = date('Y-m-d H:i:s', $thread_stats['latest_timestamp']);
                    $initials         = strtoupper(mb_substr(trim($c['name']), 0, 1));
                    $isPending        = $hasCommentStatusCol && (($c['status'] ?? 'approved') === 'pending');
                    $qs_tail          = '&page=' . $page . '&filter=' . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : '');
                ?>

                <div class="comment-card<?= $isPending ? ' is-pending' : '' ?>" id="comment-<?= $c['id'] ?>">
                    <div class="comment-card-body">

                        <!-- Header -->
                        <div class="comment-header">
                            <div class="comment-author">
                                <div class="author-avatar"><?= htmlspecialchars($initials) ?></div>
                                <div class="author-info">
                                    <div class="author-name">
                                        <?= htmlspecialchars($c['name']) ?>
                                        <?php if (!empty($c['evf'])): ?>
                                            <span class="badge-verified"><i class="fas fa-check-circle"></i> Verified</span>
                                        <?php endif; ?>
                                        <?php if ($hasCommentStatusCol): ?>
                                            <?php if ($isPending): ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php else: ?>
                                                <span class="status-badge status-approved">Approved</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="author-email"><?= htmlspecialchars($c['email']) ?></div>
                                    <div class="comment-meta">
                                        <a href="../<?= postUrl($c['post_slug'], $c['post_id']) ?>" target="_blank" class="post-link">
                                            <i class="fas fa-file-alt"></i> <?= htmlspecialchars($c['post_title']) ?>
                                        </a>
                                        <span class="meta-dot">•</span>
                                        <span class="time-label"><?= time_ago($display_date_str) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="dropdown">
                                <button class="dropdown-toggle" onclick="toggleDropdown(this)" title="Options">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if ($hasCommentStatusCol): ?>
                                        <?php if ($isPending): ?>
                                            <a class="dropdown-item"
                                               href="comments-manager.php?approve=<?= $c['id'] ?><?= $qs_tail ?>">
                                                <i class="fas fa-check"></i> Approve
                                            </a>
                                        <?php else: ?>
                                            <a class="dropdown-item"
                                               href="comments-manager.php?unapprove=<?= $c['id'] ?><?= $qs_tail ?>">
                                                <i class="fas fa-rotate-left"></i> Unapprove
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button type="button" class="dropdown-item" onclick="toggleEditForm(<?= $c['id'] ?>); closeDropdowns()">
                                        <i class="fas fa-pen"></i> Edit
                                    </button>
                                    <a class="dropdown-item danger"
                                       href="comments-manager.php?delete=<?= $c['id'] ?><?= $qs_tail ?>&token=<?= urlencode(csrfToken()) ?>"
                                       onclick="return confirm('WARNING: This will delete the comment AND all its replies. Continue?')">
                                        <i class="fas fa-trash"></i> Delete Thread
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Body -->
                        <div class="comment-body" id="reply-content-<?= $c['id'] ?>">
                            <?= nl2br(htmlspecialchars($c['content'])) ?>
                        </div>

                        <div id="edit-form-<?= $c['id'] ?>" class="reply-box" style="margin-left:0;">
                            <form class="ajax-edit-form" data-comment-id="<?= $c['id'] ?>">
                                <textarea name="content" class="reply-textarea" rows="3" required><?= htmlspecialchars($c['content']) ?></textarea>
                                <div class="reply-form-actions">
                                    <button type="button" class="btn btn-sm-cancel" onclick="toggleEditForm(<?= $c['id'] ?>)">Cancel</button>
                                    <button type="submit" class="btn btn-sm-submit"><i class="fas fa-check"></i> Save</button>
                                </div>
                            </form>
                        </div>

                        <!-- Actions -->
                        <div class="comment-actions">
                            <button class="btn btn-reply" onclick="toggleReplyForm(<?= $c['id'] ?>)">
                                <i class="fas fa-reply"></i> Reply
                            </button>

                            <?php if ($total_replies > 0): ?>
                                <button class="btn btn-view-replies"
                                        id="toggle-btn-<?= $c['id'] ?>"
                                        onclick="toggleReplies(<?= $c['id'] ?>)">
                                    <i class="fas fa-comments"></i>
                                    <span id="reply-count-<?= $c['id'] ?>"><?= $total_replies ?></span>
                                    Repl<?= $total_replies == 1 ? 'y' : 'ies' ?>
                                    <i class="fas fa-chevron-down" id="chevron-<?= $c['id'] ?>"></i>
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Root Reply Form -->
                        <div id="reply-form-<?= $c['id'] ?>" class="reply-box root-reply-box">
                            <span class="reply-label"><i class="fas fa-shield-alt"></i> Reply as Admin</span>
                            <form class="ajax-reply-form"
                                  data-post-id="<?= $c['post_id'] ?>"
                                  data-parent-id="<?= $c['id'] ?>"
                                  data-parent-name="<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>">
                                <textarea name="content" class="reply-textarea" rows="3"
                                          placeholder="Write your reply here..." required></textarea>
                                <div class="reply-form-actions">
                                    <button type="button" class="btn-sm-cancel" onclick="toggleReplyForm(<?= $c['id'] ?>)">Cancel</button>
                                    <button type="submit" class="btn-sm-submit">
                                        <i class="fas fa-paper-plane"></i> Post Reply
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Nested Replies -->
                        <?php if ($total_replies > 0): ?>
                            <div class="replies-container" id="replies-<?= $c['id'] ?>">
                                <?php render_replies($pdo, $c['id'], $page, $c['post_id'], 0, $c['name']); ?>
                            </div>
                        <?php else: ?>
                            <div class="replies-container" id="replies-<?= $c['id'] ?>"></div>
                        <?php endif; ?>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
            <?php
                $pg_qs = '?filter=' . urlencode($filter) . ($search !== '' ? '&search=' . urlencode($search) : '') . '&page=';
            ?>
            <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= $pg_qs ?>1" class="page-link">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="<?= $pg_qs ?><?= $page - 1 ?>" class="page-link">
                        <i class="fas fa-angle-left"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                    <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                <?php endif; ?>

                <?php
                $range = 2;
                $start = max(1, $page - $range);
                $end = min($pages, $page + $range);

                if ($start > 1): ?>
                    <a href="<?= $pg_qs ?>1" class="page-link">1</a>
                    <?php if ($start > 2): ?><span class="page-link disabled">...</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="page-link active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= $pg_qs ?><?= $i ?>" class="page-link"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $pages): ?>
                    <?php if ($end < $pages - 1): ?><span class="page-link disabled">...</span><?php endif; ?>
                    <a href="<?= $pg_qs ?><?= $pages ?>" class="page-link"><?= $pages ?></a>
                <?php endif; ?>

                <?php if ($page < $pages): ?>
                    <a href="<?= $pg_qs ?><?= $page + 1 ?>" class="page-link">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="<?= $pg_qs ?><?= $pages ?>" class="page-link">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php else: ?>
                    <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                    <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

    <?php endif; ?>
        </div>
    </main>
</div>

<footer>
    <div style="padding: 10px 20px; text-align: center; color: #6C6C6C;">
        <p style="font-size:0.8rem; margin-bottom:0px;">&copy; 2025-2026 EduMint24. All rights reserved.</p>
    </div>
</footer>

<script>
const ADMIN_URL = "/admin";

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

// ── Toast ─────────────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const bg = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b';
    Toastify({
        text: message,
        duration: 3500,
        position: 'center',
        style: {
            background: bg,
            borderRadius: '12px',
            padding: '14px 22px',
            fontSize: '14px',
            fontWeight: '500',
            boxShadow: '0 8px 24px rgba(0,0,0,0.15)',
        }
    }).showToast();
}

// ── Toggle reply form ─────────────────────────────────────────────────
function toggleReplyForm(id) {
    const form = document.getElementById('reply-form-' + id);
    if (!form) return;
    const isVisible = form.style.display === 'block';
    form.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) form.querySelector('textarea')?.focus();
}

function toggleEditForm(id) {
    const form = document.getElementById('edit-form-' + id);
    if (!form) return;
    const isVisible = form.style.display === 'block';
    form.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        const ta = form.querySelector('textarea');
        ta?.focus();
        if (ta) ta.selectionStart = ta.value.length;
    }
}

// ── Toggle replies panel ──────────────────────────────────────────────
function toggleReplies(id) {
    const container = document.getElementById('replies-' + id);
    const btn       = document.getElementById('toggle-btn-' + id);
    const chevron   = document.getElementById('chevron-' + id);
    if (!container) return;

    const isOpen = container.classList.contains('open');
    container.classList.toggle('open', !isOpen);
    btn?.classList.toggle('active', !isOpen);
    if (chevron) {
        chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
        chevron.style.transition = 'transform 0.2s ease';
    }
}

// ── Dropdown ──────────────────────────────────────────────────────────
function toggleDropdown(btn) {
    const menu = btn.nextElementSibling;
    const isOpen = menu.classList.contains('open');
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    if (!isOpen) menu.classList.add('open');
}

function closeDropdowns() {
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
}

document.addEventListener('click', (e) => {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
    }
});

// ── AJAX reply submission ─────────────────────────────────────────────
document.addEventListener('submit', async function (e) {
    if (!e.target.classList.contains('ajax-reply-form')) return;
    e.preventDefault();

    const form       = e.target;
    const postId     = form.dataset.postId;
    const parentId   = form.dataset.parentId;
    const parentName = form.dataset.parentName;
    const textarea   = form.querySelector('textarea[name="content"]');
    const submitBtn  = form.querySelector('.btn-sm-submit');
    const content    = textarea.value.trim();

    if (!content) return;

    // Disable while submitting
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> Posting…';

    const body = new URLSearchParams({
        reply_submit: '1',
        post_id:      postId,
        parent_id:    parentId,
        content:      content,
        csrf_token:   '<?= addslashes(csrfToken()) ?>',
    });

    try {
        const res  = await fetch(window.location.pathname, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        });

        const data = await res.json();

        if (data.success) {
            showToast('Reply posted successfully!', 'success');

            // Build reply HTML and inject
            const isAdmin = true;
            const replyEl = buildReplyHTML(data, parentName);

            // Find the replies container for the root comment
            const rootId     = findRootCommentId(parentId);
            const container  = document.getElementById('replies-' + rootId);

            if (container) {
                // Find the direct parent's reply block, or append at top level
                const parentBlock = container.querySelector('[data-reply-id="' + parentId + '"]');
                if (parentBlock) {
                    // Append after parent block as a sibling nested group or inside existing
                    let sibling = parentBlock.nextElementSibling;
                    if (sibling && sibling.classList.contains('d-flex')) {
                        sibling.appendChild(replyEl);
                    } else {
                        const nestedDiv = document.createElement('div');
                        nestedDiv.className = 'd-flex flex-column gap-2 replies-indent';
                        nestedDiv.appendChild(replyEl);
                        parentBlock.insertAdjacentElement('afterend', nestedDiv);
                    }
                } else {
                    // Direct child of root - append inside container's first child list
                    let list = container.querySelector('.d-flex.flex-column');
                    if (!list) {
                        list = document.createElement('div');
                        list.className = 'd-flex flex-column gap-2';
                        container.appendChild(list);
                    }
                    list.appendChild(replyEl);
                }

                // Auto-open replies panel
                if (!container.classList.contains('open')) {
                    toggleReplies(rootId);
                }

                // Update reply count badge
                const countEl = document.getElementById('reply-count-' + rootId);
                if (countEl) {
                    const cur = parseInt(countEl.textContent) || 0;
                    countEl.textContent = cur + 1;
                } else {
                    // If no replies button existed yet, we need to add one – just refresh once
                    // (edge case: first-ever reply on a comment)
                }
            }

            textarea.value = '';
            // Hide form
            const formWrapper = document.getElementById('reply-form-' + parentId);
            if (formWrapper) formWrapper.style.display = 'none';

        } else {
            showToast(data.message || 'Failed to post reply.', 'error');
        }

    } catch (err) {
        console.error(err);
        showToast('Network error. Please try again.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Post Reply';
    }
});

// ── AJAX edit submission (root comments + replies, same handler) ──────
document.addEventListener('submit', async function (e) {
    if (!e.target.classList.contains('ajax-edit-form')) return;
    e.preventDefault();

    const form      = e.target;
    const commentId = form.dataset.commentId;
    const textarea  = form.querySelector('textarea[name="content"]');
    const submitBtn = form.querySelector('.btn-sm-submit');
    const content   = textarea.value.trim();

    if (!content) return;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> Saving…';

    const body = new URLSearchParams({
        edit_submit: '1',
        comment_id:  commentId,
        content:     content,
        csrf_token:  '<?= addslashes(csrfToken()) ?>',
    });

    try {
        const res  = await fetch(window.location.pathname, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        });
        const data = await res.json();

        if (data.success) {
            const target = document.getElementById('reply-content-' + commentId);
            if (target) {
                const d = document.createElement('div');
                d.textContent = data.content;
                target.innerHTML = d.innerHTML.replace(/\n/g, '<br>');
            }
            toggleEditForm(commentId);
            showToast('Comment updated.', 'success');
        } else {
            showToast(data.message || 'Failed to save changes.', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Network error. Please try again.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check"></i> Save';
    }
});

// Build reply HTML node from server response
function buildReplyHTML(data, parentName) {
    const wrapper = document.createElement('div');
    wrapper.className = 'reply-item reply-item--admin';
    wrapper.setAttribute('data-reply-id', data.reply_id);

    const escaped = (str) => {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    };

    const nl2br = (str) => escaped(str).replace(/\n/g, '<br>');

    wrapper.innerHTML = `
        <div class="reply-header">
            <div class="reply-meta">
                <strong class="reply-name">${escaped(data.name)}</strong>
                <span class="badge-admin">ADMIN</span>
                ${parentName ? `<span class="reply-to"><i class="fas fa-reply"></i> ${escaped(parentName)}</span>` : ''}
                <span class="reply-time">• Just Now</span>
            </div>
            <div class="reply-actions-inline">
                <a href="comments-manager.php?delete=${data.reply_id}&page=<?= $page ?>&filter=<?= urlencode($filter) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>&token=<?= urlencode(csrfToken()) ?>"
                   class="btn-delete-reply"
                   onclick="return confirm('Delete this reply?')"
                   title="Delete">
                    <i class="fas fa-times"></i>
                </a>
                <button type="button" class="btn-edit-reply" onclick="toggleEditForm(${data.reply_id})" title="Edit">
                    <i class="fas fa-pen"></i>
                </button>
            </div>
        </div>
        <div class="reply-content" id="reply-content-${data.reply_id}">${nl2br(data.content)}</div>
        <button class="btn-reply-link" onclick="toggleReplyForm(${data.reply_id})">
            <i class="fas fa-reply"></i> Reply
        </button>
        <div id="edit-form-${data.reply_id}" class="reply-box" style="display:none;">
            <form class="ajax-edit-form" data-comment-id="${data.reply_id}">
                <textarea name="content" class="reply-textarea" rows="2" required>${escaped(data.content)}</textarea>
                <div class="reply-form-actions">
                    <button type="button" class="btn-sm-cancel" onclick="toggleEditForm(${data.reply_id})">Cancel</button>
                    <button type="submit" class="btn-sm-submit"><i class="fas fa-check"></i> Save</button>
                </div>
            </form>
        </div>
        <div id="reply-form-${data.reply_id}" class="reply-box" style="display:none;">
            <form class="ajax-reply-form"
                  data-post-id="${data.post_id || ''}"
                  data-parent-id="${data.reply_id}"
                  data-parent-name="${escaped(data.name)}">
                <textarea name="content" class="reply-textarea" rows="2"
                          placeholder="Reply to ${escaped(data.name)}..." required></textarea>
                <div class="reply-form-actions">
                    <button type="button" class="btn-sm-cancel" onclick="toggleReplyForm(${data.reply_id})">Cancel</button>
                    <button type="submit" class="btn-sm-submit"><i class="fas fa-paper-plane"></i> Reply</button>
                </div>
            </form>
        </div>
    `;
    return wrapper;
}

// Walk up the DOM to find the root comment-card id
function findRootCommentId(parentId) {
    // Try to find the element, walk up to comment-card
    let el = document.querySelector('[data-reply-id="' + parentId + '"]');
    if (el) {
        const card = el.closest('.comment-card');
        if (card) return card.id.replace('comment-', '');
    }
    // Fallback: the parent IS the root
    return parentId;
}

// ── Auto-dismiss delete alert ──────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const alert = document.getElementById('deleteAlert');
    if (alert) {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            alert.style.opacity    = '0';
            alert.style.transform  = 'translateY(-5px)';
            setTimeout(() => alert.remove(), 400);
        }, 3000);
    }

    // Clean URL params
    const url = new URL(window.location.href);
    if (url.searchParams.has('msg')) {
        url.searchParams.delete('msg');
        window.history.replaceState({}, document.title, url.pathname + url.search);
    }
});

window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});

let touchStartX = 0, touchEndX = 0;
const sidebarEl = document.getElementById('sidebar');
sidebarEl.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, false);
sidebarEl.addEventListener('touchend', e => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchStartX - touchEndX > 100) toggleSidebar();
}, false);
</script>

</body>
</html>
