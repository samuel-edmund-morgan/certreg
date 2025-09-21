<?php
// Список шаблонів (поки що без завантаження файлів через цей endpoint)
// GET /api/templates_list.php?org_id= (опціонально, тільки для admin)
// Відповідь: { ok:true, items:[{id,name,org_id,org_code,filename,is_active,created_at}] }

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

$columns = ['t.id','t.name','t.filename','t.is_active','t.created_at'];
$hasOrgCol = column_exists($pdo,'templates','org_id');
if($hasOrgCol){ $columns[]='t.org_id'; }

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
    echo json_encode(['ok'=>true,'items'=>$rows]);
} catch(Throwable $e){ echo json_encode(['error'=>'db']); }
