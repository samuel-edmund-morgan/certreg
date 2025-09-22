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
// Allow ad-hoc debug trigger via header (for non-production troubleshooting without setting env var globally)
$debugHeader = isset($_SERVER['HTTP_X_DEBUG_TEMPLATE']) && $_SERVER['HTTP_X_DEBUG_TEMPLATE'] === '1';

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

// Optional code (user supplied)
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

// Pre-flight: verify required columns exist to avoid silent 'db' on legacy schema
try {
    $colsStmt = $pdo->query("SHOW COLUMNS FROM templates");
    $have = [];
    foreach($colsStmt->fetchAll(PDO::FETCH_ASSOC) as $c){ $have[$c['Field']] = true; }
    $required = ['org_id','name','code','status','filename','file_ext','file_hash','file_size','width','height','version','created_at','updated_at'];
    $missing = [];
    foreach($required as $r){ if(!isset($have[$r])) $missing[]=$r; }
    if($missing){ json_fail('schema_mismatch',500,['missing'=>$missing]); }
} catch(Throwable $e){ /* ignore: let later db fail handle */ }

// Ensure unique code per org (if provided). If omitted we will insert a temporary placeholder (non-null) then rewrite to T{id} post-insert.
if($code !== ''){
    $st=$pdo->prepare('SELECT id FROM templates WHERE org_id = ? AND code = ? LIMIT 1');
    try { $st->execute([$orgId, $code]); if($st->fetch()) json_fail('code_exists'); } catch(Throwable $e){ json_fail('db'); }
}

// Prepare placeholder if no code specified (handles NOT NULL constraint on code in some legacy schemas)
$autoPlaceholder = false; $placeholder = null;
if($code === ''){
    $autoPlaceholder = true;
    $tries = 0;
    do {
        $placeholder = '__P'.bin2hex(random_bytes(4)); // 10 chars + prefix
        $tries++;
        try {
            $chk = $pdo->prepare('SELECT id FROM templates WHERE org_id=? AND code=? LIMIT 1');
            $chk->execute([$orgId,$placeholder]);
            $exists = (bool)$chk->fetch();
        } catch(Throwable $e){ $exists=false; }
    } while($exists && $tries < 5);
    if($exists){ json_fail('temp_code_gen_failed'); }
}

// Verify org exists (defensive) – prevents FK error becoming opaque 'db'
try {
    $chkOrg = $pdo->prepare('SELECT id FROM organizations WHERE id=? LIMIT 1');
    $chkOrg->execute([$orgId]);
    if(!$chkOrg->fetch()) json_fail('org_not_found');
} catch(Throwable $e){ json_fail('db'); }

// Insert initial row (code may be null now)
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO templates (org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())');
    $initialCode = $code !== '' ? $code : $placeholder; // never NULL now
    $status = 'active';
    $ins->execute([$orgId,$name,$initialCode,$status,$origName,$ext,$fileHash,$size,$width,$height]);
    $tplId = (int)$pdo->lastInsertId();
    if($autoPlaceholder){
        // deterministic fallback code T{ID}
        $genCode = 'T'.$tplId;
        try {
            $up=$pdo->prepare('UPDATE templates SET code=? WHERE id=? LIMIT 1');
            $up->execute([$genCode,$tplId]);
            $code = $genCode;
        } catch(Throwable $e){
            // If updating final code failed, leave placeholder but return it so caller still gets a usable code
            $code = $initialCode;
        }
    }
    $pdo->commit();
} catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $msg = $e->getMessage();
    // Server-side log for forensic diagnostics (not exposed unless debug flag)
    try {
        $logDir = __DIR__.'/../logs';
        if(!is_dir($logDir)) @mkdir($logDir,0775,true);
        $line = date('c')."\tTEMPLATE_CREATE_EXCEPTION\t".str_replace(["\n","\r"],' ',$msg)."\t".($e->getFile().':'.$e->getLine())."\n";
        @file_put_contents($logDir.'/templates_errors.log',$line,FILE_APPEND|LOCK_EX);
    } catch(Throwable $ignore){}
    // Classify
    if(stripos($msg,'Duplicate')!==false){ json_fail('code_exists'); }
    if(stripos($msg,'foreign key')!==false){ json_fail('org_fk_fail'); }
    if(stripos($msg,'cannot be null')!==false){ json_fail('null_violation'); }
    if(!empty($_ENV['CERTREG_DEBUG']) || $debugHeader){ json_fail('db',500,['debug'=>$msg]); }
    json_fail('db');
}

// Prepare filesystem path
// --- Filesystem storage preparations & diagnostics ---
$baseDir = __DIR__.'/../files/templates';
$orgDir = $baseDir.'/'.$orgId;
$tplDir = $orgDir.'/'.$tplId;

// Helper to validate writability and fail early with details (without exposing sensitive FS layout beyond what caller already infers)
$fsIssues = [];
if(!is_dir($baseDir)) { if(!@mkdir($baseDir, 0775, true)) $fsIssues[] = 'mkdir_base_failed'; }
if(is_dir($baseDir) && !is_writable($baseDir)) $fsIssues[]='base_not_writable';
if(!is_dir($orgDir)) { if(!@mkdir($orgDir, 0775, true)) $fsIssues[] = 'mkdir_org_failed'; }
if(is_dir($orgDir) && !is_writable($orgDir)) $fsIssues[]='org_not_writable';
if(!is_dir($tplDir)) { if(!@mkdir($tplDir, 0775, true)) $fsIssues[] = 'mkdir_tpl_failed'; }
if(is_dir($tplDir) && !is_writable($tplDir)) $fsIssues[]='tpl_not_writable';
if($fsIssues){
    try { $del=$pdo->prepare('DELETE FROM templates WHERE id=? LIMIT 1'); $del->execute([$tplId]); } catch(Throwable $e){}
    json_fail('storage_path_not_writable',500,['issues'=>$fsIssues]);
}

$destName = 'original.'.$ext;
$destPath = $tplDir.'/'.$destName;

$moved = @move_uploaded_file($tmpPath, $destPath);
if(!$moved){
    // Fallback: sometimes move_uploaded_file fails due to open_basedir or tmp upload mismatch; try copy()
    if(is_uploaded_file($tmpPath)){
        $moved = @copy($tmpPath,$destPath);
        if($moved){ @unlink($tmpPath); }
    }
}
if(!$moved){
    $errCtx = [];
    $errCtx['exists_tpl_dir'] = is_dir($tplDir);
    $errCtx['tpl_dir_writable'] = is_writable($tplDir);
    $errCtx['dest_exists_pre'] = file_exists($destPath);
    $errCtx['tmp_is_uploaded'] = is_uploaded_file($tmpPath);
    $errCtx['php_upload_tmp_dir'] = ini_get('upload_tmp_dir');
    try { $del=$pdo->prepare('DELETE FROM templates WHERE id=? LIMIT 1'); $del->execute([$tplId]); } catch(Throwable $e){}
    if(!empty($_ENV['CERTREG_DEBUG']) || $debugHeader){
        json_fail('file_store_failed',500,['debug'=>$errCtx]);
    }
    json_fail('file_store_failed');
}

// Success response
// Generate preview (JPEG) up to max width 800 preserving aspect
function make_preview($src,$dst,$maxW=800){
    $info=@getimagesize($src); if(!$info) return false; [$w,$h]=$info; $ratio = $w>0?min(1,$maxW/$w):1; if($ratio>=1){ $targetW=$w; $targetH=$h; } else { $targetW=(int)round($w*$ratio); $targetH=(int)round($h*$ratio); }
    switch($info[2]){ // IMAGETYPE_*
        case IMAGETYPE_JPEG: $im=@imagecreatefromjpeg($src); break;
        case IMAGETYPE_PNG: $im=@imagecreatefrompng($src); break;
        case IMAGETYPE_WEBP: if(function_exists('imagecreatefromwebp')) $im=@imagecreatefromwebp($src); else return false; break;
        default: return false;
    }
    if(!$im) return false;
    $out=imagecreatetruecolor($targetW,$targetH);
    imagecopyresampled($out,$im,0,0,0,0,$targetW,$targetH,imagesx($im),imagesy($im));
    $ok=imagejpeg($out,$dst,82);
    imagedestroy($im); imagedestroy($out);
    return $ok;
}
@make_preview($destPath,$tplDir.'/preview.jpg');

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
