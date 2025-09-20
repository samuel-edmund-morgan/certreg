<?php
require_once __DIR__.'/../auth.php';
require_login(); // allow both admin & operator to change own password
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');

// Only accept POST form (not JSON) for simplicity & reuse of csrf hidden input.
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method']);
    exit;
}

$uid = (int)($_SESSION['admin_id'] ?? 0);
if($uid <= 0){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'auth']); exit; }

$old = $_POST['old_password'] ?? '';
$new = $_POST['new_password'] ?? '';
$new2 = $_POST['new_password2'] ?? '';

$errors = [];
if($old === '') $errors['old_password'] = 'required';
if($new === '') $errors['new_password'] = 'required';
if($new2 === '') $errors['new_password2'] = 'required';
if(!$errors && $new !== $new2){ $errors['new_password2'] = 'mismatch'; }
if(!$errors){
    if(strlen($new) < 8){ $errors['new_password'] = 'too_short'; }
    // Basic strength heuristic: require at least one letter & one number
    if(!preg_match('/[A-Za-z]/',$new) || !preg_match('/\d/',$new)){
        $errors['new_password'] = 'weak';
    }
}
if($errors){ echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

// Fetch current hash
$st = $pdo->prepare('SELECT passhash FROM creds WHERE id=? LIMIT 1');
$st->execute([$uid]);
$row = $st->fetch();
if(!$row || !password_verify($old, $row['passhash'])){
    http_response_code(422);
    echo json_encode(['ok'=>false,'errors'=>['old_password'=>'invalid']]);
    exit;
}

try {
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $opts = $algo === PASSWORD_ARGON2ID ? [
        'memory_cost' => 1<<17,
        'time_cost'   => 3,
        'threads'     => 1,
    ] : ['cost'=>12];
    $newHash = password_hash($new, $algo, $opts);
    $up = $pdo->prepare('UPDATE creds SET passhash=? WHERE id=? LIMIT 1');
    $up->execute([$newHash, $uid]);
    // Optional: rotate session id to prevent fixation after credential change
    session_regenerate_id(true);
    echo json_encode(['ok'=>true]);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server']);
}
?>
