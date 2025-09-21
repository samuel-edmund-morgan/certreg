<?php
// Usage: php scripts/maintenance/purge_non_default_orgs.php
// Removes all organizations except the default (config org_code), if they have no operators or tokens.
// If operators/tokens exist for a non-default org, the org is skipped with a warning.

require __DIR__.'/../../config.php';
require __DIR__.'/../../db.php';

$default = $config['org_code'] ?? 'DEFAULT';
echo "[INFO] Default org code: $default\n";

try {
    $st = $pdo->query('SELECT id, code FROM organizations');
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach($all as $org){
        if($org['code'] === $default){
            echo "[KEEP] #{$org['id']} {$org['code']} (default)\n";
            continue;
        }
        // Dependencies
        $cOps = $pdo->prepare('SELECT COUNT(*) FROM creds WHERE org_id=?'); $cOps->execute([$org['id']]); $ops = (int)$cOps->fetchColumn();
        $tokens = 0;
        $tokTable = $pdo->query("SHOW TABLES LIKE 'tokens'");
        if($tokTable->fetch()){
            $cTok = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE org_id=?'); $cTok->execute([$org['id']]); $tokens = (int)$cTok->fetchColumn();
        }
        if($ops>0 || $tokens>0){
            echo "[SKIP] #{$org['id']} {$org['code']} has deps (operators=$ops tokens=$tokens)\n";
            continue;
        }
        $del = $pdo->prepare('DELETE FROM organizations WHERE id=? LIMIT 1');
        $del->execute([$org['id']]);
        if($del->rowCount()===1){
            echo "[DEL] #{$org['id']} {$org['code']} deleted.\n";
            // Remove branding directory if exists
            $dir = $_SERVER['DOCUMENT_ROOT'].'/files/branding/org_'.$org['id'];
            if(is_dir($dir)){
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach($it as $f){ if($f->isDir()) @rmdir($f->getRealPath()); else @unlink($f->getRealPath()); }
                @rmdir($dir);
            }
        } else {
            echo "[ERR] Failed to delete #{$org['id']} {$org['code']}\n";
        }
    }
    echo "[DONE] Purge complete.\n";
} catch(Throwable $e){
    fwrite(STDERR, '[ERROR] '.$e->getMessage()."\n");
    exit(1);
}
?>
