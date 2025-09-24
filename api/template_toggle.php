<?php
// Швидке перемикання статусу шаблону (active <-> inactive)
// POST /api/template_toggle.php
// Поля: id, _csrf
// Відповідь: { ok:true, template:{...} } або { ok:false, error:"code" }
// Коди помилок: bad_id, not_found, org_context_missing, forbidden, bad_status_current, db, no_templates_table
// Примітка: працює тільки для статусів 'active' або 'inactive'. 'archived' – не змінюється (bad_status_current).

require_once __DIR__.'/../auth.php';
require_login();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_csrf();

$isAdmin = is_admin();
$sessionOrg = current_org_id();

function t_fail($code,$http=400,$extra=[]) { if(!headers_sent()) http_response_code($http); echo json_encode(['ok'=>false,'error'=>$code]+$extra,JSON_UNESCAPED_UNICODE); exit; }
function t_ok($data){ echo json_encode(['ok'=>true]+$data,JSON_UNESCAPED_UNICODE); exit; }

// Ensure templates table exists
try { $t=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$t->fetch()) t_fail('no_templates_table'); } catch(Throwable $e){ t_fail('db'); }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if($id<=0) t_fail('bad_id');

try {
    $st=$pdo->prepare('SELECT id,org_id,status FROM templates WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $tpl=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tpl) t_fail('not_found',404);
} catch(Throwable $e){ t_fail('db'); }

if(!$isAdmin){
    if($sessionOrg===null) t_fail('org_context_missing');
    if((int)$tpl['org_id'] !== (int)$sessionOrg) t_fail('forbidden',403);
}

$current = strtolower(trim($tpl['status']));
if($current==='archived') t_fail('bad_status_current');
if(!in_array($current,['active','inactive'],true)) t_fail('bad_status_current');
$next = $current === 'active' ? 'inactive' : 'active';

try {
    $up=$pdo->prepare('UPDATE templates SET status=?, updated_at=NOW() WHERE id=? LIMIT 1');
    $up->execute([$next,$id]);
} catch(Throwable $e){ t_fail('db'); }

try {
    $st=$pdo->prepare('SELECT id,org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at FROM templates WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) t_fail('not_found_post');
    t_ok(['template'=>$row]);
} catch(Throwable $e){ t_fail('db'); }
