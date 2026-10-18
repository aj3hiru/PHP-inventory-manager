<?php
// Staff login for the store panel (owner, managers, billing staff — accounts in the `users` table).
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");

define('ADMIN_DASHBOARD', '/admin/dashboard.php');

if (isset($_SESSION['user_id'], $_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor', 'author'], true)) {
    header("Location: " . ADMIN_DASHBOARD);
    exit;
}

$ip_address = $_SERVER['REMOTE_ADDR']     ?? 'UNKNOWN';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

// ── Brute-force lockout (session-based) ─────────────────────────────────────
const MAX_ATTEMPTS = 5;
const LOCKOUT_SECS = 900; // 15 min

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['lockout_until']))  $_SESSION['lockout_until']  = 0;

$locked    = $_SESSION['lockout_until'] > time();
$lock_left = $locked ? $_SESSION['lockout_until'] - time() : 0;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    if (!csrfValid()) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($identity === '' || $password === '') {
            $error = 'Please enter your username/email and password.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, username, email, password_hash, role, status, permissions
                 FROM users WHERE (username = ? OR email = ?) AND role IN ('admin','editor','author') LIMIT 1"
            );
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $dummy_hash = '$2y$10$invaliddummyhashXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
            $pass_ok = password_verify($password, $user['password_hash'] ?? $dummy_hash);

            if ($user && $pass_ok) {
                $log = $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                if ($user['status'] === 'suspended') {
                    $error = 'Your account has been suspended. Contact the store owner.';
                    $log->execute([$user['id'], 'login_blocked', "Suspended account login attempt: {$user['username']}", $ip_address, $user_agent]);
                } elseif ($user['status'] === 'pending') {
                    $error = 'Your account is waiting for approval.';
                    $log->execute([$user['id'], 'login_blocked', "Pending account login attempt: {$user['username']}", $ip_address, $user_agent]);
                } else {
                    $permissions = json_decode($user['permissions'] ?? '{}', true);
                    if (empty($permissions['dashboard_access'])) {
                        $error = 'You do not have permission to open the store panel.';
                        $log->execute([$user['id'], 'login_denied', "No dashboard_access: {$user['username']}", $ip_address, $user_agent]);
                    } else {
                        session_regenerate_id(true); // prevent session fixation
                        $_SESSION['login_attempts'] = 0;
                        $_SESSION['lockout_until']  = 0;
                        $_SESSION['user_id']    = $user['id'];
                        $_SESSION['username']   = $user['username'];
                        $_SESSION['email']      = $user['email'];
                        $_SESSION['role']       = $user['role'];
                        $_SESSION['login_time'] = time();
                        $log->execute([$user['id'], 'login_success', "Logged in as {$user['role']}: {$user['username']}", $ip_address, $user_agent]);
                        header("Location: " . ADMIN_DASHBOARD);
                        exit;
                    }
                }
            } else {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                    $_SESSION['lockout_until'] = time() + LOCKOUT_SECS;
                    $locked = true;
                    $lock_left = LOCKOUT_SECS;
                } else {
                    $error = 'Incorrect username/email or password.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Login - <?= htmlspecialchars($site_name) ?></title>
<link rel="icon" href="/assets/icons/favicon-32x32.png">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:#f3f4f6;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#111827}
.box{width:100%;max-width:380px;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:28px}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.brand i{width:40px;height:40px;border-radius:10px;background:#4361ee;display:flex;align-items:center;justify-content:center;color:#fff;font-style:normal;font-weight:700}
.brand b{font-size:18px}
h1{font-size:20px;margin:0 0 4px}
p.sub{margin:0 0 20px;color:#6b7280;font-size:14px}
label{display:block;font-size:13px;font-weight:600;margin:0 0 6px}
input[type=text],input[type=password]{width:100%;padding:11px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;margin-bottom:14px}
input:focus{outline:none;border-color:#4361ee;box-shadow:0 0 0 3px rgba(67,97,238,.15)}
button{width:100%;padding:12px;border:0;border-radius:8px;background:#4361ee;color:#fff;font-size:15px;font-weight:600;cursor:pointer}
button:hover{background:#3651d4}
.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-size:14px;margin-bottom:14px}
</style>
</head>
<body>
<div class="box">
    <div class="brand"><i><?= htmlspecialchars(strtoupper(substr($site_name, 0, 1))) ?></i><b><?= htmlspecialchars($site_name) ?></b></div>
    <h1>Store login</h1>
    <p class="sub">Sign in to manage stock, billing and customers.</p>

    <?php if ($locked): ?>
    <div class="err">Too many failed attempts. Please try again in <?= ceil($lock_left / 60) ?> minute(s).</div>
    <?php elseif ($error): ?>
    <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$locked): ?>
    <form method="POST" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <label for="identity">Username or email</label>
        <input type="text" id="identity" name="identity" required autofocus autocomplete="username">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="submit">Login</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
