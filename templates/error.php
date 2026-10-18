<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>500 - Server Error | <?= htmlspecialchars($site_name) ?></title>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/components/head_script.php'; ?>
    <style>
        .error-section{min-height:calc(100vh - 300px);display:flex;align-items:center;justify-content:center;padding:0;}
        .error-content{text-align:center;max-width:600px;padding:1rem;}
        .error-illustration{max-width:400px;width:100%;height:auto;margin:0 auto 1rem;}
        .error-title{font-size:2.5rem;font-weight:700;margin:0 0 1rem;color:#333;}
        .error-message{font-size:1.125rem;color:#666;margin:0 0 2rem;line-height:1.6;}
        .error-button{display:inline-block;padding:0.75rem 2rem;font-size:0.9rem;background:var(--primary-color);color:white;text-decoration:none;font-weight:600;transition:all 0.3s ease;}
        @media (max-width:768px){.error-title{font-size:1.8rem;}.error-message{font-size:1rem;}.error-illustration{max-width:300px;}}
    </style>
</head>
<body>
<?php include_once 'components/header.php'; ?>
<main>
    <div class="container">
        <section class="error-section">
            <div class="error-content">
                <img src="/assets/500-error.svg" alt="Server error illustration" class="error-illustration">
                <h1 class="error-title">Oops! Something Went Wrong</h1>
                <p class="error-message">We're sorry, but something broke on our end. Please try again later or contact support if the issue persists.</p>
                <a href="/" class="error-button">Back to Home</a>
            </div>
        </section>
    </div>
</main>
<footer>
    <div class="container">&copy; 2025 <?= htmlspecialchars($site_name) ?>. All rights reserved.</div>
</footer>
</body>
</html>