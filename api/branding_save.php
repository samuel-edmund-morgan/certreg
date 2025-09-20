<?php
require_once __DIR__.'/../auth.php';
require_admin(); // Only admins modify branding
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');

// Accept multipart (logo upload) OR application/x-www-form-urlencoded
$siteName = trim($_POST['site_name'] ?? '');
$primary  = trim($_POST['primary_color'] ?? '');
$accent   = trim($_POST['accent_color'] ?? '');
$secondary = trim($_POST['secondary_color'] ?? '');
$footerText = trim($_POST['footer_text'] ?? '');
$supportContact = trim($_POST['support_contact'] ?? '');
$errors = [];
if($siteName===''){ $errors['site_name']='empty'; }
// Basic HEX validation (# optional)
function norm_hex($v){
  $v = trim($v);
  if($v==='') return '';
  if($v[0]==='#') $v = substr($v,1);
  if(preg_match('/^[0-9A-Fa-f]{6}$/',$v)) return '#'.strtoupper($v);
  return false;
}
$primaryHex = norm_hex($primary); if($primary!=='' && $primaryHex===false){ $errors['primary_color']='bad_format'; }
$accentHex  = norm_hex($accent); if($accent!=='' && $accentHex===false){ $errors['accent_color']='bad_format'; }
$secondaryHex = norm_hex($secondary); if($secondary!=='' && $secondaryHex===false){ $errors['secondary_color']='bad_format'; }

// Handle logo upload
$logoRelPath = null; $uploadField = 'logo_file';
if(!empty($_FILES[$uploadField]['name'])){
  $f = $_FILES[$uploadField];
  if($f['error']===UPLOAD_ERR_OK){
    $tmp = $f['tmp_name'];
    $orig = $f['name'];
    $size = $f['size'];
    if($size > 2*1024*1024){ $errors['logo']='too_large'; }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','svg'];
    if(!in_array($ext,$allowed,true)){ $errors['logo']='bad_type'; }
    if(empty($errors['logo'])){
      $targetDir = $_SERVER['DOCUMENT_ROOT'].'/files/branding';
      if(!is_dir($targetDir)) @mkdir($targetDir,0755,true);
      $dest = $targetDir.'/logo_'.date('Ymd_His').'.'.$ext;
      if(!move_uploaded_file($tmp,$dest)){
        $errors['logo']='move_failed';
      } else {
        $logoRelPath = '/files/branding/'.basename($dest);
      }
    }
  } else {
    $errors['logo']='upload_error_'.$f['error'];
  }
}

// Handle favicon upload (optional). Accept .ico, .png, .svg (small size limit 128KB)
$faviconRelPath = null; $favField = 'favicon_file';
if(!empty($_FILES[$favField]['name'])){
  $f = $_FILES[$favField];
  if($f['error']===UPLOAD_ERR_OK){
    $tmp = $f['tmp_name'];
    $orig = $f['name'];
    $size = $f['size'];
    if($size > 128*1024){ $errors['favicon']='too_large'; }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowedFav = ['ico','png','svg'];
    if(!in_array($ext,$allowedFav,true)){ $errors['favicon']='bad_type'; }
    if(empty($errors['favicon'])){
      $targetDir = $_SERVER['DOCUMENT_ROOT'].'/files/branding';
      if(!is_dir($targetDir)) @mkdir($targetDir,0755,true);
      $dest = $targetDir.'/favicon_'.date('Ymd_His').'.'.$ext;
      if(!move_uploaded_file($tmp,$dest)){
        $errors['favicon']='move_failed';
      } else {
        $faviconRelPath = '/files/branding/'.basename($dest);
      }
    }
  } else {
    $errors['favicon']='upload_error_'.$f['error'];
  }
}

if($errors){ echo json_encode(['ok'=>false,'errors'=>$errors]); exit; }

// Upsert helper
function branding_upsert(PDO $pdo, string $key, string $value){
  $st = $pdo->prepare("INSERT INTO branding_settings (setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
  $st->execute([$key,$value]);
}

try {
  if($siteName!=='') branding_upsert($pdo,'site_name',$siteName);
  if($primaryHex!=='' && $primaryHex!==false) branding_upsert($pdo,'primary_color',$primaryHex);
  if($accentHex!=='' && $accentHex!==false) branding_upsert($pdo,'accent_color',$accentHex);
  if($secondaryHex!=='' && $secondaryHex!==false) branding_upsert($pdo,'secondary_color',$secondaryHex);
  if($logoRelPath) branding_upsert($pdo,'logo_path',$logoRelPath);
  if($faviconRelPath) branding_upsert($pdo,'favicon_path',$faviconRelPath);
  if($footerText!=='') branding_upsert($pdo,'footer_text',$footerText);
  if($supportContact!=='') branding_upsert($pdo,'support_contact',$supportContact);
  // After DB writes, (re)generate external branding CSS for deterministic override.
  try {
    $targetDir = $_SERVER['DOCUMENT_ROOT'].'/files/branding';
    if(!is_dir($targetDir)) @mkdir($targetDir,0755,true);
    $cssFile = $targetDir.'/branding_colors.css';
    // Load current values to decide content
    $map = [];
  $st2 = $pdo->query("SELECT setting_key, setting_value FROM branding_settings WHERE setting_key IN ('primary_color','accent_color','secondary_color')");
    foreach($st2->fetchAll(PDO::FETCH_ASSOC) as $r){ $map[$r['setting_key']] = $r['setting_value']; }
    $p = $map['primary_color'] ?? '';
    $a = $map['accent_color'] ?? '';
    $s = $map['secondary_color'] ?? '';
  if($p || $a || $s){
      $lines = [':root{'];
      if($p) $lines[] = '--primary: '.$p.';';
      if($a) $lines[] = '--accent: '.$a.';';
      if($s) $lines[] = '--secondary: '.$s.';';
      $lines[]='}';
      $css = implode('', $lines)."\n";
      file_put_contents($cssFile,$css,LOCK_EX);
    } else {
      if(is_file($cssFile)) @unlink($cssFile);
    }
  } catch(Throwable $e){ /* swallow generation errors; do not block primary response */ }
  echo json_encode(['ok'=>true,'logo'=>$logoRelPath,'favicon'=>$faviconRelPath]);
} catch(Throwable $e){
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'server']);
}