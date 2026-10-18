<?php
// Router for PHP's built-in server (local testing only):  php -S localhost:8000 router.php
// Copies the main URL rules from .htaccess; on Apache/LiteSpeed the .htaccess file does this instead.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    if (substr($file, -4) !== '.php') return false;          // static file
    $_SERVER['SCRIPT_NAME'] = $path; chdir(dirname($file)); require $file; return true;
}
$rules = [
    '#^/p/([a-zA-Z0-9-]+)/?$#'          => ['shop/product.php', 'slug'],
    '#^/c/([a-zA-Z0-9-]+)/?$#'          => ['shop/category.php', 'slug'],
    '#^/order/([0-9]+)/?$#'             => ['shop/order.php', 'id'],
    '#^/cart/?$#'      => ['shop/cart.php'],     '#^/checkout/?$#' => ['shop/checkout.php'],
    '#^/login/?$#'     => ['shop/login.php'],    '#^/register/?$#' => ['shop/register.php'],
    '#^/logout/?$#'    => ['shop/logout.php'],   '#^/account/?$#'  => ['shop/account.php'],
    '#^/wishlist/?$#'  => ['shop/wishlist.php'], '#^/shop-ajax/?$#' => ['shop/ajax.php'],
    '#^/search/?$#'    => ['search.php'],        '#^/categories/?$#' => ['categories.php'],
    '#^/categories/([a-zA-Z0-9-]+)/?$#' => ['category.php', 'slug'],
    '#^/author/([a-zA-Z0-9_-]+)/?$#'    => ['author.php', 'slug'],
    '#^/tag/[a-z0-9-]+-([0-9]+)/?$#'    => ['tag.php', 'id'],
    '#^/state-jobs/?$#' => ['states.php'],
    '#^/state-jobs/([a-zA-Z0-9-]+)/?$#' => ['state-jobs.php', 'slug'],
    '#^/(about-us|contact-us|terms-of-service|cookie-policy|privacy-policy|disclaimer|affiliate-disclaimer|editorial-policy|commercial-policy)/?$#' => ['pages/%1.php'],
];
$target = 'index.php';
foreach ($rules as $re => $r) {
    if (preg_match($re, $path, $m)) {
        $target = isset($m[1]) ? str_replace('%1', $m[1], $r[0]) : $r[0];
        if (isset($r[1], $m[1])) $_GET[$r[1]] = $m[1];
        break;
    }
}
if ($target === 'index.php' && $path !== '/' && !preg_match('#^/index\.php$#', $path)) {
    $php = __DIR__ . rtrim($path, '/') . '.php';                 // extension-less pages, e.g. /post-slug
    $target = is_file($php) ? ltrim(rtrim($path, '/'), '/') . '.php' : 'index.php';
}
$_SERVER['SCRIPT_NAME'] = '/' . $target;
chdir(dirname(__DIR__ . '/' . $target));
require __DIR__ . '/' . $target;
