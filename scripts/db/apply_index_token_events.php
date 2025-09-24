<?php
// Apply composite index token_events(cid, created_at) using configured DB creds
// Usage: php scripts/db/apply_index_token_events.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
$config = require __DIR__.'/../../config.php';
$dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
try {
  $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
} catch(Throwable $e){ fwrite(STDERR, "DB connect failed: ".$e->getMessage()."\n"); exit(1); }

try {
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_token_events_cid_created_at ON token_events(cid, created_at)");
  echo "[OK] Index ensured: idx_token_events_cid_created_at on token_events(cid, created_at)\n";
} catch(Throwable $e){
  // MySQL < 8.0.13 or MariaDB may not support IF NOT EXISTS for CREATE INDEX
  if (stripos($e->getMessage(), 'IF NOT EXISTS') !== false) {
    // fallback: check if exists, then create
    $exists = false;
    try {
      $st = $pdo->query("SHOW INDEX FROM token_events");
      while($r = $st->fetch(PDO::FETCH_ASSOC)){
        if($r['Key_name'] === 'idx_token_events_cid_created_at') { $exists=true; break; }
        if($r['Column_name']==='cid' && (int)$r['Seq_in_index']===1){
          // if there is an index starting with cid and second col created_at, treat as exists
          // build per index columns
        }
      }
    } catch(Throwable $e2){ /* ignore */ }
    if(!$exists){
      try { $pdo->exec("CREATE INDEX idx_token_events_cid_created_at ON token_events(cid, created_at)"); $exists=true; }
      catch(Throwable $e3){ fwrite(STDERR, "[FAIL] Could not create index: ".$e3->getMessage()."\n"); exit(2); }
    }
    if($exists){ echo "[OK] Index present or created.\n"; }
  } else {
    fwrite(STDERR, "[FAIL] Could not create index: ".$e->getMessage()."\n"); exit(2);
  }
}
