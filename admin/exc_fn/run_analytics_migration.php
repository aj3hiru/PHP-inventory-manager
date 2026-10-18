<?php
require_once dirname(dirname(dirname(__FILE__))) . '/includes/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, role, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$permissions = json_decode($user['permissions'] ?? '{}', true);

if (
    !$user ||
    $user['status'] !== 'active' ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true)
) {
    exit('Access Denied');
}

header('Content-Type: text/html; charset=utf-8');
echo "<h3>Running Analytics Upgrade Migration...</h3>";

try {
    // 1) Daily stats per post, broken down by traffic source + country.
    //    Powers the date-range filter, growth %, and the Sources / Country
    //    breakdown cards on the Analytics page.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS post_stats_daily (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id     INT UNSIGNED NOT NULL,
            stat_date   DATE NOT NULL,
            source      VARCHAR(20) NOT NULL DEFAULT 'direct',
            country     VARCHAR(2) NOT NULL DEFAULT 'XX',
            views       INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_post_date_source_country (post_id, stat_date, source, country),
            KEY idx_stat_date (stat_date),
            KEY idx_post_id (post_id),
            KEY idx_country (country)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->query("SELECT 1 FROM post_stats_daily LIMIT 1");
    echo "<p style='color:green;font-weight:bold;'>post_stats_daily is ready.</p>";

    // 2) One row per (day, visitor, post) — used to count unique visitors.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visitor_log (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visit_date  DATE NOT NULL,
            visitor_id  VARCHAR(64) NOT NULL,
            post_id     INT UNSIGNED NOT NULL,
            UNIQUE KEY uniq_visit (visit_date, visitor_id, post_id),
            KEY idx_visit_date (visit_date),
            KEY idx_post_id (post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->query("SELECT 1 FROM visitor_log LIMIT 1");
    echo "<p style='color:green;font-weight:bold;'>visitor_log is ready.</p>";

    echo "<p>All done. Go back to <a href='/admin/blog/analytics.php'>Analytics</a> — the migration notice ";
    echo "should be gone, and Yesterday / 7 Days / 30 Days / Previous Month / 6 Months / 1 Year, ";
    echo "Unique Visitors, Traffic Sources and Traffic by Country will start collecting data from ";
    echo "new views onward (it can't backfill past views, since that data was never recorded before).</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;font-weight:bold;'>Migration failed:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
