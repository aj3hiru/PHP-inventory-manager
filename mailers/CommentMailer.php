<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CommentMailer
{
    private $baseUrl = 'https://careerdiksha.co.in';

    private function mailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.example.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'you@example.com'; 
        $mail->Password   = 'your-smtp-password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->setFrom('you@example.com', 'CareerDiksha Discussions');
        $mail->addReplyTo('you@example.com', 'CareerDiksha Support');
        $mail->isHTML(true);
        return $mail;
    }

    private function generateSecureLink($email): string
    {
        if (!defined('SECRET_KEY')) define('SECRET_KEY', 'CD_v1_9x2Lq5Vm8Zp3Rs7Kw4Yn1Jt6Hb0Gf_SecureHash_2025');
        
        $encoded_email = str_replace(['+', '/'], ['-', '_'], base64_encode($email));
        $hash = hash_hmac('sha256', $email, SECRET_KEY);
        
        $params = [
            'auth_token' => bin2hex(random_bytes(8)),
            'd' => $encoded_email,
            'mode' => 'secure_verify',
            'h' => $hash,
            'ts' => time()
        ];

        return $this->baseUrl . '/api/ab36bsk27sbd27.php?' . http_build_query($params);
    }

    public function sendVerificationEmail($name, $email, $comment_content): void
    {
        $verifyLink = $this->generateSecureLink($email);
        $logoUrl = $this->baseUrl . '/assets/img/logo.webp';
        $currentYear = date('Y');
        $privacyUrl = $this->baseUrl . '/privacy-policy';
        $termsUrl = $this->baseUrl . '/terms-of-service';

        $mail = $this->mailer();
        $mail->Subject = 'Comment Discussions - CareerDiksha';
        $mail->addAddress($email);

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comment Verification</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f7fa; font-family:Arial, sans-serif; color:#333;">
    <div style="max-width:600px; margin:20px auto; background:#ffffff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden;">
        
        <div style="background:#ffffff; padding-bottom:5px; text-align:center; border-bottom:1px solid #e5e7eb;">
            <img src="$logoUrl" alt="CareerDiksha" style="height:80px; width:auto;">
        </div>

        <div style="padding:30px 25px;">
            <h2 style="color:#111827; font-size:20px; margin-top:0;">Verify Your Email</h2>
            <p style="font-size:15px; line-height:1.5; color:#4b5563;">
                Hi <strong>$name</strong>,<br><br>
                You recently posted a comment on CareerDiksha. To verify your identity and enable future discussions, please confirm your email address.
            </p>

            <div style="background:#f9fafb; border-left:4px solid #2563eb; padding:15px; margin:20px 0; font-style:italic; color:#555; font-size:14px;">
                "$comment_content"
            </div>

            <div style="text-align:center; margin-top:30px; margin-bottom:20px;">
                <a href="$verifyLink" style="background-color:#2563eb; color:#ffffff; padding:12px 28px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:15px; display:inline-block;">
                    Verify Email Address
                </a>
            </div>
            
            <p style="font-size:13px; color:#9ca3af; text-align:center;">
                If you didn't post this comment, you can safely ignore this email.
            </p>
        </div>

        <div style="background:#f9fafb; padding:15px; text-align:center; font-size:12px; color:#9ca3af; border-top:1px solid #e5e7eb;">
            <p style="margin:0 0 10px;">&copy; $currentYear CareerDiksha. All rights reserved.</p>
            <p style="margin:0;">
                <a href="$privacyUrl" style="color:#6b7280; text-decoration:none; margin:0 5px;">Privacy</a> | 
                <a href="$termsUrl" style="color:#6b7280; text-decoration:none; margin:0 5px;">Terms</a>
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        try {
            $mail->send();
        } catch (Exception $e) {
            error_log("Comment verification mail failed: " . $mail->ErrorInfo);
        }
    }
    
    public function sendReplyNotification($name, $email, $user_comment, $admin_reply, $postLink): void
    {
        $logoUrl = $this->baseUrl . '/assets/img/logo.webp';
        $currentYear = date('Y');
        $privacyUrl = $this->baseUrl . '/privacy-policy';
        $termsUrl = $this->baseUrl . '/terms-of-service';

        $mail = $this->mailer();
        $mail->Subject = 'Admin Replied to your Comment - CareerDiksha';
        $mail->addAddress($email);

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Reply</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f7fa; font-family:Arial, sans-serif; color:#333;">
    <div style="max-width:600px; margin:20px auto; background:#ffffff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden;">
        
        <div style="background:#ffffff; padding-bottom:5px; text-align:center; border-bottom:1px solid #e5e7eb;">
            <img src="$logoUrl" alt="CareerDiksha" style="height:80px; width:auto;">
        </div>

        <div style="padding:30px 25px;">
            <h2 style="color:#111827; font-size:20px; margin-top:0;">New Reply from CareerDiksha Support</h2>
            <p style="font-size:15px; line-height:1.5; color:#4b5563;">
                Hi <strong>$name</strong>,<br><br>
                Admin has replied to your comment on CareerDiksha.
            </p>

            <p style="font-size:12px; color:#999; margin-bottom:5px;">You wrote:</p>
            <div style="background:#f9fafb; border-left:4px solid #ccc; padding:12px; margin-bottom:20px; font-style:italic; color:#666; font-size:14px;">
                "$user_comment"
            </div>

            <p style="font-size:12px; color:#1e40af; margin-bottom:5px; font-weight:bold;">Admin Replied:</p>
            <div style="background:#eff6ff; border-left:4px solid #1e40af; padding:15px; margin-bottom:25px; color:#333; font-size:14px;">
                "$admin_reply"
            </div>

            <div style="text-align:center; margin-top:30px; margin-bottom:20px;">
                <a href="$postLink" style="background-color:#2563eb; color:#ffffff; padding:12px 28px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:15px; display:inline-block;">
                    View Post & Discussions
                </a>
            </div>
        </div>

        <div style="background:#f9fafb; padding:15px; text-align:center; font-size:12px; color:#9ca3af; border-top:1px solid #e5e7eb;">
            <p style="margin:0 0 10px;">&copy; $currentYear CareerDiksha. All rights reserved.</p>
            <p style="margin:0;">
                <a href="$privacyUrl" style="color:#6b7280; text-decoration:none; margin:0 5px;">Privacy</a> | 
                <a href="$termsUrl" style="color:#6b7280; text-decoration:none; margin:0 5px;">Terms</a>
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        try {
            $mail->send();
        } catch (Exception $e) {
            error_log("Reply notification mail failed: " . $mail->ErrorInfo);
        }
    }
    

    public function sendUserReplyNotification($parentName, $parentEmail, $parentContent, $replyName, $replyContent, $postTitle, $postLink): void
    {
        $logoUrl = $this->baseUrl . '/assets/img/logo.webp';
        $currentYear = date('Y');
        $privacyUrl = $this->baseUrl . '/privacy-policy';
        $termsUrl = $this->baseUrl . '/terms-of-service';

        $mail = $this->mailer();
        $mail->Subject = "Someone Replied your Comment on CareerDiksha";
        $mail->addAddress($parentEmail);

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Reply</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f7fa; font-family:Arial, sans-serif; color:#333;">
    <div style="max-width:600px; margin:20px auto; background:#ffffff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden;">
        
        <div style="background:#ffffff; padding-bottom:5px; text-align:center; border-bottom:1px solid #e5e7eb;">
            <img src="$logoUrl" alt="CareerDiksha" style="height:80px; width:auto;">
        </div>

        <div style="padding:30px 25px;">
            <h2 style="color:#111827; font-size:18px; margin-top:0;">New Reply on <a href="$postLink" style="color:#2563eb; text-decoration:none;">$postTitle</a></h2>
            <p style="font-size:15px; line-height:1.5; color:#4b5563;">
                Hi <strong>$parentName</strong>,<br>
                <strong>$replyName</strong> just replied to your comment.
            </p>

            <div style="margin-top:25px;">
                
                <div style="margin-bottom:10px;">
                    <p style="font-size:12px; color:#6b7280; margin:0 0 4px; font-weight:600;">You wrote:</p>
                    <div style="background:#f3f4f6; border-left:4px solid #9ca3af; padding:12px 15px; border-radius:4px; font-style:italic; color:#4b5563; font-size:14px;">
                        "$parentContent"
                    </div>
                </div>

                <div style="padding-left:20px; color:#9ca3af; font-size:18px; line-height:1;">
                    &#8627;
                </div>

                <div style="margin-top:5px; margin-bottom:25px;">
                    <p style="font-size:12px; color:#2563eb; margin:0 0 4px; font-weight:600;">$replyName replied:</p>
                    <div style="background:#eff6ff; border-left:4px solid #2563eb; padding:12px 15px; border-radius:4px; color:#1e3a8a; font-size:14px;">
                        "$replyContent"
                    </div>
                </div>

            </div>

            <div style="text-align:center; margin-top:30px; margin-bottom:20px;">
                <a href="$postLink" style="background-color:#2563eb; color:#ffffff; padding:12px 28px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:15px; display:inline-block;">
                    Reply to Discussion
                </a>
            </div>
        </div>

        <div style="background:#f9fafb; padding:15px; text-align:center; font-size:12px; color:#9ca3af; border-top:1px solid #e5e7eb;">
            <p style="margin:0 0 10px;">&copy; $currentYear CareerDiksha. All rights reserved.</p>
            <p style="margin:0;">
                <a href="$privacyUrl" style="color:#6b7280; text-decoration:none; margin:0 5px;">Privacy</a> | 
                <a href="$termsUrl" style="color:#6b7280; text-decoration:none; margin:0 5px;">Terms</a>
            </p>
        </div>
    </div>
</body>
</html>
HTML;

        try {
            $mail->send();
        } catch (Exception $e) {
            error_log("User reply notification mail failed: " . $mail->ErrorInfo);
        }
    }  
    
}
?>
