<?php
// Set the default timezone to Asia/Kolkata (India Time)
date_default_timezone_set('Asia/Kolkata');
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// Check security
if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}

$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

$permissions = json_decode($user['permissions'] ?? '{}', true);

if (
    !$user ||
    $user['status'] !== 'active' ||
    empty($permissions['analytics']['view_basic'])
) {
    exit('Access Denied');
}

$username = $_SESSION['username'];

// Admins/editors (or anyone explicitly granted "view_advanced") see every author's
// numbers and can filter by author. Everyone else only ever sees their own posts.
$can_view_all_analytics = (
    $_SESSION['role'] === 'admin' ||
    $_SESSION['role'] === 'editor' ||
    !empty($permissions['analytics']['view_advanced'])
);

// ====================================================================
// Author scoping — which post IDs is this viewer allowed to see?
// null = no restriction (sees everything, or everything for $filter_author_id)
// ====================================================================
$filter_author_id = ($can_view_all_analytics && isset($_GET['author_id']) && is_numeric($_GET['author_id']) && (int)$_GET['author_id'] > 0)
    ? (int)$_GET['author_id']
    : null;

$owned_post_ids = null;
if (!$can_view_all_analytics) {
    $stmt = $pdo->prepare("SELECT p.id FROM posts p JOIN authors a ON p.author_id = a.id WHERE a.user_id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $owned_post_ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
} elseif ($filter_author_id !== null) {
    $stmt = $pdo->prepare("SELECT p.id FROM posts p JOIN authors a ON p.author_id = a.id WHERE a.user_id = ?");
    $stmt->execute([$filter_author_id]);
    $owned_post_ids = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
}

$analytics_authors = [];
if ($can_view_all_analytics) {
    $analytics_authors = $pdo->query("SELECT a.user_id, a.name, u.username FROM authors a JOIN users u ON a.user_id = u.id ORDER BY a.name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Cache files paths
$cacheDir = $_SERVER['DOCUMENT_ROOT'] . '/cj_smart_cache';
$viewsFile = $cacheDir . '/blog_views.json';
$trackingFile = $cacheDir . '/views_tracking.json';

// Load data from cache files
$viewsData = file_exists($viewsFile) ? json_decode(file_get_contents($viewsFile), true) : [];
$trackingData = file_exists($trackingFile) ? json_decode(file_get_contents($trackingFile), true) : [];

// Has the analytics upgrade migration been run yet?
$statsTableReady = false;
try {
    $pdo->query("SELECT 1 FROM post_stats_daily LIMIT 1");
    $statsTableReady = true;
} catch (PDOException $e) {
    $statsTableReady = false;
}

// Function to get post details from database
function getPostDetails(array $post_ids, PDO $pdo): array {
    if (empty($post_ids)) {
        return [];
    }

    $placeholders = str_repeat('?,', count($post_ids) - 1) . '?';
    $query = "
        SELECT
            p.id,
            p.title,
            m.file_path as banner_image,
            COALESCE(pv.views, 0) AS views
        FROM posts p
        LEFT JOIN (SELECT post_id, SUM(views) AS views FROM post_views GROUP BY post_id) pv ON pv.post_id = p.id
        LEFT JOIN media m ON p.featured_image_id = m.id
        WHERE p.id IN ($placeholders)
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($post_ids);

    $posts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $posts[$row['id']] = $row;
    }

    return $posts;
}

// Function to format hour in AM/PM
function formatHourAmPm($hourDateTime) {
    return date('h:00 A', strtotime($hourDateTime));
}

// Function to calculate growth percentage
function calculateGrowth($current, $previous) {
    if ($previous == 0) {
        return $current > 0 ? 100 : 0;
    }
    return round((($current - $previous) / $previous) * 100, 2);
}

// Get lifetime total views, optionally scoped to a set of post IDs
function getTotalViews(PDO $pdo, array $viewsData, ?array $ownedPostIds = null): int {
    if ($ownedPostIds === null) {
        $key = 'total_tr_views_cache';
        $dbViews = null;
        if (extension_loaded('apcu')) {
            $cached = apcu_fetch($key, $success);
            if ($success) $dbViews = (int)$cached;
        }
        if ($dbViews === null) {
            $stmt = $pdo->query("SELECT COALESCE(SUM(views), 0) AS total_views FROM post_views");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $dbViews = (int)$row['total_views'];
            if (extension_loaded('apcu')) apcu_store($key, $dbViews, 300);
        }
        $cacheViews = array_sum($viewsData);
        return $dbViews + $cacheViews;
    }

    if (empty($ownedPostIds)) return 0;
    $placeholders = str_repeat('?,', count($ownedPostIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(views), 0) AS total_views FROM post_views WHERE post_id IN ($placeholders)");
    $stmt->execute($ownedPostIds);
    $dbViews = (int)$stmt->fetchColumn();
    $cacheViews = 0;
    foreach ($viewsData as $pid => $views) {
        if (in_array((int)$pid, $ownedPostIds, true)) $cacheViews += (int)$views;
    }
    return $dbViews + $cacheViews;
}

// Turns a selected range key into concrete start/end dates + the matching
// previous period (for growth %) + how the chart should be bucketed.
function getRangeBounds(string $range): array {
    $today = date('Y-m-d');
    switch ($range) {
        case 'yesterday':
            $start = $end = date('Y-m-d', strtotime('-1 day'));
            $prevStart = $prevEnd = date('Y-m-d', strtotime('-2 days'));
            $granularity = 'hour'; $label = 'Yesterday';
            break;
        case '7d':
            $start = date('Y-m-d', strtotime('-6 days')); $end = $today;
            $prevEnd = date('Y-m-d', strtotime('-7 days')); $prevStart = date('Y-m-d', strtotime('-13 days'));
            $granularity = 'day'; $label = 'Last 7 Days';
            break;
        case '30d':
            $start = date('Y-m-d', strtotime('-29 days')); $end = $today;
            $prevEnd = date('Y-m-d', strtotime('-30 days')); $prevStart = date('Y-m-d', strtotime('-59 days'));
            $granularity = 'day'; $label = 'Last 30 Days';
            break;
        case 'prev_month':
            $firstOfThisMonth = date('Y-m-01');
            $start = date('Y-m-01', strtotime('-1 month', strtotime($firstOfThisMonth)));
            $end = date('Y-m-t', strtotime($start));
            $prevStart = date('Y-m-01', strtotime('-1 month', strtotime($start)));
            $prevEnd = date('Y-m-t', strtotime($prevStart));
            $granularity = 'day'; $label = 'Previous Month';
            break;
        case '6m':
            $start = date('Y-m-d', strtotime('-6 months +1 day')); $end = $today;
            $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
            $prevStart = date('Y-m-d', strtotime($prevEnd . ' -6 months +1 day'));
            $granularity = 'week'; $label = 'Last 6 Months';
            break;
        case '1y':
            $start = date('Y-m-d', strtotime('-1 year +1 day')); $end = $today;
            $prevEnd = date('Y-m-d', strtotime($start . ' -1 day'));
            $prevStart = date('Y-m-d', strtotime($prevEnd . ' -1 year +1 day'));
            $granularity = 'month'; $label = 'Last 1 Year';
            break;
        case 'today':
        default:
            $range = 'today';
            $start = $end = $today;
            $prevStart = $prevEnd = date('Y-m-d', strtotime('-1 day'));
            $granularity = 'hour'; $label = 'Today';
            break;
    }
    return compact('range', 'start', 'end', 'prevStart', 'prevEnd', 'granularity', 'label');
}

function getRangeTotal(PDO $pdo, string $start, string $end, ?array $ownedPostIds): int {
    $sql = "SELECT COALESCE(SUM(views),0) FROM post_stats_daily WHERE stat_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($ownedPostIds !== null) {
        if (empty($ownedPostIds)) return 0;
        $ph = implode(',', array_fill(0, count($ownedPostIds), '?'));
        $sql .= " AND post_id IN ($ph)";
        $params = array_merge($params, $ownedPostIds);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)round((float)$stmt->fetchColumn());
}

function getRangeSeries(PDO $pdo, string $start, string $end, string $granularity, ?array $ownedPostIds): array {
    $sql = "SELECT stat_date, SUM(views) AS v FROM post_stats_daily WHERE stat_date BETWEEN ? AND ?";
    $params = [$start, $end];
    $raw = [];
    if ($ownedPostIds === null || !empty($ownedPostIds)) {
        if ($ownedPostIds !== null) {
            $ph = implode(',', array_fill(0, count($ownedPostIds), '?'));
            $sql .= " AND post_id IN ($ph)";
            $params = array_merge($params, $ownedPostIds);
        }
        $sql .= " GROUP BY stat_date";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raw[$row['stat_date']] = (int)round((float)$row['v']);
        }
    }

    $labels = []; $data = [];
    $cursor = strtotime($start); $endTs = strtotime($end);

    if ($granularity === 'week') {
        while ($cursor <= $endTs) {
            $weekEndTs = min($endTs, strtotime('+6 days', $cursor));
            $sum = 0;
            for ($t = $cursor; $t <= $weekEndTs; $t = strtotime('+1 day', $t)) {
                $sum += $raw[date('Y-m-d', $t)] ?? 0;
            }
            $labels[] = date('M j', $cursor);
            $data[] = $sum;
            $cursor = strtotime('+7 days', $cursor);
        }
    } elseif ($granularity === 'month') {
        $monthCursor = strtotime(date('Y-m-01', $cursor));
        while ($monthCursor <= $endTs) {
            $monthKey = date('Y-m', $monthCursor);
            $sum = 0;
            foreach ($raw as $d => $v) {
                if (strpos($d, $monthKey) === 0) $sum += $v;
            }
            $labels[] = date('M Y', $monthCursor);
            $data[] = $sum;
            $monthCursor = strtotime('+1 month', $monthCursor);
        }
    } else {
        while ($cursor <= $endTs) {
            $d = date('Y-m-d', $cursor);
            $labels[] = date('M j', $cursor);
            $data[] = $raw[$d] ?? 0;
            $cursor = strtotime('+1 day', $cursor);
        }
    }

    return ['labels' => $labels, 'data' => $data];
}

// Today / Yesterday use the hourly JSON tracking file so the chart updates
// live throughout the day (post_stats_daily is also live, but this keeps
// the same lightweight hour-by-hour source the old dashboard used).
function getHourlySeriesForDate(array $trackingData, string $dateYmd, ?array $ownedPostIds): array {
    $labels = []; $data = [];
    for ($h = 0; $h < 24; $h++) {
        $hourKey = $dateYmd . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00:00';
        $posts = $trackingData[$hourKey] ?? [];
        if ($ownedPostIds !== null) {
            $posts = array_filter($posts, fn($pid) => in_array((int)$pid, $ownedPostIds, true), ARRAY_FILTER_USE_KEY);
        }
        $labels[] = formatHourAmPm($hourKey);
        $data[] = array_sum($posts);
    }
    return ['labels' => $labels, 'data' => $data];
}

function getRangeSources(PDO $pdo, string $start, string $end, ?array $ownedPostIds): array {
    $sql = "SELECT source, SUM(views) AS v FROM post_stats_daily WHERE stat_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($ownedPostIds !== null) {
        if (empty($ownedPostIds)) return [];
        $ph = implode(',', array_fill(0, count($ownedPostIds), '?'));
        $sql .= " AND post_id IN ($ph)";
        $params = array_merge($params, $ownedPostIds);
    }
    $sql .= " GROUP BY source ORDER BY v DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($rows as $r) $total += (int)round((float)$r['v']);
    $out = [];
    foreach ($rows as $r) {
        $v = (int)round((float)$r['v']);
        $out[] = ['source' => $r['source'], 'views' => $v, 'pct' => $total > 0 ? round(($v / $total) * 100, 1) : 0];
    }
    return $out;
}

/**
 * Country-wise breakdown of views for the top N countries, with everything
 * else bucketed into a single "Other" row. Same adjustment multiplier as the
 * other range functions, so an editor/author's own adjustment rules apply
 * here too, and the percentages always add up to 100% of what THEY see.
 */
function getRangeCountries(PDO $pdo, string $start, string $end, ?array $ownedPostIds, int $topN = 7): array {
    $sql = "SELECT country, SUM(views) AS v FROM post_stats_daily WHERE stat_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($ownedPostIds !== null) {
        if (empty($ownedPostIds)) return [];
        $ph = implode(',', array_fill(0, count($ownedPostIds), '?'));
        $sql .= " AND post_id IN ($ph)";
        $params = array_merge($params, $ownedPostIds);
    }
    $sql .= " GROUP BY country ORDER BY v DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return [];
    $total = 0;
    foreach ($rows as $r) $total += (int)round((float)$r['v']);
    if ($total <= 0) return [];
    $top = array_slice($rows, 0, $topN);
    $rest = array_slice($rows, $topN);
    $out = [];
    foreach ($top as $r) {
        $v = (int)round((float)$r['v']);
        if ($v <= 0) continue;
        $out[] = ['country' => strtoupper($r['country']), 'views' => $v, 'pct' => round(($v / $total) * 100, 1)];
    }
    $otherViews = 0;
    foreach ($rest as $r) $otherViews += (int)round((float)$r['v']);
    if ($otherViews > 0) {
        $out[] = ['country' => 'OTHER', 'views' => $otherViews, 'pct' => round(($otherViews / $total) * 100, 1)];
    }
    return $out;
}

// Paginated "All Posts" leaderboard for the selected range.
function getTopPostsForRange(PDO $pdo, string $start, string $end, ?array $ownedPostIds, int $limit = 10, int $offset = 0): array {
    $baseSql = "FROM post_stats_daily WHERE stat_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($ownedPostIds !== null) {
        if (empty($ownedPostIds)) return ['posts' => [], 'total' => 0];
        $ph = implode(',', array_fill(0, count($ownedPostIds), '?'));
        $baseSql .= " AND post_id IN ($ph)";
        $params = array_merge($params, $ownedPostIds);
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT post_id $baseSql GROUP BY post_id) t");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    if ($total === 0) return ['posts' => [], 'total' => 0];

    $sql = "SELECT post_id, SUM(views) AS v $baseSql GROUP BY post_id ORDER BY v DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return ['posts' => [], 'total' => $total];

    $ids = array_column($rows, 'post_id');
    $details = getPostDetails($ids, $pdo);
    $maxRaw = max(array_map(fn($r) => (float)$r['v'], $rows)) ?: 1;

    $result = [];
    foreach ($rows as $r) {
        $pid = $r['post_id'];
        if (!isset($details[$pid])) continue;
        $post = $details[$pid];
        $post['range_views'] = (int)round((float)$r['v']);
        $post['percentage'] = ((float)$r['v'] / $maxRaw) * 100;
        $result[] = $post;
    }
    return ['posts' => $result, 'total' => $total];
}

function getUniqueVisitors(PDO $pdo, string $start, string $end, ?array $ownedPostIds): int {
    $sql = "SELECT COUNT(DISTINCT visitor_id) FROM visitor_log WHERE visit_date BETWEEN ? AND ?";
    $params = [$start, $end];
    if ($ownedPostIds !== null) {
        if (empty($ownedPostIds)) return 0;
        $ph = implode(',', array_fill(0, count($ownedPostIds), '?'));
        $sql .= " AND post_id IN ($ph)";
        $params = array_merge($params, $ownedPostIds);
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function formatViews($views) {
    if ($views >= 1000) return number_format($views / 1000, 2) . 'K';
    return $views;
}

$sourceMeta = [
    'google' => ['label' => 'Google', 'color' => '#4285F4', 'icon' => 'fa-brands fa-google'],
    'facebook' => ['label' => 'Facebook', 'color' => '#1877F2', 'icon' => 'fa-brands fa-facebook'],
    'instagram' => ['label' => 'Instagram', 'color' => '#E1306C', 'icon' => 'fa-brands fa-instagram'],
    'twitter' => ['label' => 'Twitter / X', 'color' => '#111827', 'icon' => 'fa-brands fa-x-twitter'],
    'youtube' => ['label' => 'YouTube', 'color' => '#FF0000', 'icon' => 'fa-brands fa-youtube'],
    'whatsapp' => ['label' => 'WhatsApp', 'color' => '#25D366', 'icon' => 'fa-brands fa-whatsapp'],
    'telegram' => ['label' => 'Telegram', 'color' => '#229ED9', 'icon' => 'fa-brands fa-telegram'],
    'pinterest' => ['label' => 'Pinterest', 'color' => '#E60023', 'icon' => 'fa-brands fa-pinterest'],
    'linkedin' => ['label' => 'LinkedIn', 'color' => '#0A66C2', 'icon' => 'fa-brands fa-linkedin'],
    'bing' => ['label' => 'Bing', 'color' => '#00809D', 'icon' => 'fa-brands fa-microsoft'],
    'yahoo' => ['label' => 'Yahoo', 'color' => '#6001D2', 'icon' => 'fa-brands fa-yahoo'],
    'reddit' => ['label' => 'Reddit', 'color' => '#FF4500', 'icon' => 'fa-brands fa-reddit'],
    'other_search' => ['label' => 'Other Search', 'color' => '#8b5cf6', 'icon' => 'fa-solid fa-magnifying-glass'],
    'direct' => ['label' => 'Direct', 'color' => '#7c3aed', 'icon' => 'fa-solid fa-link'],
    'other' => ['label' => 'Other', 'color' => '#9ca3af', 'icon' => 'fa-solid fa-globe'],
];
function sourceMetaFor(array $sourceMeta, string $key): array {
    return $sourceMeta[$key] ?? ['label' => ucfirst($key), 'color' => '#9ca3af', 'icon' => 'fa-solid fa-globe'];
}

$countryNames = [
    'IN' => 'India', 'ID' => 'Indonesia', 'MX' => 'Mexico', 'US' => 'United States',
    'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'BR' => 'Brazil',
    'RU' => 'Russia', 'JP' => 'Japan', 'DE' => 'Germany', 'FR' => 'France',
    'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'PH' => 'Philippines', 'VN' => 'Vietnam',
    'TH' => 'Thailand', 'MY' => 'Malaysia', 'TR' => 'Turkey', 'ES' => 'Spain', 'IT' => 'Italy',
    'NG' => 'Nigeria', 'EG' => 'Egypt', 'ZA' => 'South Africa', 'KE' => 'Kenya',
    'AE' => 'UAE', 'SA' => 'Saudi Arabia', 'NP' => 'Nepal', 'LK' => 'Sri Lanka',
    'SG' => 'Singapore', 'NL' => 'Netherlands', 'PL' => 'Poland', 'AR' => 'Argentina',
    'CO' => 'Colombia', 'KR' => 'South Korea', 'CN' => 'China', 'IE' => 'Ireland',
    'NZ' => 'New Zealand', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'PT' => 'Portugal',
];
$countryColors = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#9333ea', '#0891b2', '#db2777', '#9ca3af'];

/** Country name for display: known code -> full name, 'OTHER' -> "Other", unknown code -> the code itself. */
function countryNameFor(array $countryNames, string $code): string {
    if ($code === 'OTHER') return 'Other';
    if ($code === 'XX') return 'Unknown';
    return $countryNames[$code] ?? $code;
}
/** Converts a 2-letter country code into its flag emoji. Falls back to a globe icon. */
function countryFlagEmoji(string $code): string {
    if ($code === 'XX' || !preg_match('/^[A-Z]{2}$/', $code)) return '🌐';
    $out = '';
    foreach (str_split($code) as $c) {
        $out .= mb_chr(127397 + ord($c), 'UTF-8');
    }
    return $out;
}

// ====================================================================
// Pull it all together for the selected range
// ====================================================================
$allowedRanges = ['today', 'yesterday', '7d', '30d', 'prev_month', '6m', '1y'];
$selectedRange = $_GET['range'] ?? 'today';
if (!in_array($selectedRange, $allowedRanges, true)) $selectedRange = 'today';
$bounds = getRangeBounds($selectedRange);

$topPostsPerPage = 10;
$topPostsPage = isset($_GET['tp_page']) && is_numeric($_GET['tp_page']) && (int)$_GET['tp_page'] > 0 ? (int)$_GET['tp_page'] : 1;
$topPostsOffset = ($topPostsPage - 1) * $topPostsPerPage;

$lifetimeTotal = getTotalViews($pdo, $viewsData, $owned_post_ids);

if ($statsTableReady) {
    $rangeTotal = getRangeTotal($pdo, $bounds['start'], $bounds['end'], $owned_post_ids);
    $rangePrevTotal = getRangeTotal($pdo, $bounds['prevStart'], $bounds['prevEnd'], $owned_post_ids);
    $rangeGrowth = calculateGrowth($rangeTotal, $rangePrevTotal);
    $sourceBreakdown = getRangeSources($pdo, $bounds['start'], $bounds['end'], $owned_post_ids);
    $countryBreakdown = getRangeCountries($pdo, $bounds['start'], $bounds['end'], $owned_post_ids, 7);
    $topPostsResult = getTopPostsForRange($pdo, $bounds['start'], $bounds['end'], $owned_post_ids, $topPostsPerPage, $topPostsOffset);
    $topPosts = $topPostsResult['posts'];
    $topPostsTotal = $topPostsResult['total'];
    $topPostsPages = max(1, (int)ceil($topPostsTotal / $topPostsPerPage));

    if ($bounds['granularity'] === 'hour') {
        $series = getHourlySeriesForDate($trackingData, $bounds['start'], $owned_post_ids);
    } else {
        $series = getRangeSeries($pdo, $bounds['start'], $bounds['end'], $bounds['granularity'], $owned_post_ids);
    }

    $daysInRange = max(1, (strtotime($bounds['end']) - strtotime($bounds['start'])) / 86400 + 1);
    $avgPerDay = round($rangeTotal / $daysInRange, 1);
} else {
    $rangeTotal = $rangePrevTotal = $rangeGrowth = $avgPerDay = 0;
    $sourceBreakdown = [];
    $countryBreakdown = [];
    $topPosts = [];
    $topPostsTotal = 0;
    $topPostsPages = 1;
    $series = ['labels' => [], 'data' => []];
}

$uniqueVisitors = getUniqueVisitors($pdo, $bounds['start'], $bounds['end'], $owned_post_ids);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Analytics - <?= htmlspecialchars($site_name) ?> Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
/* Dark mode toggle - identical to dashboard.php / categories-manager.php / file-manager.php / comments-manager.php / blogs-manager.php / tag-manager.php */
!function () {
    let e = localStorage.dm === "1", t = !1, n = !1,
        s = () => new Promise((e, r) => {
            let o = document.createElement("script");
            o.src = "/assets/js/darkreader.min.js";
            o.onload = () => { t = !0; e(); };
            o.onerror = r;
            document.head.appendChild(o);
        }),
        a = () => DarkReader.enable({ brightness: 100, contrast: 100, sepia: 10 }),
        d = () => DarkReader.disable(),
        i = () => {
            document.querySelectorAll(".dark-mode-toggle i").forEach(o => {
                o.classList.add("rotate");
                if (e) { o.classList.remove("fa-moon"); o.classList.add("fa-sun"); }
                else { o.classList.remove("fa-sun"); o.classList.add("fa-moon"); }
                setTimeout(() => o.classList.remove("rotate"), 400);
            });
        };
    e && (t ? a() : s().then(a));
    document.addEventListener("DOMContentLoaded", () => {
        i();
        document.querySelectorAll(".dark-mode-toggle").forEach(o => o.onclick = r);
    });
    async function r(o) {
        o.preventDefault();
        if (n) return;
        n = !0;
        let c = document.querySelectorAll(".dark-mode-toggle");
        c.forEach(e => e.classList.add("loading"));
        try {
            t || await s();
            e = !e;
            localStorage.dm = e ? "1" : "0";
            e ? a() : d();
            i();
        } finally {
            setTimeout(() => { c.forEach(e => e.classList.remove("loading")); n = !1; }, 600);
        }
    }
}();
</script>
<style>
/* ============================================================
   SHELL CSS — copied 1:1 from dashboard.php / categories-manager.php /
   file-manager.php / comments-manager.php / blogs-manager.php / tag-manager.php
   Do NOT diverge from this block on any admin page.
   ============================================================ */
:root {
    --primary: #7c3aed;
    --primary-dark: #6d28d9;
    --primary-light: #ede9fe;
    --primary-lighter: #f5f3ff;
    --success: #10b981;
    --success-light: #d1fae5;
    --warning: #f59e0b;
    --warning-light: #fef3c7;
    --danger: #ef4444;
    --danger-light: #fee2e2;
    --info: #3b82f6;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --radius: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    --sidebar-width: 280px;
}
* { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--gray-50); color: var(--gray-800); line-height: 1.5; }

.admin-container { display: grid; grid-template-columns: 1fr; min-height: 100vh; }
@media (min-width: 1024px) { .admin-container { grid-template-columns: var(--sidebar-width) 1fr; } }

.sidebar { position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-width); background: white; border-right: 1px solid var(--gray-200); z-index: 1000; transform: translateX(-100%); transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); overflow-y: auto; display: flex; flex-direction: column; }
.sidebar.open { transform: translateX(0); }
@media (min-width: 1024px) { .sidebar { position: sticky; transform: translateX(0); height: 100vh; top: 0; } }
.sidebar-header { padding: 1.5rem; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
.brand { display: flex; align-items: center; gap: 0.65rem; font-size: 1.15rem; font-weight: 800; color: var(--primary); text-decoration: none; }
.brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
.close-sidebar { width: 36px; height: 36px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--gray-600); transition: all 0.2s; }
.close-sidebar:hover { background: var(--gray-200); }
@media (min-width: 1024px) { .close-sidebar { display: none; } }
.sidebar-nav { flex: 1; padding: 1rem 0; overflow-y: auto; }
.nav-section { margin-bottom: 1.5rem; padding: 0 1rem; }
.nav-title { font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gray-400); padding: 0 1rem; margin-bottom: 0.75rem; }
.nav-link { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem 1rem; border-radius: var(--radius); color: var(--gray-600); text-decoration: none; font-size: 0.9375rem; font-weight: 500; transition: all 0.2s; margin-bottom: 0.25rem; }
.nav-link:hover { background: var(--gray-50); color: var(--gray-900); }
.nav-link.active { background: var(--primary-lighter); color: var(--primary); font-weight: 600; }
.nav-link i { width: 24px; text-align: center; font-size: 1.125rem; }
.sidebar-footer { padding: 1rem; border-top: 1px solid var(--gray-100); }
.user-card { display: flex; align-items: center; gap: 0.875rem; padding: 0.875rem; background: var(--gray-50); border-radius: var(--radius-lg); }
.user-avatar { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; }
.user-info { flex: 1; min-width: 0; }
.user-name { font-weight: 600; color: var(--gray-900); font-size: 0.9375rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 0.75rem; color: var(--gray-500); }
.sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; opacity: 0; visibility: hidden; transition: all 0.3s; }
.sidebar-overlay.active { opacity: 1; visibility: visible; }
@media (min-width: 1024px) { .sidebar-overlay { display: none; } }

.dark-mode-toggle i { transition: transform .4s ease, opacity .3s ease; }
.dark-mode-toggle i.rotate { transform: rotate(180deg); }

.main-content { min-width: 0; }
.top-nav { position: sticky; top: 0; background: white; border-bottom: 1px solid var(--gray-200); padding: 0.450rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; z-index: 100; }
.nav-left { display: flex; align-items: center; gap: 1rem; }
.menu-toggle { width: 40px; height: 40px; border: none; background: var(--gray-100); border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-700); cursor: pointer; transition: all 0.2s; }
.menu-toggle:hover { background: var(--gray-200); }
.sitename-mob { display: block; font-size: 1.15rem; font-weight: 800; color: var(--primary); }
@media (min-width: 1024px) { .menu-toggle { display: none; } }
.page-heading { display: none; }
@media (min-width: 768px) { .page-heading { display: block; } .sitename-mob { display: none; } .page-heading h1 { font-size: 1.5rem; font-weight: 700; color: var(--gray-900); } .page-heading p { font-size: 0.875rem; color: var(--gray-500); } }
.nav-right { display: flex; align-items: center; gap: 0.75rem; }
.icon-btn { width: 40px; height: 40px; border: none; background: transparent; border-radius: var(--radius); display: flex; align-items: center; justify-content: center; font-size: 1.125rem; color: var(--gray-600); cursor: pointer; transition: all 0.2s; }
.icon-btn:hover { background: var(--gray-100); }

.content-wrapper { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
@media (max-width: 640px) { .content-wrapper { padding: 1rem; } }

/* ============================================================
   PAGE-SPECIFIC CSS — Analytics only (unique to this page)
   ============================================================ */
.an-migration-note { background: var(--warning-light); border: 1px solid #fde68a; color: #92400e; border-radius: var(--radius-lg); padding: 1rem 1.25rem; font-size: .8125rem; margin-bottom: 1.25rem; line-height: 1.6; }
.an-migration-note code { background: rgba(0,0,0,.06); padding: .1rem .4rem; border-radius: 4px; }

.an-filter { background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: .75rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; box-shadow: var(--shadow-sm); }
.an-range-pills { display: flex; gap: .35rem; flex-wrap: wrap; }
.an-range-pill { display: inline-flex; align-items: center; padding: .4rem .8rem; border-radius: 9999px; font-size: .8125rem; font-weight: 600; color: var(--gray-600); background: var(--gray-50); border: 1px solid var(--gray-200); text-decoration: none; transition: all .15s; white-space: nowrap; }
.an-range-pill:hover { background: var(--gray-100); color: var(--gray-800); }
.an-range-pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }

.an-author-select { display: inline-flex; align-items: center; gap: .5rem; }
.an-author-select label { font-size: .8125rem; font-weight: 600; color: var(--gray-600); white-space: nowrap; }
.an-author-select select { padding: .4rem .7rem; border: 1px solid var(--gray-200); border-radius: var(--radius); font-size: .8125rem; font-family: inherit; outline: none; background: #fff; color: var(--gray-800); }
.an-author-select select:focus { border-color: var(--primary); }

.an-scope-badge { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .7rem; background: var(--primary-lighter); color: var(--primary-dark); border-radius: 9999px; font-size: .75rem; font-weight: 600; }

.an-stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .875rem; margin-bottom: 1.25rem; }
@media (min-width: 640px) { .an-stats-grid { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 1024px) { .an-stats-grid { grid-template-columns: repeat(4, 1fr); } }

.an-stat { background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: 1.1rem 1.25rem; box-shadow: var(--shadow-sm); }
.an-stat .an-stat-label { font-size: .75rem; font-weight: 600; color: var(--gray-500); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .55rem; }
.an-stat .an-stat-val { font-size: 1.75rem; font-weight: 700; color: var(--gray-900); line-height: 1.1; }
.an-stat .an-stat-sub { font-size: .75rem; color: var(--gray-400); margin-top: .4rem; }
@media (min-width: 1024px) and (max-width: 1279px) {
    .an-stat { padding: .9rem 1rem; }
    .an-stat .an-stat-val { font-size: 1.5rem; }
    .an-stat .an-stat-label { font-size: .7rem; }
}
.an-growth { display: inline-flex; align-items: center; gap: .25rem; font-size: .75rem; font-weight: 600; padding: .2rem .55rem; border-radius: 9999px; margin-top: .55rem; }
.an-growth.positive { background: var(--success-light); color: var(--success); }
.an-growth.negative { background: var(--danger-light); color: var(--danger); }
.an-growth.neutral { background: var(--gray-100); color: var(--gray-500); }

.an-charts { display: grid; gap: 1rem; margin-bottom: 1.25rem; grid-template-columns: 1fr; }
@media (min-width: 1024px) { .an-charts { grid-template-columns: 1.7fr 1fr; } }

.an-row2 { display: grid; gap: 1rem; margin-bottom: 1.25rem; grid-template-columns: 1fr; align-items: start; }
@media (min-width: 1024px) { .an-row2 { grid-template-columns: 1fr 1.4fr; } }
.an-row2 .an-table-wrap { margin-bottom: 0; }

.an-card { background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: 1.25rem; box-shadow: var(--shadow-sm); }
.an-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.an-card-title { font-size: .9375rem; font-weight: 700; color: var(--gray-900); display: flex; align-items: center; gap: .5rem; }
.an-card-title i { color: var(--primary); font-size: .875rem; }
.an-card-title-sub { font-size: .75rem; color: var(--gray-400); font-weight: 500; }
.an-responsive-chart { position: relative; height: 280px; width: 100%; }
.an-responsive-chart.small { height: 190px; }
.an-empty-note { text-align: center; color: var(--gray-400); font-size: .8125rem; padding: 2.5rem 1rem; }

.an-source-list { list-style: none; margin: 1rem 0 0; padding: 0; }
.an-source-row { display: flex; align-items: center; gap: .6rem; padding: .5rem 0; border-bottom: 1px solid var(--gray-100); font-size: .8125rem; }
.an-source-row:last-child { border-bottom: none; }
.an-source-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.an-source-name { flex: 1; min-width: 0; color: var(--gray-700); font-weight: 600; display: flex; align-items: center; gap: .45rem; }
.an-source-name .an-source-icon { flex-shrink: 0; }
.an-source-name .an-source-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-source-views { flex-shrink: 0; width: 3.4rem; color: var(--gray-500); font-variant-numeric: tabular-nums; text-align: right; }
.an-source-pct { flex-shrink: 0; color: var(--gray-900); font-weight: 700; width: 3.2rem; text-align: right; font-variant-numeric: tabular-nums; }

.an-table-wrap { background: #fff; border: 1px solid var(--gray-200); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; margin-bottom: 1.25rem; }
.an-pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 1rem; border-top: 1px solid var(--gray-100); }
.an-page-btn { display: inline-flex; align-items: center; gap: .4rem; padding: .45rem .9rem; font-size: .8125rem; font-weight: 600; color: var(--gray-700); background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: 8px; text-decoration: none; transition: background .15s; }
.an-page-btn:hover:not(.disabled) { background: var(--gray-100); }
.an-page-btn.disabled { opacity: .45; pointer-events: none; }
.an-page-info { font-size: .8125rem; color: var(--gray-500); font-weight: 500; }
.an-table { width: 100%; border-collapse: collapse; }
.an-table thead { background: var(--gray-50); }
.an-table th { padding: .75rem 1rem; text-align: left; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--gray-500); border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
.an-table td { padding: .75rem 1rem; font-size: .875rem; color: var(--gray-700); border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
.an-table tbody tr:last-child td { border-bottom: none; }
.an-table tbody tr:hover { background: var(--gray-50); }
.an-post-cell { display: flex; align-items: center; gap: .75rem; min-width: 220px; }
.an-post-thumb { width: 56px; height: 40px; object-fit: cover; border-radius: var(--radius); flex-shrink: 0; background: var(--gray-100); }
.an-post-title { font-weight: 600; color: var(--gray-900); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: .8125rem; line-height: 1.35; }
.an-bar-cell { width: 140px; }
.an-mini-bar { width: 100%; height: 6px; background: var(--gray-100); border-radius: 9999px; overflow: hidden; }
.an-mini-bar-fill { height: 100%; background: var(--primary); border-radius: 9999px; }
.an-rank { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; background: var(--gray-100); color: var(--gray-500); font-size: .75rem; font-weight: 700; }

@media (max-width: 640px) {
    .an-stat { padding: .85rem 1rem; }
    .an-stat .an-stat-val { font-size: 1.4rem; }
    .an-stat .an-stat-label { font-size: .6875rem; }
    .an-stat .an-stat-sub, .an-growth { font-size: .6875rem; }
    .an-card { padding: 1rem; }
    .an-responsive-chart { height: 220px; }
    .an-responsive-chart.small { height: 180px; max-width: 260px; margin: 0 auto; }
    .an-card-header { flex-wrap: wrap; gap: .35rem; }
    .an-source-row { font-size: .75rem; gap: .4rem; }
    .an-source-views { width: 2.8rem; }
    .an-source-pct { width: 2.6rem; }
    .an-table-wrap { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
    .an-table { table-layout: fixed; width: 100%; }
    .an-table th, .an-table td { padding: .6rem .5rem; font-size: .8125rem; }
    .an-table th:nth-child(3), .an-table td:nth-child(3) { text-align: right; white-space: nowrap; width: 3.5rem; }
    .an-table th:first-child, .an-table td:first-child { width: 28px; padding-right: .25rem; }
    .an-table th:nth-child(2), .an-table td:nth-child(2) { width: auto; }
    .an-bar-cell, th.an-bar-cell { display: none; }
    .an-post-cell { min-width: 0; gap: .5rem; }
    .an-post-thumb { width: 40px; height: 32px; }
    .an-post-title { font-size: .75rem; -webkit-line-clamp: 2; }
    .an-rank { width: 20px; height: 20px; font-size: .6875rem; }
}
</style>
</head>
<body>

<div class="admin-container">
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <?php include DROOT_PATH . '/admin/components/sidebar-nav.php'; ?>

    <main class="main-content">
        <header class="top-nav">
            <div class="nav-left">
                <button class="menu-toggle" type="button" onclick="toggleSidebar()" aria-label="Open menu">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="sitename-mob"><?= htmlspecialchars($site_name) ?></span>
                <div class="page-heading">
                    <h1>Analytics</h1>
                    <p><?= $can_view_all_analytics ? 'Views, top posts, and traffic sources' : 'Views and traffic for your posts' ?></p>
                </div>
            </div>
            <div class="nav-right">
                <button class="icon-btn dark-mode-toggle" type="button" aria-label="Toggle dark mode" style="background: var(--primary-lighter); color: var(--primary);">
                    <i class="fas fa-moon"></i>
                </button>
                <?php include DROOT_PATH . '/admin/components/header-user.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">

        <?php if (!$statsTableReady): ?>
        <div class="an-migration-note">
            <i class="fas fa-triangle-exclamation"></i>
            Date-range filtering, traffic-source and country tracking need one small database migration.
            Click the button below to run it (safe, one-time, takes a second), then refresh this page.
            Your existing lifetime view counts are unaffected and still shown below.
            <div style="margin-top:.75rem;">
                <a href="<?= htmlspecialchars(ADMIN_URL) ?>/exc_fn/run_analytics_migration.php" target="_blank" rel="noopener"
                   style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;background:#92400e;color:#fff;border-radius:8px;font-weight:600;text-decoration:none;font-size:.8125rem;">
                    <i class="fas fa-database"></i> Run Migration Now
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="an-filter">
            <div class="an-range-pills">
                <?php
                $rangeLabels = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 Days', '30d' => '30 Days', 'prev_month' => 'Previous Month', '6m' => '6 Months', '1y' => '1 Year'];
                foreach ($rangeLabels as $key => $label):
                    $qs = ['range' => $key];
                    if ($filter_author_id !== null) $qs['author_id'] = $filter_author_id;
                    $url = '?' . http_build_query($qs);
                ?>
                <a href="<?= htmlspecialchars($url) ?>" class="an-range-pill<?= $selectedRange === $key ? ' active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (!$can_view_all_analytics): ?>
            <div class="an-scope-badge"><i class="fas fa-user-lock"></i> Your posts only</div>
            <?php else: ?>
            <form method="GET" action="" class="an-author-select">
                <input type="hidden" name="range" value="<?= htmlspecialchars($selectedRange) ?>">
                <label for="author_id">Author</label>
                <select name="author_id" id="author_id" onchange="this.form.submit()">
                    <option value="0">All Authors</option>
                    <?php foreach ($analytics_authors as $aa): ?>
                    <option value="<?= (int)$aa['user_id'] ?>" <?= $filter_author_id === (int)$aa['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($aa['name'] ?: $aa['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>

        <div class="an-stats-grid">
            <div class="an-stat">
                <div class="an-stat-label">Total Views (All Time)</div>
                <div class="an-stat-val"><?= formatViews($lifetimeTotal) ?></div>
            </div>

            <div class="an-stat">
                <div class="an-stat-label"><?= htmlspecialchars($bounds['label']) ?> Views</div>
                <div class="an-stat-val"><?= formatViews($rangeTotal) ?></div>
                <span class="an-growth <?= $rangeGrowth > 0 ? 'positive' : ($rangeGrowth < 0 ? 'negative' : 'neutral') ?>">
                    <i class="fas fa-arrow-<?= $rangeGrowth >= 0 ? 'up' : 'down' ?>"></i>
                    <?= ($rangeGrowth > 0 ? '+' : '') . $rangeGrowth ?>% vs previous period
                </span>
            </div>

            <div class="an-stat">
                <div class="an-stat-label"><?= htmlspecialchars($bounds['label']) ?> Unique Visitors</div>
                <div class="an-stat-val"><?= formatViews($uniqueVisitors) ?></div>
                <div class="an-stat-sub">Distinct readers, <?= htmlspecialchars(strtolower($bounds['label'])) ?></div>
            </div>

            <div class="an-stat">
                <div class="an-stat-label">Avg. Views / Day</div>
                <div class="an-stat-val"><?= formatViews($avgPerDay) ?></div>
                <div class="an-stat-sub">Across <?= htmlspecialchars($bounds['label']) ?></div>
            </div>
        </div>

        <div class="an-charts">
            <div class="an-card">
                <div class="an-card-header">
                    <div class="an-card-title"><i class="fas fa-chart-line"></i> Views Over Time</div>
                    <div class="an-card-title-sub"><?= htmlspecialchars($bounds['label']) ?></div>
                </div>
                <div class="an-responsive-chart">
                    <?php if (empty($series['data']) || array_sum($series['data']) === 0): ?>
                        <div class="an-empty-note">No views recorded for this period yet</div>
                    <?php else: ?>
                        <canvas id="viewsChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <div class="an-card">
                <div class="an-card-header">
                    <div class="an-card-title"><i class="fas fa-globe"></i> Traffic Sources</div>
                </div>
                <?php if (empty($sourceBreakdown)): ?>
                    <div class="an-empty-note">No source data for this period yet</div>
                <?php else: ?>
                <div class="an-responsive-chart small">
                    <canvas id="sourceChart"></canvas>
                </div>
                <ul class="an-source-list">
                    <?php foreach ($sourceBreakdown as $s): $meta = sourceMetaFor($sourceMeta, $s['source']); ?>
                    <li class="an-source-row">
                        <span class="an-source-dot" style="background:<?= htmlspecialchars($meta['color']) ?>"></span>
                        <span class="an-source-name"><i class="an-source-icon <?= htmlspecialchars($meta['icon']) ?>"></i> <span class="an-source-label"><?= htmlspecialchars($meta['label']) ?></span></span>
                        <span class="an-source-views"><?= formatViews($s['views']) ?></span>
                        <span class="an-source-pct"><?= $s['pct'] ?>%</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="an-row2">
            <div class="an-card">
                <div class="an-card-header">
                    <div class="an-card-title"><i class="fas fa-flag"></i> Traffic by Country</div>
                    <div class="an-card-title-sub">Top 7 · rest grouped as Other</div>
                </div>
                <?php if (empty($countryBreakdown)): ?>
                    <div class="an-empty-note">No country data for this period yet</div>
                <?php else: ?>
                <div class="an-responsive-chart small">
                    <canvas id="countryChart"></canvas>
                </div>
                <ul class="an-source-list">
                    <?php foreach ($countryBreakdown as $i => $c): $flag = countryFlagEmoji($c['country']); $name = countryNameFor($countryNames, $c['country']); $dotColor = $countryColors[$i] ?? '#9ca3af'; ?>
                    <li class="an-source-row">
                        <span class="an-source-dot" style="background:<?= htmlspecialchars($dotColor) ?>"></span>
                        <span class="an-source-name"><span class="an-source-icon"><?= $flag ?></span> <span class="an-source-label" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></span></span>
                        <span class="an-source-views"><?= formatViews($c['views']) ?></span>
                        <span class="an-source-pct"><?= $c['pct'] ?>%</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="an-table-wrap">
                <div class="an-card-header" style="padding:1.25rem 1.25rem 0;">
                    <div class="an-card-title"><i class="fas fa-fire"></i> All Posts</div>
                    <div class="an-card-title-sub"><?= htmlspecialchars($bounds['label']) ?> · views per post</div>
                </div>
                <?php if (empty($topPosts)): ?>
                    <div class="an-empty-note">No post views recorded for this period yet</div>
                <?php else: ?>
                <table class="an-table">
                    <thead>
                        <tr>
                            <th style="width:36px;">#</th>
                            <th>Post</th>
                            <th>Views</th>
                            <th class="an-bar-cell">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPosts as $i => $post): ?>
                        <tr>
                            <td><span class="an-rank"><?= $topPostsOffset + $i + 1 ?></span></td>
                            <td>
                                <div class="an-post-cell">
                                    <img src="/<?= htmlspecialchars($post['banner_image'] ?? 'assets/img/logo.webp') ?>" alt="" class="an-post-thumb">
                                    <span class="an-post-title"><?= htmlspecialchars($post['title']) ?></span>
                                </div>
                            </td>
                            <td><?= formatViews($post['range_views']) ?></td>
                            <td class="an-bar-cell">
                                <div class="an-mini-bar"><div class="an-mini-bar-fill" style="width:<?= $post['percentage'] ?>%;"></div></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($topPostsPages > 1): $tpQs = ['range' => $selectedRange]; if ($filter_author_id !== null) $tpQs['author_id'] = $filter_author_id; $tpUrl = function(int $p) use ($tpQs) { $q = $tpQs; $q['tp_page'] = $p; return '?' . http_build_query($q); }; ?>
                <div class="an-pagination">
                    <a href="<?= htmlspecialchars($tpUrl(max(1, $topPostsPage - 1))) ?>"
                       class="an-page-btn <?= $topPostsPage <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                    <span class="an-page-info">Page <?= $topPostsPage ?> of <?= $topPostsPages ?></span>
                    <a href="<?= htmlspecialchars($tpUrl(min($topPostsPages, $topPostsPage + 1))) ?>"
                       class="an-page-btn <?= $topPostsPage >= $topPostsPages ? 'disabled' : '' ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        </div>
    </main>
</div>

<script>
<?php if (!empty($series['data']) && array_sum($series['data']) > 0): $maxTicks = $bounds['granularity'] === 'hour' ? 8 : 10; $peakVal = max($series['data']); ?>
const viewsCtx = document.getElementById('viewsChart').getContext('2d');
const viewsGradient = viewsCtx.createLinearGradient(0, 0, 0, 300);
viewsGradient.addColorStop(0, 'rgba(124, 58, 237, 0.25)');
viewsGradient.addColorStop(1, 'rgba(124, 58, 237, 0.02)');

new Chart(viewsCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($series['labels']) ?>,
        datasets: [{
            label: 'Views',
            data: <?= json_encode($series['data']) ?>,
            backgroundColor: viewsGradient,
            borderColor: '#7c3aed',
            borderWidth: 2.5,
            pointRadius: 0,
            pointHitRadius: 12,
            pointHoverRadius: 5,
            pointHoverBorderWidth: 2,
            pointHoverBackgroundColor: '#7c3aed',
            pointHoverBorderColor: '#fff',
            fill: true,
            tension: 0.4,
            cubicInterpolationMode: 'monotone'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: { top: 10, right: 8 } },
        interaction: { intersect: false, mode: 'index' },
        scales: {
            y: {
                beginAtZero: true,
                suggestedMax: <?= $peakVal > 0 ? $peakVal * 1.25 : 4 ?>,
                grid: { color: 'rgba(0,0,0,0.05)', drawTicks: false },
                ticks: { precision: 0, padding: 8, maxTicksLimit: 5, color: '#9ca3af', font: { size: 11 } }
            },
            x: {
                grid: { display: false },
                ticks: { autoSkip: true, maxTicksLimit: <?= $maxTicks ?>, maxRotation: 0, minRotation: 0, color: '#9ca3af', font: { size: 11 } }
            }
        },
        plugins: {
            tooltip: {
                backgroundColor: 'rgba(17,24,39,0.92)',
                padding: 10,
                cornerRadius: 8,
                displayColors: false,
                titleFont: { size: 12, weight: '600' },
                bodyFont: { size: 12 },
                callbacks: { label: (ctx) => ' ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' view' : ' views') }
            },
            legend: { display: false }
        }
    }
});
<?php endif; ?>

<?php if (!empty($sourceBreakdown)): ?>
const sourceCtx = document.getElementById('sourceChart').getContext('2d');
new Chart(sourceCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($s) => sourceMetaFor($sourceMeta, $s['source'])['label'], $sourceBreakdown)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($s) => $s['views'], $sourceBreakdown)) ?>,
            backgroundColor: <?= json_encode(array_map(fn($s) => sourceMetaFor($sourceMeta, $s['source'])['color'], $sourceBreakdown)) ?>,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 10, cornerRadius: 8 } }
    }
});
<?php endif; ?>

<?php if (!empty($countryBreakdown)): ?>
const countryCtx = document.getElementById('countryChart').getContext('2d');
new Chart(countryCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(fn($c) => countryNameFor($countryNames, $c['country']), $countryBreakdown)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($c) => $c['views'], $countryBreakdown)) ?>,
            backgroundColor: <?= json_encode(array_map(fn($i) => $countryColors[$i] ?? '#9ca3af', array_keys($countryBreakdown))) ?>,
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 10, cornerRadius: 8 } }
    }
});
<?php endif; ?>

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
    }
});

let touchStartX = 0, touchEndX = 0;
const sidebarEl = document.getElementById('sidebar');
if (sidebarEl) {
    sidebarEl.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, false);
    sidebarEl.addEventListener('touchend', e => {
        touchEndX = e.changedTouches[0].screenX;
        if (touchStartX - touchEndX > 100) toggleSidebar();
    }, false);
}
</script>

<footer>
    <div style="padding: 10px 20px; text-align: center; color: #6C6C6C;">
        <p style="font-size:0.8rem; margin-bottom:0px;">&copy; 2025-2026 <?= htmlspecialchars($site_name) ?>. All rights reserved.</p>
    </div>
</footer>

</body>
</html>
