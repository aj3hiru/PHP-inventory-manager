<?php
declare(strict_types=1);

// Store settings. Copy this file to includes/config.php and fill in your values.
// includes/config.php is in .gitignore, so your database password never goes into git.

// ======================================
// YOUR STORE
// ======================================

const APP_NAME     = 'My Store';                  // shown in the panel header and on the login page
const APP_URL      = 'http://localhost:8000';     // address of this panel, without a trailing slash
const APP_TIMEZONE = 'Asia/Kolkata';
const APP_DEBUG    = false;                       // true shows database errors on screen (only while setting up)

// ======================================
// DATABASE
// ======================================

const DB_HOST    = '127.0.0.1';
const DB_PORT    = 3306;
const DB_NAME    = 'your_database';
const DB_USER    = 'your_db_user';
const DB_PASS    = 'your_db_password';
const DB_CHARSET = 'utf8mb4';

// ======================================
// SESSION — one long-lived (30 day), site-wide, HttpOnly, SameSite=Lax cookie, so staff
// are not logged out on refresh. It is marked Secure automatically when the request is HTTPS.
// ======================================

$SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 30;
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME_SECONDS);
ini_set('session.cookie_lifetime', (string)$SESSION_LIFETIME_SECONDS);
session_set_cookie_params([
    'lifetime' => $SESSION_LIFETIME_SECONDS,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Keep an active login alive: push the cookie expiry forward on every request.
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], [
        'expires'  => time() + $SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ======================================
// PATHS AND NAMES USED BY THE PAGES
// ======================================

define('ROOT_PATH', dirname(__DIR__));
defined('ADMIN_PATH') || define('ADMIN_PATH', ROOT_PATH . '/admin');
const UPLOADS_PATH = ROOT_PATH . '/uploads';
const SITE_NAME    = APP_NAME;
const SITE_URL     = APP_URL;

date_default_timezone_set(APP_TIMEZONE);

$site_name = SITE_NAME;
$site_url  = SITE_URL;

// Who did what (activity log)
$log_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$log_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

// ======================================
// DATABASE CONNECTION
// ======================================

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Dates saved by MySQL (bill time, due date) use the same timezone as PHP
    $pdo->exec("SET time_zone = '" . (new DateTime('now'))->format('P') . "'");
} catch (PDOException $e) {
    http_response_code(500);
    die(APP_DEBUG ? 'Database connection failed: ' . $e->getMessage() : 'Database connection failed.');
}
