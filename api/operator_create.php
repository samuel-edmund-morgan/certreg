<?php
// Robust path resolution
$root = dirname(__DIR__);
require_once $root.'/auth.php';
require_once $root.'/db.php';
require_admin();
require_csrf();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
$u = trim($_POST['username'] ?? '');
$p1 = $_POST['password'] ?? '';
$p2 = $_POST['password2'] ?? '';
$orgId = isset($_POST['org_id']) ? (int)$_POST['org_id'] : 0;
if($u==='' || $p1==='' || $p2===''){ echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
if($p1!==$p2){ echo json_encode(['ok'=>false,'error'=>'mismatch']); exit; }
if(strlen($p1) < 8){ echo json_encode(['ok'=>false,'error'=>'short']); exit; }
// username simple policy
if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/',$u)){ echo json_encode(['ok'=>false,'error'=>'uname']); exit; }
// org required (operators must belong to org). Admins (role=admin) not created here so enforce.
if($orgId <= 0){ echo json_encode(['ok'=>false,'error'=>'org_required']); exit; }
try {
    // Column existence (for robustness if migration incomplete)
    $hasActive = false; $hasCreated = false;
    try {
        $cst = $pdo->query("SHOW COLUMNS FROM `creds` LIKE 'is_active'");
        $hasActive = $cst && $cst->rowCount() === 1;
    } catch(Throwable $ie) { $hasActive=false; }
    try {
        $cst2 = $pdo->query("SHOW COLUMNS FROM `creds` LIKE 'created_at'");
        $hasCreated = $cst2 && $cst2->rowCount() === 1;
    } catch(Throwable $ie) { $hasCreated=false; }

    // Validate org exists & active
    $orgSt = $pdo->prepare('SELECT id,is_active FROM organizations WHERE id=? LIMIT 1');
    $orgSt->execute([$orgId]);
    $orgRow = $orgSt->fetch(PDO::FETCH_ASSOC);
    if(!$orgRow){ echo json_encode(['ok'=>false,'error'=>'org_nf']); exit; }
    if((int)$orgRow['is_active']!==1){ echo json_encode(['ok'=>false,'error'=>'org_inactive']); exit; }

    $st = $pdo->prepare('SELECT 1 FROM creds WHERE username=? LIMIT 1');
    $st->execute([$u]);
    if($st->fetch()){ echo json_encode(['ok'=>false,'error'=>'exists']); exit; }
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $opts = $algo === PASSWORD_ARGON2ID ? ['memory_cost'=>1<<17,'time_cost'=>3,'threads'=>1] : ['cost'=>12];
    $hash = password_hash($p1,$algo,$opts);
    // role always operator
    $orgColExists = false; try { $cOrg=$pdo->query("SHOW COLUMNS FROM `creds` LIKE 'org_id'"); $orgColExists = $cOrg && $cOrg->rowCount()===1; } catch(Throwable $e){ $orgColExists=false; }
    if(!$orgColExists){ echo json_encode(['ok'=>false,'error'=>'org_col_missing']); exit; }
    if($hasActive && $hasCreated){
        $ins = $pdo->prepare('INSERT INTO creds (username, passhash, role, org_id, is_active, created_at) VALUES (?,?,?,?,?,NOW())');
        $ins->execute([$u,$hash,'operator',$orgId,1]);
    } elseif($hasActive && !$hasCreated){
        $ins = $pdo->prepare('INSERT INTO creds (username, passhash, role, org_id, is_active) VALUES (?,?,?,?,?)');
        $ins->execute([$u,$hash,'operator',$orgId,1]);
    } elseif(!$hasActive && $hasCreated){
        $ins = $pdo->prepare('INSERT INTO creds (username, passhash, role, org_id, created_at) VALUES (?,?,?,?,NOW())');
        $ins->execute([$u,$hash,'operator',$orgId]);
    } else {
        $ins = $pdo->prepare('INSERT INTO creds (username, passhash, role, org_id) VALUES (?,?,?,?)');
        $ins->execute([$u,$hash,'operator',$orgId]);
    }
    echo json_encode(['ok'=>true,'org_id'=>$orgId]);
} catch(Throwable $e){ http_response_code(500); error_log('operator_create error: '.$e->getMessage()); echo json_encode(['ok'=>false,'error'=>'db']); }
?>
