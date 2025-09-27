<?php
// Список шаблонів (поки що без завантаження файлів через цей endpoint)
// GET /api/templates_list.php?org_id= (опціонально, тільки для admin)
// Відповідь (оновлено): { ok:true, items:[{id,org_id?,org_code?,name,code,status,filename,file_ext,width,height,version,created_at,updated_at,coords?,preview_url?}] }

require_once __DIR__.'/../auth.php';
require_login(); // і оператор і адмін
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function column_exists(PDO $pdo,string $table,string $col): bool {
    try { $s=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $s->execute([$col]); return (bool)$s->fetch(); } catch(Throwable $e){ return false; }
}

// Перевірка наявності таблиці templates
try { $chk=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$chk->fetch()){ echo json_encode(['ok'=>true,'items'=>[],'note'=>'no_templates_table']); return; } } catch(Throwable $e){ echo json_encode(['error'=>'db']); return; }

$isAdmin = is_admin();
$sessionOrg = current_org_id();

$reqOrg = isset($_GET['org_id']) ? (int)$_GET['org_id'] : null;
if(!$isAdmin){
    // оператор: ігноруємо будь-який org_id, жорстко фільтруємо по своїй
    $reqOrg = $sessionOrg ?: 0; // 0 щоб нічого не віддати якщо раптом null
}

$columns = ['t.id','t.name','t.code','t.status','t.filename','t.file_ext','t.width','t.height','t.version','t.created_at','t.updated_at'];
$hasOrgCol = column_exists($pdo,'templates','org_id');
if($hasOrgCol){ $columns[]='t.org_id'; }
$hasCoordsCol = column_exists($pdo,'templates','coords');
if($hasCoordsCol){ $columns[]='t.coords'; }

// Приєднуємо code
$joinOrg = '';
if($hasOrgCol){
    $joinOrg = ' LEFT JOIN organizations o ON o.id = t.org_id';
    $columns[]='o.code AS org_code';
}

$sql = 'SELECT '.implode(',', $columns).' FROM templates t'.$joinOrg;
$where=[]; $params=[];
if($hasOrgCol){
    if($reqOrg){ $where[]='t.org_id = ?'; $params[]=$reqOrg; }
} else {
    // якщо немає org_id у таблиці, просто повертаємо всі (для зворотної сумісності), оператор не може змінити org все одно
}
if($where){ $sql.=' WHERE '.implode(' AND ',$where); }
$sql.=' ORDER BY t.id DESC LIMIT 200';

try {
    $st=$pdo->prepare($sql); $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    // Augment with preview_url if preview exists
    $baseRel = '/files/templates';
    foreach($rows as &$r){
        if(isset($r['org_id'])){
            $previewPathFs = __DIR__.'/../files/templates/'.$r['org_id'].'/'.$r['id'].'/preview.jpg';
            if(is_file($previewPathFs)){
                $r['preview_url'] = $baseRel.'/'.$r['org_id'].'/'.$r['id'].'/preview.jpg?v='.filemtime($previewPathFs);
            }
        }
        if($hasCoordsCol && array_key_exists('coords',$r)){
            if($r['coords'] === null){
                $r['coords']=null;
            } else {
                $decoded=json_decode($r['coords'], true);
                if($decoded === null && json_last_error() !== JSON_ERROR_NONE){
                    $r['coords']=null;
                } else {
                    $r['coords']=$decoded;
                }
            }
        }
    }
    unset($r);
    echo json_encode(['ok'=>true,'items'=>$rows]);
} catch(Throwable $e){ echo json_encode(['error'=>'db']); }
