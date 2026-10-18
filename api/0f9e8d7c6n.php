<?php
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

if (!function_exists('_request_is_https')) {
    function _request_is_https(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
        if (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') return true;
        if (($_SERVER['SERVER_PORT'] ?? '') == '443') return true;
        return false;
    }
}

// Basic hardening: on production, only accept same-origin, HTTPS, POST requests.
if (APP_ENV === 'production') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($origin)) {
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $ownHost = strtolower((string)parse_url(APP_URL, PHP_URL_HOST));
        if ($originHost === '' || $originHost !== $ownHost) {
            http_response_code(403);
            exit;
        }
    }
    if (!_request_is_https()) {
        http_response_code(403);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
}

// Simple per-session rate limit so one visitor can't hammer this endpoint.
$now_rl = time();
$_SESSION['track_hits'] = array_filter(
    $_SESSION['track_hits'] ?? [],
    fn($t) => ($now_rl - $t) < 60
);
if (count($_SESSION['track_hits']) >= 60) {
    http_response_code(429);
    exit;
}
$_SESSION['track_hits'][] = $now_rl;

$pid = (int)($_POST['id'] ?? 0);
if (!$pid) exit;

// ------------------------------------------------------------------
// Unique-visitor cookie + visitor_log (powers "Unique Visitors" stat)
// ------------------------------------------------------------------
if (!empty($_COOKIE['cms_visitor_id'])) {
    $visitorId = $_COOKIE['cms_visitor_id'];
} else {
    $visitorId = bin2hex(random_bytes(16));
    setcookie('cms_visitor_id', $visitorId, time() + 31536000, '/', '', true, true);
}

try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO visitor_log (visit_date, visitor_id, post_id) VALUES (CURDATE(), ?, ?)");
    $stmt->execute([$visitorId, $pid]);
} catch (PDOException $e) {
    error_log('visitor_log insert failed: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// Traffic source classification (powers the "Traffic Sources" card)
// ------------------------------------------------------------------
function classify_traffic_source(string $referrer, string $ownHost): string {
    $referrer = trim($referrer);
    if ($referrer === '') return 'direct';
    $host = strtolower((string)parse_url($referrer, PHP_URL_HOST));
    if ($host === '' || $host === strtolower($ownHost)) return 'direct';
    $host = preg_replace('/^www\./', '', $host);
    $map = [
        'google.' => 'google', 'facebook.' => 'facebook', 'fb.' => 'facebook',
        'instagram.' => 'instagram', 'twitter.' => 'twitter', 'x.com' => 'twitter',
        't.co' => 'twitter', 'youtube.' => 'youtube', 'youtu.be' => 'youtube',
        'whatsapp.' => 'whatsapp', 'wa.me' => 'whatsapp', 'telegram.' => 'telegram',
        't.me' => 'telegram', 'pinterest.' => 'pinterest', 'linkedin.' => 'linkedin',
        'bing.' => 'bing', 'yahoo.' => 'yahoo', 'duckduckgo.' => 'other_search',
        'reddit.' => 'reddit',
    ];
    foreach ($map as $needle => $source) {
        if (strpos($host, $needle) !== false) return $source;
    }
    return 'other';
}

$refHost = $_SERVER['HTTP_HOST'] ?? (parse_url(APP_URL, PHP_URL_HOST) ?? '');
$rawReferrer = substr((string)($_POST['ref'] ?? ''), 0, 500);
$trafficSource = classify_traffic_source($rawReferrer, $refHost);

// Country comes from Cloudflare's CF-IPCountry header, which Cloudflare adds to every
// request that passes through its proxy. 'XX' means unknown (site accessed directly,
// bypassing Cloudflare, or the header wasn't present for some other reason).
$visitorCountry = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX')));
if (!preg_match('/^[A-Z]{2}$/', $visitorCountry)) {
    $visitorCountry = 'XX';
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO post_stats_daily (post_id, stat_date, source, country, views)
        VALUES (?, CURDATE(), ?, ?, 1)
        ON DUPLICATE KEY UPDATE views = views + 1
    ");
    $stmt->execute([$pid, $trafficSource, $visitorCountry]);
} catch (PDOException $e) {
    error_log('post_stats_daily insert failed: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// Existing lightweight JSON-cache tracking (unchanged behaviour) —
// still powers the lifetime view counter and the "Today" hourly chart.
// ------------------------------------------------------------------
$cDir = $_SERVER['DOCUMENT_ROOT'] . '/cj_smart_cache';
if (!file_exists($cDir)) mkdir($cDir, 0755, true);

$cFile = $cDir . '/blog_views.json';
$tFile = $cDir . '/views_tracking.json';
$dFile = $cDir . '/daily_performance.json';
$fFile = $cDir . '/flush_trigger.json';

function secure_json_modify($filepath, callable $callback) {
    $fp = fopen($filepath, 'c+');
    if (!$fp) return [];

    if (flock($fp, LOCK_EX)) {
        $size = fstat($fp)['size'];
        $content = $size > 0 ? fread($fp, $size) : '{}';
        $data = json_decode($content, true);
        if (!is_array($data)) $data = [];

        $data = $callback($data);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $data;
}

$vData = secure_json_modify($cFile, function($data) use ($pid) {
    if (!isset($data[$pid])) $data[$pid] = 0;
    $data[$pid]++;
    return $data;
});

$fData = secure_json_modify($fFile, function($data) {
    if (!isset($data['last_flush'])) $data['last_flush'] = 0;
    return $data;
});

$now = time();
$lastFlush = $fData['last_flush'];
$hrsSince = ($now - $lastFlush) / 3600;
$currentViews = $vData[$pid] ?? 0;

if ($currentViews >= 10 || $hrsSince >= 24) {

    if ($currentViews >= 10) {
        try {
            $stmt = $pdo->prepare("INSERT INTO post_views (views, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + VALUES(views)");
            $stmt->execute([$currentViews, $pid]);

            secure_json_modify($cFile, function($data) use ($pid) {
                unset($data[$pid]);
                return $data;
            });

            if (extension_loaded('apcu')) apcu_delete('total_tr_views_cache');
        } catch (PDOException $e) {
            error_log($e->getMessage());
        }
    }

    if ($hrsSince >= 24) {
        $finalData = secure_json_modify($cFile, function($data) {
            return $data;
        });

        if (!empty($finalData)) {
            $stmt = $pdo->prepare("INSERT INTO post_views (views, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + VALUES(views)");
            $flushedKeys = [];

            foreach ($finalData as $p => $cnt) {
                if ($cnt > 0) {
                    try {
                        $stmt->execute([$cnt, $p]);
                        $flushedKeys[] = $p;
                    } catch (PDOException $e) {
                        if ($e->errorInfo[1] == 1452) $flushedKeys[] = $p;
                    }
                }
            }

            if (!empty($flushedKeys)) {
                secure_json_modify($cFile, function($data) use ($flushedKeys) {
                    foreach ($flushedKeys as $k) unset($data[$k]);
                    return $data;
                });
                if (extension_loaded('apcu')) apcu_delete('total_tr_views_cache');
            }
        }

        secure_json_modify($fFile, function($data) use ($now) {
            $data['last_flush'] = $now;
            return $data;
        });
    }
}

secure_json_modify($tFile, function($data) use ($pid) {
    $hr = date('Y-m-d H:00:00');
    if (!isset($data[$hr])) $data[$hr] = [];
    if (!isset($data[$hr][$pid])) $data[$hr][$pid] = 0;
    $data[$hr][$pid]++;

    // Kept at 48h (not 24h) so the "Yesterday" range can still show an
    // hour-by-hour breakdown from this same file.
    $cutoff = strtotime('-48 hours');
    foreach ($data as $h => $val) {
        if (strtotime($h) < $cutoff) unset($data[$h]);
    }
    return $data;
});

secure_json_modify($dFile, function($data) use ($pid) {
    $dt = date('Y-m-d');
    if (!isset($data[$dt])) $data[$dt] = [];
    if (!isset($data[$dt][$pid])) $data[$dt][$pid] = 0;
    $data[$dt][$pid]++;

    $cutoff = strtotime('-7 days');
    foreach ($data as $d => $val) {
        if (strtotime($d) < $cutoff) unset($data[$d]);
    }
    return $data;
});
