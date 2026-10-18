<?php
require_once __DIR__ . '/../includes/config.php';
$last_updated = "December 24, 2025";

$seo = [
    'title'       => 'Editorial Policy | ' . SITE_NAME,
    'description' => 'Learn about the editorial standards and verification process at ' . SITE_NAME . '. We are committed to providing authentic, timely, and reliable job updates.',
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
  	
<link rel="canonical" href="<?= SITE_URL ?>/editorial-policy">

  <link rel=preload href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as=style crossorigin onload="this.onload=null;this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel=stylesheet></noscript>
  
  
  <style type="text/css">
  body {font-family: 'Nunito', sans-serif;}
  .fw100 {font-size: 1.8rem; color:#004aad; font-weight:800; text-align:center; margin-bottom: 1rem;}
    .c7 { text-decoration-skip-ink: none; text-decoration: underline; color: #004aad; }
    * { margin: 0; padding: 0; }
    .c4 { color: #333; text-align: left; padding: 24px; background-color: #fff; max-width: 900px; margin: 20px auto; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    p, .section-block { margin-bottom: 24px; font-size: 0.95rem; line-height: 1.7; }
    h2 { font-size: 1.3rem; font-weight: 700; color: #004aad; border-bottom: 2px solid #f0f0f0; padding-bottom: 8px; margin-top: 20px; margin-bottom: 16px; }
    span { font-weight: 400; font-size: 0.95rem; line-height: 24px; letter-spacing: 0.15px; }
    .fw700 { font-weight: 700; }
    .ml24 { margin-left: 24px; display: block; margin-top: 8px;}
    .last-updated { font-weight: 600; font-size: 0.9rem; color: #000221; }
    ul.policy-list { list-style-type: disc; margin-left: 40px; }
    ul.policy-list li { margin-bottom: 10px; }
  </style>
</head>
<body class="c4">
	<main role="main">
  <h1 class="fw100">Editorial Policy</h1>
  <p class="last-updated" style="text-align:center;">Last Updated: <?php echo $last_updated; ?></p>
  
  <section class="section-block">
    <h2>1. Our Commitment to Authenticity</h2>
    <p>At <?= SITE_NAME ?>, our foremost priority is to be a trusted resource for job seekers in India. This editorial policy outlines our rigorous process for content creation, sourcing, and verification. Our mission is to provide information that is accurate, timely, unbiased, and genuinely helpful. We are not a government body and are not affiliated with any recruiting agency. We are an independent information portal.</p>
  </section>

  <section class="section-block">
    <h2>2. Our Sourcing & Verification Process</h2>
    <p>The integrity of our job listings is the foundation of our platform. Every post on <?= SITE_NAME ?> undergoes the following verification process:</p>
    <ul class="policy-list">
        <li><strong>Official Sources Only:</strong> We collect information *only* from official, primary sources. We do not republish content from other non-official job websites, news blogs, or social media channels.</li>
        <li><strong>Primary Source List:</strong> Our team monitors a curated list of official portals, including (but not limited to):
            <ul class="policy-list" style="margin-top:10px;">
                <li>Official government portals (e.g., `gov.in`, `nic.in`)</li>
                <li>Union Public Service Commission (UPSC): `upsc.gov.in`</li>
                <li>Staff Selection Commission (SSC): `ssc.nic.in`</li>
                <li>Railway Recruitment Boards (RRB)</li>
                <li>Banking Institutes (IBPS, SBI, RBI)</li>
                <li>Official Public Sector Undertaking (PSU) career pages</li>
                <li>State Public Service Commissions (SPSCs)</li>
            </ul>
        </li>
        <li><strong>Notification Verification:</strong> Before any job is published, our team locates and reviews the official PDF notification (e.g., the official advertisement) released by the organization. All details in our post (vacancies, eligibility, dates) are cross-checked against this official document.</li>
        <li><strong>Direct Links:</strong> We *only* provide direct links to the official website for "Apply Online," "Download Notification," or "View Results." We will never redirect users to third-party or spammy websites.</li>
    </ul>
  </section>

  <section class="section-block">
    <h2>3. Content Principles</h2>
    <ul class="policy-list">
        <li><strong>Accuracy:</strong> We strive for 100% accuracy. All data, such as application dates, vacancy numbers, and eligibility criteria, is transcribed with care.</li>
        <li><strong>Clarity:</strong> Government notifications can be complex. We rewrite and organize the information to be as clear and easy to understand as possible, without changing the factual details.</li>
        <li><strong>Impartiality:</strong> We present information factually. We do not provide opinions on the desirability of a job or organization.</li>
        <li><strong>No Misleading Titles:</strong> Our headlines and titles (e.g., "New Government Jobs 2025") accurately reflect the content of the post. We do not use "clickbait."</li>
    </ul>
  </section>

  <section class="section-block">
    <h2>4. Corrections & Updates Policy</h2>
    <p>We are human, and errors can happen. We are also committed to keeping our content current in a fast-changing environment.</p>
    <ul class="policy-list">
        <li><strong>Updates:</strong> If an organization changes a deadline, increases vacancies, or postpones an exam, we will update our original post with the new information. The "Last Updated" date on the post will be revised.</li>
        <li><strong>Corrections:</strong> If we make a factual error (e.g., type in the wrong date), we will correct it immediately upon discovery.</li>
        <li><strong>How to Report an Error:</strong> We value our community's help. If you find an error or outdated information on any post, please contact us immediately at <a href="mailto:<?php echo $contact_email; ?>" class="c7"><?php echo $contact_email; ?></a>. We will investigate and correct any verified errors within 24 hours.</li>
    </ul>
  </section>

  <section class="section-block">
    <h2>5. Our Team</h2>
    <p>Our content is managed by our editorial team, led by Manish Dhaker and Vikash Dhaker. Our team is trained to follow this policy strictly, ensuring that <?= SITE_NAME ?> remains a reliable and safe resource for all job seekers.</p>
  </section>
  
  <p>We also encourage you to review our legal policies:
      <br>
      <a href="<?php echo $site_url; ?>/editorial-policy" class="c7">Editorial Policy</a><br>
      <a href="<?php echo $site_url; ?>/terms-of-service" class="c7">Terms of Service</a><br>
      <a href="<?php echo $site_url; ?>/privacy-policy" class="c7">Privacy Policy</a><br>
      <a href="<?php echo $site_url; ?>/cookie-policy" class="c7">Cookie Policy</a>
    </p>
  </main>
</body>
</html>