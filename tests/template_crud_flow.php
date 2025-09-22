<?php
// Template CRUD lifecycle test
// Run: php tests/template_crud_flow.php
// Flow: create -> list -> update (rename + file) -> toggle -> delete -> self_check (no orphan warning for that id)

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';

// Simulate admin WITH org context (template endpoints currently require org_id for create)
$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_template_crud';
$_SESSION['admin_role']='admin';
// Provide an org context (assume default org id = 1). If different, adjust before running test.
if(!isset($_SESSION['org_id'])) $_SESSION['org_id']=1;

function tassert($cond,$msg){ if(!$cond){ echo "[FAIL] $msg\n"; exit(1);} else { echo "[OK] $msg\n"; }}
function csrf_hdr(){ return csrf_token(); }

// Helper to invoke API include-style (POST)
function call_api_post($path,$post,$files=[]) {
  $_SERVER['REQUEST_METHOD']='POST';
  $_POST=$post; $_FILES=$files; $_SERVER['HTTP_X_CSRF_TOKEN']=csrf_token();
  ob_start(); include __DIR__.'/../'.$path; $out=ob_get_clean(); return $out; }
function call_api_get($path,$query=[]) {
  $_SERVER['REQUEST_METHOD']='GET'; $_GET=$query; ob_start(); include __DIR__.'/../'.$path; return ob_get_clean(); }

// Create a temporary image for upload
$imgTmp = tempnam(sys_get_temp_dir(),'tpl');
$im = imagecreatetruecolor(1200,600); $bg=imagecolorallocate($im,16,45,78); imagefilledrectangle($im,0,0,1200,600,$bg);
$accent=imagecolorallocate($im,210,45,138); imagestring($im,5,20,20,'TEMPLATE CRUD',$accent);
imagepng($im,$imgTmp); imagedestroy($im);
$fakeName='test_template.png';

// 1. CREATE
$files=[ 'template_file'=>[ 'name'=>$fakeName,'type'=>'image/png','tmp_name'=>$imgTmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($imgTmp) ] ];
$out = call_api_post('api/template_create.php',[ 'name'=>'Lifecycle Test','_csrf'=>csrf_hdr() ], $files);
$j = json_decode($out,true); tassert($j && $j['ok']===true,'create ok');
$tplId = $j['template']['id']; $orgId = $j['template']['org_id'];

// 2. LIST (ensure appears with metadata)
$list = call_api_get('api/templates_list.php');
$jl = json_decode($list,true); tassert(isset($jl['ok'])&&$jl['ok']===true,'list ok');
$found=false; foreach($jl['items'] as $it){ if($it['id']==$tplId){ $found=true; tassert(isset($it['code'],$it['status'],$it['version']), 'list item has metadata'); break; }}
tassert($found,'template present in list');

// 3. UPDATE (rename + replace file)
$imgTmp2 = tempnam(sys_get_temp_dir(),'tpl'); $im2=imagecreatetruecolor(1000,500); $bg2=imagecolorallocate($im2,50,120,90); imagefilledrectangle($im2,0,0,1000,500,$bg2); imagestring($im2,5,30,30,'UPDATED',$accent); imagepng($im2,$imgTmp2); imagedestroy($im2);
$files2=[ 'template_file'=>[ 'name'=>'updated.png','type'=>'image/png','tmp_name'=>$imgTmp2,'error'=>UPLOAD_ERR_OK,'size'=>filesize($imgTmp2) ] ];
$out2 = call_api_post('api/template_update.php',[ 'id'=>$tplId,'name'=>'Lifecycle Test Updated','_csrf'=>csrf_hdr() ], $files2);
$j2=json_decode($out2,true); tassert($j2 && $j2['ok']===true,'update ok'); tassert($j2['template']['version']==2,'version incremented');

// 4. TOGGLE (active -> disabled -> active)
$out3 = call_api_post('api/template_toggle.php',[ 'id'=>$tplId,'_csrf'=>csrf_hdr() ]);
$j3=json_decode($out3,true); tassert($j3['ok']===true && $j3['template']['status']==='disabled','toggle to disabled');
$out4 = call_api_post('api/template_toggle.php',[ 'id'=>$tplId,'_csrf'=>csrf_hdr() ]);
$j4=json_decode($out4,true); tassert($j4['ok']===true && $j4['template']['status']==='active','toggle back to active');

// 5. DELETE
$out5 = call_api_post('api/template_delete.php',[ 'id'=>$tplId,'_csrf'=>csrf_hdr() ]);
$j5=json_decode($out5,true); tassert($j5 && $j5['ok']===true,'delete ok');

// 6. SELF_CHECK to ensure no orphan dir (warning allowed only if partial fs failure). We'll run and grep output.
$scOut = shell_exec('php '.escapeshellarg(__DIR__.'/../self_check.php').' 2>&1');
$hasTplWarn = (bool)preg_match('~Orphan template directories.*'.$tplId.'~',$scOut);
$hasMissingDirWarn = (bool)preg_match('~Template DB rows missing directory.*'.$tplId.'~',$scOut);
tassert(!$hasTplWarn,'no orphan directory for deleted template');
tassert(!$hasMissingDirWarn,'no missing dir warning for deleted template');

echo "\n[OK] Template CRUD lifecycle test finished. ID=$tplId\n";
?>
