<?php
// Creates (or resets) the store owner account. Run from the command line only:
//   php install/create-admin.php <username> <email> <password>
// Then log in at /admin/login.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc < 4) { fwrite(STDERR, "Usage: php install/create-admin.php <username> <email> <password>\n"); exit(1); }
[$_, $user, $email, $pass] = $argv;
if (strlen($pass) < 8) { fwrite(STDERR, "Password must be at least 8 characters.\n"); exit(1); }

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require __DIR__ . '/../includes/config.php';

// Owner account: every permission switch on (see includes/permissions.php).
require __DIR__ . '/../includes/permissions.php';
$permsJson = json_encode(store_role_preset('admin'));

$hash = password_hash($pass, PASSWORD_DEFAULT);
$st = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, permissions)
                     VALUES (?, ?, ?, 'admin', 'active', ?)
                     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin', status = 'active', permissions = VALUES(permissions)");
$st->execute([$user, $email, $hash, $permsJson]);
echo "Owner account '$user' is ready. Log in at /admin/login.php\n";
