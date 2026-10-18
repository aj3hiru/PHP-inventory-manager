<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>404 - Page Not Found | <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/components/head_script.php'; ?>
    <style>
        .error-section{min-height:calc(100vh - 300px);display:flex;align-items:center;justify-content:center;padding:0;}
        .error-content{text-align:center;max-width:600px;padding:1rem;}
        .error-illustration{max-width:400px;width:100%;height:auto;margin:0 auto 1rem;}
        .error-code{font-size:3rem;font-weight:900;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin:0;}
        .error-title{font-size:2rem;font-weight:700;margin:0 0 .8rem;color:#333;}
        .error-message{font-size:1.125rem;color:#666;margin:0 0 2rem;line-height:1.6;}
        .error-button{display:inline-block;padding:.75rem 2rem;font-size:.9rem;background:var(--primary-color);color:#fff;text-decoration:none;font-weight:600;transition:all .3s ease;}
        @media (max-width:768px){.error-title{font-size:1.5rem;}.error-message{font-size:1rem;}.error-illustration{max-width:300px;}}
    </style>
</head>
<body>
<?php include_once $_SERVER['DOCUMENT_ROOT'].'/components/header.php'; ?>
<main>
    <div class="container">
        <section class="error-section">
            <div class="error-content">
                <img src="/assets/Empty-amico.svg" alt="Page not found illustration" class="error-illustration">
                <p class="error-code">404</p>
                <h1 class="error-title">Page Not Found</h1>
                <p class="error-message">This page is not found or does not exist. The page you're looking for might have been removed, had its name changed, or is temporarily unavailable.</p>
                <a href="/" class="error-button">Back to Home</a>
            </div>
        </section>
    </div>
</main>
<footer>
    <div class="container">&copy; 2025 <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>. All rights reserved.</div>
</footer>
</body>
</html>