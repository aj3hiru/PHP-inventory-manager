<?php
require_once __DIR__ . '/../includes/config.php';
$last_updated = "December 24, 2025";

$seo = [
    'title'       => 'Terms of Service | ' . SITE_NAME,
    'description' => 'Read the Terms of Service for ' . SITE_NAME . '. Understand our rules regarding comments, notifications, and data usage.',
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
  	
<link rel="canonical" href="<?= SITE_URL ?>/terms-of-service">
  
  <link rel=preload href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as=style crossorigin onload="this.onload=null;this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel=stylesheet></noscript>
  
  
  <style type="text/css">body{font-family:'Nunito',sans-serif;background-color:#f9f9f9;color:#333}*{margin:0;padding:0;box-sizing:border-box}.container{max-width:900px;margin:20px auto;padding:24px;background-color:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05)}h1{font-size:1.8rem;font-weight:800;text-align:center;margin-bottom:10px;color:#004aad}.last-updated{text-align:center;font-size:.9rem;color:#777;margin-bottom:24px}h2{font-size:1.4rem;font-weight:700;color:#004aad;border-bottom:2px solid #f0f0f0;padding-bottom:8px;margin-top:20px;margin-bottom:16px}p,li{font-size:1rem;line-height:1.7;margin-bottom:15px}ol{list-style-type:lower-alpha;padding-left:30px}ol li{margin-bottom:15px}strong{font-weight:700;color:#111}.c7{text-decoration:underline;color:#004aad;font-weight:600}.c7:hover{text-decoration:none}</style>
  
  
  <script type="application/ld+json">
<?= json_encode([
    '@context'   => 'https://schema.org',
    '@type'      => 'WebPage',
    'name'       => $seo['title'],
    'description'=> $seo['description'],
    'url'        => SITE_URL . '/terms-of-service',
    'mainEntity' => [
        '@type' => 'CreativeWork',
        'name'  => 'Terms of Service',
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
    <h1>Terms and Conditions for <?= SITE_NAME ?></h1>
    <p class="last-updated"><strong>Last Updated: <?php echo $last_updated; ?></strong></p>
    
    <h2>1. Parties</h2>
    <ol>
      <li>These Terms and Conditions for Usage of <?= SITE_NAME ?> ("<strong>Website</strong>") are between you, the user ("<strong>you</strong>" or "<strong>User</strong>"), and <?= SITE_NAME ?> (herein, "<strong>Us</strong>" or "<strong>Owner</strong>").</li>
      <li>These terms read together with the <a href="<?php echo $site_url; ?>/privacy-policy" class="c7" target="_blank">Privacy Policy</a> available on the website <a href="<?php echo $site_url; ?>" target="_blank" class="c7"><?php echo $site_url; ?></a> ("<strong>Terms</strong>") together govern your use of the Website in connection with accessing government jobs updates, PSU updates, private job openings, and bank jobs updates.</li>
    </ol>
  
    <h2>2. Agreement to Terms</h2>
    <ol>
      <li>By using the Website, you agree to be bound by these Terms.</li>
      <li>In the event you are not agreeable to any of the Terms provided herein, you shall not use the Website.</li>
      <li>In using the Website, you agree that you are of legal age and possess full capacity to agree to these Terms.</li>
    </ol>

    <h2>3. Non-Commercial Usage</h2>
    <ol>
      <li>"<?= SITE_NAME ?>" is a platform provided by the Owner that enables access to updates on government jobs, PSU updates, private job openings, and bank jobs for User's personal/informational use.</li>
      <li>The Website shall not be used for commercial purposes without prior written consent.</li>
    </ol>

    <h2>4. Interactive Services & User Content</h2>
    <ol>
      <li><strong>Comment Verification:</strong> When you post a comment, we require email verification via OTP or a link. This is to maintain the integrity of our platform and prevent spam.</li>
      <li><strong>Service Notifications (Mandatory):</strong> By participating in discussions (User Generated Content), you explicitly agree to receive "Service Notifications" at your verified email address. These include admin replies, answers to queries, and thread updates. You acknowledge that these are transactional emails integral to the service, and <strong>opting out is only possible by deleting your comment data</strong>.</li>
      <li><strong>Content Rights:</strong> By posting comments, you grant us a non-exclusive license to display, modify, or remove your content if it violates our policies (e.g., abusive language, political spam, or promotional links).</li>
    </ol>

    <h2>5. Disclaimer Regarding Government Information</h2>
    <ol>
      <li><strong>Not a Government Entity:</strong> <?= SITE_NAME ?> is a private informational blog. We are <strong>not affiliated, associated, authorized, endorsed by, or in any way officially connected</strong> with the Government of India or any state government organization.</li>
      <li><strong>Information Accuracy:</strong> While we strive to provide accurate updates on Sarkari Naukri, Admit Cards, and Results from official notifications, we do not guarantee the absolute accuracy of the data. Users are strictly advised to verify details from the official government websites before applying.</li>
    </ol>

    <h2>6. Push Notifications & Newsletters</h2>
    <ol>
      <li><strong>Push Notifications:</strong> By enabling push notifications, you consent to our use of local storage, cookies, and our in-house algorithms (alongside Google Firebase) to send job alerts to your device. You may revoke this consent via your browser settings.</li>
      <li><strong>Newsletter:</strong> Subscriptions to our email newsletter are voluntary. You may unsubscribe from marketing emails at any time using the link provided in the email.</li>
    </ol>

    <h2>7. User Responsibility</h2>
    <ol>
      <li>The use/intended use of the Website is for the personal or private informational use of the User only.</li>
      <li>The Owner does not take any responsibility for the User's use or misuse of the Website.</li>
      <li>In order to use the Website, Users may need to grant consent for (i) receipt of notifications; (ii) accessing cookies for better experience; (iii) sharing personal data as per Privacy Policy. Without granting such consent where applicable, Users will not be able to make full use of the Website.</li>
      <li>The Website must be used strictly in accordance with applicable laws and regulations and shall under no circumstances be used for any illegal purposes.</li>
    </ol>
  
    <h2>8. Territorial Restrictions</h2>
    <ol>
      <li>The Website's features are only available and may only be used in regions where local laws and regulations permit such access to job information.</li>
      <li>By accessing, browsing, or using this Website, you confirm that you are within a permitted jurisdiction and that you will only use the information in accordance with local laws and regulations.</li>
      <li>It is the user's responsibility to ensure that the use of the Website is in compliance with any applicable laws and regulations in your location, and you agree not to use this platform in any manner that violates applicable local laws.</li>
      <li>Unlawful use of the information or features is strictly prohibited.</li>
    </ol>
  
    <h2>9. Liability</h2>
    <ol>
      <li>The User shall further be liable for any loss, damage, penalty and legal action arising out of or relating to use/misuse of the Website.</li>
      <li>The Owner will have no liability if any information is used in violation of any law being in force. <strong>YOU SHALL INDEMNIFY AND HOLD HARMLESS THE OWNER, ITS AFFILIATES, SUCCESSORS, ASSIGNS AND THEIR RESPECTIVE OFFICERS, DIRECTORS, REPRESENTATIVES, EMPLOYEES, AND AGENTS FROM ANY CLAIMS, JUDGEMENTS, PENALTIES, INTEREST, COSTS, OR EXPENSES (INCLUDING REASONABLE ATTORNEYS' FEES) ARISING FROM AND RELATING TO YOUR USE OF THE WEBSITE.</strong></li>
      <li>By accessing the Website, you, the User, declare that you will use this platform in strict adherence to applicable laws, including, without limitation and as applicable, the Indian Information Technology Act 2000 & the rules made thereunder, Digital Personal Data Protection Act 2023, and other such laws which are for the time being in force in User's country.</li>
    </ol>
  
    <h2>10. Governing Law and Dispute Resolution</h2>
    <ol>
      <li>This Agreement and any disputes arising under or in connection with it shall be governed by and construed in accordance with India's law, without regard to its conflict of laws provisions. If there is any conflict between a provision of this Agreement and laws and regulations, the laws and regulations shall prevail.</li>
      <li>In the event of any dispute arising under or in connection with this Agreement, the parties shall attempt, promptly and in good faith, to resolve such dispute. If no settlement can be reached, you and we both agree that the courts of India will have exclusive jurisdiction. Claims under the Agreement must be brought within one year after the cause of action arising, or any such claim or cause of action shall be barred to the extent permitted by law.</li>
    </ol>
  
    <h2>11. Severability</h2>
    <ol>
      <li>Each term and provision of these Terms shall be valid and enforceable to the fullest extent permitted by law.</li>
      <li>Any invalid, illegal, void or unenforceable term or provision shall be deemed replaced by a term or provision that is valid and enforceable and that comes closest to expressing the intention of the invalid, illegal or unenforceable term or provision.</li>
    </ol>
  
    <h2>12. Entire Agreement</h2>
    <ol>
      <li>These Terms comprise the entire understanding regarding their subject matter and supersede and merge all prior and contemporaneous agreements, understandings and discussions.</li>
    </ol>
  
    <h2>13. Relationship Amongst the Parties</h2>
    <ol>
      <li>The relationship between you and the Owner is on a principal-to-principal basis and nothing in these Terms will be construed as creating a partnership, joint venture, association of persons, or employment/agency relationship between you and the Owner. You shall not have any right to bind the Owner.</li>
      <li>These Terms shall not give any rights of any kind to any third parties, whatsoever.</li>
    </ol>
  
    <h2>14. Acceptance of Terms</h2>
    <ol>
      <li>By using our Website, you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions in their entirety.</li>
    </ol>

    <h2>15. Contact & Grievance Information</h2>
    <ol>
      <li>For any questions, concerns, or grievances related to these Terms of Service, please contact our designated **Grievance Officer**:
     <!--   <br><strong>Name:</strong> Vikash Dhaker -->
        <br><strong>Email:</strong> <a href="mailto:<?= CONTACT_EMAIL?>" class="c7"><?= CONTACT_EMAIL?></a>
        <br><strong>Phone:</strong> <?= PHONE_NUMBER ?>
      </li>
    </ol>
    
    <p>We also encourage you to review our legal policies:
      <br>
      <a href="<?= SITE_URL ?>/editorial-policy" class="c7">Editorial Policy</a><br>
      <a href="<?= SITE_URL ?>/terms-of-service" class="c7">Terms of Service</a><br>
      <a href="<?= SITE_URL ?>/privacy-policy" class="c7">Privacy Policy</a><br>
      <a href="<?= SITE_URL ?>/cookie-policy" class="c7">Cookie Policy</a><br>
      <a href="<?= SITE_URL ?>/disclaimer" class="c7">Disclaimer</a>
    </p>
  </div>
  </main>
</body>
</html>