<?php
require_once __DIR__ . '/../includes/config.php';

$type = $_GET['type'] ?? '';

$subject = '';
$message = '';

switch ($type) {

    case 'bug':
        $subject = 'Bug Report';
        $message = "Please describe the bug you encountered.\n\n"
                 . "Steps to reproduce:\n1.\n2.\n3.\n\n"
                 . "Expected behavior:\n"
                 . "Actual behavior:";
        break;

    case 'idea':
        $subject = 'New Idea / Feature Request';
        $message = "Please describe your idea in detail.\n\n"
                 . "Problem it solves:\n"
                 . "How it should work:\n"
                 . "Target users:\n"
                 . "Additional notes:";
        break;
}

if (!empty($_GET['ctSub'])) {
    $subject = $_GET['ctSub'];
}

if (!empty($_GET['ctMsg'])) {
    $message = $_GET['ctMsg'];
}

$subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');





$seo = [
    'title'       => 'Contact Us | ' . SITE_NAME,
    'description' => 'Get in touch with ' . SITE_NAME . '. We\'re here to help with any questions, feedback, or support you need.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];



$cTkn = $_SESSION['cTkn'] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
  
  <meta name="description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
  
  <meta name="robots" content="<?= htmlspecialchars($seo['robots'], ENT_QUOTES, 'UTF-8') ?>">
  	
<link rel="canonical" href="<?= SITE_URL ?>/contact-us">

  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as="style" onload="this.onload=null; this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel="stylesheet"></noscript>
  
  
  <style type="text/css">body{font-family:'Nunito',sans-serif;background-color:#f9f9f9;color:#333}*{margin:0;padding:0;box-sizing:border-box}.container{max-width:900px;margin:20px auto;padding:24px;background-color:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05)}h1{font-size:1.8rem;font-weight:800;text-align:center;margin-bottom:10px;color:#004aad}h2{font-size:1.4rem;font-weight:700;color:#004aad;border-bottom:2px solid #f0f0f0;padding-bottom:8px;margin-top:20px;margin-bottom:16px}p,.contact-method{font-size:1rem;line-height:1.7;margin-bottom:20px}.contact-method strong{display:block;font-size:1.1rem;color:#111;margin-bottom:5px}.contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.social-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:15px;margin-bottom:30px}.social-btn{display:flex;align-items:center;justify-content:center;padding:12px;border-radius:6px;text-decoration:none;font-weight:700;color:#fff;transition:opacity .2s;font-size:.95rem}.social-btn:hover{opacity:.9;text-decoration:none}.bg-wa{background-color:#198D44}.bg-tg{background-color:#0088cc}.bg-li{background-color:#0077b5}.bg-ig{background-color:#E1306C}.bg-yt{background-color:#FF0000}.bg-x{background-color:#000}@media(max-width:600px){.contact-grid{grid-template-columns:1fr}}.c7{text-decoration:underline;color:#004aad;font-weight:600}.c7:hover{text-decoration:none}.contact-form .form-group{margin-bottom:15px}.contact-form label{display:block;font-weight:600;margin-bottom:5px}.contact-form input[type=text],.contact-form input[type=email],.contact-form textarea{width:100%;padding:10px;font-family:'Nunito',sans-serif;font-size:1rem;border:1px solid #ddd;border-radius:4px}.contact-form textarea{min-height:120px;resize:vertical}.contact-form .submit-btn{background-color:#004aad;color:#fff;padding:12px 20px;border:none;border-radius:4px;font-size:1rem;font-weight:700;cursor:pointer;transition:background-color .2s}.contact-form .submit-btn:hover{background-color:#003d8a}</style>
  
  
  
   <script type="application/ld+json">
<?= json_encode([
    '@context'   => 'https://schema.org',
    '@type'      => 'ContactPage',
    'name'       => $seo['title'],
    'description'=> $seo['description'],
    'url'        => SITE_URL . '/contact-us',
    'mainEntity' => [
        '@type' => 'Organization',
        'name'  => SITE_NAME,
        'url'   => SITE_URL,
        'logo'  => SITE_LOGO,
        'contactPoint' => [
            '@type'             => 'ContactPoint',
            'contactType'       => 'Customer Support',
            'email'             => CONTACT_EMAIL,
            'availableLanguage' => ['en', 'hi'],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>



</head>

<body>
	<main>
  <div class="container">
    <h1>Contact Us</h1>
     
    <p>We're here to help! If you have any questions, feedback, or suggestions regarding our job listings, or if you've found an error, please let us know. We value your input and strive to respond within 48 hours.</p>

    <div class="contact-grid">
      <div class="contact-method">
        <h2>Email Us</h2>
        <strong>General Inquiries & Support</strong>
        <p>For any general questions, content recommendations, or support, please email us directly. This is the best way to reach us for non-urgent matters.</p>
        <a href="mailto:<?= CONTACT_EMAIL ?>" class="c7"><?= CONTACT_EMAIL ?></a>
      </div>

<!--
      <div class="contact-method">
        <h2>Call Us (Grievance Officer)</h2>
        <strong>Content Manager & Grievance Officer</strong>
        <p>For urgent matters related to content accuracy or to file a grievance, you can contact our Grievance Officer, Vikash Dhaker.</p>
        <p><strong>Phone:</strong> <?php echo $phone_number; ?><br>
           <strong>Hours:</strong> Mon-Fri, 9:00 AM - 6:00 PM (IST)
        </p>
      </div>
    </div>
    
    
    <h2>Connect on Social Media</h2>
    <p>For the fastest updates on new government jobs, admit cards, and results, join our communities. It's often faster than email!</p>
    
    <div class="social-grid">
        <a href="https://whatsapp.com/channel/0029VasVjh8GOj9v3DgoWa3V" target="_blank" class="social-btn bg-wa">Join WhatsApp</a>
        <a href="https://t.me/careerjyoti1" target="_blank" class="social-btn bg-tg">Join Telegram</a>
        <a href="https://www.instagram.com/careerdiksha" target="_blank" class="social-btn bg-ig">Follow Instagram</a>
        <a href="https://www.linkedin.com/in/careerdiksha/" target="_blank" class="social-btn bg-li">Connect LinkedIn</a>
        <a href="https://x.com/careerdiksha" target="_blank" class="social-btn bg-x">Follow on X</a>
    </div> -->

<div id="ct-form" class="ct-form">
    <h2>Send Us a Message</h2>
    <p>You can also fill out the form below to send us a message directly from this page.</p>

    <form id="contactForm" class="contact-form" aria-label="Contact Form">
  <input type="hidden" name="cTkn" value="<?= $cTkn ?>">
  <div class="form-group"><label for="name">Your Name</label><input type="text" id="name" name="name" required></div>
  <div class="form-group"><label for="email">Your Email</label><input type="email" id="email" name="email" required></div>
  <div class="form-group"><label for="subject">Subject</label><input type="text" id="subject" name="subject" value="<?= $subject ?>" required></div>
  <div class="form-group"><label for="message">Message</label><textarea id="message" name="message" rows="5" required><?= $message ?></textarea></div>
  <button type="submit" class="submit-btn">Send Message</button>
</form>
<div id="formResponse" style="margin-top:15px;padding:12px;border-radius:4px;font-size:1rem;text-align:center;min-height:20px"></div>
</div>

<script>document.getElementById("contactForm").addEventListener("submit",(async function(e){e.preventDefault();const t=e.target,a=new FormData(t),n=document.getElementById("formResponse"),o=t.querySelector(".submit-btn"),s=o.innerHTML;n.innerHTML="",n.className="",o.disabled=!0,o.innerHTML="Sending...";try{const e=await fetch("/api/2c4a5d7f8h.php",{method:"POST",body:a}),i=await e.text();e.ok?(n.innerHTML=`<p style="color:green;font-weight:600;">${i}</p>`,t.reset()):(n.innerHTML=`<p style="color:red;font-weight:600;">${i}</p>`)}catch(e){n.innerHTML='<p style="color:red;font-weight:600;">Connection failed. Please try again.</p>'}finally{o.disabled=!1,o.innerHTML=s}}));</script>

    <h2>Important Disclaimer</h2>
    <p><?= SITE_NAME ?> is an information portal. We do not offer individual career advice, resume services, or act as a recruitment agency. All job applications must be submitted through the official government or employer portals linked in our posts.</p>
    <p>Any personal information you provide is handled accordingto our <a href="<?= SITE_URL ?>/privacy-policy" class="c7" target="_blank">Privacy Policy</a>.</p>
  </div>
  </main>
</body>
</html>