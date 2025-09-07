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

// Simple perms check: config.php should not be world-readable
$cfgPath = $root.'/config.php';
clearstatcache(true,$cfgPath);
$perms = substr(sprintf('%o', fileperms($cfgPath)),-3);
if($perms > '640'){
  echo "[WARN] config.php permissions $perms (recommend 640 or 600).\n";
} else {
  echo "[OK] config.php permissions = $perms.\n";
}

echo "Self-check complete.\n";