<?php
header('Content-Type: application/json');
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';
// require_once $_SERVER['DOCUMENT_ROOT'] . '/mailers/CommentMailer.php';

if (!hash_equals($_SESSION['cTkn'] ?? '', $_POST['cTkn'] ?? '')) {
    http_response_code(403);
    exit('Bad token');
}


$now = time();
$window = 120;  // 2 minutes
$max = 3;       // max 3 comments per window

// Clean old entries
$_SESSION['comment_times'] = array_filter(
    $_SESSION['comment_times'] ?? [],
    fn($t) => ($now - $t) < $window
);

if (count($_SESSION['comment_times']) >= $max) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many comments. Please slow down.']);
    exit;
}

// Log this submission
$_SESSION['comment_times'][] = $now;


$ADMIN_EMAIL = 'contact@edumint24.com';

$id = $_GET['id'] ?? 0;
if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Post ID required']);
    exit;
}

try {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if (empty($name) || empty($email) || empty($content)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit;
    }
    
    
    if (strlen($content) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Comment too long']);
        exit;
    }
    if (strlen($name) > 60) {
        echo json_encode(['success' => false, 'message' => 'Name too long']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM posts WHERE id = ? AND status = 'published'");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Post not found']);
        exit;
    }

    $stmtCheck = $pdo->prepare("SELECT evf FROM comments WHERE email = ? AND evf = 1 LIMIT 1");
    $stmtCheck->execute([$email]);
    $is_already_verified = $stmtCheck->fetch() ? 1 : 0;

    // Moderation status: a previously-verified email keeps posting live
    // (unchanged behaviour). Everyone else now lands as "pending" so it
    // shows up in the Comments Manager for review instead of going live
    // instantly. Falls back gracefully to the old always-live behaviour
    // if the `status` column hasn't been added yet (see admin/exc_fn/
    // run_comment_status_migration.php).
    $initial_status = $is_already_verified ? 'approved' : 'pending';
    try {
        $stmt = $pdo->prepare("INSERT INTO comments (name, email, content, post_id, parent_id, date, evf, status) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
        $stmt->execute([$name, $email, $content, $id, $parent_id, $is_already_verified, $initial_status]);
    } catch (PDOException $e) {
        // `status` column not migrated yet on this install — insert without it.
        $stmt = $pdo->prepare("INSERT INTO comments (name, email, content, post_id, parent_id, date, evf) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->execute([$name, $email, $content, $id, $parent_id, $is_already_verified]);
    }
    $comment_id = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $new_comment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $parent_name = null;
    if ($parent_id) {
        $stmt = $pdo->prepare("SELECT name, email FROM comments WHERE id = ?");
        $stmt->execute([$parent_id]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        
    $parent_name  = $parent['name']  ?? null;
    $parent_email = $parent['email'] ?? null;  
        $should_send_notification = true;
       
        if ($parent_email && strtolower($parent_email) === strtolower($ADMIN_EMAIL)) {
            $should_send_notification = false;
        }


/*
        if ($parent && $should_send_notification) {
            $stmtP = $pdo->prepare("
                SELECT c.name, c.email, c.content, c.evf, p.title, p.slug 
                FROM comments c
                JOIN posts p ON c.post_id = p.id
                WHERE c.id = ?
            ");
            $stmtP->execute([$parent_id]);
            $parentData = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($parentData && $parentData['evf'] == 1 && $email !== $parentData['email']) {
                try {
                    $postLinkRelative = postUrl($parentData['slug'], $id);
                    $fullPostLink = 'https://careerdiksha.co.in/' . ltrim($postLinkRelative, '/');
                    
                    $mailer = new CommentMailer();
                    $mailer->sendUserReplyNotification(
                        $parentData['name'],
                        $parentData['email'],
                        $parentData['content'],
                        $name,
                        $content,
                        $parentData['title'],
                        $fullPostLink
                    );
                } catch (Exception $e) {
                    error_log("Failed to send reply notification: " . $e->getMessage());
                }
            }
        } */
    }

    $verification_email_sent = false;

/*    if ($is_already_verified === 0) {
        try {
            $mailer = new CommentMailer();
            $mailer->sendVerificationEmail($name, $email, $content);
            $verification_email_sent = true;
        } catch (Exception $mailError) {
            error_log("Non-fatal error: Comment verification email failed: " . $mailError->getMessage());
        }
    } */
    
    echo json_encode([
        'success' => true,
        'message' => 'Comment posted successfully',
        'comment' => $new_comment,
        'parent_name' => $parent_name,
        'verification_sent' => $verification_email_sent
    ]);
    
} catch (Exception $e) {
    error_log('Comment submission critical error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to post comment']);
}
?>