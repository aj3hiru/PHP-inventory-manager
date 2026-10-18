<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/session-cookie.php';
$_SESSION['cTkn'] ??= bin2hex(random_bytes(32));
header('Content-Type: application/json');
echo json_encode(['token'=>$_SESSION['cTkn']]);
?>