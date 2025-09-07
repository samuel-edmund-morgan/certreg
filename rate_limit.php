<?php
// Centralized rate limiting helper.
// Usage: require rate_limit.php; rate_limit('login');
// Returns silently if allowed; sends HTTP 429 and exits if exceeded.

function rate_limit(string $key, array $cfgOverrides = []): void {
    static $config;
    if($config === null){
        $app = require __DIR__.'/config.php';
        $config = $app['rate_limits'] ?? [];
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pair = $cfgOverrides ?: ($config[$key] ?? null);
    if(!$pair){ return; } // unknown key => no limit
    [$limit,$window] = $pair;
    if($limit <=0 || $window <=0){ return; }
    $dir = sys_get_temp_dir().'/certreg_rl';
    if(!is_dir($dir)) @mkdir($dir,0700,true);
    $file = $dir.'/'.preg_replace('/[^A-Za-z0-9._-]/','_',$key).'_'.preg_replace('/[^A-Fa-f0-9:._-]/','_', $ip);
    $now = time(); $allowed = true; $retry=0; $timestamps=[];
    try {
        $fh = @fopen($file,'c+');
        if($fh && flock($fh, LOCK_EX)){
            $raw = trim(stream_get_contents($fh));
            $arr = $raw===''?[]:explode(' ', $raw);
            foreach($arr as $t){ $ti=(int)$t; if($ti > $now - $window) $timestamps[] = $ti; }
            $timestamps[] = $now;
            if(count($timestamps) > $limit){
                $allowed = false;
                $retry = max(1, ($timestamps[0] + $window) - $now);
            }
            ftruncate($fh,0); rewind($fh); fwrite($fh, implode(' ',$timestamps)); fflush($fh); flock($fh, LOCK_UN);
        }
        if($fh) fclose($fh);
    } catch(Throwable $e){ /* fail-open */ }
    if(!$allowed){
        http_response_code(429);
        header('Retry-After: '.$retry);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'rate_limited','retry_after'=>$retry,'key'=>$key]);
        exit;
    }
}
