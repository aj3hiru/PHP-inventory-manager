<?php
/**
 * Shared session-cookie setup for the few standalone API endpoints that
 * call session_start() without loading the full includes/config.php (to
 * avoid an unnecessary DB connection for lightweight endpoints like a
 * CSRF-token issuer or health-check). Kept in sync with the cookie params
 * set in includes/config.php so the whole site uses exactly ONE session
 * cookie lifetime/path/security policy — this was part of the fix for
 * customers/admins getting logged out on refresh or after a short time.
 */
if (session_status() === PHP_SESSION_NONE) {
    $SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 30; // 30 days
    ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME_SECONDS);
    ini_set('session.cookie_lifetime', (string)$SESSION_LIFETIME_SECONDS);
    session_set_cookie_params([
        'lifetime' => $SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),   // secure cookie on HTTPS; plain http (local test) still works
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
