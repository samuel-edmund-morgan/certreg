<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../config.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='GET'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

$page = max(1, (int)($_GET['page'] ?? 1));
$per  = (int)($_GET['per_page'] ?? 20); if($per <1 || $per>100) $per=20;
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'id';
$dir  = strtoupper($_GET['dir'] ?? 'ASC'); if(!in_array($dir,['ASC','DESC'],true)) $dir='ASC';
$allowedSort = ['id','name','code','created_at'];
if(!in_array($sort,$allowedSort,true)) $sort='id';

$where = [];$params=[];
if($search!==''){
  $where[] = '(name LIKE ? OR code LIKE ?)';
  $like = '%'.$search.'%';
  $params[]=$like; $params[]=$like;
}
$wsql = $where?('WHERE '.implode(' AND ',$where)) : '';

try {
  $stc = $pdo->prepare("SELECT COUNT(*) FROM organizations $wsql");
  $stc->execute($params);
  $total = (int)$stc->fetchColumn();
  $pages = $total? (int)ceil($total/$per):1;
  if($page>$pages) $page=$pages;
  $off = ($page-1)*$per;
  $sql = "SELECT id,name,code,logo_path,favicon_path,primary_color,accent_color,secondary_color,footer_text,support_contact,is_active,created_at,updated_at FROM organizations $wsql ORDER BY $sort $dir LIMIT :lim OFFSET :off";
  $st = $pdo->prepare($sql);
  foreach($params as $i=>$v){ $st->bindValue($i+1,$v,PDO::PARAM_STR); }
  $st->bindValue(':lim',$per,PDO::PARAM_INT);
  $st->bindValue(':off',$off,PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $defaultCode = $config['org_code'] ?? 'DEFAULT';
  foreach($rows as &$r){ $r['is_default'] = ($r['code'] === $defaultCode) ? 1 : 0; }
  echo json_encode(['ok'=>true,'page'=>$page,'per_page'=>$per,'total'=>$total,'pages'=>$pages,'orgs'=>$rows,'default_code'=>$defaultCode]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
