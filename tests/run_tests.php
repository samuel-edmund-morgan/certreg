<?php
// Lightweight test harness (no PHPUnit) to capture current working behavior as a baseline.
// Run via: php tests/run_tests.php

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';

function assert_true($cond, $msg){
  if(!$cond){ echo "[FAIL] $msg\n"; exit(1);} else { echo "[OK] $msg\n"; }
}

// 1. Ensure schema columns exist (tokens)
$cols = $pdo->query("SHOW COLUMNS FROM tokens")->fetchAll(PDO::FETCH_COLUMN,0);
foreach(['cid','h','version','issued_date','created_at','lookup_count','last_lookup_at','valid_until'] as $c){
  assert_true(in_array($c,$cols,true),"tokens column $c present");
}

// 2. Create a synthetic token directly (bypassing register API) to verify status endpoint later
$cid = 'T'.bin2hex(random_bytes(4));
$h = str_repeat('a',64);
$course='TEST-COURSE'; $grade='A'; $issued=date('Y-m-d'); $valid='4000-01-01';
$stmt = $pdo->prepare("INSERT INTO tokens (cid,version,h,course,grade,issued_date,valid_until) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$cid,2,$h,$course,$grade,$issued,$valid]);
assert_true($pdo->lastInsertId()>0,'insert token row');

// 3. Call status endpoint internally
$_GET['cid']=$cid; ob_start(); include __DIR__.'/../api/status.php'; $json = ob_get_clean();
$data = json_decode($json,true);
assert_true(isset($data['exists']) && $data['exists']===true,'status exists true');
assert_true($data['h']===$h,'status hash matches');
assert_true(isset($data['valid_until']) && $data['valid_until']===$valid,'valid_until matches');

// 4. Revoke then check revoke fields via direct DB update (simulate API path separately in future)
$pdo->prepare("UPDATE tokens SET revoked_at=NOW(), revoke_reason='test' WHERE cid=?")->execute([$cid]);
$_GET['cid']=$cid; ob_start(); include __DIR__.'/../api/status.php'; $json2 = ob_get_clean();
$data2 = json_decode($json2,true);
assert_true($data2['revoked']===true,'revoked flag true after revoke');
assert_true($data2['revoke_reason']==='test','revoke reason matches');

echo "\nAll baseline tests passed.\n";
?>