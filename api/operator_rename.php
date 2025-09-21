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
$u = trim($_POST['username'] ?? '');
if($id<=0 || $u===''){ echo json_encode(['ok'=>false,'error'=>'input']); exit; }
if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$u)){ echo json_encode(['ok'=>false,'error'=>'uname']); exit; }
try {
    $st = $pdo->prepare('SELECT role FROM creds WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $role = $st->fetchColumn();
    if(!$role){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
    if($role==='admin'){ echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    $chk = $pdo->prepare('SELECT 1 FROM creds WHERE username=? AND id<>? LIMIT 1');
    $chk->execute([$u,$id]);
    if($chk->fetch()){ echo json_encode(['ok'=>false,'error'=>'exists']); exit; }
    $pdo->prepare('UPDATE creds SET username=? WHERE id=? LIMIT 1')->execute([$u,$id]);
    echo json_encode(['ok'=>true]);
} catch(Throwable $e){ http_response_code(500); error_log('operator_rename error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
