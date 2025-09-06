<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_once __DIR__.'/../db.php';
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$cid = trim($_POST['cid'] ?? '');
$reason = trim($_POST['reason'] ?? '');
if($cid===''){ http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }

// Check existing status
$st = $pdo->prepare("SELECT id, revoked_at FROM tokens WHERE cid=? LIMIT 1");
$st->execute([$cid]);
$row = $st->fetch();
if(!$row){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }

if($row['revoked_at']){
  echo json_encode(['ok'=>true,'already'=>true]);
  exit;
}

$revokedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$st = $pdo->prepare("UPDATE tokens SET revoked_at=?, revoke_reason=? WHERE id=? LIMIT 1");
$st->execute([$revokedAt, $reason!==''? mb_substr($reason,0,255):null, $row['id']]);
echo json_encode(['ok'=>true,'revoked_at'=>$revokedAt]);