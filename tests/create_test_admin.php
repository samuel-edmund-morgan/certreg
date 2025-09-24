<?php
// CLI-only script to ensure existence of a test admin user for UI automation.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
require_once __DIR__.'/../db.php';
$username = 'testadmin';
$password = 'testpass';

// Detect creds schema columns to insert/update compatibly
$cols = [];
try {
    $c = $pdo->query('SHOW COLUMNS FROM creds');
    foreach(($c?$c->fetchAll(PDO::FETCH_ASSOC):[]) as $cr){ $cols[$cr['Field']] = true; }
} catch(Throwable $e){}

$hasRole = isset($cols['role']);
$hasActive = isset($cols['is_active']);
$hasOrgId = isset($cols['org_id']);

// Ensure user exists
$st = $pdo->prepare('SELECT id FROM creds WHERE username=?');
$st->execute([$username]);
$row = $st->fetch();
if(!$row){
        $hash = password_hash($password, PASSWORD_DEFAULT, ['cost'=>12]);
        // Build dynamic insert
        $fields = ['username','passhash'];
        $values = [$username, $hash];
        if($hasRole){ $fields[]='role'; $values[]='admin'; }
        if($hasActive){ $fields[]='is_active'; $values[]=1; }
        if($hasOrgId){ $fields[]='org_id'; $values[]=null; }
        $ph = implode(',', array_fill(0,count($fields),'?'));
        $fl = implode(',', array_map(fn($f)=>"`$f`", $fields));
        $ins = $pdo->prepare("INSERT INTO creds($fl) VALUES ($ph)");
        $ins->execute($values);
        echo "Created test admin user\n";
} else {
        // Update to ensure admin role and active status where applicable
        if($hasRole){
            $pdo->prepare('UPDATE creds SET role=? WHERE username=?')->execute(['admin',$username]);
        }
        if($hasActive){
            $pdo->prepare('UPDATE creds SET is_active=1 WHERE username=?')->execute([$username]);
        }
        if($hasOrgId){
            // Ensure admin has no org context by default (null)
            try { $pdo->prepare('UPDATE creds SET org_id=NULL WHERE username=?')->execute([$username]); } catch(Throwable $e){}
        }
        echo "Test admin already exists (updated role/active if needed)\n";
}
