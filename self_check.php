<?php
// Self-check script: run via CLI `php self_check.php`
// Optional flags: `--auto-fix-missing-create`, `--auto-fix-state`, `--suggest-fixes`
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$root = __DIR__;
$whitelist = [
  // Public / functional entrypoints
  'index.php','admin.php','login.php','logout.php','issue_token.php','tokens.php','token.php','operator.php','organization.php','verify.php','qr.php','events.php',
  'template.php',
  // API endpoints
  'api/register.php','api/status.php','api/revoke.php','api/unrevoke.php','api/delete_token.php','api/events.php','api/bulk_action.php','api/branding_save.php',
  'api/account_change_password.php',
  'api/operators_list.php','api/operator_create.php','api/operator_toggle_active.php','api/operator_reset_password.php','api/operator_rename.php','api/operator_delete.php',
  'api/org_create.php','api/org_update.php','api/org_set_active.php','api/org_delete.php',
  'api/org_list.php',
  'api/operator_change_org.php',
  'api/templates_list.php',
  'api/template_create.php',
  'api/template_update.php',
  'api/template_toggle.php',
  'api/template_delete.php',
  // Support / layout (not directly exposed in nginx whitelist, but present in fs)
  'header.php','footer.php','auth.php','db.php','config.php','common_pagination.php',
  'settings.php','settings_section.php',
  // Infrastructure helpers
  'rate_limit.php',
  // Test-only endpoint for CI/browser tests
  'test_download.php',
  // Legacy stubs kept for compatibility (410/redirect)
  // Legacy stubs removed
];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$bad = [];
foreach($rii as $file){
  if($file->isDir()) continue;
  $rel = substr($file->getPathname(), strlen($root)+1);
  if(substr($rel, -4) === '.php'){
  if(str_starts_with($rel,'migrations/') || $rel==='self_check.php' || str_starts_with($rel,'lib/') || str_starts_with($rel,'vendor/') || str_starts_with($rel,'tests/') || str_starts_with($rel,'scripts/')) continue; // ignore bundled libraries, test harness, and CLI/maintenance helpers
  if(!in_array($rel,$whitelist,true)) $bad[] = $rel;
  }
}
if($bad){
  echo "[FAIL] Unexpected PHP entrypoints found:\n - ".implode("\n - ",$bad)."\n";
  exit(1);
}
echo "[OK] No unexpected PHP entrypoints.\n";
// Classify (info only): public vs admin (for operator awareness)
echo "[INFO] Public endpoints expected: verify.php, api/status.php\n";
echo "[INFO] Admin endpoints expected: issue_token.php, tokens.php, token.php, operator.php, events.php, qr.php + related api/*.php writes\n";

// Simple perms check: config.php should not be world-readable
$cfgPath = $root.'/config.php';
clearstatcache(true,$cfgPath);
$perms = substr(sprintf('%o', fileperms($cfgPath)),-3);
if($perms > '640'){
  echo "[WARN] config.php permissions $perms (recommend 640 or 600).\n";
} else {
  echo "[OK] config.php permissions = $perms.\n";
}

// DB privilege & schema sanity checks
require __DIR__.'/db.php';
echo "[INFO] DB connection ok (primary or public).\n";
// Basic expected tables
$expectedTables = ['tokens','token_events'];
foreach($expectedTables as $t){
  try {
    $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
    echo "[OK] Table $t accessible.\n";
  } catch(Throwable $e){
    echo "[FAIL] Table $t not accessible: ".$e->getMessage()."\n"; $fail=true;
  }
}
// Privilege probe for public user detection (attempt harmless EXPLAIN of update)
try {
  $pdo->query("EXPLAIN SELECT h FROM tokens LIMIT 1");
  echo "[OK] SELECT privilege confirmed.\n";
} catch(Throwable $e){ echo "[FAIL] Cannot SELECT from tokens.\n"; $fail=true; }
// Load config early for later checks
$cfg = require __DIR__.'/config.php';
// Attempt metadata check for columns
$cols = $pdo->query("SHOW COLUMNS FROM tokens")->fetchAll(PDO::FETCH_COLUMN);
foreach(['cid','h','version','lookup_count','last_lookup_at'] as $col){
  if(!in_array($col,$cols,true)){ echo "[WARN] Column $col missing in tokens.\n"; }
}
// M1 expiry column check
if(!in_array('valid_until',$cols,true)){
  echo "[WARN] Column valid_until missing (M1 expiry not applied).\n";
} else {
  $cfgLocal = $cfg; $sentinel = $cfgLocal['infinite_sentinel'] ?? '4000-01-01';
  try {
    $cInf = $pdo->query("SELECT COUNT(*) FROM tokens WHERE version=2 AND valid_until IS NULL")->fetchColumn();
    if($cInf>0) echo "[WARN] Found $cInf v2 tokens with NULL valid_until.\n"; else echo "[OK] v2 tokens have valid_until set (or none exist).\n";
    $cSent = $pdo->query("SELECT COUNT(*) FROM tokens WHERE valid_until=".$pdo->quote($sentinel))->fetchColumn();
    echo "[INFO] Tokens with sentinel ($sentinel): $cSent\n";
  } catch(Throwable $e){ echo "[WARN] Could not run expiry checks: ".$e->getMessage()."\n"; }
}
// Warn if public DB user not configured
if(empty($cfg['db_public_user']) || empty($cfg['db_public_pass'])){
  echo "[WARN] db_public_user / db_public_pass not set – status API runs with full DB user. Consider least privilege.\n";
} else {
  echo "[OK] Public DB user configured (least privilege).\n";
}
echo "Self-check complete.\n";
// Additional multi-org/template checks
// Verify templates_list.php reachable (filesystem presence already implied by whitelist)
if(!is_file(__DIR__.'/api/templates_list.php')) {
  echo "[WARN] templates_list.php missing unexpectedly.\n";
} else {
  echo "[OK] templates_list.php present.\n";
}
// Ensure no inline <script> block remains in issue_token.php (CSP requirement)
$issuePath = __DIR__.'/issue_token.php';
if(is_file($issuePath)){
  $issueContent = file_get_contents($issuePath);
  if(preg_match('#<script(?![^>]*\bsrc=)[^>]*>#i',$issueContent)){
    echo "[WARN] Inline <script> tag detected in issue_token.php (should be external).\n";
  } else {
    echo "[OK] issue_token.php contains no inline <script> tags.\n";
  }
} else {
  echo "[WARN] issue_token.php not found for CSP inline check.\n";
}
// === H2 Extension: filesystem & privilege audit ===
echo "[SECTION] Filesystem audit (H2)\n";
$base = realpath(__DIR__);
$critDirs = [
  $base => 'root',
  $base.'/assets' => 'assets',
  $base.'/files' => 'files',
  $base.'/fonts' => 'fonts',
  $base.'/lib' => 'lib'
];
foreach($critDirs as $path=>$label){
  if(!is_dir($path)) { echo "[WARN] Missing directory: $label ($path)\n"; continue; }
  $st = @stat($path);
  if(!$st){ echo "[WARN] Cannot stat $label ($path)\n"; continue; }
  $perm = substr(sprintf('%o', $st['mode']), -3);
  $owner = function_exists('posix_getpwuid') ? (posix_getpwuid($st['uid'])['name'] ?? $st['uid']) : $st['uid'];
  $group = function_exists('posix_getgrgid') ? (posix_getgrgid($st['gid'])['name'] ?? $st['gid']) : $st['gid'];
  $issues = [];
  if((int)$perm > 775) $issues[] = 'too-open';
  if($perm[2] >= '6') $issues[] = 'world-writable';
  if($label==='files' && $perm[2] === '0'){ /* 770/750 fine */ } 
  $tag = empty($issues)?'OK':'WARN '.implode(',',$issues);
  echo "[DIR] $label perms=$perm owner=$owner:$group $tag\n";
}

// Suspicious / extraneous artifacts
$suspPatterns = '/\.(bak|old|tmp|swp|swo|sql|dump|tar|tgz|gz|zip|7z|log)$/i';
$susp = [];$unexpectedPhpStatic=[];$worldWritable=[];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
foreach($rii as $f){
  $rel = substr($f->getPathname(), strlen($base)+1);
  if($rel==='self_check.php') continue;
  if($f->isFile()){
    $name = $f->getFilename();
    if(preg_match($suspPatterns,$name)) $susp[] = $rel;
    $perm = substr(sprintf('%o', $f->getPerms()), -3);
    if($perm[2] >= '6') $worldWritable[] = $rel.'('.$perm.')';
    // PHP inside static dirs
    if(preg_match('#^(assets|files|fonts)/#',$rel) && str_ends_with($rel,'.php')) $unexpectedPhpStatic[] = $rel;
  }
}
if($susp){ echo "[WARN] Suspicious/backup artifacts:\n - ".implode("\n - ",$susp)."\n"; }
else echo "[OK] No obvious backup/temp artifacts found.\n";
if($unexpectedPhpStatic){ echo "[FAIL] PHP files inside static dirs:\n - ".implode("\n - ",$unexpectedPhpStatic)."\n"; }
else echo "[OK] No PHP in assets/files/fonts.\n";
if($worldWritable){ echo "[WARN] World-writable files detected:\n - ".implode("\n - ",$worldWritable)."\n"; }
else echo "[OK] No world-writable files.\n";

echo "[H2] Filesystem audit done.\n";
// === Password hashing capability (H8 prep) ===
if(defined('PASSWORD_ARGON2ID')){
  echo "[OK] Argon2id supported by PHP build.\n";
} else {
  echo "[WARN] Argon2id not supported; fallback to PASSWORD_DEFAULT (bcrypt).\n";
}
// Quick probe: generate dummy hash (not stored)
try {
  if(defined('PASSWORD_ARGON2ID')){
    password_hash('test', PASSWORD_ARGON2ID, ['memory_cost'=>1<<15,'time_cost'=>2,'threads'=>1]);
    echo "[OK] Argon2id functional (test hash).\n";
  }
} catch(Throwable $e){ echo "[FAIL] Argon2id hashing failed: ".$e->getMessage()."\n"; }
// === Rate limiter directory check (H9) ===
$rlDir = sys_get_temp_dir().'/certreg_rl';
if(is_dir($rlDir)){
  $st = @stat($rlDir); $perm = $st?substr(sprintf('%o',$st['mode']),-3):'???';
  if($perm > '750') echo "[WARN] rate-limit dir perms=$perm (consider 700).\n"; else echo "[OK] rate-limit dir present (perms $perm).\n";
} else {
  echo "[INFO] rate-limit dir not yet created (will appear after first limited action).\n";
}

// === Audit trail integrity (H10) ===
echo "[SECTION] Audit integrity (H10)\n";
echo "[INFO] H10 validates token_events consistency: each token should start with create; revocation/unrevocation must alternate; DB revoked_at must match last revoke/unrevoke state; orphan events flagged.\n";
try {
  $tokensRows = $pdo->query("SELECT cid, revoked_at FROM tokens")->fetchAll(PDO::FETCH_ASSOC);
  $tokenMap = [];
  foreach($tokensRows as $r){ $tokenMap[$r['cid']] = $r; }
  // Fetch events in true chronological order when created_at column exists; fall back to id order if not.
  try {
    $hasCreatedAt = false;
    try {
      $colsEv = $pdo->query("SHOW COLUMNS FROM token_events")->fetchAll(PDO::FETCH_COLUMN);
      $hasCreatedAt = in_array('created_at',$colsEv,true);
    } catch(Throwable $e){ /* ignore */ }
    if($hasCreatedAt){
      $eventsStmt = $pdo->query("SELECT id,cid,event_type,created_at FROM token_events ORDER BY created_at IS NULL, created_at ASC, id ASC");
    } else {
      $eventsStmt = $pdo->query("SELECT id,cid,event_type,NULL AS created_at FROM token_events ORDER BY id ASC");
    }
  } catch(Throwable $e){ throw $e; }
  $eventsByCid = [];
  $createCount=0; $revokeCount=0; $unrevokeCount=0; $deleteCount=0; $lookupCount=0; $otherCount=0;
  while($e = $eventsStmt->fetch(PDO::FETCH_ASSOC)){
    $cid = $e['cid'];
    if(!isset($eventsByCid[$cid])) $eventsByCid[$cid]=[];
    $eventsByCid[$cid][] = $e;
    switch($e['event_type']){
      case 'create': $createCount++; break;
      case 'revoke': $revokeCount++; break;
      case 'unrevoke': $unrevokeCount++; break;
      case 'delete': $deleteCount++; break;
      case 'lookup': $lookupCount++; break;
      default: $otherCount++; break;
    }
  }
  $an_missingCreate=[]; $an_multiCreate=[]; $an_badOrder=[]; $an_doubleRevoke=[]; $an_doubleUnrevoke=[]; $an_stateMismatch=[]; $an_unrevokeWithoutRevoke=[]; $an_unknownCidNoDelete=[]; $an_firstNotCreate=[];

  // Build set of cids that ever had delete
  $deletedCids = [];
  foreach($eventsByCid as $cid=>$elist){
    foreach($elist as $ev){ if($ev['event_type']==='delete') { $deletedCids[$cid]=true; break; } }
  }

  // Detect unknown cids (only in events)
  foreach($eventsByCid as $cid=>$elist){
    if(!isset($tokenMap[$cid]) && empty($deletedCids[$cid])){ $an_unknownCidNoDelete[] = $cid; }
  }

  foreach($tokenMap as $cid=>$trow){
    $elist = $eventsByCid[$cid] ?? [];
    // Must have at least one create
    $createEvents = array_values(array_filter($elist, fn($x)=>$x['event_type']==='create'));
    if(!$createEvents){ $an_missingCreate[] = $cid; }
    if(count($createEvents) > 1){ $an_multiCreate[] = $cid; }
    if($elist){
      $first = $elist[0]['event_type'];
      if($first !== 'create'){
        // Defensive re-check: if chronological ordering (by created_at) differs from id ordering and earliest chronological is create, suppress warning.
        // $elist is already chronologically ordered when created_at exists (see query above). Still, if created_at missing we retain original behavior.
        $an_firstNotCreate[] = $cid; // tentatively flag; we'll prune below if conditions allow.
      }
    }
    // Revocation sequence checks
    $revSeq = array_values(array_filter($elist, fn($x)=>$x['event_type']==='revoke' || $x['event_type']==='unrevoke'));
    $seenRevoke=false; $lastType=null; $currentExpected=false;
    foreach($revSeq as $ev){
      if($ev['event_type']==='revoke'){
        if($lastType==='revoke') $an_doubleRevoke[] = $cid;
        $seenRevoke=true; $currentExpected=true; $lastType='revoke';
      } elseif($ev['event_type']==='unrevoke') {
        if(!$seenRevoke) $an_unrevokeWithoutRevoke[] = $cid; // unrevoke before any revoke
        if($lastType==='unrevoke') $an_doubleUnrevoke[] = $cid;
        $currentExpected=false; $lastType='unrevoke';
      }
    }
    $dbRev = !empty($trow['revoked_at']);
    if($dbRev !== $currentExpected){ $an_stateMismatch[] = $cid; }
  }

  // De-duplicate anomaly lists
  $dedupe = function(array $a){ return array_values(array_unique($a)); };
  $an_missingCreate = $dedupe($an_missingCreate);
  $an_multiCreate = $dedupe($an_multiCreate);
  $an_badOrder = $dedupe($an_badOrder);
  $an_doubleRevoke = $dedupe($an_doubleRevoke);
  $an_doubleUnrevoke = $dedupe($an_doubleUnrevoke);
  $an_unrevokeWithoutRevoke = $dedupe($an_unrevokeWithoutRevoke);
  $an_stateMismatch = $dedupe($an_stateMismatch);
  $an_unknownCidNoDelete = $dedupe($an_unknownCidNoDelete);
  $an_firstNotCreate = $dedupe($an_firstNotCreate);
  // If we have created_at column and the earliest chronological event is create, remove from anomaly list (handles retroactive synthetic create events with higher id).
  if(!empty($hasCreatedAt) && $an_firstNotCreate){
    $retain = [];
    foreach($an_firstNotCreate as $cid){
      $elist = $eventsByCid[$cid] ?? [];
      if(!$elist) continue; // safety
      $earliest = $elist[0]; // already chronological
      if(($earliest['event_type'] ?? null) !== 'create') $retain[] = $cid; // keep only true anomalies
    }
    $an_firstNotCreate = $retain;
  }

  echo "[INFO] Events summary: create=$createCount revoke=$revokeCount unrevoke=$unrevokeCount delete=$deleteCount lookup=$lookupCount other=$otherCount\n";
  $anyH10Fail = false;
  $report = function($label,$arr,$severity) use (&$anyH10Fail){
    if(!$arr) { echo "[OK] $label\n"; return; }
    $prefix = $severity==='fail' ? '[FAIL]' : '[WARN]';
    if($severity==='fail') $anyH10Fail = true;
    $sample = implode(', ', array_slice($arr,0,10));
    $extra = count($arr)>10 ? ' (+' . (count($arr)-10) . ' more)' : '';
    echo "$prefix $label: $sample$extra\n";
  };
  $report('Tokens missing create event',$an_missingCreate,'fail');
  $report('Tokens with multiple create events',$an_multiCreate,'fail');
  $report('First event not create',$an_firstNotCreate,'warn');
  $report('Consecutive revoke events',$an_doubleRevoke,'warn');
  $report('Consecutive unrevoke events',$an_doubleUnrevoke,'warn');
  $report('Unrevoke without prior revoke',$an_unrevokeWithoutRevoke,'warn');
  $report('Revocation state mismatch (DB vs event sequence)',$an_stateMismatch,'fail');
  $report('Events referencing unknown cid without delete',$an_unknownCidNoDelete,'fail');
  if($anyH10Fail){ echo "[H10] One or more FAIL conditions detected.\n"; } else { echo "[H10] Audit integrity checks passed (no FAIL).\n"; }

  // Optional automatic remediation
  if(in_array('--auto-fix-missing-create',$argv,true) && $an_missingCreate){
    $ins = $pdo->prepare("INSERT INTO token_events (cid,event_type) VALUES (?, 'create')");
    $fixed=0; foreach($an_missingCreate as $cid){ try { $ins->execute([$cid]); $fixed++; } catch(Throwable $e){ echo "[WARN] Failed insert synthetic create for $cid: ".$e->getMessage()."\n"; } }
    echo "[FIX] Inserted $fixed synthetic create events. Re-run self_check.php to verify.\n";
  }
  if(in_array('--auto-fix-state',$argv,true) && $an_stateMismatch){
    foreach($an_stateMismatch as $cid){
      $elist = $eventsByCid[$cid] ?? [];
      $revState=false; foreach($elist as $ev){ if($ev['event_type']==='revoke') $revState=true; elseif($ev['event_type']==='unrevoke') $revState=false; }
      try {
        if($revState){
          $pdo->prepare("UPDATE tokens SET revoked_at=IFNULL(revoked_at,NOW()), revoke_reason=COALESCE(revoke_reason,'(restored)') WHERE cid=? LIMIT 1")->execute([$cid]);
        } else {
          $pdo->prepare("UPDATE tokens SET revoked_at=NULL, revoke_reason=NULL WHERE cid=? LIMIT 1")->execute([$cid]);
        }
      } catch(Throwable $e){ echo "[WARN] Failed state reconcile for $cid: ".$e->getMessage()."\n"; }
    }
    echo "[FIX] Applied revocation state reconciliation. Re-run self_check.php to verify.\n";
  }

  // Optional remediation suggestions
  if(in_array('--suggest-fixes', $argv, true)){
    echo "[H10] --- Suggested SQL remediation (review before executing) ---\n";
    if($an_missingCreate){
      echo "-- Insert missing create events (uses NOW(); adjust if you have original issue times)\n";
      foreach($an_missingCreate as $cid){
        echo "INSERT INTO token_events (cid,event_type) VALUES ('".addslashes($cid)."','create');\n";
      }
    }
    if($an_stateMismatch){
      echo "-- Fix revocation state mismatches (derive state from last revoke/unrevoke event)\n";
      foreach($an_stateMismatch as $cid){
        $elist = $eventsByCid[$cid] ?? [];
        $revState=false; foreach($elist as $ev){ if($ev['event_type']==='revoke') $revState=true; elseif($ev['event_type']==='unrevoke') $revState=false; }
        if($revState){
          echo "-- Token $cid is revoked per events but DB shows not revoked\n";
          echo "UPDATE tokens SET revoked_at=IFNULL(revoked_at,NOW()), revoke_reason=COALESCE(revoke_reason,'(restored)') WHERE cid='".addslashes($cid)."' LIMIT 1;\n";
        } else {
          echo "-- Token $cid is NOT revoked per events but DB shows revoked\n";
          echo "UPDATE tokens SET revoked_at=NULL, revoke_reason=NULL WHERE cid='".addslashes($cid)."' LIMIT 1;\n";
        }
      }
    }
    if($an_unrevokeWithoutRevoke){
      echo "-- Unrevoke without prior revoke (decide: either add synthetic revoke or delete orphan unrevoke). Example adds synthetic revoke BEFORE first unrevoke: \n";
      foreach($an_unrevokeWithoutRevoke as $cid){
        echo "-- Example (choose timestamp): INSERT INTO token_events (cid,event_type) VALUES ('".addslashes($cid)."','revoke');\n";
      }
    }
    if($an_firstNotCreate){
      echo "-- First event not create: consider inserting synthetic create at sequence start.\n";
    }
    if(!$an_missingCreate && !$an_stateMismatch && !$an_unrevokeWithoutRevoke && !$an_firstNotCreate){
      echo "-- No automatic fix suggestions necessary.\n";
    }
  }
} catch(Throwable $e){
  echo "[WARN] H10 audit section error: ".$e->getMessage()."\n";
}

// === Branding checks (B1) ===
echo "[SECTION] Branding checks (B1)\n";
try {
  // Ensure branding_settings table exists
  try { $pdo->query("SELECT 1 FROM branding_settings LIMIT 1"); echo "[OK] branding_settings table present.\n"; }
  catch(Throwable $e){ echo "[WARN] branding_settings table missing or inaccessible: ".$e->getMessage()."\n"; }
  // Load overrides
  $branding = [];
  try {
    $st = $pdo->query("SELECT setting_key, setting_value FROM branding_settings");
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $branding[$r['setting_key']] = $r['setting_value']; }
  } catch(Throwable $e){ /* ignore */ }
  $logoPath = $branding['logo_path'] ?? ($cfg['logo_path'] ?? null);
  $favPath  = $branding['favicon_path'] ?? ($cfg['favicon_path'] ?? '/assets/favicon.ico');
  $checkFile = function($rel, $label){
    if(!$rel){ echo "[WARN] $label path empty.\n"; return; }
    $fs = __DIR__.$rel;
    if(!file_exists($fs)) echo "[WARN] $label file missing ($rel).\n"; else echo "[OK] $label file exists ($rel).\n";
  };
  $checkFile($logoPath,'Logo');
  $checkFile($favPath,'Favicon');
  if(isset($branding['primary_color'])){
    if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$branding['primary_color'])) echo "[WARN] primary_color invalid format.\n"; else echo "[OK] primary_color format valid.\n";
  }
  if(isset($branding['accent_color'])){
    if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$branding['accent_color'])) echo "[WARN] accent_color invalid format.\n"; else echo "[OK] accent_color format valid.\n";
  }
  if(isset($branding['secondary_color'])){
    if(!preg_match('/^#[0-9A-Fa-f]{6}$/',$branding['secondary_color'])) echo "[WARN] secondary_color invalid format.\n"; else echo "[OK] secondary_color format valid.\n";
  }
  // branding_colors.css validation
  $colorsSet = !empty($branding['primary_color']) || !empty($branding['accent_color']) || !empty($branding['secondary_color']);
  $colorsCss = __DIR__.'/files/branding/branding_colors.css';
  if($colorsSet){
    if(is_file($colorsCss)){
      echo "[OK] branding_colors.css present.\n";
      $cssContent = @file_get_contents($colorsCss);
      if($cssContent===false) echo "[WARN] Cannot read branding_colors.css.\n"; else {
  if(!empty($branding['primary_color']) && !str_contains($cssContent,$branding['primary_color'])) echo "[WARN] primary_color not found in branding_colors.css.\n";
  if(!empty($branding['accent_color']) && !str_contains($cssContent,$branding['accent_color'])) echo "[WARN] accent_color not found in branding_colors.css.\n";
  if(!empty($branding['secondary_color']) && !str_contains($cssContent,$branding['secondary_color'])) echo "[WARN] secondary_color not found in branding_colors.css.\n";
      }
    } else {
      echo "[FAIL] branding_colors.css missing while colors are set.\n";
    }
  } else {
    if(is_file($colorsCss)) echo "[WARN] branding_colors.css exists but no colors set (stale).\n"; else echo "[OK] No colors set; no branding_colors.css (expected).\n";
  }
} catch(Throwable $e){ echo "[WARN] Branding checks error: ".$e->getMessage()."\n"; }

// === DB index checks (I1) ===
echo "[SECTION] DB index checks (I1)\n";
try {
  // Helper: fetch index definitions for a table as [key_name => [columns...]]
  $getIndexes = function(string $table) use ($pdo): array {
    $idx = [];
    try {
      $st = $pdo->query("SHOW INDEX FROM `".$table."`");
    } catch (Throwable $e) { return $idx; }
    while($r = $st->fetch(PDO::FETCH_ASSOC)){
      $k = $r['Key_name'];
      $seq = (int)$r['Seq_in_index'];
      $col = $r['Column_name'];
      if(!isset($idx[$k])) $idx[$k] = [];
      $idx[$k][$seq] = $col;
    }
    // Normalize sequence ordering
    foreach($idx as $k=>$cols){ ksort($cols); $idx[$k] = array_values($cols); }
    return $idx;
  };

  // Requirement spec: [table => list of requirements], where each requirement is
  // ['columns' => string|array, 'label' => string, 'mandatory' => bool, 'suggest' => string]
  $reqs = [
    'tokens' => [
      ['columns'=>'template_id', 'label'=>'Index on tokens(template_id)', 'mandatory'=>true,  'suggest'=>'CREATE INDEX idx_tokens_template_id ON tokens(template_id);'],
      ['columns'=>'created_at',  'label'=>'Index on tokens(created_at)',  'mandatory'=>true,  'suggest'=>'CREATE INDEX idx_tokens_created_at ON tokens(created_at);'],
      ['columns'=>'revoked_at',  'label'=>'Index on tokens(revoked_at)',  'mandatory'=>true,  'suggest'=>'CREATE INDEX idx_tokens_revoked_at ON tokens(revoked_at);'],
      ['columns'=>'h',           'label'=>'Index on tokens(h) (fast verify)', 'mandatory'=>false, 'suggest'=>'CREATE INDEX idx_tokens_h ON tokens(h);'],
      ['columns'=>'cid',         'label'=>'Index on tokens(cid) (lookup)',    'mandatory'=>false, 'suggest'=>'CREATE INDEX idx_tokens_cid ON tokens(cid);'],
    ],
    'token_events' => [
      ['columns'=>['cid','created_at'], 'label'=>'Composite index token_events(cid, created_at)', 'mandatory'=>false, 'suggest'=>'CREATE INDEX idx_token_events_cid_created_at ON token_events(cid, created_at);'],
      ['columns'=>'cid',                 'label'=>'Index on token_events(cid)',                    'mandatory'=>true,  'suggest'=>'CREATE INDEX idx_token_events_cid ON token_events(cid);'],
      ['columns'=>'created_at',          'label'=>'Index on token_events(created_at)',             'mandatory'=>false, 'suggest'=>'CREATE INDEX idx_token_events_created_at ON token_events(created_at);'],
    ],
  ];

  $suggestions = [];
  foreach($reqs as $table=>$list){
    // Skip table if not present
    try {
      $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
    } catch(Throwable $e){ echo "[INFO] Skip index scan for $table (table not accessible).\n"; continue; }
    $idx = $getIndexes($table);
    // Build convenience list of index first-column prefixes
    $prefixes = [];
    foreach($idx as $k=>$cols){ if($cols){ $prefixes[] = $cols; } }

    foreach($list as $r){
      $colsReq = is_array($r['columns']) ? $r['columns'] : [$r['columns']];
      $found = false;
      foreach($prefixes as $cols){
        // prefix match: index starts with required column sequence
        $match = true;
        foreach($colsReq as $i=>$c){ if(($cols[$i] ?? null) !== $c){ $match = false; break; } }
        if($match){ $found = true; break; }
      }
      if($found){
        echo "[OK] {$r['label']}.\n";
      } else {
        $sev = !empty($r['mandatory']) ? '[WARN]' : '[INFO]';
        echo "$sev Missing {$r['label']}.\n";
        if(!empty($r['suggest'])) $suggestions[] = $r['suggest'];
      }
    }
  }
  if($suggestions){
    echo "[I1] Suggested SQL to add missing indexes (review before running):\n";
    foreach(array_unique($suggestions) as $sql){ echo $sql."\n"; }
  } else {
    echo "[I1] All required indexes present.\n";
  }
} catch(Throwable $e){ echo "[WARN] Index checks error: ".$e->getMessage()."\n"; }

// === Templates checks (T1) ===
echo "[SECTION] Templates checks (T1)\n";
try {
  $hasTemplates = false; $tplCols = [];
  try {
    $colsStmt = $pdo->query("SHOW COLUMNS FROM templates");
    while($r = $colsStmt->fetch(PDO::FETCH_ASSOC)){ $tplCols[] = $r['Field']; }
    if($tplCols) $hasTemplates = true;
  } catch(Throwable $e){ /* table absent */ }
  if(!$hasTemplates){
    echo "[INFO] templates table absent (feature not initialized).\n";
  } else {
    echo "[OK] templates table present.\n";
    $required = ['id','org_id','name','code','status','filename','file_ext','file_hash','file_size','width','height','version','created_at','updated_at'];
    foreach($required as $c){ if(!in_array($c,$tplCols,true)) echo "[WARN] templates missing column $c.\n"; }
    // Status domain check
    if(in_array('status',$tplCols,true)){
      try {
  $badStatus = $pdo->query("SELECT COUNT(*) FROM templates WHERE status NOT IN ('active','inactive','archived')")->fetchColumn();
        if($badStatus>0) echo "[WARN] $badStatus templates have unknown status values.\n"; else echo "[OK] All template status values within expected domain.\n"; 
      } catch(Throwable $e){ echo "[WARN] Could not validate template status domain: ".$e->getMessage()."\n"; }
    }
    // Uniqueness (org_id,code) soft validation (DB-level unique may not exist yet)
    try {
      $dupes = $pdo->query("SELECT org_id,code,COUNT(*) c FROM templates WHERE code IS NOT NULL GROUP BY org_id,code HAVING c>1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
      if($dupes){
        echo "[FAIL] Duplicate (org_id,code) pairs detected:\n";
        foreach($dupes as $d){ echo " - org_id={$d['org_id']} code={$d['code']} count={$d['c']}\n"; }
      } else {
        echo "[OK] No duplicate (org_id,code) pairs.\n";
      }
    } catch(Throwable $e){ echo "[WARN] Could not scan duplicates: ".$e->getMessage()."\n"; }
    // Filesystem presence sample: list up to 5 newest templates and verify file exists
    try {
      $st = $pdo->query("SELECT id,org_id,file_ext FROM templates ORDER BY id DESC LIMIT 5");
      $checked=0; $missing=0; $base = __DIR__.'/files/templates';
      while($t = $st->fetch(PDO::FETCH_ASSOC)){
        $checked++; $p = $base."/{$t['org_id']}/{$t['id']}/original.".$t['file_ext'];
        $preview = $base."/{$t['org_id']}/{$t['id']}/preview.jpg";
        if(is_file($p)){
          echo "[OK] Template file present id={$t['id']} org={$t['org_id']}\n";
          if(is_file($preview)){
            echo "[OK] Preview present id={$t['id']}\n";
          } else {
            echo "[WARN] Preview missing id={$t['id']} (expected $preview)\n";
          }
        } else {
          echo "[WARN] Template file missing id={$t['id']} expected=$p\n"; $missing++; }
      }
      if($checked===0) echo "[INFO] No templates to verify yet.\n"; else if($missing===0) echo "[OK] All sampled template files present ($checked checked).\n"; 
    } catch(Throwable $e){ echo "[WARN] Could not enumerate templates for fs check: ".$e->getMessage()."\n"; }
    // Version sanity
    try {
      $badVer = $pdo->query("SELECT COUNT(*) FROM templates WHERE version < 1 OR version IS NULL")->fetchColumn();
      if($badVer>0) echo "[FAIL] $badVer templates have invalid version (<1 or NULL).\n"; else echo "[OK] Template versions valid (>=1).\n";
    } catch(Throwable $e){ echo "[WARN] Could not validate template versions: ".$e->getMessage()."\n"; }

    // Orphan directory audit (T1-orphans): scan files/templates/<org>/<id>
    try {
      $tplBase = realpath(__DIR__.'/files/templates');
      if($tplBase && is_dir($tplBase)){
        // Build set of existing template (org_id,id) pairs
        $dbPairs = [];
        $stAll = $pdo->query("SELECT id, org_id FROM templates");
        while($r=$stAll->fetch(PDO::FETCH_ASSOC)){
          $dbPairs[$r['org_id'].':'.$r['id']] = true;
        }
        $orphanDirs = [];
        $missingDirs = 0;
        // For each org directory
        $orgDirs = glob($tplBase.'/*', GLOB_ONLYDIR);
        if($orgDirs){
          foreach($orgDirs as $orgDir){
            $orgName = basename($orgDir);
            if(!ctype_digit($orgName)) continue; // skip unexpected
            $orgId = (int)$orgName;
            $tplDirs = glob($orgDir.'/*', GLOB_ONLYDIR);
            if($tplDirs){
              foreach($tplDirs as $tplDir){
                $tplIdName = basename($tplDir);
                if(!ctype_digit($tplIdName)) continue;
                $tplId = (int)$tplIdName;
                $key = $orgId.':'.$tplId;
                if(empty($dbPairs[$key])){
                  $orphanDirs[] = $orgId.'/'.$tplId;
                }
              }
            }
          }
        }
        // Detect DB rows whose directory missing (sample up to 10 for noise control)
        $missingSamples = [];
        foreach(array_keys($dbPairs) as $pair){
          [$o,$i] = array_map('intval', explode(':',$pair));
            $expectDir = $tplBase.'/'.$o.'/'.$i;
            if(!is_dir($expectDir)){
              $missingDirs++;
              if(count($missingSamples)<10) $missingSamples[] = $o.'/'.$i;
            }
        }
        if($orphanDirs){
          $sample = implode(', ', array_slice($orphanDirs,0,10));
          $more = count($orphanDirs)>10 ? ' (+'.(count($orphanDirs)-10).' more)' : '';
          echo "[WARN] Orphan template directories (no DB row): $sample$more\n";
        } else {
          echo "[OK] No orphan template directories detected.\n";
        }
        if($missingDirs>0){
          $more = $missingDirs>10 ? ' (+'.($missingDirs-10).' more)' : '';
          echo "[WARN] Template DB rows missing directory: ".implode(', ',$missingSamples)."$more\n";
        } else {
          echo "[OK] All template DB rows have directory present.\n";
        }
      } else {
        echo "[INFO] Template base directory not found (files/templates) – skip orphan scan.\n";
      }
    } catch(Throwable $e){ echo "[WARN] Could not perform orphan directory audit: ".$e->getMessage()."\n"; }
  }
} catch(Throwable $e){ echo "[WARN] Templates checks error: ".$e->getMessage()."\n"; }
