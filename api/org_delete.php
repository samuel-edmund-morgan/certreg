<?php
// Delete an organization (admin only)
// Preconditions:
//  - Cannot delete default (main) organization (from config org_code)
//  - Cannot delete if operators or tokens still reference it
//  - Removes per-org branding directory if present (silently ignores errors)
// Returns JSON: {ok:true} or {ok:false,error:<code>}

require_once __DIR__.'/../auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../db.php';

if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }

$id = (int)($_POST['id'] ?? 0);
if($id <= 0){ echo json_encode(['ok'=>false,'error'=>'bad_id']); exit; }

$defaultCode = $config['org_code'] ?? 'DEFAULT';

try {
    // Load org & protect default
    $st = $pdo->prepare('SELECT id, code FROM organizations WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $org = $st->fetch(PDO::FETCH_ASSOC);
    if(!$org){ echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
    if($org['code'] === $defaultCode){ echo json_encode(['ok'=>false,'error'=>'default_protected']); exit; }

    // Operators referencing?
    $cOps = $pdo->prepare('SELECT COUNT(*) FROM creds WHERE org_id=?');
    $cOps->execute([$id]);
    if((int)$cOps->fetchColumn() > 0){ echo json_encode(['ok'=>false,'error'=>'has_operators']); exit; }

    // Tokens referencing?
    $tokensTableExists = false;
    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'tokens'");
        $tokensTableExists = (bool)$chk->fetch();
    } catch(Throwable $ie){ $tokensTableExists = false; }
    if($tokensTableExists){
        $cTok = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE org_id=?');
        $cTok->execute([$id]);
        if((int)$cTok->fetchColumn() > 0){ echo json_encode(['ok'=>false,'error'=>'has_tokens']); exit; }
    }

    // Delete
    $del = $pdo->prepare('DELETE FROM organizations WHERE id=? LIMIT 1');
    $del->execute([$id]);
    if($del->rowCount() !== 1){ echo json_encode(['ok'=>false,'error'=>'delete_fail']); exit; }

    // Remove branding dir (best-effort)
    $brandDir = $_SERVER['DOCUMENT_ROOT'].'/files/branding/org_'.$id; // planned path pattern
    if(is_dir($brandDir)){
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($brandDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($it as $f){
            try {
                if($f->isDir()) rmdir($f->getRealPath()); else unlink($f->getRealPath());
            } catch(Throwable $ie){ /* ignore */ }
        }
        @rmdir($brandDir);
    }

    echo json_encode(['ok'=>true]);
} catch(Throwable $e){ http_response_code(500); error_log('org_delete error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
