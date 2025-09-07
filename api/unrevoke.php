<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_once __DIR__.'/../db.php';
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$cid = trim($_POST['cid'] ?? '');
if($cid===''){ http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }

$st = $pdo->prepare("SELECT id, revoked_at FROM tokens WHERE cid=? LIMIT 1");
$st->execute([$cid]);
$row = $st->fetch();
if(!$row){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }

if(!$row['revoked_at']){
  echo json_encode(['ok'=>true,'already'=>true]);
  exit;
}

$prev = $pdo->prepare("SELECT revoke_reason, revoked_at FROM tokens WHERE id=?");
$prev->execute([$row['id']]);
$prevRow = $prev->fetch();
$st = $pdo->prepare("UPDATE tokens SET revoked_at=NULL, revoke_reason=NULL WHERE id=? LIMIT 1");
$st->execute([$row['id']]);
try {
  $adminId = $_SESSION['admin_id'] ?? null; $adminUser = $_SESSION['admin_user'] ?? null;
  $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
  $log->execute([$cid,'unrevoke', null, $adminId, $adminUser, $prevRow['revoked_at'] ?? null, $prevRow['revoke_reason'] ?? null]);
} catch(Throwable $e){ }
echo json_encode(['ok'=>true]);