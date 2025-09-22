<?php
// Diagnostic script: php scripts/diag_templates_schema.php
// Outputs templates table schema, columns, indexes, enum values, and performs a dry-run insert (rolled back)
// to surface the real PDO exception message that currently maps to generic { error:"db" } in template_create.
// DO NOT expose this publicly; intended for temporary debugging.

require __DIR__.'/../db.php';

function out($label,$data){
    if(is_array($data)||is_object($data)){
        echo "=== $label ===\n".print_r($data,true)."\n"; return; }
    echo "=== $label ===\n$data\n"; }

echo "[certreg diagnostics templates schema]\n";

// 1. SHOW CREATE TABLE (if permitted)
try {
    $row = $pdo->query("SHOW CREATE TABLE templates")->fetch(PDO::FETCH_ASSOC);
    if($row){ out('SHOW CREATE TABLE templates',$row['Create Table'] ?? json_encode($row)); }
} catch(Throwable $e){ out('SHOW CREATE TABLE error',$e->getMessage()); }

// 2. Columns detail
try {
    $cols = $pdo->query("SHOW COLUMNS FROM templates")->fetchAll(PDO::FETCH_ASSOC);
    out('COLUMNS',$cols);
} catch(Throwable $e){ out('COLUMNS error',$e->getMessage()); }

// 3. Indexes
try {
    $idx = $pdo->query("SHOW INDEX FROM templates")->fetchAll(PDO::FETCH_ASSOC);
    out('INDEXES',$idx);
} catch(Throwable $e){ out('INDEX error',$e->getMessage()); }

// 4. Sample organizations (need at least one org id)
$orgId = null;
try {
    $org = $pdo->query("SELECT id, code, name FROM organizations ORDER BY id ASC LIMIT 1")->fetch();
    if($org){ $orgId = (int)$org['id']; out('ORG sample', $org); }
    else { out('ORG sample','NO ORGANIZATIONS FOUND'); }
} catch(Throwable $e){ out('ORG query error',$e->getMessage()); }

if(!$orgId){ echo "Cannot proceed with dry-run insert: no org id.\n"; exit(0); }

// 5. Dry-run insert inside rollback transaction
try {
    $pdo->beginTransaction();
    $testName = '__diag_tpl_'.time();
    $sql = "INSERT INTO templates (org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())";
    $stmt = $pdo->prepare($sql);
    // Minimal plausible data
    $stmt->execute([
        $orgId,
        $testName,
        null, // code nullable initially in migration; if not, should trigger an error we want to see
        'active',
        'diag.png',
        'png',
        str_repeat('0',64),
        1234,
        800,
        600
    ]);
    $newId = (int)$pdo->lastInsertId();
    // Fallback code update (simulate template_create logic)
    if($newId){
        try { $pdo->prepare('UPDATE templates SET code=? WHERE id=?')->execute(['T'.$newId,$newId]); } catch(Throwable $e) { out('UPDATE code exception',$e->getMessage()); }
    }
    $pdo->rollBack();
    out('Dry-run insert result',"Inserted row id=$newId then rolled back");
} catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    out('Dry-run insert exception',$e->getMessage());
}

// 6. Enum values for status (SHOW COLUMNS parse)
if(!empty($cols)){
    foreach($cols as $c){
        if($c['Field']==='status'){
            out('Status column type',$c['Type']);
        }
    }
}

echo "Done.\n";
?>
