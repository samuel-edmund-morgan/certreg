<?php
require_once __DIR__.'/../auth.php';
require_admin(); // only admin/operator issues certificates
require_once __DIR__.'/../db.php';
header('Content-Type: application/json; charset=utf-8');

// Expect JSON: {cid, v, h, course, grade, date}
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
$date   = val_str($payload,'date',10); // YYYY-MM-DD

if (!$cid || !$h || strlen($h)!==64 || !ctype_xdigit($h)) {
  http_response_code(422); echo json_encode(['error'=>'invalid_fields']); exit;
}
if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { $date=null; }

try {
  $st = $pdo->prepare("INSERT INTO tokens (cid, version, h, course, grade, issued_date) VALUES (?,?,?,?,?,?)");
  $st->execute([$cid,$v,$h,$course,$grade,$date]);
  echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
} catch (PDOException $e) {
  if ($e->getCode()==='23000') { http_response_code(409); echo json_encode(['error'=>'conflict']); } else { throw $e; }
}
