<?php
// Robust path resolution
$root = dirname(__DIR__);
require_once $root.'/auth.php';
require_once $root.'/db.php';
require_admin();
require_csrf();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
$id = (int)($_POST['id'] ?? 0);
$p1 = $_POST['password'] ?? '';
$p2 = $_POST['password2'] ?? '';
if($id<=0){ echo json_encode(['ok'=>false,'error'=>'id']); exit; }
if($p1==='' || $p2===''){ echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
if($p1!==$p2){ echo json_encode(['ok'=>false,'error'=>'mismatch']); exit; }
if(strlen($p1) < 8){ echo json_encode(['ok'=>false,'error'=>'short']); exit; }
try {
    $st = $pdo->prepare('SELECT role FROM creds WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $role = $st->fetchColumn();
    if(!$role){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
    if($role==='admin'){ echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $opts = $algo === PASSWORD_ARGON2ID ? ['memory_cost'=>1<<17,'time_cost'=>3,'threads'=>1] : ['cost'=>12];
    $hash = password_hash($p1,$algo,$opts);
    $pdo->prepare('UPDATE creds SET passhash=? WHERE id=? LIMIT 1')->execute([$hash,$id]);
    echo json_encode(['ok'=>true]);
} catch(Throwable $e){ http_response_code(500); error_log('operator_reset_password error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
