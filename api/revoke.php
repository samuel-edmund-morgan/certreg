<?php
require_once __DIR__.'/../auth.php';
require_login();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
// Apply centralized rate limiting early to avoid unnecessary DB work when over limit
require_once __DIR__.'/../rate_limit.php';
rate_limit('revoke');
$cid = trim($_POST['cid'] ?? '');
$reasonRaw = $_POST['reason'] ?? '';
$reason = preg_replace('/\s+/u',' ', trim($reasonRaw));
if($cid===''){ http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }
// Validation: required, min length 5, must contain at least one letter or digit
if($reason===''){ http_response_code(422); echo json_encode(['error'=>'empty_reason']); exit; }
if(mb_strlen($reason) < 5){ http_response_code(422); echo json_encode(['error'=>'too_short']); exit; }
if(!preg_match('/[\p{L}\p{N}]/u',$reason)){ http_response_code(422); echo json_encode(['error'=>'bad_chars']); exit; }

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
$st->execute([$revokedAt, mb_substr($reason,0,255), $row['id']]);

// Audit event
try {
  $adminId = $_SESSION['admin_id'] ?? null; $adminUser = $_SESSION['admin_user'] ?? null;
  $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
  $log->execute([$cid,'revoke', mb_substr($reason,0,255), $adminId, $adminUser, null, null]);
} catch(Throwable $e){ /* swallow logging errors */ }
echo json_encode(['ok'=>true,'revoked_at'=>$revokedAt]);
