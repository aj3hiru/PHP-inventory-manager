<?php
require_once __DIR__ . '/../includes/config.php';
$last_updated = "December 24, 2025";

$seo = [
    'title'       => 'Privacy Policy | ' . SITE_NAME,
    'description' => 'Read the Privacy Policy for ' . SITE_NAME . '. Learn how we collect, use, and protect your personal data in compliance with India\'s DPDP Act and IT Act.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
  
  <meta name="description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
  
  <meta name="robots" content="<?= htmlspecialchars($seo['robots'], ENT_QUOTES, 'UTF-8') ?>">
  	
<link rel="canonical" href="<?= SITE_URL ?>/privacy-policy">
  
  
  <link rel=preload href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as=style crossorigin onload="this.onload=null;this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel=stylesheet></noscript>
  
  
<style type="text/css">body{font-family:'Nunito',sans-serif;background-color:#f9f9f9;color:#333}*{margin:0;padding:0;box-sizing:border-box}.container{max-width:900px;margin:20px auto;padding:24px;background-color:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05)}h1{font-size:1.8rem;font-weight:800;text-align:center;margin-bottom:10px;color:#004aad}.last-updated{text-align:center;font-size:.9rem;color:#777;margin-bottom:24px}h2{font-size:1.4rem;font-weight:700;color:#004aad;border-bottom:2px solid #f0f0f0;padding-bottom:8px;margin-top:20px;margin-bottom:16px}p,li{font-size:1rem;line-height:1.7;margin-bottom:15px}ol{list-style-type:lower-alpha;padding-left:30px}ol li{margin-bottom:15px}strong{font-weight:700;color:#111}.c7{text-decoration:underline;color:#004aad;font-weight:600}.c7:hover{text-decoration:none}</style> 


  <script type="application/ld+json">
<?= json_encode([
    '@context'   => 'https://schema.org',
    '@type'      => 'WebPage',
    'name'       => $seo['title'],
    'description'=> $seo['description'],
    'url'        => SITE_URL . '/privacy-policy',
    'mainEntity' => [
        '@type' => 'CreativeWork',
        'name'  => 'Privacy Policy',
    ],
    'publisher'  => [
        '@type' => 'Organization',
        'name'  => SITE_NAME,
        'url'   => SITE_URL,
        'logo'  => SITE_LOGO,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>

</head>
<body class="c4">
	<main role="main">
  <div class="container">
    <h1>Privacy Policy for <?= SITE_NAME ?></h1>
    <p class="last-updated"><strong>Last Updated: <?php echo $last_updated; ?></strong></p>
    
    <h2>1. Introduction</h2>
    <ol>
      <li><?= SITE_NAME ?> ("we", "us", or "our") respects your privacy and is committed to protecting your personal data. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website <?= SITE_URL ?> ("Site"), which provides updates on government jobs, PSU updates, private job openings, and bank jobs. It is governed by the Information Technology Act, 2000, the Digital Personal Data Protection Act, 2023 ("DPDP Act"), and other applicable Indian laws.</li>
      <li>By using the Site, you consent to the practices described in this Privacy Policy. If you do not agree, please do not use the Site.</li>
    </ol>
    
    <h2>2. Information We Collect</h2>
    <ol>
      <li><strong>User Comments & Verification Data:</strong> When you leave a comment, we collect your <strong>Name</strong> and <strong>Email Address</strong>. We verify this email via an OTP or verification link to ensure it is legitimate. This data is treated as User Generated Content (UGC).</li>
      <li><strong>Newsletter Subscription Data:</strong> If you voluntarily subscribe to our newsletter via our in-house form, we collect your email address to send you job updates.</li>
      <li><strong>Contact Form Data:</strong> When you use our "Contact Us" form, we collect your <strong>Name</strong>, <strong>Email Address</strong>, <strong>Subject</strong>, and <strong>Message</strong>. This data is forwarded to our internal email system solely for the purpose of reading and replying to your specific query.</li>
      <li><strong>Push Notification Data:</strong> We use an in-house script and Google Firebase to provide push notifications. This collects technical data such as your <strong>Device Token</strong>, and stores preferences in <strong>Cookies</strong> and <strong>Local Storage</strong>. We do not collect personal names or emails through this specific script.</li>
      <li><strong>Job Application Data:</strong> If you submit resumes or job-related details through specific forms on our Site, we collect that data solely for informational purposes and do not store or share it without consent.</li>
      <li><strong>Non-Personal Information:</strong> We collect usage data like IP address, browser type, pages visited, and time spent via analytics tools (e.g., Google Analytics) to improve our 51+ job categories.</li>
    </ol>
    
    <h2>3. How We Use Your Information</h2>
    <ol>
      <li><strong>Service-Related Communications (Mandatory):</strong> Your verified email address provided in comments is used to send "Service Notifications." These include admin replies, discussion updates, and thread notifications. As these are transactional and integral to the user discussion system, you cannot unsubscribe from these specific alerts unless the comment itself is removed.</li>
      <li><strong>Marketing Communications (Optional):</strong> Emails collected for the Newsletter are used to send weekly job alerts, welcome emails, or unsubscribe confirmations. You may unsubscribe from these at any time.</li>
      <li><strong>Push Delivery:</strong> Data in Local Storage/Cookies is used by our algorithm to manage your subscription state and deliver relevant notifications without collecting personal identifiers.</li>
      <li><strong>General Usage:</strong> To improve Services, respond to inquiries, analyze Site usage, and comply with legal obligations under the DPDP Act and IT Act.</li>
    </ol>
    
    <h2>4. Sharing Your Information</h2>
    <ol>
      <li>We do not sell your personal data. We may share it with service providers (e.g., email delivery services, Google Firebase) under strict confidentiality solely to operate our systems.</li>
      <li>In case of merger or acquisition, data may be transferred with prior notice.</li>
      <li>We may disclose information if required by law, court order, or government regulation.</li>
    </ol>
    
    <h2>5. Cookies and Tracking Technologies</h2>
    <ol>
      <li><strong>Essential & Functional:</strong> We use cookies and Local Storage to manage your Push Notification subscription and prevent repetitive pop-ups.</li>
      <li><strong>Analytics:</strong> Third-party cookies (e.g., Google Analytics) help us track performance and user behavior across our pages.</li>
      <li>You can manage or disable cookies via your browser settings, though this may impact the functionality of push notifications.</li>
    </ol>
    
    <h2>6. Data Security</h2>
    <ol>
      <li>We implement reasonable security measures as per Section 43A of the IT Act, 2000. This includes <strong>Email Verification</strong> to prevent spam and encryption of sensitive data. However, no system is completely secure.</li>
    </ol>
    
    <h2>7. Your Rights Under DPDP Act</h2>
    <ol>
      <li>You have rights to access, correct, delete, or withdraw consent for your data. Contact us at <a href="mailto:<?= CONTACT_EMAIL ?>" class="c7"><?= CONTACT_EMAIL ?></a> to exercise these rights.</li>
      <li><strong>Note on Comments:</strong> Since comment notifications are mandatory service messages, withdrawing consent for these requires the deletion of your comment data from our records.</li>
      <li>We will respond to valid requests within 30 days.</li>
    </ol>
    
    <h2>8. Children's Privacy</h2>
    <ol>
      <li>The Site is not for children under 18. We do not knowingly collect data from minors.</li>
    </ol>
    
    <h2>9. International Transfers</h2>
    <ol>
      <li>Data may be processed outside India via third-party services (like Google), ensuring adequate protection and compliance with applicable laws.</li>
    </ol>
    
    <h2>10. Changes to This Policy</h2>
    <ol>
      <li>We may update this policy. Changes will be posted here with the updated date. Continued use constitutes acceptance.</li>
    </ol>
    
    <h2>11. Contact & Grievance Officer</h2>
    <ol>
      <li>For any questions regarding this Privacy Policy, please email us at <a href="mailto:<?= CONTACT_EMAIL ?>" class="c7"><?= CONTACT_EMAIL ?></a>.</li>
      <li>In accordance with the Information Technology Act, 2000 and rules made thereunder, for any grievances or discrepancies, you may contact our designated **Grievance Officer**:
        <!-- <br><strong>Name:</strong> Vikash Dhaker -->
        <br><strong>Email:</strong> <a href="mailto:<?= CONTACT_EMAIL ?>" class="c7"><?= CONTACT_EMAIL ?></a>
        <br><strong>Phone:</strong> <?= PHONE_NUMBER ?>
      </li>
    </ol>
    
    <p>We also encourage you to review our legal policies:
      <br>
      <a href="<?= SITE_URL ?>/editorial-policy" class="c7">Editorial Policy</a><br>
      <a href="<?= SITE_URL ?>/terms-of-service" class="c7">Terms of Service</a><br>
      <a href="<?= SITE_URL ?>/privacy-policy" class="c7">Privacy Policy</a><br>
      <a href="<?= SITE_URL ?>/cookie-policy" class="c7">Cookie Policy</a>
    </p>
  </div>
  </main>
</body>
</html>