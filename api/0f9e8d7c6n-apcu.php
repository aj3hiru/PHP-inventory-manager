<?php
date_default_timezone_set('Asia/Kolkata');
require_once $_SERVER['DOCUMENT_ROOT'].'/includes/config.php';

$pid = (int)($_POST['id'] ?? 0);
if (!$pid) exit;

$dir = $_SERVER['DOCUMENT_ROOT'].'/cj_smart_cache';
$vFile = $dir.'/blog_views.json';
$hFile = $dir.'/views_tracking.json';
$dFile = $dir.'/daily_performance.json';
$fFile = $dir.'/flush_trigger.json';

if (!is_dir($dir)) mkdir($dir, 0755, true);

const A_V = 'a1';
const A_H = 'a2';
const A_D = 'a3';
const A_F = 'a4';

$views = apcu_fetch(A_V) ?: [];
$views[$pid] = ($views[$pid] ?? 0) + 1;
apcu_store(A_V, $views);

$hr = date('Y-m-d H:00:00');
$hh = apcu_fetch(A_H) ?: [];
$hh[$hr][$pid] = ($hh[$hr][$pid] ?? 0) + 1;
apcu_store(A_H, $hh);

$dy = date('Y-m-d');
$dd = apcu_fetch(A_D) ?: [];
$dd[$dy][$pid] = ($dd[$dy][$pid] ?? 0) + 1;
apcu_store(A_D, $dd);

$lastSync = (int)(apcu_fetch('sync_ts')?:0);
$syncCount = (int)(apcu_fetch('sync_count')?:0) + 1;
apcu_store('sync_count', $syncCount);

if(time() - $lastSync >= 30 || $syncCount >= 30) {
    $oldV = file_exists($vFile) ? @json_decode(file_get_contents($vFile), true) : [];
    $oldH = file_exists($hFile) ? @json_decode(file_get_contents($hFile), true) : [];
    $oldD = file_exists($dFile) ? @json_decode(file_get_contents($dFile), true) : [];
    $oldF = file_exists($fFile) ? @json_decode(file_get_contents($fFile), true) : [];
    
    if(!isset($oldF['last_flush'])) $oldF['last_flush'] = 0;

    $liveV = apcu_fetch(A_V) ?: [];
    $liveH = apcu_fetch(A_H) ?: [];
    $liveD = apcu_fetch(A_D) ?: [];
    $liveF = (int)(apcu_fetch(A_F) ?: $oldF['last_flush']);

    foreach($liveV as $p=>$c) $oldV[$p] = ($oldV[$p]??0) + $c;
    foreach($liveH as $h=>$posts) foreach($posts as $p=>$c) $oldH[$h][$p] = ($oldH[$h][$p]??0) + $c;
    foreach($liveD as $d=>$posts) foreach($posts as $p=>$c) $oldD[$d][$p] = ($oldD[$d][$p]??0) + $c;

    $now = time();

    try {
        $stmt = $pdo->prepare("INSERT INTO post_views (views,post_id) VALUES (?,?) ON DUPLICATE KEY UPDATE views=views+VALUES(views)");

        foreach($oldV as $p=>$total){
            if($total >= 10){
                $stmt->execute([$total, $p]);
                unset($oldV[$p]);
            }
        }

        if($oldF['last_flush'] > 0 && ($now - $oldF['last_flush']) >= 86400){
            foreach($oldV as $p=>$c){
                if($c>0) $stmt->execute([$c,$p]);
            }
            $oldV = [];
            $oldF['last_flush'] = $now;
            $liveF = $now;
        }
    } catch(Exception $e){}

    $cut24 = strtotime('-24 hours');
    foreach($oldH as $h=>$x) if(strtotime($h) < $cut24) unset($oldH[$h]);
    $cut7 = strtotime('-7 days');
    foreach($oldD as $d=>$x) if(strtotime($d) < $cut7) unset($oldD[$d]);

    $write = fn($data,$file) => @file_put_contents($file.'.tmp', json_encode($data)) && @rename($file.'.tmp',$file);

    $write($oldV, $vFile);
    $write($oldH, $hFile);
    $write($oldD, $dFile);
    $write($oldF, $fFile);

    apcu_store(A_V, []);
    apcu_store(A_H, []);
    apcu_store(A_D, []);
    apcu_store(A_F, $liveF);
    apcu_store('sync_ts', time());
    apcu_store('sync_count', 0);
}

exit;