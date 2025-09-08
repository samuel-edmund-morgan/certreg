<?php
// CLI-only script to ensure existence of a test admin user for UI automation.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
require_once __DIR__.'/../db.php';
$username = 'testadmin';
$password = 'testpass';
$st = $pdo->prepare('SELECT id FROM creds WHERE username=?');
$st->execute([$username]);
if(!$st->fetch()){
    $hash = password_hash($password, PASSWORD_DEFAULT, ['cost'=>12]);
    $ins = $pdo->prepare('INSERT INTO creds(username, passhash) VALUES(?,?)');
    $ins->execute([$username, $hash]);
    echo "Created test admin user\n";
} else {
    echo "Test admin already exists\n";
}
