<?php
require_once __DIR__.'/../auth.php';
require_admin(); // only admin/operator issues certificates
require_csrf();
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../rate_limit.php';
rate_limit('register');

// Expect JSON: {cid, v, h, course, grade, date, valid_until?}
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) { http_response_code(400); echo json_encode(['error'=>'bad_json']); exit; }

function val_str($a,$k,$max){
  if (!isset($a[$k])) return null;
  $v = trim((string)$a[$k]);
  if ($v==='') return null;
  if (strlen($v) > $max) $v = substr($v,0,$max);
  return $v;
}
$cid = val_str($payload,'cid',64);
$v   = (int)($payload['v'] ?? 1);
$h   = val_str($payload,'h',64);
$course = val_str($payload,'course',100);
$grade  = val_str($payload,'grade',32);
$date   = val_str($payload,'date',10); // issued_date YYYY-MM-DD
$validUntil = val_str($payload,'valid_until',10); // YYYY-MM-DD or sentinel
// Load config for sentinel
$cfg = require __DIR__.'/../config.php';
$sentinel = $cfg['infinite_sentinel'] ?? '4000-01-01';

if (!$cid || !$h || strlen($h)!==64 || !ctype_xdigit($h)) {
  http_response_code(422); echo json_encode(['error'=>'invalid_fields']); exit;
}
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { $date=null; }
if ($v === 2) {
  if(!$validUntil){ $validUntil = $sentinel; }
  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$validUntil)) { http_response_code(422); echo json_encode(['error'=>'bad_valid_until']); exit; }
  // basic logical check: if not sentinel and earlier than issued_date
  if($validUntil !== $sentinel && $date && strcmp($validUntil,$date) < 0){ http_response_code(422); echo json_encode(['error'=>'expiry_before_issue']); exit; }
} else {
  // v1 should not send valid_until; normalize to null for DB insert compatibility (will default sentinel by migration if missing)
  $validUntil = null;
}

try {
  if($v===2){
    $st = $pdo->prepare("INSERT INTO tokens (cid, version, h, course, grade, issued_date, valid_until) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$cid,$v,$h,$course,$grade,$date,$validUntil]);
  } else {
    $st = $pdo->prepare("INSERT INTO tokens (cid, version, h, course, grade, issued_date) VALUES (?,?,?,?,?,?)");
    $st->execute([$cid,$v,$h,$course,$grade,$date]);
  }
  $tokenId = $pdo->lastInsertId();
  // Audit: creation event (no PII)
  try {
    if (isset($_SESSION['admin_id'])) {
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type,admin_id,admin_user) VALUES (?,?,?,?)");
      $log->execute([$cid,'create',$_SESSION['admin_id'] ?? null,$_SESSION['admin_user'] ?? null]);
    } else {
      // Fallback if session naming differs
      $log = $pdo->prepare("INSERT INTO token_events (cid,event_type) VALUES (?,?)");
      $log->execute([$cid,'create']);
    }
  } catch (PDOException $le) { /* ignore audit failure */ }
  echo json_encode(['ok'=>true,'id'=>$tokenId]);
} catch (PDOException $e) {
  if ($e->getCode()==='23000') { http_response_code(409); echo json_encode(['error'=>'conflict']); } else { throw $e; }
}
