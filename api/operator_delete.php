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
if($id<=0){ echo json_encode(['ok'=>false,'error'=>'id']); exit; }
try {
    $st = $pdo->prepare('SELECT role FROM creds WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $role = $st->fetchColumn();
    if(!$role){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
    if($role==='admin'){ echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    $pdo->prepare('DELETE FROM creds WHERE id=? LIMIT 1')->execute([$id]);
    echo json_encode(['ok'=>true]);
} catch(Throwable $e){ http_response_code(500); error_log('operator_delete error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
