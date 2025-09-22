<?php
// Quick DB insertion dry test for template_create logic (placeholder code path)
// Run: php scripts/quick_template_test_insert.php
// Does NOT write any files, only DB transaction with rollback.

require __DIR__.'/../db.php';

function line($m){ echo $m, "\n"; }

try {
    $org = $pdo->query("SELECT id FROM organizations WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetch();
    if(!$org){ line('No active organization found.'); exit(2); }
    $orgId = (int)$org['id'];
    line("Using org_id={$orgId}");

    $placeholder = '__P'.bin2hex(random_bytes(4));
    line("Generated placeholder code: $placeholder");

    $pdo->beginTransaction();
    $sql = "INSERT INTO templates (org_id,name,code,status,filename,file_ext,file_hash,file_size,width,height,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $orgId,
        'QuickTest '.date('H:i:s'),
        $placeholder,
        'active',
        'test.png',
        'png',
        str_repeat('0',64),
        1234,
        800,
        600
    ]);
    $id = (int)$pdo->lastInsertId();
    line("Inserted row id=$id");
    $finalCode = 'T'.$id;
    $pdo->prepare('UPDATE templates SET code=? WHERE id=?')->execute([$finalCode,$id]);
    line("Updated code to $finalCode");
    // Rollback instead of keeping the row
    $pdo->rollBack();
    line('Rolled back (no persistent row).');
    line('SUCCESS: DB insert path works with placeholder logic.');
} catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    line('FAIL: '.$e->getMessage());
    exit(1);
}
