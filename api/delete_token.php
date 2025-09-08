<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_once __DIR__.'/../db.php';
require_csrf();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['error'=>'method']); exit; }
require_once __DIR__.'/../rate_limit.php';
rate_limit('delete');

$cid = trim($_POST['cid'] ?? '');
if($cid===''){ http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }

try {
	$pdo->beginTransaction();
	// Capture previous revocation state for audit trail
	$prev = $pdo->prepare('SELECT revoked_at, revoke_reason FROM tokens WHERE cid=? LIMIT 1');
	$prev->execute([$cid]);
	$prevRow = $prev->fetch();
	if(!$prevRow){
		$pdo->rollBack();
		echo json_encode(['ok'=>false,'error'=>'not_found']);
		exit;
	}
	$del = $pdo->prepare('DELETE FROM tokens WHERE cid=? LIMIT 1');
	$del->execute([$cid]);
	if($del->rowCount()<1){
		$pdo->rollBack();
		echo json_encode(['ok'=>false,'error'=>'not_found']);
		exit;
	}
	$adminId = $_SESSION['admin_id'] ?? null; $adminUser = $_SESSION['admin_user'] ?? null;
	$log = $pdo->prepare("INSERT INTO token_events (cid,event_type,reason,admin_id,admin_user,prev_revoked_at,prev_revoke_reason) VALUES (?,?,?,?,?,?,?)");
	$log->execute([$cid,'delete', null, $adminId, $adminUser, $prevRow['revoked_at'] ?? null, $prevRow['revoke_reason'] ?? null]);
	$pdo->commit();
	echo json_encode(['ok'=>true]);
} catch(Throwable $e){
	if($pdo->inTransaction()) $pdo->rollBack();
	http_response_code(500);
	echo json_encode(['error'=>'server']);
}
