<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

// Same auth pattern used by comments-manager.php and the rest of the admin panel.
if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}
$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user        = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);
if (!$user || $user['status'] !== 'active' || empty($permissions['blogs']['manage_comments'])) {
    exit('Access Denied');
}

header('Content-Type: text/html; charset=utf-8');
echo "<h3>Running Comment Moderation (status column) Migration...</h3>";

try {
    // 1. Add the column only if it doesn't already exist (safe to run more than once).
    $col = $pdo->query("SHOW COLUMNS FROM comments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);

    if (!$col) {
        // Default is 'approved' so every comment that already exists today
        // (all of which are currently live on the site) keeps showing exactly
        // as before — nothing disappears because of this migration.
        $pdo->exec("
            ALTER TABLE comments
            ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER evf
        ");
        echo "<p style='color:green;font-weight:bold;'>Added `status` column to `comments` (existing rows backfilled as 'approved').</p>";
    } else {
        echo "<p>Column `status` already exists — nothing to do.</p>";
    }

    // 2. Small supporting index so the Pending/Approved filter tabs and the
    //    counts in the Comments Manager stay fast as the table grows.
    $idx = $pdo->query("SHOW INDEX FROM comments WHERE Key_name = 'idx_comments_status'")->fetch(PDO::FETCH_ASSOC);
    if (!$idx) {
        $pdo->exec("ALTER TABLE comments ADD INDEX idx_comments_status (status)");
        echo "<p>Added index on `status` for faster filtering.</p>";
    }

    $pdo->query("SELECT status FROM comments LIMIT 1");
    echo "<p style='color:green;font-weight:bold;'>Success! Comments table is ready for moderation (Pending/Approved).</p>";
    echo "<p>Go to <a href='/admin/blog/comments-manager.php'>Comments Manager</a> — the new Pending/Approved tabs and search box should now work.</p>";
    echo "<p style='color:#92400e;'>Note: this only prepares the database. New comments will only start landing in \"Pending\" once the front-end submit endpoint (api/a1b2c3d4e5.php) is updated to set that status — see the notes that came with this file.</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;font-weight:bold;'>Migration failed:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
