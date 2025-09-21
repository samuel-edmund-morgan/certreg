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
    // Ensure is_active column exists; if not, abort with explicit code
    $hasActive = false; try { $c=$pdo->query("SHOW COLUMNS FROM `creds` LIKE 'is_active'"); $hasActive = $c && $c->rowCount()===1; } catch(Throwable $ie){ $hasActive=false; }
    if(!$hasActive){ echo json_encode(['ok'=>false,'error'=>'unsupported']); exit; }
    $st = $pdo->prepare('SELECT id, role, is_active FROM creds WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
    if($row['role']==='admin'){ echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    $new = $row['is_active']?0:1;
    $up = $pdo->prepare('UPDATE creds SET is_active=? WHERE id=? LIMIT 1');
    $up->execute([$new,$id]);
    echo json_encode(['ok'=>true,'is_active'=>$new]);
} catch(Throwable $e){ http_response_code(500); error_log('operator_toggle_active error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
