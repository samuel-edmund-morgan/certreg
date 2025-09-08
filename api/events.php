<?php
require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');

$cid = trim($_GET['cid'] ?? '');
$limit = (int)($_GET['limit'] ?? 50);
if($limit < 1 || $limit > 500) $limit = 50;

if($cid === ''){
  $st = $pdo->prepare("SELECT id,cid,event_type,reason,admin_user,prev_revoked_at,prev_revoke_reason,created_at FROM token_events ORDER BY id DESC LIMIT :lim");
  $st->bindValue(':lim',$limit,PDO::PARAM_INT);
  $st->execute();
  echo json_encode(['ok'=>1,'events'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

$st = $pdo->prepare("SELECT id,cid,event_type,reason,admin_user,prev_revoked_at,prev_revoke_reason,created_at FROM token_events WHERE cid=:cid ORDER BY id DESC LIMIT :lim");
$st->bindValue(':cid',$cid);
$st->bindValue(':lim',$limit,PDO::PARAM_INT);
$st->execute();
echo json_encode(['ok'=>1,'cid'=>$cid,'events'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
