<?php
require_once __DIR__.'/../auth.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['error'=>'method']); exit; }
if(!hash_equals(csrf_token(), $_POST['_csrf'] ?? '')){ http_response_code(400); echo json_encode(['error'=>'csrf']); exit; }
$cid = trim($_POST['cid'] ?? '');
if($cid===''){ http_response_code(400); echo json_encode(['error'=>'missing_cid']); exit; }
require_once __DIR__.'/../db.php';
$st = $pdo->prepare('DELETE FROM tokens WHERE cid=? LIMIT 1');
$st->execute([$cid]);
if($st->rowCount()<1){ echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
// (Не пишемо лог видалення щоб не тримати PII; можна додати окрему службову таблицю audit якщо потрібно)

echo json_encode(['ok'=>true]);
