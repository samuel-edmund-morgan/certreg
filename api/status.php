<?php
// Public endpoint: returns existence + revocation + hash for local (client-side) verification.
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');
$cid = trim($_GET['cid'] ?? '');
if ($cid==='') { http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }
$st = $pdo->prepare("SELECT h, version, revoked_at, revoked_at IS NOT NULL AS revoked, revoke_reason FROM tokens WHERE cid=? LIMIT 1");
$st->execute([$cid]);
$row = $st->fetch();
if (!$row) { echo json_encode(['exists'=>false]); exit; }

// Increment lookup counters (best-effort, ignore race conditions) and audit lookup
try {
  $upd = $pdo->prepare("UPDATE tokens SET lookup_count = lookup_count + 1, last_lookup_at=NOW() WHERE cid=? LIMIT 1");
  $upd->execute([$cid]);
  // Log lookup event (no user id, public endpoint)
  $elog = $pdo->prepare("INSERT INTO token_events (cid,event_type) VALUES (?,?)");
  $elog->execute([$cid,'lookup']);
} catch (PDOException $e) { /* ignore to avoid impacting public availability */ }

echo json_encode([
  'exists'=>true,
  'h'=>$row['h'],
  'version'=>(int)$row['version'],
  'revoked'=>(bool)$row['revoked'],
  'revoke_reason'=>$row['revoked'] ? (string)$row['revoke_reason'] : null,
  'revoked_at'=>$row['revoked'] ? $row['revoked_at'] : null
]);
