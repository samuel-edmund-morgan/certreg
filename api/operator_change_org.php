<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
$id = (int)($_POST['id'] ?? 0);
$orgId = (int)($_POST['org_id'] ?? 0);
if($id<=0 || $orgId<=0){ echo json_encode(['ok'=>false,'error'=>'bad_input']); exit; }
try {
  // Ensure org exists & active
  $orgSt = $pdo->prepare('SELECT id,is_active FROM organizations WHERE id=? LIMIT 1');
  $orgSt->execute([$orgId]);
  $org = $orgSt->fetch(PDO::FETCH_ASSOC);
  if(!$org){ echo json_encode(['ok'=>false,'error'=>'org_nf']); exit; }
  if((int)$org['is_active']!==1){ echo json_encode(['ok'=>false,'error'=>'org_inactive']); exit; }
  $st = $pdo->prepare('SELECT id,role FROM creds WHERE id=? LIMIT 1');
  $st->execute([$id]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if(!$user){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
  if($user['role']==='admin'){ echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
  $colExists = false; try { $c=$pdo->query("SHOW COLUMNS FROM `creds` LIKE 'org_id'"); $colExists = $c && $c->rowCount()===1; } catch(Throwable $e){ $colExists=false; }
  if(!$colExists){ echo json_encode(['ok'=>false,'error'=>'org_col_missing']); exit; }
  $up = $pdo->prepare('UPDATE creds SET org_id=? WHERE id=? LIMIT 1');
  $up->execute([$orgId,$id]);
  echo json_encode(['ok'=>true,'id'=>$id,'org_id'=>$orgId]);
} catch(Throwable $e){ http_response_code(500); error_log('operator_change_org error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
