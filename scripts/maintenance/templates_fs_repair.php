<?php
// Usage: php scripts/maintenance/templates_fs_repair.php [--dry-run] [--use-placeholder]
// - Cleans orphan template directories under files/templates/<org>/<tpl>
// - Reports DB rows missing directories; creates directories if missing
// - Checks presence of original.ext and preview.jpg; regenerates preview.jpg if original exists (png/jpg)

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
$dry = in_array('--dry-run', $argv, true);
$usePlaceholder = in_array('--use-placeholder', $argv, true);
$base = realpath(__DIR__.'/../../');
require $base.'/db.php';

$tplBase = $base.'/files/templates';
$placeholder = $base.'/files/cert_template.jpg';
if($usePlaceholder && !is_file($placeholder)){
  echo "[WARN] --use-placeholder supplied but placeholder not found at $placeholder\n";
  $usePlaceholder = false;
}
if (!is_dir($tplBase)) { echo "[INFO] No templates dir, nothing to do.\n"; exit(0); }

// Fetch all templates from DB
$templates = [];
$exts = [];
$st = $pdo->query("SELECT id, org_id, file_ext FROM templates");
while($r = $st->fetch(PDO::FETCH_ASSOC)){
  $key = $r['org_id'].':'.$r['id'];
  $templates[$key] = $r;
  $exts[$key] = $r['file_ext'];
}

$orphanDirs = [];
$missingDirs = [];
$missingFiles = [];
$regenPreviews = 0; $failedPreview = 0; $createdOriginals = 0; $failedOriginals = 0;
$fixedDirs = 0; $removedDirs = 0;

// Scan FS for orphans
$orgDirs = glob($tplBase.'/*', GLOB_ONLYDIR) ?: [];
foreach($orgDirs as $orgDir){
  $orgId = basename($orgDir);
  if(!ctype_digit($orgId)) continue;
  $tplDirs = glob($orgDir.'/*', GLOB_ONLYDIR) ?: [];
  foreach($tplDirs as $tplDir){
    $tplId = basename($tplDir);
    if(!ctype_digit($tplId)) continue;
    $key = ((int)$orgId).':'.((int)$tplId);
    if(empty($templates[$key])){
      $orphanDirs[] = $tplDir;
    }
  }
}

// Report and optionally remove orphan dirs
if($orphanDirs){
  echo "[INFO] Orphan template directories: ".count($orphanDirs)."\n";
  foreach($orphanDirs as $dir){
    echo " - $dir\n";
    if(!$dry){
      // Safe remove: only if contains only expected files
      $entries = glob($dir.'/*') ?: [];
      $canRemove = true;
      foreach($entries as $p){
        if(is_dir($p)) { $canRemove = false; break; }
        $bn = basename($p);
        if(!preg_match('/^(original\.[A-Za-z0-9]+|preview\.jpg)$/', $bn)) { $canRemove = false; break; }
      }
      if($canRemove){
        foreach($entries as $p){ @unlink($p); }
        @rmdir($dir);
        $removedDirs++;
      } else {
        echo "   [SKIP] Contains unexpected files, not removed.\n";
      }
    }
  }
}

// Ensure dirs for DB rows; check files presence
foreach($templates as $key=>$row){
  $orgId = (int)$row['org_id']; $tplId = (int)$row['id']; $ext = $row['file_ext'] ?? 'png';
  $dir = $tplBase."/$orgId/$tplId";
  if(!is_dir($dir)){
    $missingDirs[] = "$orgId/$tplId";
    if(!$dry){
      if(!is_dir($tplBase."/$orgId")) @mkdir($tplBase."/$orgId", 0755, true);
      if(@mkdir($dir, 0755, true)) $fixedDirs++;
    }
  }
  $orig = $dir.'/original.'.$ext;
  $prev = $dir.'/preview.jpg';
  $hasOrig = is_file($orig);
  if(!$hasOrig && $usePlaceholder && !$dry){
    // Attempt to create original from placeholder
    $extLower = strtolower($ext);
    try {
      $src = @imagecreatefromjpeg($placeholder);
      if($src){
        if($extLower === 'png'){
          $ok = imagepng($src, $orig, 6);
        } elseif($extLower === 'jpg' || $extLower === 'jpeg'){
          $ok = imagejpeg($src, $orig, 88);
        } else {
          // default to png
          $ok = imagepng($src, $orig, 6);
        }
        imagedestroy($src);
        if($ok){ $hasOrig = true; $createdOriginals++; }
        else { $failedOriginals++; }
      } else { $failedOriginals++; }
    } catch (Throwable $e) { $failedOriginals++; }
  }
  if(!$hasOrig) $missingFiles[] = $orig;
  if(!is_file($prev)){
    // try regenerate preview if original exists and not dry
    if($hasOrig && !$dry){
      $ok = false;
      $extLower = strtolower($ext);
      try {
        if($extLower === 'jpg' || $extLower === 'jpeg'){
          $src = @imagecreatefromjpeg($orig);
        } elseif($extLower === 'png'){
          $src = @imagecreatefrompng($orig);
        } else {
          $src = false; // unsupported
        }
        if($src){
          $w = imagesx($src); $h = imagesy($src);
          $targetW = 800; // cap width
          $scale = $w > 0 ? min(1.0, $targetW / $w) : 1.0;
          $nw = max(1, (int)round($w * $scale));
          $nh = max(1, (int)round($h * $scale));
          $dst = imagecreatetruecolor($nw, $nh);
          imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
          $ok = imagejpeg($dst, $prev, 82);
          imagedestroy($dst); imagedestroy($src);
        }
      } catch (Throwable $e) { $ok = false; }
      if($ok){ $regenPreviews++; } else { $failedPreview++; $missingFiles[] = $prev; }
    } else {
      $missingFiles[] = $prev;
    }
  }
}

// Summary
if($orphanDirs) echo "[DONE] Removed orphan dirs: $removedDirs (dry=$dry)\n";
if($missingDirs){ echo "[WARN] DB rows missing dirs: ".count($missingDirs)." (created: $fixedDirs)\n"; }
if($createdOriginals>0) echo "[FIX] Created originals from placeholder: $createdOriginals\n";
if($failedOriginals>0) echo "[WARN] Failed to create originals from placeholder: $failedOriginals\n";
if($regenPreviews>0) echo "[FIX] Regenerated previews: $regenPreviews\n";
if($failedPreview>0) echo "[WARN] Failed to regenerate previews: $failedPreview\n";
if($missingFiles){ echo "[WARN] Missing files: ".count($missingFiles)."\n"; foreach(array_slice($missingFiles,0,20) as $p) echo " - $p\n"; if(count($missingFiles)>20) echo " ... (+".(count($missingFiles)-20)." more)\n"; }
if(!$missingDirs && !$missingFiles) echo "[OK] Template FS looks consistent.\n";
