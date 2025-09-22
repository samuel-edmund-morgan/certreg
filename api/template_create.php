<?php
// Створення шаблону сертифіката
// POST /api/template_create.php (multipart/form-data)
// Fields: name (required), code (optional), file (input name=template_file)
// Response: { ok:true, template:{...} } or { ok:false, error:"code", details?:... }

require_once __DIR__.'/../auth.php';
require_login(); // admin або operator
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_csrf();

$isAdmin = is_admin();
$sessionOrg = current_org_id();

function json_fail($code, $http=400, $extra=[]) {
    if(!headers_sent()) http_response_code($http);
    echo json_encode(array_merge(['ok'=>false,'error'=>$code], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok($data){ echo json_encode(['ok'=>true]+$data, JSON_UNESCAPED_UNICODE); exit; }

// Validate name
$name = trim($_POST['name'] ?? '');
if($name==='') json_fail('name_required');
if(mb_strlen($name) > 160) json_fail('name_too_long');

// org resolution: admin can optionally pass org_id (future), for now we use session org for operator
$orgId = null;
if($isAdmin){
    // Allow specifying org_id explicitly later; currently prefer explicit pass or null (global) if multi-org not required
    if(isset($_POST['org_id']) && $_POST['org_id'] !== ''){
        $orgId = (int)$_POST['org_id'];
        if($orgId <= 0) json_fail('bad_org_id');
    } else {
        // admin without org_id means global template? For now require org assignment to keep logic simple
        if($sessionOrg !== null) $orgId = $sessionOrg; // if admin has context (rare) use it
        if($orgId === null) json_fail('org_id_required');
    }
} else {
    // operator
    if($sessionOrg === null) json_fail('org_context_missing');
    $orgId = $sessionOrg;
}

// Optional code
$code = trim($_POST['code'] ?? '');
if($code !== ''){
    if(!preg_match('~^[A-Za-z0-9_-]{2,60}$~', $code)) json_fail('bad_code');
}

// File validation
if(!isset($_FILES['template_file']) || ($_FILES['template_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE){
    json_fail('file_required');
}
$file = $_FILES['template_file'];
if(($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK){
    json_fail('upload_error', 400, ['php_code'=>$file['error']]);
}
$tmpPath = $file['tmp_name'];
if(!is_uploaded_file($tmpPath)) json_fail('upload_invalid');
$origName = $file['name'];
$size = (int)$file['size'];
if($size <= 0) json_fail('file_empty');
$maxSize = 15 * 1024 * 1024; // 15MB
if($size > $maxSize) json_fail('file_too_large');

$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','webp'];
if(!in_array($ext, $allowed, true)) json_fail('bad_ext');

$imageInfo = @getimagesize($tmpPath);
if(!$imageInfo) json_fail('not_image');
$width = $imageInfo[0];
$height = $imageInfo[1];
if($width < 200 || $height < 200) json_fail('image_too_small');
if($width > 12000 || $height > 12000) json_fail('image_too_large');

$fileHash = hash_file('sha256', $tmpPath);

// Ensure templates table present
try { $chk=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$chk->fetch()) json_fail('no_templates_table'); } catch(Throwable $e){ json_fail('db'); }

// Ensure unique code per org (if provided). If not provided, we'll generate after insert (using id) for guaranteed uniqueness.
if($code !== ''){
    $st=$pdo->prepare('SELECT id FROM templates WHERE org_id = ? AND code = ? LIMIT 1');
    try { $st->execute([$orgId, $code]); if($st->fetch()) json_fail('code_exists'); } catch(Throwable $e){ json_fail('db'); }
}

// Insert initial row (code may be null now)
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO templates (org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())');
    $initialCode = $code !== '' ? $code : null;
    $status = 'active';
    $ins->execute([$orgId,$name,$initialCode,$status,$origName,$ext,$fileHash,$size,$width,$height]);
    $tplId = (int)$pdo->lastInsertId();
    if($code === ''){
        // deterministic fallback code T{ID}
        $genCode = 'T'.$tplId;
        $up=$pdo->prepare('UPDATE templates SET code=? WHERE id=? LIMIT 1');
        $up->execute([$genCode,$tplId]);
        $code = $genCode;
    }
    $pdo->commit();
} catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); json_fail('db'); }

// Prepare filesystem path
$baseDir = __DIR__.'/../files/templates';
$orgDir = $baseDir.'/'.$orgId;
$tplDir = $orgDir.'/'.$tplId;
if(!is_dir($baseDir)) { @mkdir($baseDir, 0775, true); }
if(!is_dir($orgDir)) { @mkdir($orgDir, 0775, true); }
if(!is_dir($tplDir)) { @mkdir($tplDir, 0775, true); }

$destName = 'original.'.$ext;
$destPath = $tplDir.'/'.$destName;
if(!@move_uploaded_file($tmpPath, $destPath)){
    // Rollback DB row? Could delete the row; but leaving row without file might confuse. We'll attempt delete.
    try { $del=$pdo->prepare('DELETE FROM templates WHERE id=? LIMIT 1'); $del->execute([$tplId]); } catch(Throwable $e){}
    json_fail('file_store_failed');
}

// Success response
json_ok(['template'=>[
  'id'=>$tplId,
  'org_id'=>$orgId,
  'name'=>$name,
  'code'=>$code,
  'status'=>'active',
  'filename'=>$origName,
  'file_ext'=>$ext,
  'file_hash'=>$fileHash,
  'file_size'=>$size,
  'width'=>$width,
  'height'=>$height,
  'version'=>1,
]]);
