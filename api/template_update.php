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

function column_exists(PDO $pdo,string $table,string $col): bool {
    try {
        $s=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $s->execute([$col]);
        return (bool)$s->fetch();
    } catch(Throwable $e){ return false; }
}

function norm_number($value,float $min,float $max,string $errCode){
    if($value===null || $value==='') jfail($errCode);
    if(!is_numeric($value)) jfail($errCode);
    $v=(float)$value;
    if(!is_finite($v)) jfail($errCode);
    if($v<$min) $v=$min;
    if($v>$max) $v=$max;
    return round($v,3);
}

function norm_string($value,int $maxLen,string $errCode){
    if(!is_scalar($value)) jfail($errCode);
    $str=trim((string)$value);
    if(mb_strlen($str)>$maxLen) jfail($errCode);
    return $str;
}

function normalize_coords_payload($input, bool $allowNull, bool $qrRequiresSize=true){
    if($input===null) return null;
    if(is_string($input)){
        $trim=trim($input);
        if($trim==='') return $allowNull ? null : jfail('coords_required');
        $decoded=json_decode($trim,true);
        if($decoded===null && json_last_error()!==JSON_ERROR_NONE) jfail('coords_invalid_json');
        $input=$decoded;
    }
    if($input===null) return null;
    if(!is_array($input)) jfail('coords_invalid_structure');
    $out=[];
    $allowedKeys=['x','y','size','width','height','angle','max_width','tracking','line_height','radius','scale','uppercase','wrap','bold','italic','align','font','color','text','order'];
    foreach($input as $field=>$spec){
        if(!is_string($field) || $field==='') jfail('coords_invalid_key');
        if($spec instanceof stdClass) $spec=(array)$spec;
        if(!is_array($spec)) jfail('coords_invalid_spec');
        $lower=array_change_key_case($spec, CASE_LOWER);
        foreach(array_keys($lower) as $prop){ if(!in_array($prop,$allowedKeys,true) && !in_array($prop,['x','y'],true)) jfail('coords_unknown_property'); }
        if(!array_key_exists('x',$lower) || !array_key_exists('y',$lower)) jfail('coords_missing_xy');
        $entry=[
            'x'=>norm_number($lower['x'],-2000,20000,'coords_bad_xy'),
            'y'=>norm_number($lower['y'],-2000,20000,'coords_bad_xy'),
        ];
        $floatProps=[
            'size'=>[0,5000,'coords_bad_size'],
            'width'=>[0,20000,'coords_bad_width'],
            'height'=>[0,20000,'coords_bad_height'],
            'angle'=>[-360,360,'coords_bad_angle'],
            'max_width'=>[0,20000,'coords_bad_max_width'],
            'tracking'=>[-1000,1000,'coords_bad_tracking'],
            'line_height'=>[0,10,'coords_bad_line_height'],
            'radius'=>[0,20000,'coords_bad_radius'],
            'scale'=>[0,10,'coords_bad_scale']
        ];
        foreach($floatProps as $prop=>$conf){
            if(array_key_exists($prop,$lower) && $lower[$prop]!==null && $lower[$prop]!==''){
                $entry[$prop]=norm_number($lower[$prop], $conf[0], $conf[1], $conf[2]);
            }
        }
        $boolProps=['uppercase','wrap','bold','italic'];
        foreach($boolProps as $prop){
            if(array_key_exists($prop,$lower)){
                $val=filter_var($lower[$prop], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if($val===null) jfail('coords_bad_bool');
                $entry[$prop]=(bool)$val;
            }
        }
        if(array_key_exists('align',$lower)){
            $align=strtolower(norm_string($lower['align'],12,'coords_bad_align'));
            $allowed=['left','center','right','justify'];
            if(!in_array($align,$allowed,true)) jfail('coords_bad_align');
            $entry['align']=$align;
        }
        if(array_key_exists('font',$lower)){
            $entry['font']=norm_string($lower['font'],120,'coords_bad_font');
        }
        if(array_key_exists('color',$lower)){
            $color=strtolower(norm_string($lower['color'],32,'coords_bad_color'));
            if($color!=='' && !preg_match('/^#[0-9a-f]{3,8}$/', $color)) jfail('coords_bad_color');
            $entry['color']=$color;
        }
        if(array_key_exists('text',$lower)){
            $entry['text']=norm_string($lower['text'],240,'coords_bad_text');
        }
        if(array_key_exists('order',$lower)){
            $entry['order']=(int)round(norm_number($lower['order'],-1000,1000,'coords_bad_order'));
        }
        ksort($entry);
        if($qrRequiresSize && strtolower($field)==='qr' && !isset($entry['size'])) jfail('coords_qr_size_required');
        $out[$field]=$entry;
    }
    ksort($out);
    return $out ? $out : ($allowNull ? null : []);
}

// Перевірка наявності таблиці
try { $chk=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$chk->fetch()) jfail('no_templates_table'); } catch(Throwable $e){ jfail('db'); }
$hasCoordsColumn = column_exists($pdo,'templates','coords');

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
$coordsNormalizedResponse = null;

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

// coords (JSON)
if(array_key_exists('coords', $_POST)){
    if(!$hasCoordsColumn) jfail('coords_not_supported');
    $newCoords = normalize_coords_payload($_POST['coords'], true);
    $coordsNormalizedResponse = $newCoords;
    $newCoordsJson = $newCoords === null ? null : json_encode($newCoords, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $existingCoordsJson = null;
    if($hasCoordsColumn && array_key_exists('coords',$row) && $row['coords'] !== null){
        $decodedExisting = json_decode($row['coords'], true);
        if($decodedExisting === null && json_last_error() !== JSON_ERROR_NONE){
            $existingCoordsJson = $row['coords'];
        } else {
            $existingCoordsJson = $decodedExisting === null ? null : json_encode($decodedExisting, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
    }
    if($newCoordsJson !== $existingCoordsJson){
        $updates[]='coords=?';
        $params[]=$newCoords === null ? null : $newCoordsJson;
    }
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
    $st=$pdo->prepare('SELECT id,org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at,coords FROM templates WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $tpl=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tpl) jfail('not_found_post');
    if(array_key_exists('coords',$tpl)){
        if($coordsNormalizedResponse !== null){
            $tpl['coords']=$coordsNormalizedResponse;
        } elseif($tpl['coords'] === null){
            $tpl['coords']=null;
        } else {
            $decoded=json_decode($tpl['coords'], true);
            if($decoded === null && json_last_error() !== JSON_ERROR_NONE){
                $tpl['coords']=null;
            } else {
                $tpl['coords']=$decoded;
            }
        }
    }
    jok(['template'=>$tpl]);
} catch(Throwable $e){ jfail('db'); }
