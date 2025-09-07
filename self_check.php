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
  // Infrastructure helpers
  'rate_limit.php',
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
echo "[INFO] Public endpoints expected: verify.php, api/status.php\n";
echo "[INFO] Admin endpoints expected: issue_token.php, tokens.php, token.php, events.php, qr.php + related api/*.php writes\n";

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