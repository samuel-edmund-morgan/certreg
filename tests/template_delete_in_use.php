<?php
// Ensure API blocks deleting template referenced by tokens.template_id
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';

$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_delete_in_use'; $_SESSION['admin_role']='admin';
if(!isset($_SESSION['org_id'])) $_SESSION['org_id']=1;

function tassert($cond,$msg){ if(!$cond){ echo "[FAIL] $msg\n"; exit(1);} else { echo "[OK] $msg\n"; }}
function csrf_hdr(){ return csrf_token(); }

// Helper: call include-style API
function call_api_post($path,$post,$files=[]) {
  $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post; $_FILES=$files; $_SERVER['HTTP_X_CSRF_TOKEN']=csrf_token(); ob_start(); include __DIR__.'/../'.$path; return ob_get_clean(); }

// Create a temp template
$imgTmp = tempnam(sys_get_temp_dir(),'tpl'); $im=imagecreatetruecolor(600,300); $bg=imagecolorallocate($im,80,110,160); imagefilledrectangle($im,0,0,600,300,$bg); imagepng($im,$imgTmp); imagedestroy($im);
$files=[ 'template_file'=>[ 'name'=>'use.png','type'=>'image/png','tmp_name'=>$imgTmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($imgTmp) ] ];
$out = call_api_post('api/template_create.php',[ 'name'=>'Del-Use Test','_csrf'=>csrf_hdr() ], $files); $j=json_decode($out,true);
tassert($j && $j['ok']===true,'create template ok');
$tplId = (int)$j['template']['id']; $orgId=(int)$j['template']['org_id'];

// Ensure tokens.template_id exists
$hasCol=false; try{$c=$pdo->query("SHOW COLUMNS FROM tokens LIKE 'template_id'"); if($c->fetch()) $hasCol=true;}catch(Throwable $e){ }
if(!$hasCol){ echo "[WARN] tokens.template_id missing; skipping deletion-in-use assertion.\n"; exit(0); }

// Insert a token referencing this template
$cid='TUSE'.bin2hex(random_bytes(3));
$stmt=$pdo->prepare("INSERT INTO tokens (cid,version,org_id,template_id,h,issued_date,valid_until) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$cid,3,$orgId,$tplId,bin2hex(random_bytes(32)),date('Y-m-d'),'4000-01-01']);
$pdo->prepare("INSERT INTO token_events (cid,event_type) VALUES (?,?)")->execute([$cid,'create']);

// Attempt delete -> expect in_use
$outDel = call_api_post('api/template_delete.php',[ 'id'=>$tplId,'_csrf'=>csrf_hdr() ]);
$jd = json_decode($outDel,true);
tassert($jd && $jd['ok']===false && $jd['error']==='in_use','delete blocked by in_use');

echo "\n[OK] template_delete_in_use test finished.\n";
?>
