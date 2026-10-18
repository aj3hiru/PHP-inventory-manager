<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>404 - Post Not Found | <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/components/head_script.php'; ?>
    <style>
        .error-section{min-height:calc(100vh - 300px);display:flex;align-items:center;justify-content:center;padding:0;}
        .error-content{text-align:center;max-width:600px;padding:1rem;}
        .error-illustration{max-width:400px;width:100%;height:auto;margin:0 auto 1rem;}
        .error-title{font-size:2.5rem;font-weight:700;margin:0 0 1rem;color:#333;}
        .error-message{font-size:1.125rem;color:#666;margin:0 0 2rem;line-height:1.6;}
        .error-button{display:inline-block;padding:.75rem 2rem;font-size:.9rem;background:var(--primary-color);color:#fff;text-decoration:none;font-weight:600;transition:all .3s ease;}
        @media (max-width:768px){.error-title{font-size:1.8rem;}.error-message{font-size:1rem;}.error-illustration{max-width:300px;}}
    </style>
</head>
<body>
<?php include_once 'components/header.php'; ?>
<main>
    <div class="container">
        <section class="error-section">
            <div class="error-content">
                <img src="/assets/Empty-amico.svg" alt="Post not found illustration" class="error-illustration">
                <h1 class="error-title">Oops!! Post Not Found</h1>
                <p class="error-message">Sorry, the post you're looking for doesn't exist or has been removed. It might have been deleted or the URL might be incorrect.</p>
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