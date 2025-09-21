<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
$id = (int)($_POST['id'] ?? 0);
$active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
if($id<=0){ echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }
try {
  $st=$pdo->prepare('SELECT id,code FROM organizations WHERE id=?'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row){ echo json_encode(['ok'=>false,'error'=>'nf']); exit; }
  $up=$pdo->prepare('UPDATE organizations SET is_active=? WHERE id=? LIMIT 1'); $up->execute([$active,$id]);
  echo json_encode(['ok'=>true,'id'=>$id,'is_active'=>$active]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
