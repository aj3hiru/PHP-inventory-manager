<?php
declare(strict_types=1);

// ============================================================================
// SESSION — explicit, robust cookie config.
//
// Before this fix, session_start() ran with whatever the server's php.ini
// defaults happened to be: no explicit cookie lifetime, path, domain,
// Secure or SameSite flag. That's what was causing customers/admins to get
// logged out on a plain page refresh, or after a short while:
//   - No explicit cookie lifetime -> depends entirely on server/php.ini
//     defaults, which on shared hosting are often short (e.g. session
//     garbage-collected after ~24 minutes of inactivity) or the cookie
//     itself was a "until browser closes" cookie that some mobile browsers
//     treat as gone on the very next request.
//   - No explicit `path` -> if the server's default session.cookie_path
//     wasn't exactly "/", a cookie set while browsing /shop/ could fail to
//     be sent back on a request to /admin/ (or vice versa), or even to a
//     different /shop/ page, making the session look "lost" on refresh.
//   - No explicit `secure`/`samesite` -> on some browser/server
//     combinations over HTTPS, a cookie without these flags set correctly
//     can be dropped or blocked entirely.
//
// Setting all of this explicitly, once, here (before session_start()) is
// the fix: every page on the site now gets a long-lived (30 day), site-wide
// ("/"), secure, SameSite=Lax session cookie, and the server is told to
// keep session data alive for that same 30 days instead of relying on
// whatever short default the host has.
// ============================================================================
$SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 30; // 30 days

ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME_SECONDS);
ini_set('session.cookie_lifetime', (string)$SESSION_LIFETIME_SECONDS);

session_set_cookie_params([
    'lifetime' => $SESSION_LIFETIME_SECONDS,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),   // secure cookie on HTTPS; plain http (local test) still works
    'httponly' => true,     // JS can't read the session cookie (XSS protection)
    'samesite' => 'Lax',    // sent on normal navigation/links, blocks CSRF-y cross-site POSTs
]);

session_start();

// Refresh the cookie's expiry on every request that has a session, so an
// active user's login keeps extending instead of counting down from the
// moment they first logged in.
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], [
        'expires'  => time() + $SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),   // secure cookie on HTTPS; plain http (local test) still works
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ======================================
// ROOT PATHS
// ======================================

define('ROOT_PATH', dirname(__DIR__));

const INCLUDES_PATH = ROOT_PATH . '/includes';
defined('ADMIN_PATH') || define('ADMIN_PATH', ROOT_PATH . '/admin');
const ASSETS_PATH   = ROOT_PATH . '/assets';
const UPLOADS_PATH  = ROOT_PATH . '/uploads';


// ======================================
// APP CONFIGURATION
// ======================================

const APP_NAME           = 'EduMint24';
const APP_URL            = 'http://localhost:8000';
const APP_ENV            = 'production';
const APP_DEBUG          = false;
const APP_TIMEZONE       = 'Asia/Kolkata';
const APP_VERSION        = '1.0.0';
const THEME_COLOR = '#7c3aed';

date_default_timezone_set(APP_TIMEZONE);

// ======================================
// DATABASE CONFIGURATION
// ======================================

const DB_HOST            = '127.0.0.1';
const DB_PORT            = 3306;
const DB_NAME            = 'your_database';
const DB_USER            = 'your_db_user';
const DB_PASS            = 'your_db_password';
const DB_CHARSET         = 'utf8mb4';



// ======================================
// PUSH NOTIFICATION CONFIG
// ======================================

const PUSH_SECRET        = 'change-me-long-random-string';

const VAPID_SUBJECT      = 'mailto:you@example.com';
const VAPID_PUBLIC_KEY   = 'your-vapid-public-key';
const VAPID_PRIVATE_KEY  = 'your-vapid-private-key';

// ======================================
// SITE INFORMATION
// ======================================

const SITE_NAME          = APP_NAME;
const SITE_URL           = APP_URL;

const SITE_LOGO          = SITE_URL . '/assets/img/logo.webp';
const SEO_DEFAULT_IMAGE  = SITE_URL . '/assets/img/seo_og_default.png';

const CONTACT_EMAIL      = 'contact@example.com';
const COMPANY_ADDRESS    = 'Your address';
const PHONE_NUMBER       = '+910000000000';

const FOUNDING_YEAR      = '2026';

const SITE_FOOTER_TAGLINE = 'Trusted platform for government job updates, exams, results & career resources.';


// ======================================
// SOCIAL MEDIA
// ======================================

const SOCIAL_INSTAGRAM   = '';
const SOCIAL_THREADS     = '';
const SOCIAL_LINKEDIN    = '';
const SOCIAL_FACEBOOK    = '';
const SOCIAL_TWITTER     = '';
const SOCIAL_WHATSAPP    = '';
const SOCIAL_TELEGRAM    = '';
const SOCIAL_ARATT       = '';



// ======================================
// BACKWARD COMPATIBILITY VARIABLES
// ======================================

$site_name       = SITE_NAME;
$site_url        = SITE_URL;
$site_logo       = SITE_LOGO;
$seo_image       = SEO_DEFAULT_IMAGE;

$contact_email   = CONTACT_EMAIL;
$company_address = COMPANY_ADDRESS;
$phone_number    = PHONE_NUMBER;

// ======================================
// DATABASE CONNECTION
// ======================================

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);

    if (APP_DEBUG) {
        die('Database Connection Failed: ' . $e->getMessage());
    }

    die('Database connection failed.');
}

// ======================================
// PUSH CONFIG ARRAY
// ======================================

$config = [
    'push_secret' => PUSH_SECRET,
    'vapid' => [
        'subject'    => VAPID_SUBJECT,
        'publicKey'  => VAPID_PUBLIC_KEY,
        'privateKey' => VAPID_PRIVATE_KEY,
    ],
];

// ======================================
// DYNAMIC URLS
// ======================================

$scheme = (
    !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
) ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? parse_url(SITE_URL, PHP_URL_HOST);
$uri  = $_SERVER['REQUEST_URI'] ?? '/';

$current_url = $scheme . '://' . $host . $uri;

if (
    !empty($_GET['page']) &&
    (int) $_GET['page'] > 1
) {
    $seo_canonical = $current_url;
} else {
    $path = strtok($uri, '?');
    $seo_canonical = $scheme . '://' . $host . $path;
}

// ======================================
// REQUEST LOGGING VARIABLES
// ======================================

$log_ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
$log_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';


// ======================================
// SEO DEFAULTS
// ====================================== 

const SEO_DEFAULT_TITLE =
    SITE_NAME . ' | Latest Government Job Updates 2025';

const SEO_DEFAULT_DESCRIPTION =
    'Get latest government job updates, sarkari naukri notifications, admit cards, results, and exam alerts. Your trusted source for govt jobs 2025.';

const SEO_DEFAULT_TYPE    = 'website';

const SEO_DEFAULT_ROBOTS  =
    'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1';
    

// ======================================
// PAGE SEO DEFAULT VARIABLES
// ======================================

$seo = [
    'title' => SEO_DEFAULT_TITLE,

    'description' => SEO_DEFAULT_DESCRIPTION,

    'image' => SEO_DEFAULT_IMAGE,

    'type' => SEO_DEFAULT_TYPE,
    
    'robots' => SEO_DEFAULT_ROBOTS
];


// ======================================
// PAGINATION
// ======================================

const POSTS_PER_PAGE            = 9;
const COMMENTS_PER_PAGE         = 20;