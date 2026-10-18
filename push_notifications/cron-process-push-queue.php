<?php
$_SERVER['DOCUMENT_ROOT'] = dirname(__FILE__) . '/..';
require $_SERVER['DOCUMENT_ROOT'] . '/db_cron.php';
require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/push_notifications/push_cron.log';

function logg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

$webPush = new WebPush(['VAPID' => $config['vapid']]);
$webPush->setReuseVAPIDHeaders(true);

$batchSize    = 300;
$maxAttempts  = 3;

try {
    $stmt = $pdo->query("
        SELECT id, status 
        FROM push_campaigns 
        WHERE status IN ('pending', 'processing')
        ORDER BY created_at ASC 
        LIMIT 1 
        FOR UPDATE SKIP LOCKED
    ");
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        exit;
    }

    $campaign_id = $campaign['id'];

    if ($campaign['status'] === 'pending') {
        $pdo->prepare("UPDATE push_campaigns SET status = 'processing' WHERE id = ?")
            ->execute([$campaign_id]);
    }

    $stmt = $pdo->prepare("SELECT title, body, image, url FROM push_campaigns WHERE id = ?");
    $stmt->execute([$campaign_id]);
    $campaignData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaignData) {
        logg("No data found for campaign #$campaign_id");
        exit;
    }

    $payload = json_encode([
        'title' => $campaignData['title'],
        'body'  => $campaignData['body'],
        'image' => $campaignData['image'],
        'url'   => $campaignData['url']
    ], JSON_UNESCAPED_SLASHES);

    $pdo->beginTransaction();

    $q = $pdo->prepare("
        SELECT q.id AS queue_id, q.subscription_id, s.subscription
        FROM push_queue q
        INNER JOIN push_subscriptions s ON q.subscription_id = s.id
        WHERE q.campaign_id = ? AND q.status = 'pending'
        LIMIT $batchSize
        FOR UPDATE SKIP LOCKED
    ");
    $q->execute([$campaign_id]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $pdo->commit();
        
        $pdo->prepare("UPDATE push_campaigns SET status = 'completed' WHERE id = ?")->execute([$campaign_id]);
        exit;
    }

    $toDeleteQueue = [];
    $toDeleteSubs  = [];
    
    $batchSent = 0;
    $batchFailed = 0;

    foreach ($rows as $row) {
        try {
            $subData = json_decode($row['subscription'], true);
            if (empty($subData['endpoint'])) {
                $toDeleteSubs[] = $row['subscription_id'];
                $toDeleteQueue[] = $row['queue_id'];
                $batchFailed++; 
                continue;
            }

            $subscription = Subscription::create($subData);
            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSuccess()) {
                $toDeleteQueue[] = $row['queue_id'];
                $batchSent++;
            } else {
                $code = $report->getResponse()?->getStatusCode();

                if (in_array($code, [410, 404])) {
                    $toDeleteSubs[]  = $row['subscription_id'];
                    $toDeleteQueue[] = $row['queue_id'];
                    $batchFailed++;
                } else {
                    $pdo->prepare("
                        UPDATE push_queue 
                        SET attempts = attempts + 1, 
                            last_error = ?
                        WHERE id = ?
                    ")->execute([$report->getReason(), $row['queue_id']]);

                    $attempts = $pdo->query("SELECT attempts FROM push_queue WHERE id = {$row['queue_id']}")->fetchColumn();

                    if ($attempts >= $maxAttempts) {
                        $toDeleteQueue[] = $row['queue_id'];
                        $batchFailed++;
                    }
                }
            }
        } catch (Exception $ex) {
            logg("Error sending to queue #{$row['queue_id']}: " . $ex->getMessage());
            $toDeleteQueue[] = $row['queue_id'];
            $batchFailed++;
        }
    }

    if ($toDeleteQueue) {
        $placeholders = implode(',', array_fill(0, count($toDeleteQueue), '?'));
        $pdo->prepare("DELETE FROM push_queue WHERE id IN ($placeholders)")
            ->execute($toDeleteQueue);
    }

    if ($toDeleteSubs) {
        $placeholders = implode(',', array_fill(0, count($toDeleteSubs), '?'));
        $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($placeholders)")
            ->execute($toDeleteSubs);
    }

    $pdo->prepare("
        UPDATE push_campaigns 
        SET sent = sent + ?, failed = failed + ? 
        WHERE id = ?
    ")->execute([$batchSent, $batchFailed, $campaign_id]);

    $remaining = $pdo->query("SELECT COUNT(*) FROM push_queue WHERE campaign_id = $campaign_id")->fetchColumn();
    
    if ($remaining == 0) {
        $pdo->prepare("UPDATE push_campaigns SET status = 'completed' WHERE id = ?")->execute([$campaign_id]);
    }

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logg("CRITICAL ERROR: " . $e->getMessage());
}