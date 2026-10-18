<?php
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/session-cookie.php';

// Helper function — clean inputs, remove header injections, limit length
function clean_field(string $input, int $maxLength = 500): string {
    $s = trim($input);
    $s = str_replace("\0", '', $s);
    $s = preg_replace("/[\r\n]+/", ' ', $s); // prevent header injection
    $s = preg_replace("/\s+/", ' ', $s);
    return mb_substr($s, 0, $maxLength);
}

// Accept only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (!hash_equals($_SESSION['cTkn'] ?? '', $_POST['cTkn'] ?? '')) {
 http_response_code(403); exit('Invalid CSRF token');
}

// Collect & sanitize
$nameRaw    = $_POST['name'] ?? '';
$emailRaw   = $_POST['email'] ?? '';
$subjectRaw = $_POST['subject'] ?? '';
$messageRaw = $_POST['message'] ?? '';

$name    = clean_field($nameRaw, 100);
$subject = clean_field($subjectRaw, 150);

// Validate email strictly
$emailCandidate = trim(str_replace("\0", '', $emailRaw));
$emailCandidate = preg_replace("/[\r\n]+/", '', $emailCandidate);
if (!filter_var($emailCandidate, FILTER_VALIDATE_EMAIL) || mb_strlen($emailCandidate) > 254) {
    http_response_code(400);
    exit('Invalid email address.');
}
$email = $emailCandidate;

// Message cleanup
$message = trim($messageRaw);
$message = str_replace("\0", '', $message);
if (mb_strlen($message) > 10000) {
    http_response_code(400);
    exit('Message too long.');
}
if ($name === '' || $subject === '' || $message === '') {
    http_response_code(400);
    exit('All fields are required.');
}

// Basic rate limiting (1 submission / 20 sec)
$throttleSeconds = 20;
$lastSent = $_SESSION['contact_last_send'] ?? 0;
if (time() - $lastSent < $throttleSeconds) {
    http_response_code(429);
    exit('Too many requests. Please wait a few minutes.');
}

// Escape safely for HTML body
$escName    = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escEmail   = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

// Construct message
$htmlBody = <<<HTML
<h3>New Message from Contact Form</h3>
<p><strong>Name:</strong> {$escName}</p>
<p><strong>Email:</strong> {$escEmail}</p>
<p><strong>Subject:</strong> {$escSubject}</p>
<p><strong>Message:</strong><br>{$escMessage}</p>
<hr>
<p style="font-size:12px;color:#666;">Sent via <a href="https://careerdiksha.co.in">CareerDiksha.co.in</a></p>
HTML;

$altBody = "Name: {$name}\nEmail: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}";

// Initialize PHPMailer
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
$mail->Host       = 'smtp.example.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'you@example.com';
$mail->Password   = 'your-smtp-password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
$mail->Port       = 465;
$mail->CharSet  = 'UTF-8';
$mail->Encoding = 'base64';                     

    // HEADERS
    $mail->setFrom('you@example.com', 'CareerDiksha Contact Form');
    $mail->addAddress('you@example.com', 'CareerDiksha Contact');
    $mail->addReplyTo($email, $name);

    // CONTENT
    $mail->isHTML(true);
    $mail->Subject = "New Contact Form Message: " . $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $altBody;

    // Send mail
    $mail->send();
    $_SESSION['contact_last_send'] = time();
    http_response_code(200);
    echo 'Message sent successfully!';
} catch (Exception $e) {
    // Log full details to server error log
    error_log('Contact form mail error: ' . $e->getMessage() . ' | ' . $mail->ErrorInfo);
    http_response_code(500);
    echo 'Internal Server Error';
}
?>