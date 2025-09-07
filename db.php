<?php
$config = require __DIR__.'/config.php';
// Allow code to define USE_PUBLIC_DB before including to force least-privilege account (e.g. status API)
$usePublic = defined('USE_PUBLIC_DB') && USE_PUBLIC_DB && !empty($config['db_public_user']) && !empty($config['db_public_pass']);
$dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
  $pdo = new PDO($dsn,
    $usePublic ? $config['db_public_user'] : $config['db_user'],
    $usePublic ? $config['db_public_pass'] : $config['db_pass'],
    $options
  );
} catch (PDOException $e) {
  http_response_code(500);
  exit('Помилка зʼєднання з БД');
}
