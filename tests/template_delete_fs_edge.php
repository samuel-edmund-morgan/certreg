<?php
// Edge test: simulate fs_delete_failed by making directory read-only before delete
// Run: php tests/template_delete_fs_edge.php

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';
$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_template_fs_edge';
$_SESSION['admin_role']='admin';
if(!isset($_SESSION['org_id'])) $_SESSION['org_id']=1; // ensure org context

function tassert($c,$m){ if(!$c){ echo "[FAIL] $m\n"; exit(1);} else echo "[OK] $m\n"; }
function call_api_post($path,$post,$files=[]) { $_SERVER['REQUEST_METHOD']='POST'; $_POST=$post; $_FILES=$files; $_SERVER['HTTP_X_CSRF_TOKEN']=csrf_token(); ob_start(); include __DIR__.'/../'.$path; return ob_get_clean(); }

// Create temp image
$imgTmp=tempnam(sys_get_temp_dir(),'tpl'); $im=imagecreatetruecolor(800,400); $bg=imagecolorallocate($im,30,60,120); imagefilledrectangle($im,0,0,800,400,$bg); imagestring($im,5,20,20,'EDGE FS',$bg^0xffffff); imagepng($im,$imgTmp); imagedestroy($im);
$files=[ 'template_file'=>[ 'name'=>'edge.png','type'=>'image/png','tmp_name'=>$imgTmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($imgTmp) ] ];

// Create template
$create=call_api_post('api/template_create.php',[ 'name'=>'FS Edge','_csrf'=>csrf_token() ],$files);
$j=json_decode($create,true); tassert($j&&$j['ok'],'create ok'); $id=$j['template']['id']; $org=$j['template']['org_id'];

$tplDir=__DIR__.'/../files/templates/'.$org.'/'.$id;
// Make directory and contents read-only (0555)
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tplDir,FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $f){ @chmod($f->getPathname(),0555); }
@chmod($tplDir,0555);

$del=call_api_post('api/template_delete.php',[ 'id'=>$id,'_csrf'=>csrf_token() ]);
$d=json_decode($del,true); tassert(!$d['ok'] && $d['error']==='fs_delete_failed','delete reports fs_delete_failed'); tassert(!empty($d['partial']),'partial flag present');

// Cleanup: relax perms so manual cleanup (dev can remove). Not failing test if cleanup fails.
@chmod($tplDir,0755);
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tplDir,FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach($it2 as $f){ @chmod($f->getPathname(),0644); }

echo "[INFO] Edge test complete (directory may still exist due to simulated failure).\n";
?>
