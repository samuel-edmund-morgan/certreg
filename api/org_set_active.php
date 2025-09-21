<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../db.php';
// Load config to determine default organization code (cannot be disabled)
$config = require __DIR__.'/../config.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
$id = (int)($_POST['id'] ?? 0);
$active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
if($id<=0){ echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }
try {
  $st=$pdo->prepare('SELECT id,code,is_active FROM organizations WHERE id=?'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
  // Prevent deactivating the default organization (identified by immutable code from config)
  if($row['code'] === ($config['org_code'] ?? '') && (int)$active !== 1){
    echo json_encode(['ok'=>false,'error'=>'default_protected']);
    exit;
  }
  // If already desired state, short‑circuit success (idempotent)
  if((int)$row['is_active'] === (int)$active){
    echo json_encode(['ok'=>true,'id'=>$id,'is_active'=>$active,'unchanged'=>true]);
    exit;
  }
  $up=$pdo->prepare('UPDATE organizations SET is_active=? WHERE id=? LIMIT 1'); $up->execute([$active,$id]);
  echo json_encode(['ok'=>true,'id'=>$id,'is_active'=>$active]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
