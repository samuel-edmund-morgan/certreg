<?php
// CLI-only token seeding for UI tests (adds tokens with audit create events)
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Forbidden\n"); }
require_once __DIR__.'/../db.php';
$count = (int)($argv[1] ?? 3);
if($count < 1) $count = 1; if($count>10) $count = 10;
$now = gmdate('Y-m-d H:i:s');
for($i=0;$i<$count;$i++){
    $cid = 'UITEST'.bin2hex(random_bytes(4)).$i;
    $course = 'COURSE-UI';
    $grade = 'A';
    $issued = date('Y-m-d');
    $valid = '4000-01-01';
    $h = bin2hex(random_bytes(32));
    $ins = $pdo->prepare('INSERT INTO tokens (cid,version,h,course,grade,issued_date,valid_until,created_at) VALUES (?,?,?,?,?,?,?,?)');
    $ins->execute([$cid,2,$h,$course,$grade,$issued,$valid,$now]);
    $ev = $pdo->prepare('INSERT INTO token_events (cid,event_type) VALUES (?,?)');
    $ev->execute([$cid,'create']);
    echo "Seeded token $cid\n";
}
