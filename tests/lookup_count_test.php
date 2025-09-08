<?php
// lookup_count increment test (standalone)
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';
$_SESSION['admin_id']=1; $_SESSION['admin_user']='lookup_test';

function call_json_endpoint($path,$method='POST',$json=null){
	global $pdo;
	if($json!==null) $GLOBALS['__TEST_JSON_BODY']=$json; else unset($GLOBALS['__TEST_JSON_BODY']);
	$_SERVER['REQUEST_METHOD']=$method;
	$_SERVER['HTTP_X_CSRF_TOKEN']=csrf_token();
	ob_start(); include __DIR__.'/../'.$path; $out=ob_get_clean(); return $out;
}

$cid = 'LCT'.bin2hex(random_bytes(4));
$course='LC-COURSE'; $grade='A'; $date=date('Y-m-d'); $valid='4000-01-01';
$h = bin2hex(random_bytes(32));
$payload = json_encode(['cid'=>$cid,'v'=>2,'h'=>$h,'course'=>$course,'grade'=>$grade,'date'=>$date,'valid_until'=>$valid]);
$reg = call_json_endpoint('api/register.php','POST',$payload); $rj=json_decode($reg,true);
if(empty($rj['ok'])){ echo "[FAIL] register failed: $reg\n"; exit(1);} else echo "[OK] register $cid\n";

// First status (increments lookup_count to 1)
$_GET=['cid'=>$cid]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/status.php'; $s1=ob_get_clean(); $j1=json_decode($s1,true);
if(!$j1 || empty($j1['exists'])){ echo "[FAIL] status1 missing\n"; exit(1);} else echo "[OK] first status exists\n";
// Second status (increments to 2)
$_GET=['cid'=>$cid]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/status.php'; $s2=ob_get_clean(); $j2=json_decode($s2,true);
if(!$j2 || empty($j2['exists'])){ echo "[FAIL] status2 missing\n"; exit(1);} else echo "[OK] second status exists\n";

$st = $pdo->prepare('SELECT lookup_count FROM tokens WHERE cid=?');
$st->execute([$cid]); $row=$st->fetch();
if(!$row){ echo "[FAIL] token not found in DB\n"; exit(1);} 
$lc=(int)$row['lookup_count'];
if($lc < 2){ echo "[FAIL] Expected lookup_count >=2 got $lc\n"; exit(1);} else echo "[OK] lookup_count=$lc (>=2)\n";
