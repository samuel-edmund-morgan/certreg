<?php
// Robust path resolution (works even if DOCUMENT_ROOT unset in CLI)
$root = dirname(__DIR__);
require_once $root.'/auth.php';
require_once $root.'/db.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

// Detect columns dynamically to avoid fatal if migration incomplete
function column_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare('SHOW COLUMNS FROM `'.$table.'` LIKE ?');
        $st->execute([$col]);
        return (bool)$st->fetch();
    } catch(Throwable $e){ return false; }
}

try {
    $hasActive = column_exists($pdo,'creds','is_active');
    $hasCreated = column_exists($pdo,'creds','created_at');
    $cols = ['id','username','role'];
    if($hasActive) $cols[] = 'IFNULL(is_active,1) AS is_active'; else $cols[] = '1 AS is_active';
    if($hasCreated) $cols[] = 'created_at'; else $cols[] = 'NULL AS created_at';

    // Pagination params
    $page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if($perPage < 10) $perPage = 10; elseif($perPage > 200) $perPage = 200;
    $offset = ($page-1)*$perPage;

    // Optional simple sorting (id or created_at)
    $sort = $_GET['sort'] ?? 'id';
    $allowedSorts = ['id','username','created_at'];
    if(!in_array($sort, $allowedSorts, true)) $sort = 'id';
    $dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    if($sort === 'created_at' && !$hasCreated) { $sort = 'id'; }

    $total = (int)$pdo->query('SELECT COUNT(*) FROM creds')->fetchColumn();
    $pages = max(1,(int)ceil($total / $perPage));
    if($page > $pages) { $page = $pages; $offset = ($page-1)*$perPage; }

    $sql = 'SELECT '.implode(',',$cols).' FROM creds ORDER BY '.$sort.' '.$dir.' LIMIT :lim OFFSET :off';
    $st = $pdo->prepare($sql);
    $st->bindValue(':lim',$perPage,PDO::PARAM_INT);
    $st->bindValue(':off',$offset,PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'=>true,
        'users'=>$rows,
        'page'=>$page,
        'pages'=>$pages,
        'per_page'=>$perPage,
        'total'=>$total,
        'sort'=>$sort,
        'dir'=>strtolower($dir)
    ]);
} catch(Throwable $e){
    // Log detailed error server-side, return generic code client-side
    error_log('operators_list error: '. $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'db']);
}
?>
