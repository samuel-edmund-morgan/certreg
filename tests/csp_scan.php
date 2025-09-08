<?php
// Simple CSP & inline scan (run locally against filesystem, optional HTTP curl if base URL env set)
// Usage: php tests/csp_scan.php [--url=https://host]
if(php_sapi_name()!=='cli'){ exit; }
$baseUrl=null;
foreach($argv as $a){ if(str_starts_with($a,'--url=')) $baseUrl=substr($a,6); }

$root=dirname(__DIR__);
$violations=[];
$rii=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach($rii as $f){
  if($f->isDir()) continue; $rel=substr($f->getPathname(),strlen($root)+1);
  if(!preg_match('/\.(php|html)$/',$rel)) continue;
  $c=file_get_contents($f->getPathname());
  if(preg_match('/<script[^>]*>[^<]+<\/script>/i',$c)) $violations[]="inline script: $rel";
  if(preg_match('/<style[^>]*>[^<]+<\/style>/i',$c)) $violations[]="inline style tag: $rel";
  if(preg_match('/style=\"[^\"]+\"/i',$c)) $violations[]="inline style attr: $rel";
}
if($violations){
  echo "[FAIL] Inline elements found:\n - ".implode("\n - ",$violations)."\n"; exit(1);
} else { echo "[OK] No inline <script>, <style> or style= attributes in PHP/HTML files.\n"; }

if($baseUrl){
  $check=['/verify.php'];
  foreach($check as $p){
    $u=rtrim($baseUrl,'/').$p; $ctx=stream_context_create(['http'=>['timeout'=>5]]);
    $h=@get_headers($u,true); if(!$h){ echo "[WARN] Cannot fetch $u\n"; continue; }
    $csp=$h['Content-Security-Policy'] ?? null; if(!$csp){ echo "[FAIL] No CSP header for $p\n"; }
  }
}
?>