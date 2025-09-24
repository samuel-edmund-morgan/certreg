<?php
// Оновлення шаблону сертифіката
// POST /api/template_update.php
// Поля: id (required), name? (<=160), status? (active|disabled), template_file? (multipart, заміна фонового зображення)
// Відповідь: { ok:true, template:{...} } або { ok:false, error:"code" }

require_once __DIR__.'/../auth.php';
require_login();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_csrf();

$isAdmin = is_admin();
$sessionOrg = current_org_id();

function jfail($code,$http=400,$extra=[]) { if(!headers_sent()) http_response_code($http); echo json_encode(array_merge(['ok'=>false,'error'=>$code],$extra),JSON_UNESCAPED_UNICODE); exit; }
function jok($data){ echo json_encode(['ok'=>true]+$data,JSON_UNESCAPED_UNICODE); exit; }

// Перевірка наявності таблиці
try { $chk=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$chk->fetch()) jfail('no_templates_table'); } catch(Throwable $e){ jfail('db'); }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if($id<=0) jfail('bad_id');

// Завантажити поточний запис
try {
    $st=$pdo->prepare('SELECT * FROM templates WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) jfail('not_found',404);
} catch(Throwable $e){ jfail('db'); }

// Перевірка доступу за org для оператора
if(!$isAdmin){
    if($sessionOrg===null) jfail('org_context_missing');
    if((int)$row['org_id'] !== (int)$sessionOrg) jfail('forbidden',403);
}

// Підготовка оновлюваних полів
$updates = [];$params=[];$fileReplaced=false;$newMeta=[];

// name
if(array_key_exists('name',$_POST)){
    $name = trim($_POST['name']);
    if($name==='') jfail('name_required');
    if(mb_strlen($name)>160) jfail('name_too_long');
    if($name !== $row['name']){ $updates[]='name=?'; $params[]=$name; }
}

// status (active|inactive|archived)
if(array_key_exists('status',$_POST)){
    $status = trim(strtolower($_POST['status']));
    if(!in_array($status,['active','inactive','archived'],true)) jfail('bad_status');
    if($status !== strtolower($row['status'])){ $updates[]='status=?'; $params[]=$status; }
}

// Файл (опційно)
if(isset($_FILES['template_file']) && ($_FILES['template_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE){
    $f = $_FILES['template_file'];
    if(($f['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) jfail('upload_error',['php_code'=>$f['error']]);
    $tmp=$f['tmp_name']; if(!is_uploaded_file($tmp)) jfail('upload_invalid');
    $size=(int)$f['size']; if($size<=0) jfail('file_empty');
    $maxSize=15*1024*1024; if($size>$maxSize) jfail('file_too_large');
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    $allowed=['jpg','jpeg','png','webp']; if(!in_array($ext,$allowed,true)) jfail('bad_ext');
    $info=@getimagesize($tmp); if(!$info) jfail('not_image');
    $width=$info[0]; $height=$info[1];
    if($width<200||$height<200) jfail('image_too_small');
    if($width>12000||$height>12000) jfail('image_too_large');
    $hash=hash_file('sha256',$tmp);
    $fileReplaced=true;
    $newMeta=[ 'filename'=>$f['name'],'file_ext'=>$ext,'file_hash'=>$hash,'file_size'=>$size,'width'=>$width,'height'=>$height ];
    foreach(['filename','file_ext','file_hash','file_size','width','height'] as $k){ $updates[]="$k=?"; $params[]=$newMeta[$k]; }
    // version++
    $updates[]='version=version+1';
}

if(!$updates){ jfail('nothing_to_update'); }

$updates[]='updated_at=NOW()';
$sql='UPDATE templates SET '.implode(',', $updates).' WHERE id=? LIMIT 1';
$params[]=$id;

try {
    $pdo->beginTransaction();
    $ok = $pdo->prepare($sql)->execute($params);
    if(!$ok){ throw new RuntimeException('update_failed'); }
    $pdo->commit();
} catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); jfail('db'); }

// Якщо файл замінено – перемістити новий
if($fileReplaced){
    $orgId = (int)$row['org_id'];
    $tplDir = __DIR__.'/../files/templates/'.$orgId.'/'.$id;
    if(!is_dir($tplDir)) @mkdir($tplDir,0775,true);
    $dest=$tplDir.'/original.'.$newMeta['file_ext'];
    if(!@move_uploaded_file($tmp,$dest)){
        jfail('file_store_failed');
    }
    // Rebuild preview
    function rebuild_preview($src,$dst,$maxW=800){
        $info=@getimagesize($src); if(!$info) return false; [$w,$h]=$info; $ratio = $w>0?min(1,$maxW/$w):1; if($ratio>=1){ $targetW=$w; $targetH=$h; } else { $targetW=(int)round($w*$ratio); $targetH=(int)round($h*$ratio); }
        switch($info[2]){
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
    @rebuild_preview($dest,$tplDir.'/preview.jpg');
}

// Повернути оновлений рядок
try {
    $st=$pdo->prepare('SELECT id,org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at FROM templates WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $tpl=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tpl) jfail('not_found_post');
    jok(['template'=>$tpl]);
} catch(Throwable $e){ jfail('db'); }
