<?php
// Self-check script: run via CLI `php self_check.php`
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

$root = __DIR__;
$whitelist = [
  // Public / functional entrypoints
  'index.php','admin.php','login.php','logout.php','issue_token.php','tokens.php','token.php','verify.php','qr.php','events.php',
  // API endpoints
  'api/register.php','api/status.php','api/revoke.php','api/unrevoke.php','api/delete_token.php','api/events.php',
  // Support / layout (not directly exposed in nginx whitelist, but present in fs)
  'header.php','footer.php','auth.php','db.php','config.php',
  // Legacy stubs kept for compatibility (410/redirect)
  // Legacy stubs removed
];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$bad = [];
foreach($rii as $file){
  if($file->isDir()) continue;
  $rel = substr($file->getPathname(), strlen($root)+1);
  if(substr($rel, -4) === '.php'){
  if(str_starts_with($rel,'migrations/') || $rel==='self_check.php' || str_starts_with($rel,'lib/') ) continue;
  if(!in_array($rel,$whitelist,true)) $bad[] = $rel;
  }
}
if($bad){
  echo "[FAIL] Unexpected PHP entrypoints found:\n - ".implode("\n - ",$bad)."\n";
  exit(1);
}
echo "[OK] No unexpected PHP entrypoints.\n";
// Classify (info only): public vs admin (for operator awareness)
echo "[INFO] Public endpoints expected: verify.php, api/status.php, qr.php\n";
echo "[INFO] Admin endpoints expected: issue_token.php, tokens.php, token.php, events.php + related api/*.php writes\n";

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
// Attempt metadata check for columns
$cols = $pdo->query("SHOW COLUMNS FROM tokens")->fetchAll(PDO::FETCH_COLUMN);
foreach(['cid','h','version','lookup_count','last_lookup_at'] as $col){
  if(!in_array($col,$cols,true)){ echo "[WARN] Column $col missing in tokens.\n"; }
}
// Warn if public DB user not configured
$cfg = require __DIR__.'/config.php';
if(empty($cfg['db_public_user']) || empty($cfg['db_public_pass'])){
  echo "[WARN] db_public_user / db_public_pass not set – status API runs with full DB user. Consider least privilege.\n";
} else {
  echo "[OK] Public DB user configured (least privilege).\n";
}
echo "Self-check complete.\n";