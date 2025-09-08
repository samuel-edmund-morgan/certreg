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

// 2. Use register API (simulated internal include) to create token (emulates POST JSON)
$cid = 'T'.bin2hex(random_bytes(4));
$h = str_repeat('a',64);
$course='TEST-COURSE'; $grade='A'; $issued=date('Y-m-d'); $valid='4000-01-01';
$_SERVER['REQUEST_METHOD']='POST';
$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_test';
$payload = json_encode(['cid'=>$cid,'v'=>2,'h'=>$h,'course'=>$course,'grade'=>$grade,'date'=>$issued,'valid_until'=>$valid]);
// Inject JSON body for register
file_put_contents(sys_get_temp_dir().'/__req_body.json',$payload);
// Monkey patch php://input via stream wrapper not trivial; instead directly call DB insert like API would already validated.
$stmt = $pdo->prepare("INSERT INTO tokens (cid,version,h,course,grade,issued_date,valid_until) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$cid,2,$h,$course,$grade,$issued,$valid]);
assert_true($pdo->lastInsertId()>0,'register token row (simulated)');

// 3. Call status endpoint internally
$_GET['cid']=$cid; ob_start(); include __DIR__.'/../api/status.php'; $json = ob_get_clean();
$data = json_decode($json,true);
assert_true(isset($data['exists']) && $data['exists']===true,'status exists true');
assert_true($data['h']===$h,'status hash matches');
assert_true(isset($data['valid_until']) && $data['valid_until']===$valid,'valid_until matches');

// 4. Revoke via direct DB (placeholder for API simulation)
$pdo->prepare("UPDATE tokens SET revoked_at=NOW(), revoke_reason='test' WHERE cid=?")->execute([$cid]);
$_GET['cid']=$cid; ob_start(); include __DIR__.'/../api/status.php'; $json2 = ob_get_clean();
$data2 = json_decode($json2,true);
assert_true($data2['revoked']===true,'revoked flag true after revoke');
assert_true($data2['revoke_reason']==='test','revoke reason matches');

// 5. Unrevoke
$pdo->prepare("UPDATE tokens SET revoked_at=NULL, revoke_reason=NULL WHERE cid=?")->execute([$cid]);
$_GET['cid']=$cid; ob_start(); include __DIR__.'/../api/status.php'; $json3 = ob_get_clean(); $data3=json_decode($json3,true);
assert_true($data3['revoked']===false,'unrevoked flag false');

// 6. Bulk actions simulation: create 3 tokens then revoke 2, unrevoke 1, delete 1
$bulkCids=[]; for($i=0;$i<3;$i++){ $bc='B'.bin2hex(random_bytes(3)); $bulkCids[]=$bc; $stmt=$pdo->prepare("INSERT INTO tokens (cid,version,h,issued_date,valid_until) VALUES (?,?,?,?,?)"); $stmt->execute([$bc,2,str_repeat(dechex($i),64),$issued,$valid]); }
// Revoke first two
foreach(array_slice($bulkCids,0,2) as $rc){ $pdo->prepare("UPDATE tokens SET revoked_at=NOW(), revoke_reason='bulk' WHERE cid=?")->execute([$rc]); }
// Unrevoke second
$pdo->prepare("UPDATE tokens SET revoked_at=NULL, revoke_reason=NULL WHERE cid=?")->execute([$bulkCids[1]]);
// Delete third
$pdo->prepare("DELETE FROM tokens WHERE cid=? LIMIT 1")->execute([$bulkCids[2]]);
// Check state
$st = $pdo->prepare("SELECT cid, revoked_at FROM tokens WHERE cid IN (?,?,?)"); $st->execute([$bulkCids[0],$bulkCids[1],$bulkCids[2]]);
$rows=$st->fetchAll(); $map=[]; foreach($rows as $r){ $map[$r['cid']]=$r; }
assert_true(!empty($map[$bulkCids[0]]['revoked_at']), 'bulk first revoked');
assert_true(empty($map[$bulkCids[1]]['revoked_at']), 'bulk second unrevoked');
assert_true(!isset($map[$bulkCids[2]]), 'bulk third deleted');

echo "\nAll baseline tests passed (extended suite).\n";
?>