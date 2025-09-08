<?php
// Simulate HTTP POST/GET to real endpoint scripts by setting superglobals.
// Usage: php tests/http_sim.php
// NOTE: This bypasses nginx/PHP-FPM but exercises endpoint logic (csrf/admin assumed).

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';
$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_test';
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['_csrf'=>csrf_token()];

function do_json_include($file, $method='POST', $post=[], $jsonBody=null){
  global $pdo; // ensure inside scope
  $_SERVER['REQUEST_METHOD']=$method;
  $_POST=$post; if($jsonBody!==null){
    $GLOBALS['__TEST_JSON_BODY']=$jsonBody; // simple global if endpoint adapted later
  }
  // Preload shared dependencies so endpoint's require_once won't duplicate output
  ob_start();
  include $file;
  $out=ob_get_clean();
  return $out;
}

// Create token via real register endpoint (using test override body)
$cid='S'.bin2hex(random_bytes(4)); $h=bin2hex(random_bytes(32)); $issued=date('Y-m-d'); $valid='4000-01-01';
$GLOBALS['__TEST_JSON_BODY']=json_encode(['cid'=>$cid,'v'=>2,'h'=>$h,'course'=>'SIM','grade'=>'A','date'=>$issued,'valid_until'=>$valid]);
$_SERVER['HTTP_X_CSRF_TOKEN']=csrf_token();
$_SERVER['REQUEST_METHOD']='POST';
ob_start(); include __DIR__.'/../api/register.php'; $regOut=ob_get_clean();
echo "Register response: $regOut\n";
unset($GLOBALS['__TEST_JSON_BODY']);

// Revoke
$_POST=['_csrf'=>csrf_token(),'cid'=>$cid,'reason'=>'integration revoke test'];
$rev = do_json_include(__DIR__.'/../api/revoke.php','POST',$_POST);
echo "Revoke response: $rev\n";
// Unrevoke
$_POST=['_csrf'=>csrf_token(),'cid'=>$cid];
$unrev = do_json_include(__DIR__.'/../api/unrevoke.php','POST',$_POST);
echo "Unrevoke response: $unrev\n";
// Delete
$_POST=['_csrf'=>csrf_token(),'cid'=>$cid];
$del = do_json_include(__DIR__.'/../api/delete_token.php','POST',$_POST);
echo "Delete response: $del\n";

// Status GET (should not exist after delete)
$_GET=['cid'=>$cid]; $_SERVER['REQUEST_METHOD']='GET'; ob_start(); include __DIR__.'/../api/status.php'; $status=ob_get_clean();
echo "Status after delete: $status\n";
echo "HTTP simulation done.\n";
?>