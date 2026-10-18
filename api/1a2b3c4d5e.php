<?php
include $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/functions.php';

$post_id = isset($_GET['pId']) ? (int)$_GET['pId'] : 0;
$offset  = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit   = 5;

[$comments, $total] = getCommentTree($pdo, $post_id, $offset, $limit);

ob_start();
renderComments($comments);
$html = ob_get_clean();

echo json_encode([
    'html' => $html,
    'total' => $total
]);