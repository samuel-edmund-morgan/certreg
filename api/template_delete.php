<?php
// Видалення шаблону сертифіката
// POST /api/template_delete.php
// Поля: id, _csrf
// Поведінка: жорстке видалення (рядок + директорія файлів) якщо немає прив'язаних токенів (коли зʼявиться tokens.template_id).
// Коди помилок: bad_id, not_found, forbidden, org_context_missing, in_use, fs_delete_failed, db

require_once __DIR__.'/../auth.php';
require_login();
require_once __DIR__.'/../db.php';
if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
require_csrf();

$isAdmin = is_admin();
$sessionOrg = current_org_id();

function fail($code,$http=400,$extra=[]){ if(!headers_sent()) http_response_code($http); echo json_encode(['ok'=>false,'error'=>$code]+$extra,JSON_UNESCAPED_UNICODE); exit; }
function ok($data){ echo json_encode(['ok'=>true]+$data,JSON_UNESCAPED_UNICODE); exit; }

// ensure templates table exists
try { $tchk=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$tchk->fetch()) fail('no_templates_table'); } catch(Throwable $e){ fail('db'); }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if($id<=0) fail('bad_id');

try {
    $st=$pdo->prepare('SELECT id, org_id, code, status FROM templates WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $tpl=$st->fetch(PDO::FETCH_ASSOC);
    if(!$tpl) fail('not_found',404);
} catch(Throwable $e){ fail('db'); }

if(!$isAdmin){
    if($sessionOrg===null) fail('org_context_missing');
    if((int)$tpl['org_id'] !== (int)$sessionOrg) fail('forbidden',403);
}

// If tokens.template_id already exists — prevent deletion if referenced
$hasTemplateIdCol = false;
try {
    $c = $pdo->query("SHOW COLUMNS FROM tokens LIKE 'template_id'");
    if($c->fetch()) $hasTemplateIdCol=true;
} catch(Throwable $e){ /* ignore */ }
if($hasTemplateIdCol){
    try {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE template_id = ?');
        $cnt->execute([$id]);
        if((int)$cnt->fetchColumn() > 0){
            fail('in_use',409);
        }
    } catch(Throwable $e){ fail('db'); }
}

// Delete DB row first (so concurrent ops fail early); then remove files.
try {
    $del=$pdo->prepare('DELETE FROM templates WHERE id=? LIMIT 1');
    $del->execute([$id]);
    if($del->rowCount()===0) fail('not_found'); // race
} catch(Throwable $e){ fail('db'); }

// Filesystem cleanup: /files/templates/<org_id>/<id>/
$dir = realpath(__DIR__.'/../files/templates').'/'.$tpl['org_id'].'/'.$id;
if(is_dir($dir)){
    // Recursive delete
    $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    $okFs=true;
    foreach($ri as $f){
        $path=$f->getPathname();
        if($f->isDir()) { if(!@rmdir($path)) $okFs=false; }
        else { if(!@unlink($path)) $okFs=false; }
    }
    if(!@rmdir($dir)) $okFs=false;
    if(!$okFs){
        // We already deleted DB row; surface warning status.
        fail('fs_delete_failed',500,['partial'=>true]);
    }
}

ok(['deleted_id'=>$id]);
