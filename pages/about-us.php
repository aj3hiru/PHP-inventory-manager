<?php
require_once __DIR__ . '/../includes/config.php';

// SEO SECTION ARRAY
$seo = [
    'title'       => 'About Us | Our Mission & Team | ' . SITE_NAME,
    'description' => 'Meet the team behind ' . SITE_NAME . '. Learn about our mission, editorial standards, and why we are India\'s trusted source for verified government job updates.',
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
  	
<link rel="canonical" href="<?= SITE_URL ?>/about-us">

  <link rel="preload" href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as="style" onload="this.onload=null; this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel="stylesheet"></noscript>
  
  
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
    .team-section { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .team-member { text-align: center; }
    .team-member img { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; background: #eee; margin-bottom: 10px; }
    .team-member h3 { font-size: 1.1rem; margin-bottom: 5px; }
    .team-member p { font-size: 0.9rem; line-height: 1.5; }
    @media (max-width: 600px) { .team-section { grid-template-columns: 1fr; } }
  </style>
  
    <script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@graph'   => [
        [
            '@type'       => 'Organization',
            '@id'         => SITE_URL . '/#organization',
            'name'        => SITE_NAME,
            'url'         => SITE_URL,
            'logo'        => SITE_LOGO,
            'description' => $seo['description'],
            'email'       => CONTACT_EMAIL,
        ],
        [
            '@type'       => 'AboutPage',
            '@id'         => SITE_URL . '/about-us/#webpage',
            'url'         => SITE_URL . '/about-us',
            'name'        => $seo['title'],
            'description' => $seo['description'],
            'about'       => ['@id' => SITE_URL . '/#organization'],
            'inLanguage'  => 'en-IN',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>

</head>
<body class="c4">
	<main>
  <h1 class="fw100">About <?= SITE_NAME ?></h1>
  
  <section class="section-block">
    <h2>Our Story: Why We Started <?= SITE_NAME ?></h2>
    <p>Welcome to <?= SITE_NAME ?>! Our journey began with a simple but frustrating problem: finding reliable, authentic, and easy-to-understand information about government jobs in India was far too difficult. Job seekers, including our friends and family, were tired of navigating confusing websites, broken links, and fake job alerts.</p>
    <p>We launched <?= SITE_NAME ?> with a clear mission: to build India's most trusted and user-friendly platform for government job seekers. We are not a government body, but we are your most dedicated partner in the job search. Our goal is to bridge the information gap, so you can focus on what matters most: preparing for and landing your dream job.</p>
  </section>
  
  <section class="section-block">
    <h2>What We Do</h2>
    <p class="ml24"><strong>1. Verify Every Job:</strong> Our core promise is authenticity. We meticulously check every job posting against official sources like government portals (e.g., `ssc.nic.in`, `upsc.gov.in`), official gazettes, and PSU career pages. If we can't find the official notification, we don't post the job.</p>
    <p class="ml24"><strong>2. Simplify Information:</strong> Government notifications can be long and complex. We break down the essential details—eligibility, salary, vacancy count, and application deadlines—into a simple, easy-to-read format.</p>
    <p class="ml24"><strong>3. Provide Direct Links:</strong> We *always* provide the direct "Apply Online" link to the official government portal. We will never ask you to apply on our site or charge you for job alerts.</p>
    <p class="ml24"><strong>4. Publish Timely Updates:</strong> We cover the entire job cycle, from the initial notification to the admit card, answer key, and final result.</p>
  </section>


  <section class="section-block">
    <h2>Get in Touch</h2>
    <p>For inquiries, partnerships, or feedback, please email us at <a href="mailto:<?= CONTACT_EMAIL ?>" class="c7"><?= CONTACT_EMAIL ?></a>.</p>
    <p>We also encourage you to review our legal policies:
      <br>
      <a href="<?= SITE_URL ?>/editorial-policy" class="c7">Editorial Policy</a><br>
      <a href="<?= SITE_URL ?>/terms-of-service" class="c7">Terms of Service</a><br>
      <a href="<?= SITE_URL ?>/privacy-policy" class="c7">Privacy Policy</a><br>
      <a href="<?= SITE_URL ?>/cookie-policy" class="c7">Cookie Policy</a><br>
      <a href="<?= SITE_URL ?>/disclaimer" class="c7">Disclaimer</a>
    </p>
  </section>
  </main>
</body>
</html>