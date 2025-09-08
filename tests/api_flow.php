<?php
// Comprehensive API flow test (register -> status -> revoke -> events -> unrevoke -> bulk revoke/unrevoke/delete)
// Run: php tests/api_flow.php
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';
$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_flow';
function jassert($cond,$msg){ if(!$cond){ echo "[FAIL] $msg\n"; exit(1);} else echo "[OK] $msg\n"; }

function call_json_endpoint($path, $method='POST', $json=null, $post=null){
  global $pdo; // ensure DB handle in included scope
  $_SERVER['REQUEST_METHOD']=$method;
  if($json!==null){ $GLOBALS['__TEST_JSON_BODY']=$json; }
  if($post!==null){ $_POST=$post; }
  $_SERVER['HTTP_X_CSRF_TOKEN']=csrf_token();
  ob_start(); include __DIR__.'/../'.$path; $out=ob_get_clean(); unset($GLOBALS['__TEST_JSON_BODY']); return $out; }

// 1. Register 3 tokens
$issued=date('Y-m-d'); $valid='4000-01-01'; $cids=[]; $hashes=[];
for($i=0;$i<3;$i++){
  $cid='F'.bin2hex(random_bytes(4)); $cids[]=$cid; $h=bin2hex(random_bytes(32)); $hashes[$cid]=$h;
  $resp = call_json_endpoint('api/register.php','POST', json_encode(['cid'=>$cid,'v'=>2,'h'=>$h,'course'=>'FLOW','grade'=>'A','date'=>$issued,'valid_until'=>$valid]) );
  $j=json_decode($resp,true); jassert(isset($j['ok'])&&$j['ok']===true,"register $cid");
}

// 2. Status each
foreach($cids as $cid){ $_GET=['cid'=>$cid]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/status.php'; $s=ob_get_clean(); $j=json_decode($s,true); jassert($j['exists']===true && $j['h']===$hashes[$cid],"status $cid ok"); }

// 3. Revoke first
$rev = call_json_endpoint('api/revoke.php','POST', null, ['_csrf'=>csrf_token(),'cid'=>$cids[0],'reason'=>'flow revoke']); $rj=json_decode($rev,true); jassert(!empty($rj['ok']), 'revoke first');

// 4. Events fetch
$_GET=['cid'=>$cids[0]]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/events.php'; $evOut=ob_get_clean(); $ev=json_decode($evOut,true); jassert(isset($ev['events']) && count($ev['events'])>=1,'events list exists');

// 5. Bulk: revoke second & delete third
$bulkBody=json_encode(['action'=>'revoke','cids'=>[$cids[1]],'reason'=>'bulk reason ok']); $bulk = call_json_endpoint('api/bulk_action.php','POST',$bulkBody); $bj=json_decode($bulk,true); jassert($bj['processed']===1,'bulk revoke processed=1');
$bulkBody=json_encode(['action'=>'delete','cids'=>[$cids[2]]]); $bulk2 = call_json_endpoint('api/bulk_action.php','POST',$bulkBody); $bj2=json_decode($bulk2,true); jassert($bj2['processed']===1,'bulk delete processed=1');

// 6. Bulk unrevoke first two (one revoked, one revoked earlier) -> should unrevoke those currently revoked
$bulkBody=json_encode(['action'=>'unrevoke','cids'=>[$cids[0],$cids[1]]]); $bulk3 = call_json_endpoint('api/bulk_action.php','POST',$bulkBody); $bj3=json_decode($bulk3,true); jassert($bj3['processed']>=1,'bulk unrevoke processed');

// 7. Final status checks
foreach([$cids[0],$cids[1]] as $cid){ $_GET=['cid'=>$cid]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/status.php'; $s=ob_get_clean(); $j=json_decode($s,true); jassert($j['revoked']===false,'status unrevoked '.$cid); }
$_GET=['cid'=>$cids[2]]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/status.php'; $sDel=ob_get_clean(); $jd=json_decode($sDel,true); jassert($jd['exists']===false,'deleted token absent');

echo "\n[OK] API flow test completed successfully.\n";
?>