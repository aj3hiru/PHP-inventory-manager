<?php
// api/verify_email.php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

if (!defined('SECRET_KEY')) define('SECRET_KEY', 'CD_v1_9x2Lq5Vm8Zp3Rs7Kw4Yn1Jt6Hb0Gf_SecureHash_2025');

$data = $_GET['d'] ?? '';
$hash = $_GET['h'] ?? '';

if (!$data || !$hash) {
    die("Invalid Request.");
}
$email = base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
$verify_hash = hash_hmac('sha256', $email, SECRET_KEY);

if (!hash_equals($verify_hash, $hash)) {
    die("Security Check Failed: Invalid Token.");
}

try {
    $stmt = $pdo->prepare("UPDATE comments SET evf = 1 WHERE email = ?");
    $stmt->execute([$email]);

    echo '<div style="font-family:sans-serif; text-align:center; margin-top:50px;">
            <h1 style="color:green;">Email Verified Successfully!</h1>
            <p>Thank you. Your email <b>'.htmlspecialchars($email).'</b> has been verified for all comments.</p>
            <a href="/" style="background:#2563eb; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;">Go back to Home</a>
          </div>';

} catch (Exception $e) {
    die("Error verifying email.");
}
?>
