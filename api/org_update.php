<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
$id = (int)($_POST['id'] ?? 0);
if($id<=0){ echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }

$st = $pdo->prepare('SELECT * FROM organizations WHERE id=? LIMIT 1');
$st->execute([$id]);
$org = $st->fetch(PDO::FETCH_ASSOC);
if(!$org){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }

$name = trim($_POST['name'] ?? $org['name']);
$code = trim($_POST['code'] ?? $org['code']); // immutable check
$primary = trim($_POST['primary_color'] ?? '');
$accent = trim($_POST['accent_color'] ?? '');
$secondary = trim($_POST['secondary_color'] ?? '');
$footerText = trim($_POST['footer_text'] ?? '');
$supportContact = trim($_POST['support_contact'] ?? '');
$is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : (int)$org['is_active'];
$errors=[];
if($name==='') $errors['name']='empty';
if($code!==$org['code']) $errors['code']='immutable';

function norm_hex_org2($v){ $v=trim($v); if($v==='') return ''; if($v[0]=='#') $v=substr($v,1); return preg_match('/^[0-9A-Fa-f]{6}$/',$v)?('#'.strtoupper($v)):false; }
$pHex = norm_hex_org2($primary); if($primary!=='' && $pHex===false) $errors['primary_color']='bad_format';
$aHex = norm_hex_org2($accent); if($accent!=='' && $aHex===false) $errors['accent_color']='bad_format';
$sHex = norm_hex_org2($secondary); if($secondary!=='' && $sHex===false) $errors['secondary_color']='bad_format';

// Uniqueness for name if changed
if($name !== $org['name']){
  $chk = $pdo->prepare('SELECT 1 FROM organizations WHERE name=? AND id<>? LIMIT 1');
  $chk->execute([$name,$id]); if($chk->fetch()) $errors['name']='exists';
}

// Uploads
$logoRel=null; $favRel=null;
function upd_handle_upload($field,$type){
  if(empty($_FILES[$field]['name'])) return null; $f=$_FILES[$field]; if($f['error']!==UPLOAD_ERR_OK) return ['error'=>$type.'_upload'];
  $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)); $size=$f['size'];
  if($type==='logo'){ if($size>2*1024*1024) return ['error'=>'logo_too_large']; if(!in_array($ext,['png','jpg','jpeg','svg'],true)) return ['error'=>'logo_type']; }
  else { if($size>128*1024) return ['error'=>'favicon_too_large']; if(!in_array($ext,['ico','png','svg'],true)) return ['error'=>'favicon_type']; }
  return ['tmp'=>$f['tmp_name'],'ext'=>$ext];
}
$logoUp = upd_handle_upload('logo_file','logo'); if(is_array($logoUp) && isset($logoUp['error'])) $errors['logo']=$logoUp['error'];
$favUp  = upd_handle_upload('favicon_file','favicon'); if(is_array($favUp) && isset($favUp['error'])) $errors['favicon']=$favUp['error'];

if($errors){ echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

try {
  $up = $pdo->prepare('UPDATE organizations SET name=?, primary_color=?, accent_color=?, secondary_color=?, footer_text=?, support_contact=?, is_active=? WHERE id=? LIMIT 1');
  $up->execute([$name, $pHex?:null,$aHex?:null,$sHex?:null, $footerText?:null,$supportContact?:null,$is_active,$id]);
  $baseDir = $_SERVER['DOCUMENT_ROOT'].'/files/branding/org_'.$id; if(!is_dir($baseDir)) @mkdir($baseDir,0755,true);
  if(is_array($logoUp) && empty($logoUp['error'])){ $dest=$baseDir.'/logo_'.date('Ymd_His').'.'.$logoUp['ext']; if(move_uploaded_file($logoUp['tmp'],$dest)){ $logoRel='/files/branding/org_'.$id.'/'.basename($dest); $pdo->prepare('UPDATE organizations SET logo_path=? WHERE id=?')->execute([$logoRel,$id]); }}
  if(is_array($favUp) && empty($favUp['error'])){ $dest=$baseDir.'/favicon_'.date('Ymd_His').'.'.$favUp['ext']; if(move_uploaded_file($favUp['tmp'],$dest)){ $favRel='/files/branding/org_'.$id.'/'.basename($dest); $pdo->prepare('UPDATE organizations SET favicon_path=? WHERE id=?')->execute([$favRel,$id]); }}
  // Regenerate per-organization CSS (overwrite or delete if all colors cleared)
  if($pHex || $aHex || $sHex){ $css=':root{'; if($pHex)$css.='--primary: '.$pHex.';'; if($aHex)$css.='--accent: '.$aHex.';'; if($sHex)$css.='--secondary: '.$sHex.';'; $css.='}' ."\n"; file_put_contents($baseDir.'/branding_colors.css',$css,LOCK_EX); }
  elseif(is_file($baseDir.'/branding_colors.css')) @unlink($baseDir.'/branding_colors.css');
  $st2=$pdo->prepare('SELECT * FROM organizations WHERE id=?'); $st2->execute([$id]);
  echo json_encode(['ok'=>true,'org'=>$st2->fetch(PDO::FETCH_ASSOC)]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
