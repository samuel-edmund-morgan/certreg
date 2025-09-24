<?php
// CLI-only: Seed a template row with a tiny original image so UI can load it.
// Usage: php tests/seed_template.php [NAME]
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("Forbidden\n"); }

require_once __DIR__.'/../db.php';

function fail($m){ fwrite(STDERR, $m."\n"); exit(1); }

// Verify templates table exists
try { $chk=$pdo->query("SHOW TABLES LIKE 'templates'"); if(!$chk->fetch()){ echo "SKIP: no templates table\n"; exit(0);} } catch(Throwable $e){ fail('DB error'); }

// Find an active organization (fallback to any if active not found)
$orgId = null; try { $q=$pdo->query("SELECT id FROM organizations WHERE is_active=1 ORDER BY id ASC LIMIT 1"); $orgId = $q?$q->fetchColumn():null; } catch(Throwable $e){}
if(!$orgId){ try { $q=$pdo->query("SELECT id FROM organizations ORDER BY id ASC LIMIT 1"); $orgId = $q?$q->fetchColumn():null; } catch(Throwable $e){} }
if(!$orgId){ fail('No organizations'); }

$name = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : ('UITest Template '.substr(bin2hex(random_bytes(3)),0,6));
$code = 'UT'.substr(bin2hex(random_bytes(4)),0,8);
$now = date('Y-m-d H:i:s');

// Detect available columns for compatibility
$cols = [];
try {
  $c = $pdo->query('SHOW COLUMNS FROM templates');
  foreach(($c?$c->fetchAll(PDO::FETCH_ASSOC):[]) as $cr){ $cols[$cr['Field']] = true; }
} catch(Throwable $e){ fail('DB columns'); }

$fields = ['org_id','name','code','status','filename','file_ext','file_hash','file_size','width','height','version'];
$values = [$orgId,$name,$code,'active','seed.jpg','jpg',str_repeat('0',64),1234,1000,700,1];
if(isset($cols['created_at'])){ $fields[]='created_at'; $values[]=$now; }
if(isset($cols['updated_at'])){ $fields[]='updated_at'; $values[]=$now; }

// Some schemas might enforce NOT NULL on many fields; ensure presence
foreach($fields as $f){ if(!isset($cols[$f])){ /* tolerant: drop field */ } }

$ph = implode(',', array_fill(0, count($fields), '?'));
$fl = implode(',', array_map(fn($f)=>"`$f`", $fields));
try {
  $ins = $pdo->prepare("INSERT INTO templates ($fl) VALUES ($ph)");
  $ins->execute($values);
} catch(Throwable $e){ fail('Insert failed: '.$e->getMessage()); }
$tplId = (int)$pdo->lastInsertId();
if($tplId<=0){ fail('No insert id'); }

// Create minimal original.jpg and preview.jpg in files/templates/{org}/{tpl}/
$base = __DIR__.'/../files/templates/'.((int)$orgId).'/'.((int)$tplId);
if(!is_dir($base)) @mkdir($base, 0775, true);
// Build a tiny JPEG via GD
$okImg = false; if(function_exists('imagecreatetruecolor')){
  $im = @imagecreatetruecolor(1000,700);
  if($im){
    $bg = imagecolorallocate($im, 240, 244, 248);
    imagefilledrectangle($im, 0,0,1000,700, $bg);
    $fg = imagecolorallocate($im, 40, 80, 160);
    @imagestring($im, 5, 30, 30, 'UITest Template', $fg);
    @imagejpeg($im, $base.'/original.jpg', 82);
    // preview smaller
    $prev = @imagecreatetruecolor(800,560);
    if($prev){ imagecopyresampled($prev,$im,0,0,0,0,800,560,1000,700); @imagejpeg($prev, $base.'/preview.jpg', 82); imagedestroy($prev); }
    imagedestroy($im);
    $okImg = true;
  }
}
if(!$okImg){
  // Fallback: copy global template if present
  $src = __DIR__.'/../files/cert_template.jpg';
  if(is_file($src)) @copy($src, $base.'/original.jpg');
}

echo 'TPL_ID: '.$tplId."\n";
echo 'NAME: '.$name."\n";
echo 'ORG_ID: '.$orgId."\n";
?>
