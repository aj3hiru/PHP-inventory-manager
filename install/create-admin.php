<?php
// Creates (or resets) the first admin account. Run from the command line only:
//   php install/create-admin.php <username> <email> <password>
// Then log in at /login (the admin panel is at /admin/dashboard.php).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc < 4) { fwrite(STDERR, "Usage: php install/create-admin.php <username> <email> <password>\n"); exit(1); }
[$_, $user, $email, $pass] = $argv;
if (strlen($pass) < 8) { fwrite(STDERR, "Password must be at least 8 characters.\n"); exit(1); }

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require __DIR__ . '/../includes/config.php';

// Full admin permissions: the same list the User Manager uses (admin/user-manager.php), every switch on.
$src = file_get_contents(__DIR__ . '/../admin/user-manager.php');
if (!preg_match("/define\\('DEFAULT_PERMISSIONS',\\s*json_encode\\((\\[.*?\\])\\)\\);/s", $src, $m)) { fwrite(STDERR, "Could not read DEFAULT_PERMISSIONS\n"); exit(1); }
$perms = eval('return ' . $m[1] . ';');
array_walk_recursive($perms, function (&$v) { $v = true; });
$permsJson = json_encode($perms);

$hash = password_hash($pass, PASSWORD_DEFAULT);
$st = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, permissions)
                     VALUES (?, ?, ?, 'admin', 'active', ?)
                     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin', status = 'active', permissions = VALUES(permissions)");
$st->execute([$user, $email, $hash, $permsJson]);
echo "Admin '$user' is ready. Log in at /login\n";
