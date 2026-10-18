<?php
require_once __DIR__ . '/../includes/config.php';
$last_updated = "November 8, 2025";


$seo = [
    'title'       => 'Cookie Policy | ' . SITE_NAME,
    'description' => 'Learn about the cookies used by ' . SITE_NAME . '. We explain what cookies are, why we use them, and your choices in compliance with the DPDP Act.',
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
  	
<link rel="canonical" href="<?= SITE_URL ?>/cookie-policy">
  
  
  <link rel=preload href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as=style crossorigin onload="this.onload=null;this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel=stylesheet></noscript>
  
  
  <style type="text/css">body{font-family:'Nunito',sans-serif;background-color:#f9f9f9;color:#333}*{margin:0;padding:0;box-sizing:border-box}.container{max-width:900px;margin:20px auto;padding:24px;background-color:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05)}h1{font-size:1.8rem;font-weight:800;text-align:center;margin-bottom:10px;color:#004aad}.last-updated{text-align:center;font-size:.9rem;color:#777;margin-bottom:24px}h2{font-size:1.4rem;font-weight:700;color:#004aad;border-bottom:2px solid #f0f0f0;padding-bottom:8px;margin-top:20px;margin-bottom:16px}p,li{font-size:1rem;line-height:1.7;margin-bottom:15px}ol,ul{padding-left:30px}ul{list-style-type:disc}ol{list-style-type:lower-alpha}li{margin-bottom:15px}strong{font-weight:700;color:#111}.c7{text-decoration:underline;color:#004aad;font-weight:600}.c7:hover{text-decoration:none}a{word-break:break-all;overflow-wrap:anywhere}</style>
  
  
  <script type="application/ld+json">
<?= json_encode([
    '@context'   => 'https://schema.org',
    '@type'      => 'WebPage',
    'name'       => $seo['title'],
    'description'=> $seo['description'],
    'url'        => SITE_URL . '/cookie-policy',
    'mainEntity' => [
        '@type' => 'CreativeWork',
        'name'  => 'Cookie Policy',
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
    <h1>Cookie Policy for <?= SITE_NAME ?></h1>
    <p class="last-updated"><strong>Last Updated: <?php echo $last_updated; ?></strong></p>
    
    <h2>1. What Are Cookies?</h2>
    <p>A cookie is a small text file that a website stores on your computer or mobile device when you visit the site. It enables the website to remember your actions and preferences (such as login, language, font size, and other display preferences) over a period of time, so you don't have to keep re-entering them whenever you come back to the site or browse from one page to another.</p>
    
    <h2>2. How We Use Cookies</h2>
    <p>We use cookies to understand how you use our site, to improve your user experience, and to personalize content and advertising. This includes:</p>
    <ul>
        <li>Ensuring the website functions correctly.</li>
        <li>Analyzing site traffic and user behavior.</li>
        <li>Remembering your preferences (if any).</li>
        <li>Serving relevant advertisements (if applicable).</li>
    </ul>

    <h2>3. Types of Cookies We Use</h2>
    <ol>
      <li><strong>Strictly Necessary Cookies:</strong> These are essential for you to browse the website and use its features, such as accessing secure areas of the site. Without these cookies, services cannot be provided.</li>
      
      <li><strong>Performance (Analytics) Cookies:</strong> We use these cookies to collect information about how visitors use our Site. For example, we use <strong>Google Analytics</strong> to understand which pages are visited most often, how users move around our site, and if they get error messages. This data is aggregated and anonymous and helps us improve how our website works.</li>
      
      <li><strong>Advertising (Marketing) Cookies:</strong> These cookies are used to deliver advertisements more relevant to you and your interests. We and our third-party partners (like <strong>Google AdSense</strong>) may use these cookies to build a profile of your interests and show you relevant ads on other sites. They do not directly store personal information but are based on uniquely identifying your browser and internet device.</li>
    </ol>
    
    <h2>4. Third-Party Cookies</h2>
    <p>We use third-party services like Google Analytics and Google AdSense. These services may also set their own cookies on your device. We do not control these cookies. You should review the respective privacy and cookie policies of these third parties for more information.</p>
    <ul>
        <li><strong>Google's Privacy & Terms:</strong><br><a href="https://policies.google.com/technologies/cookies" class="c7" target="_blank" rel="noopener">https://policies.google.com/technologies/cookies</a></li>
    </ul>

    <h2>5. Your Cookie Choices (How to Opt-Out)</h2>
    <ol>
        <li><strong>Browser Settings:</strong> You can manage, block, or delete cookies through your web browser's settings. Please note that if you block all cookies (including essential ones), you may not be able to access all or parts of our site.</li>
        <li><strong>Consent Banner:</strong> You can typically adjust your preferences through the cookie consent banner that appears when you first visit our site.</li>
    </ol>
    
    <h2>6. Compliance</h2>
    <ol>
      <li>Our use of cookies and other tracking technologies aligns with applicable laws, including India's Digital Personal Data Protection Act (DPDP Act), 2023, and the EU ePrivacy Directive where applicable.</li>
    </ol>
    
    <h2>7. Changes to This Policy</h2>
    <ol>
      <li>We may update this Cookie Policy from time to time. Any changes will be posted on this page with an updated "Last Updated" date.</li>
    </OL>

    <h2>8. Contact Us</h2>
    <ol>
      <li>If you have any questions or concerns regarding our use of cookies, please contact us via the details provided on our <a href="<?= SITE_URL ?>/contact-us" class="c7" target="_blank">Contact Us</a> page.</li>
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