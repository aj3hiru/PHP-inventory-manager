<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
require_once DROOT_PATH . '/includes/config.php';

unset($_SESSION['customer_id'], $_SESSION['customer_name']);
header('Location: /store');
exit;
