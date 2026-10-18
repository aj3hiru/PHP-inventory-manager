<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

if (isset($_SESSION['user_id'])) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

        $log_sql = "INSERT INTO activity_logs 
            (user_id, action_type, description, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)";

        $stmt_log = $pdo->prepare($log_sql);
        $stmt_log->execute([
            $_SESSION['user_id'],
            'logout',
            "User {$_SESSION['username']} logged out successfully",
            $ip_address,
            $user_agent
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Location: /shop/login.php");
exit;
?>