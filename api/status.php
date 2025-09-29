<?php
// Public endpoint: returns existence + revocation + hash for local (client-side) verification.
// Use least-privilege DB user if configured
if(!defined('USE_PUBLIC_DB')) define('USE_PUBLIC_DB', true);
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../rate_limit.php';
rate_limit('status');
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
$cid = trim($_GET['cid'] ?? '');
if ($cid==='') { http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }
$hasAwardColumn = false;
try {
  $chkAward = $pdo->query("SHOW COLUMNS FROM `tokens` LIKE 'award_title'");
  $hasAwardColumn = ($chkAward && $chkAward->fetch() !== false);
} catch(Throwable $e){ $hasAwardColumn = false; }
$selectCols = 'h, version, revoked_at, revoked_at IS NOT NULL AS revoked, revoke_reason, valid_until';
if($hasAwardColumn){ $selectCols .= ', award_title'; }
$st = $pdo->prepare("SELECT $selectCols FROM tokens WHERE cid=? LIMIT 1");
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

// Expiry logic for v2
$cfg = require __DIR__.'/../config.php';
$sentinel = $cfg['infinite_sentinel'] ?? '4000-01-01';
$validUntil = $row['valid_until'] ?? null;
$expired = false;
if(($row['version']==2 || $row['version']==3) && $validUntil){
  if($validUntil !== $sentinel){
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    if(strcmp($validUntil,$today) < 0) $expired = true;
  }
}
echo json_encode([
  'exists'=>true,
  'h'=>$row['h'],
  'version'=>(int)$row['version'],
  'revoked'=>(bool)$row['revoked'],
  'revoke_reason'=>$row['revoked'] ? (string)$row['revoke_reason'] : null,
  'revoked_at'=>$row['revoked'] ? $row['revoked_at'] : null,
  'valid_until'=>$validUntil,
  'expired'=>$expired,
  'award_title'=>$hasAwardColumn ? $row['award_title'] : null
]);
