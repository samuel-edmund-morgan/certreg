<?php
// CLI-only token seeding for UI tests (adds tokens with audit create events)
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
require_once __DIR__.'/../db.php';
$count = (int)($argv[1] ?? 3);
if($count < 1) $count = 1; if($count>10) $count = 10;
$now = gmdate('Y-m-d H:i:s');
for($i=0;$i<$count;$i++){
    $cid = 'UITEST'.bin2hex(random_bytes(4)).$i;
    $extra = 'UI-SEED';
    $issued = date('Y-m-d');
    $valid = '4000-01-01';
    $h = bin2hex(random_bytes(32));
    $ins = $pdo->prepare('INSERT INTO tokens (cid,version,h,extra_info,issued_date,valid_until,created_at) VALUES (?,?,?,?,?,?,?)');
    $ins->execute([$cid,3,$h,$extra,$issued,$valid,$now]);
    $ev = $pdo->prepare('INSERT INTO token_events (cid,event_type) VALUES (?,?)');
    $ev->execute([$cid,'create']);
    echo "Seeded token $cid\n";
}
