<?php
// Public endpoint: returns existence + revocation + hash for local (client-side) verification.
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');
$cid = trim($_GET['cid'] ?? '');
if ($cid==='') { http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }
$st = $pdo->prepare("SELECT h, version, revoked_at IS NOT NULL AS revoked, revoke_reason FROM tokens WHERE cid=? LIMIT 1");
$st->execute([$cid]);
$row = $st->fetch();
if (!$row) { echo json_encode(['exists'=>false]); exit; }

echo json_encode([
  'exists'=>true,
  'h'=>$row['h'],
  'version'=>(int)$row['version'],
  'revoked'=>(bool)$row['revoked'],
  'revoke_reason'=>$row['revoked'] ? (string)$row['revoke_reason'] : null
]);
