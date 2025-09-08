<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_once __DIR__.'/../db.php';
require_csrf();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../rate_limit.php';
rate_limit('bulk');

$raw = file_get_contents('php://input');
if(!$raw){ http_response_code(400); echo json_encode(['error'=>'empty_body']); exit; }
$data = json_decode($raw,true);
if(!is_array($data)){ http_response_code(400); echo json_encode(['error'=>'bad_json']); exit; }
$action = $data['action'] ?? '';
$cids = $data['cids'] ?? [];
$reasonRaw = $data['reason'] ?? '';

if(!in_array($action,['revoke','unrevoke','delete'],true)){
  http_response_code(422); echo json_encode(['error'=>'bad_action']); exit;
}
if(!is_array($cids) || !$cids){ http_response_code(422); echo json_encode(['error'=>'no_cids']); exit; }
// Normalize & limit
$norm = [];
foreach($cids as $c){
  if(!is_string($c)) continue; $c = trim($c); if($c==='') continue; if(strlen($c)>64) continue; $norm[$c]=true; // de-dupe
}
$cids = array_keys($norm);
$MAX = 100;
if(count($cids) > $MAX){ http_response_code(422); echo json_encode(['error'=>'too_many','max'=>$MAX]); exit; }

$reason = null;
if($action==='revoke'){
  $reason = preg_replace('/\s+/u',' ', trim($reasonRaw));
  if($reason===''){ http_response_code(422); echo json_encode(['error'=>'empty_reason']); exit; }
  if(mb_strlen($reason) < 5){ http_response_code(422); echo json_encode(['error'=>'too_short']); exit; }
  if(!preg_match('/[\p{L}\p{N}]/u',$reason)){ http_response_code(422); echo json_encode(['error'=>'bad_chars']); exit; }
  $reason = mb_substr($reason,0,255);
}

$processed = 0; $skipped=0; $errors=[]; $results=[];
$adminId = $_SESSION['admin_id'] ?? null; $adminUser = $_SESSION['admin_user'] ?? null;
foreach($cids as $cid){
  try {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id, revoked_at, revoke_reason FROM tokens WHERE cid=? LIMIT 1");
    $st->execute([$cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row){ $errors[] = ['cid'=>$cid,'error'=>'not_found']; $pdo->rollBack(); continue; }
    if($action==='revoke'){
      if($row['revoked_at']){ $skipped++; $pdo->rollBack(); continue; }
      $revokedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
      $up = $pdo->prepare("UPDATE tokens SET revoked_at=?, revoke_reason=? WHERE id=? LIMIT 1");
      $up->execute([$revokedAt,$reason,$row['id']]);
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
      $log->execute([$cid,'revoke',$reason,$adminId,$adminUser,null,null]);
      $pdo->commit(); $processed++; $results[]=['cid'=>$cid,'revoked_at'=>$revokedAt];
    } elseif($action==='unrevoke') {
      if(!$row['revoked_at']){ $skipped++; $pdo->rollBack(); continue; }
      $prevRev = $row['revoked_at']; $prevReason = $row['revoke_reason'];
      $up = $pdo->prepare("UPDATE tokens SET revoked_at=NULL, revoke_reason=NULL WHERE id=? LIMIT 1");
      $up->execute([$row['id']]);
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
      $log->execute([$cid,'unrevoke',null,$adminId,$adminUser,$prevRev,$prevReason]);
      $pdo->commit(); $processed++; $results[]=['cid'=>$cid,'unrevoked'=>true];
    } elseif($action==='delete') {
      $prevRev = $row['revoked_at']; $prevReason = $row['revoke_reason'];
      $del = $pdo->prepare("DELETE FROM tokens WHERE id=? LIMIT 1");
      $del->execute([$row['id']]);
      if($del->rowCount()<1){ $pdo->rollBack(); $errors[]=['cid'=>$cid,'error'=>'delete_failed']; continue; }
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
      $log->execute([$cid,'delete',null,$adminId,$adminUser,$prevRev,$prevReason]);
      $pdo->commit(); $processed++; $results[]=['cid'=>$cid,'deleted'=>true];
    }
  } catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = ['cid'=>$cid,'error'=>'exception'];
  }
}
echo json_encode(['ok'=>true,'action'=>$action,'processed'=>$processed,'skipped'=>$skipped,'errors'=>$errors,'results'=>$results]);
?>