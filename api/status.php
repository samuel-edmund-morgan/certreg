<?php
// Public endpoint: returns existence + revocation + hash for local (client-side) verification.
// Use least-privilege DB user if configured
if(!defined('USE_PUBLIC_DB')) define('USE_PUBLIC_DB', true);
require_once __DIR__.'/../db.php';
// --- Simple rate limiting (per IP) to mitigate brute-force CID enumeration ---
// Strategy: allow burst of 30 per 60s per IP; store counters in temp file (best-effort, no locking overhead beyond flock)
$rateOk = true; $retryAfter = 0;
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$bucketDir = sys_get_temp_dir().'/certreg_rl';
if(!is_dir($bucketDir)) @mkdir($bucketDir,0700,true);
$bucketFile = $bucketDir.'/status_'.preg_replace('/[^A-Fa-f0-9:._-]/','_', $ip);
$now = time();
try {
  $fh = @fopen($bucketFile,'c+');
  if($fh){
    if(flock($fh, LOCK_EX)){
      $data = trim(stream_get_contents($fh));
      $parts = $data==='' ? [] : explode(' ', $data);
      // Keep only recent timestamps (last 60s)
      $window = 60; $limit = 30;
      $ts = [];
      foreach($parts as $t){ $ti = (int)$t; if($ti > $now - $window) $ts[] = $ti; }
      $ts[] = $now;
      if(count($ts) > $limit){
        $rateOk = false;
        // Oldest beyond limit defines retry-after
        $firstAllowed = $ts[0] + $window;
        $retryAfter = max(1, $firstAllowed - $now);
      }
      ftruncate($fh,0); rewind($fh);
      fwrite($fh, implode(' ', $ts));
      flock($fh, LOCK_UN);
    }
    fclose($fh);
  }
} catch(Throwable $e){ /* ignore rate limiter errors */ }
if(!$rateOk){
  http_response_code(429);
  header('Retry-After: '.$retryAfter);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'rate_limited','retry_after'=>$retryAfter]);
  exit;
}
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
