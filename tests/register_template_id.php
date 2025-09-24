<?php
// Tests for registration with template_id: happy path and negatives
// Run: php tests/register_template_id.php
require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';

// Simulate admin with org context
$_SESSION['admin_id']=1; $_SESSION['admin_user']='admin_register_tpl'; $_SESSION['admin_role']='admin';
// Discover an active org id to use
$orgId = null; try { $q=$pdo->query("SELECT id FROM organizations WHERE is_active=1 ORDER BY id ASC LIMIT 1"); $orgId = $q?$q->fetchColumn():null; } catch (Throwable $e) {}
if(!$orgId){ echo "[SKIP] No active organizations found.\n"; exit(0); }
$_SESSION['org_id']=(int)$orgId;

function tassert($cond,$msg){ if(!$cond){ echo "[FAIL] $msg\n"; exit(1);} else { echo "[OK] $msg\n"; }}
function call_json_endpoint($path, $json){
  // Run the endpoint in an isolated PHP process to avoid header/warning noise and exit() side-effects
  $orgId = $_SESSION['org_id'] ?? '';
  $cmd = 'php '.escapeshellarg(__DIR__.'/endpoint_runner.php').' '.escapeshellarg($path).' '.escapeshellarg($json).' '.escapeshellarg((string)$orgId);
  $out = shell_exec($cmd);
  return $out;
}

// Detect optional schema (tokens.template_id) and templates table; skip if missing
$hasTplCol=false; $hasTplTable=false;
try { $c=$pdo->query("SHOW COLUMNS FROM tokens LIKE 'template_id'"); if($c && $c->fetch()) $hasTplCol=true; } catch(Throwable $e){}
try { $t=$pdo->query("SHOW TABLES LIKE 'templates'"); if($t && $t->fetch()) $hasTplTable=true; } catch(Throwable $e){}
if(!$hasTplCol || !$hasTplTable){
  echo "[SKIP] templates or tokens.template_id not present.\n"; exit(0);
}

// Helper: create a template row directly in DB
function create_template_direct(PDO $pdo, int $orgId, string $name, string $status='active'): int {
  // Detect available columns in templates
  $cols = [];
  $stmt = $pdo->query("SHOW COLUMNS FROM templates");
  foreach(($stmt?$stmt->fetchAll(PDO::FETCH_ASSOC):[]) as $c){ $cols[$c['Field']] = true; }
  // Minimal required set for register.php validation: id (auto), org_id, status
  $fields = ['org_id','status']; $values = [$orgId,$status];
  if(isset($cols['name'])){ $fields[]='name'; $values[]=$name; }
  if(isset($cols['code'])){ $fields[]='code'; $values[]='RT'.bin2hex(random_bytes(3)); }
  if(isset($cols['filename'])){ $fields[]='filename'; $values[]='dummy.png'; }
  if(isset($cols['file_ext'])){ $fields[]='file_ext'; $values[]='png'; }
  if(isset($cols['file_hash'])){ $fields[]='file_hash'; $values[]=str_repeat('0',64); }
  if(isset($cols['file_size'])){ $fields[]='file_size'; $values[]=12345; }
  if(isset($cols['width'])){ $fields[]='width'; $values[]=800; }
  if(isset($cols['height'])){ $fields[]='height'; $values[]=400; }
  if(isset($cols['version'])){ $fields[]='version'; $values[]=1; }
  if(isset($cols['created_at'])){ $fields[]='created_at'; $values[]=date('Y-m-d H:i:s'); }
  if(isset($cols['updated_at'])){ $fields[]='updated_at'; $values[]=date('Y-m-d H:i:s'); }
  $ph = implode(',', array_fill(0, count($fields), '?'));
  $fl = implode(',', array_map(fn($f)=>"`$f`", $fields));
  $ins = $pdo->prepare("INSERT INTO templates ($fl) VALUES ($ph)");
  $ins->execute($values);
  return (int)$pdo->lastInsertId();
}

// Create an active template for current org (direct insert)
$name = 'RegTpl '.bin2hex(random_bytes(3));
$tplId = create_template_direct($pdo, (int)$orgId, $name, 'active');
tassert($tplId>0,'template created directly');

// Happy path: register with this template
$cid='T'.bin2hex(random_bytes(6)); $h=bin2hex(random_bytes(32)); $date=date('Y-m-d'); $valid='4000-01-01';
$body=json_encode(['cid'=>$cid,'v'=>3,'h'=>$h,'date'=>$date,'valid_until'=>$valid,'template_id'=>$tplId]);
$resp = call_json_endpoint('api/register.php',$body); $rj=json_decode($resp,true);
tassert(isset($rj['ok']) && $rj['ok']===true,'register ok with template');

// Negative: inactive template (update directly)
$up = $pdo->prepare('UPDATE templates SET status=? WHERE id=?');
$up->execute(['inactive',$tplId]);
$cid2='T'.bin2hex(random_bytes(6)); $h2=bin2hex(random_bytes(32));
$resp2 = call_json_endpoint('api/register.php', json_encode(['cid'=>$cid2,'v'=>3,'h'=>$h2,'date'=>$date,'valid_until'=>$valid,'template_id'=>$tplId]));
$rj2=json_decode($resp2,true); tassert(isset($rj2['error']) && $rj2['error']==='template_inactive','reject inactive template');
// Reactivate
$up->execute(['active',$tplId]);

// Negative: nonexistent template
$cid3='T'.bin2hex(random_bytes(6)); $h3=bin2hex(random_bytes(32));
$resp3 = call_json_endpoint('api/register.php', json_encode(['cid'=>$cid3,'v'=>3,'h'=>$h3,'date'=>$date,'valid_until'=>$valid,'template_id'=>99999999]));
$rj3=json_decode($resp3,true); tassert(isset($rj3['error']) && $rj3['error']==='template_not_found','reject nonexistent template');

// If there is at least one other org, create a template there to test wrong org
$otherOrgId=null; try{ $q=$pdo->query('SELECT id FROM organizations WHERE id<>'.(int)$orgId.' AND is_active=1 LIMIT 1'); $otherOrgId=$q?$q->fetchColumn():null; } catch(Throwable $e){}
if($otherOrgId){
  // Create template directly in other org
  $tplOther = create_template_direct($pdo, (int)$otherOrgId, 'OtherOrgTpl', 'active');
  if($tplOther){
    $cid4='T'.bin2hex(random_bytes(6)); $h4=bin2hex(random_bytes(32));
    $resp4 = call_json_endpoint('api/register.php', json_encode(['cid'=>$cid4,'v'=>3,'h'=>$h4,'date'=>$date,'valid_until'=>$valid,'template_id'=>$tplOther]));
    $rj4=json_decode($resp4,true); tassert(isset($rj4['error']) && $rj4['error']==='template_wrong_org','reject template from other org');
  } else {
    echo "[WARN] Could not create template in other org; skipping org-mismatch test.\n";
  }
}

echo "\n[OK] register_template_id tests completed.\n";
