<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

$name = trim($_POST['name'] ?? '');
$code = trim($_POST['code'] ?? '');
$primary = trim($_POST['primary_color'] ?? '');
$accent = trim($_POST['accent_color'] ?? '');
$secondary = trim($_POST['secondary_color'] ?? '');
$footerText = trim($_POST['footer_text'] ?? '');
$supportContact = trim($_POST['support_contact'] ?? '');
$errors=[];
if($name==='') $errors['name']='empty';
if($code==='') $errors['code']='empty';
if($code!=='' && !preg_match('/^[A-Z0-9_-]{2,32}$/',$code)) $errors['code']='format';

function norm_hex_org($v){
  $v=trim($v); if($v==='') return '';
  if($v[0]==='#') $v=substr($v,1);
  if(preg_match('/^[0-9A-Fa-f]{6}$/',$v)) return '#'.strtoupper($v); return false;
}
$pHex = norm_hex_org($primary); if($primary!=='' && $pHex===false) $errors['primary_color']='bad_format';
$aHex = norm_hex_org($accent); if($accent!=='' && $aHex===false) $errors['accent_color']='bad_format';
$sHex = norm_hex_org($secondary); if($secondary!=='' && $sHex===false) $errors['secondary_color']='bad_format';

// Early uniqueness checks
try {
  $st = $pdo->prepare('SELECT 1 FROM organizations WHERE name=? LIMIT 1'); $st->execute([$name]); if($st->fetch()) $errors['name']='exists';
  $st2 = $pdo->prepare('SELECT 1 FROM organizations WHERE code=? LIMIT 1'); $st2->execute([$code]); if($st2->fetch()) $errors['code']='exists';
} catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); exit; }

// File uploads (logo / favicon)
$logoRel=null; $favRel=null;
function handle_upload($field,$type){
  if(empty($_FILES[$field]['name'])) return null;
  $f = $_FILES[$field]; if($f['error']!==UPLOAD_ERR_OK) return ['error'=>$type.'_upload'];
  $size=$f['size']; $orig=$f['name']; $tmp=$f['tmp_name'];
  $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION));
  if($type==='logo'){
    if($size>2*1024*1024) return ['error'=>'logo_too_large'];
    if(!in_array($ext,['png','jpg','jpeg','svg'],true)) return ['error'=>'logo_type'];
  } else {
    if($size>128*1024) return ['error'=>'favicon_too_large'];
    if(!in_array($ext,['ico','png','svg'],true)) return ['error'=>'favicon_type'];
  }
  return ['tmp'=>$tmp,'ext'=>$ext];
}
$logoUp = handle_upload('logo_file','logo'); if(is_array($logoUp) && isset($logoUp['error'])) $errors['logo']=$logoUp['error'];
$favUp  = handle_upload('favicon_file','favicon'); if(is_array($favUp) && isset($favUp['error'])) $errors['favicon']=$favUp['error'];

if($errors){ echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

try {
  $ins = $pdo->prepare('INSERT INTO organizations(name,code,primary_color,accent_color,secondary_color,footer_text,support_contact) VALUES (?,?,?,?,?,?,?)');
  $ins->execute([$name,$code,$pHex?:null,$aHex?:null,$sHex?:null, $footerText?:null,$supportContact?:null]);
  $id = (int)$pdo->lastInsertId();
  $baseDir = $_SERVER['DOCUMENT_ROOT'].'/files/branding/org_'.$id;
  if(!is_dir($baseDir)) @mkdir($baseDir,0755,true);
  if(is_array($logoUp) && empty($logoUp['error'])){
    $dest = $baseDir.'/logo_'.date('Ymd_His').'.'.$logoUp['ext'];
    if(move_uploaded_file($logoUp['tmp'],$dest)){
      $logoRel = '/files/branding/org_'.$id.'/'.basename($dest);
      $pdo->prepare('UPDATE organizations SET logo_path=? WHERE id=?')->execute([$logoRel,$id]);
    }
  }
  if(is_array($favUp) && empty($favUp['error'])){
    $dest = $baseDir.'/favicon_'.date('Ymd_His').'.'.$favUp['ext'];
    if(move_uploaded_file($favUp['tmp'],$dest)){
      $favRel = '/files/branding/org_'.$id.'/'.basename($dest);
      $pdo->prepare('UPDATE organizations SET favicon_path=? WHERE id=?')->execute([$favRel,$id]);
    }
  }
  // Generate CSS
  if($pHex || $aHex || $sHex){
    $css = ':root{'; if($pHex)$css.='--primary: '.$pHex.';'; if($aHex)$css.='--accent: '.$aHex.';'; if($sHex)$css.='--secondary: '.$sHex.';'; $css.='}' . "\n";
    file_put_contents($baseDir.'/branding_colors.css',$css,LOCK_EX);
  }
  $row = $pdo->prepare('SELECT * FROM organizations WHERE id=?'); $row->execute([$id]);
  echo json_encode(['ok'=>true,'org'=>$row->fetch(PDO::FETCH_ASSOC)]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
