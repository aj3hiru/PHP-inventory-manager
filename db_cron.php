<?php 
// Database Connection
$host = '127.0.0.1';
$port = 3306;
$dbname = 'your_database';
$user = 'your_db_user';
$pass = 'your_db_password'; 


try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$config = [
    'push_secret' => 'change-me-long-random-string',
    'vapid' => [
        'subject'    => 'mailto:admin@edumint24.com',
        'publicKey'  => 'your-vapid-public-key',
        'privateKey' => 'your-vapid-private-key',
    ],
];