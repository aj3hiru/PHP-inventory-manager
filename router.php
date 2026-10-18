<?php
// Router for PHP's built-in server (local testing only):  php -S localhost:8000 router.php
// On Apache / LiteSpeed the .htaccess file does this job.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('#^/(includes|install|vendor)(/|$)#', $path)) { http_response_code(403); exit('Forbidden'); }
if (preg_match('#^/login/?$#', $path)) $path = '/admin/login.php';
$file = __DIR__ . $path;
if (is_dir($file)) $file = rtrim($file, '/') . '/index.php';
if (!is_file($file)) { http_response_code(404); exit('Not found'); }
if (substr($file, -4) !== '.php') return false;              // css, js, images
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = substr($file, strlen(__DIR__));
chdir(dirname($file));
require $file;
