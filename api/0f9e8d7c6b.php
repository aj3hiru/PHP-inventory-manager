<?php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
$id = (int)($_POST['id'] ?? 0);
if(!$id) exit;
$pdo->prepare("INSERT INTO post_views(post_id,views)VALUES(?,1)
ON DUPLICATE KEY UPDATE views=views+1")->execute([$id]);
echo 'ok';