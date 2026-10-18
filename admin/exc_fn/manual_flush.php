<?php
// Security Note: Is file ko protect karna mat bhoolna (e.g., Admin login check)
// Taki koi bhi URL open karke bar-bar DB hit na kare.

date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', 1); // Manual run me errors dikhna chahiye
ini_set('log_errors', 1);

// 1. Config aur Paths Setup
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

$cDir = $_SERVER['DOCUMENT_ROOT'] . '/cj_smart_cache';
$cFile = $cDir . '/blog_views.json';
$fFile = $cDir . '/flush_trigger.json';

// Helper Functions
function getJsonData($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function saveJsonData($file, $data) {
    $json = json_encode($data);
    if ($json === false) return false;
    return file_put_contents($file, $json, LOCK_EX);
}

// 2. Data Load Karo
$vData = getJsonData($cFile);

if (empty($vData)) {
    die("<h3>Status:</h3> <p>No pending views to flush in JSON file.</p>");
}

echo "<h3>Starting Manual Flush...</h3>";
echo "<p>Total Posts Pending: " . count($vData) . "</p>";

// 3. Database Flush Logic
$updatedVData = $vData; // Copy banayi track karne ke liye
$flushedCount = 0;
$totalViewsFlushed = 0;

$stmt = $pdo->prepare("
    INSERT INTO post_views (views, post_id)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE views = views + VALUES(views)
");

foreach ($vData as $pid => $cnt) {
    if ($cnt > 0) {
        try {
            $stmt->execute([$cnt, $pid]);
            $totalViewsFlushed += $cnt;
            $flushedCount++;
            
            // Safely remove from tracking array
            unset($updatedVData[$pid]); 
            
            echo "Post ID: <strong>$pid</strong> -> Flushed <strong>$cnt</strong> views.<br>";
            
        } catch (PDOException $e) {
            // Error handling same as your original script
            if ($e->errorInfo[1] == 1452) {
                // Foreign key fails (Post ID DB me nahi hai)
                unset($updatedVData[$pid]);
                echo "<span style='color:red;'>Error Post ID $pid: Post not found in DB (Removed from JSON).</span><br>";
            } else {
                echo "<span style='color:red;'>DB Error for Post ID $pid: " . $e->getMessage() . "</span><br>";
            }
        }
    } else {
        // Agar count 0 ya negative hai to hata do
        unset($updatedVData[$pid]);
    }
}

// 4. Update JSON Files
// Pending views file update (Jo bach gaye ya fail huye, waise sab empty hona chahiye)
saveJsonData($cFile, $updatedVData);

// Update Flush Trigger Time
$fData = ['last_flush' => time()];
saveJsonData($fFile, $fData);

echo "<hr>";
echo "<h3>Flush Complete!</h3>";
echo "Total Posts Updated: $flushedCount <br>";
echo "Total Views Added to DB: $totalViewsFlushed <br>";
echo "JSON File Cleaned. Last Flush Time Updated.";
?>
