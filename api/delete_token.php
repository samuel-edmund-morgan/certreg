<?php
require_once __DIR__.'/../auth.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['error'=>'method']); exit; }
if(!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')){ http_response_code(400); echo json_encode(['error'=>'csrf']); exit; }
$cid = trim($_POST['cid'] ?? '');
if($cid===''){ http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }
require_once __DIR__.'/../db.php';
// Capture previous state (revocation info) before delete for audit
$prev = $pdo->prepare('SELECT revoked_at, revoke_reason FROM tokens WHERE cid=? LIMIT 1');
$prev->execute([$cid]);
$prevRow = $prev->fetch();
$st = $pdo->prepare('DELETE FROM tokens WHERE cid=? LIMIT 1');
$st->execute([$cid]);
if($st->rowCount()<1){ echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
try {
	$adminId = $_SESSION['admin_id'] ?? null; $adminUser = $_SESSION['admin_user'] ?? null;
	$log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
	$log->execute([$cid,'delete', null, $adminId, $adminUser, $prevRow['revoked_at'] ?? null, $prevRow['revoke_reason'] ?? null]);
} catch(Throwable $e){ }
echo json_encode(['ok'=>true]);
