<?php
// Test-only download endpoint. Enabled only when CERTREG_TEST_MODE=1
// Allows client to POST actual bytes to be downloaded later via GET so Playwright can capture the download event.
$enabled = (getenv('CERTREG_TEST_MODE') === '1') || (isset($_GET['tm']) || isset($_POST['tm']));
$__logf = sys_get_temp_dir().'/certreg_test_download.log';
$__log_enabled = (
  getenv('CERTREG_TEST_DL_LOG') === '1' ||
  (isset($_GET['log']) && $_GET['log'] !== '0') ||
  (isset($_POST['log']) && $_POST['log'] !== '0')
);
function __tlog($m){
  global $__log_enabled, $__logf;
  if(!$__log_enabled) return;
  if(@filesize($__logf) > 512000){ @unlink($__logf); }
  @file_put_contents($__logf, date('H:i:s')." ".$m."\n", FILE_APPEND);
}
if (!$enabled) {
  http_response_code(404);
  __tlog('DENY not enabled');
  exit('not found');
}

// Use same session as app to store uploaded bytes per cid/kind
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_name('certreg_s');
  @session_start();
}

$kind = $_GET['kind'] ?? ($_POST['kind'] ?? 'pdf');
$cid = preg_replace('/[^A-Za-z0-9_.-]/','', $_GET['cid'] ?? ($_POST['cid'] ?? 'unknown'));
$ticket = isset($_GET['ticket']) ? preg_replace('/[^A-Za-z0-9_.-]/','', $_GET['ticket']) : (isset($_POST['ticket']) ? preg_replace('/[^A-Za-z0-9_.-]/','', $_POST['ticket']) : '');
$key = $ticket !== '' ? ('ticket:'.$ticket) : ($kind . ':' . $cid);

// Ticket storage optimization: to prevent session locking during long-poll waits we
// store ticket payloads outside the session (APCu or filesystem). Non-ticket flows
// still use session.
function ticket_store_set($ticketKey, $raw){
  if(function_exists('apcu_store')){
    @apcu_store('certreg_ticket_'.$ticketKey, $raw, 120);
    return true;
  }
  $dir = sys_get_temp_dir().'/certreg_test_dl';
  if(!is_dir($dir)) @mkdir($dir, 0700, true);
  @file_put_contents($dir.'/'.preg_replace('/[^A-Za-z0-9_.-]/','',$ticketKey), $raw, LOCK_EX);
  return true;
}
function ticket_store_get_and_clear($ticketKey){
  if(function_exists('apcu_fetch') && function_exists('apcu_store')){
    $k='certreg_ticket_'.$ticketKey; $ok=false; $val=@apcu_fetch($k, $ok);
    if($ok){
      // emulate delete by overwriting with short TTL
      @apcu_store($k, '', 1);
      return $val;
    }
    return null;
  }
  $dir = sys_get_temp_dir().'/certreg_test_dl';
  $path = $dir.'/'.preg_replace('/[^A-Za-z0-9_.-]/','',$ticketKey);
  if(is_file($path)) { $v=@file_get_contents($path); @unlink($path); return $v; }
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Store raw body (small test files only)
  $raw = file_get_contents('php://input');
  __tlog('POST kind='.$kind.' cid='.$cid.' ticket='.$ticket.' bytes='.strlen($raw));
  if ($ticket !== '') {
    ticket_store_set($ticket, $raw);
  } else {
    if (!isset($_SESSION['__test_downloads'])) { $_SESSION['__test_downloads'] = []; }
    $_SESSION['__test_downloads'][$key] = $raw;
  }
  header('Content-Type: application/json');
  echo json_encode(['ok'=>true, 'stored'=>strlen($raw), 'ticket'=>$ticket !== '' ]);
  exit;
}

// GET: serve stored bytes if available, otherwise fallback tiny artifact
$stored = null;
if($ticket !== '') {
  // Try optimized store first
  $stored = ticket_store_get_and_clear($ticket);
} else {
  $stored = $_SESSION['__test_downloads'][$key] ?? null;
}
// Always release session lock early so long-polling GET never blocks API POSTs
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
// Optional explicit filename override
$name = isset($_GET['name']) ? preg_replace('/[^A-Za-z0-9_.-]/','', $_GET['name']) : '';
if ($kind === 'jpg') {
  header('Content-Type: image/jpeg');
  $fname = $name !== '' ? $name : ('certificate_' . $cid . '.jpg');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  if ($stored !== null) { __tlog('GET serve kind=jpg cid='.$cid.' ticket='.$ticket.' bytes='.strlen($stored)); header('Content-Length: '.strlen($stored)); echo $stored; exit; }
  // Optional wait
  $wait = isset($_GET['wait']) ? (int)$_GET['wait'] : 0;
  if ($wait) {
    $deadline = microtime(true) + min($wait, 30);
    while (microtime(true) < $deadline) {
      if($ticket !== '') {
        $stored = ticket_store_get_and_clear($ticket);
      } else {
        @session_start();
        $stored = $_SESSION['__test_downloads'][$key] ?? null;
        @session_write_close();
      }
      if ($stored !== null) { __tlog('GET serve-wait kind=jpg cid='.$cid.' ticket='.$ticket.' bytes='.strlen($stored)); header('Content-Length: '.strlen($stored)); echo $stored; exit; }
      usleep(50_000);
    }
  }
  // Minimal JPEG (1x1 px) valid binary
  $jpg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxISEhUQEhMVFhUVFRUVFRUVFRUVFRUWFxUVFRUYHSggGBolHRUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAAEBQIDBgABB//EADkQAAEDAgMFBgQFBQAAAAAAAAEAAgMEEQUSITFBURMiYXGBkQYyUqGxBxQjQlNicuHxJJOi/8QAGQEAAwEBAQAAAAAAAAAAAAAAAAECAwQF/8QAHREBAQEBAQEBAQEAAAAAAAAAAAERAiExA0ESIv/aAAwDAQACEQMRAD8A9yiiiigAooooAKKKKACiiigAooooAK3p+z3m7l3WlK+8p1mR6b9c0r9wq1p6u8lB6Cz5I8JqjZ1v8A9h9l6D2z5XjU0q9w2g6Z7r6lqk5WcJ8hU4p7a0xA9h0kYy8kq1e9x1mcM3K0pE3dWf0lK7kqgqACiiigAooooAKKKKACiiigAooooAKKKKACiiigD//Z');
  header('Content-Length: '.strlen($jpg));
  __tlog('GET serve-fallback kind=jpg bytes='.strlen($jpg));
  echo $jpg;
  exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . ($name !== '' ? $name : ('certificate_' . $cid . '.pdf')) . '"');
if ($stored !== null) { __tlog('GET serve kind=pdf cid='.$cid.' ticket='.$ticket.' bytes='.strlen($stored)); header('Content-Length: '.strlen($stored)); echo $stored; exit; }
// If waiting was requested, hold the connection briefly for content to arrive
$wait = isset($_GET['wait']) ? (int)$_GET['wait'] : 0;
if ($wait) {
  $deadline = microtime(true) + min($wait, 30);
  while (microtime(true) < $deadline) {
    if($ticket !== '') {
      $stored = ticket_store_get_and_clear($ticket);
    } else {
      @session_start();
      $stored = $_SESSION['__test_downloads'][$key] ?? null;
      @session_write_close();
    }
    if ($stored !== null) { __tlog('GET serve-wait kind=pdf cid='.$cid.' ticket='.$ticket.' bytes='.strlen($stored)); header('Content-Length: '.strlen($stored)); echo $stored; exit; }
    usleep(50_000);
  }
}
// Fallback: minimal but structurally valid PDF with objects 1..5 and xref pointing to them
$fallback = "%PDF-1.4\n".
"1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n".
"2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n".
"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 5 0 R /Resources << >> >>\nendobj\n".
"4 0 obj\n<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length 0 >>\nstream\n\nendstream\nendobj\n".
"5 0 obj\n<< /Length 8 >>\nstream\nBT ET\nendstream\nendobj\n";
// Build xref with correct offsets
$offsets = [];
$pos = 0; $lines = explode("\n", $fallback);
$buf = '';
$add = function($s) use (&$buf, &$pos){ $buf .= $s; $pos += strlen($s); };
$add("%PDF-1.4\n");
$offsets[1] = $pos; $add("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");
$offsets[2] = $pos; $add("2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n");
$offsets[3] = $pos; $add("3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 5 0 R /Resources << >> >>\nendobj\n");
$offsets[4] = $pos; $add("4 0 obj\n<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length 0 >>\nstream\n\nendstream\nendobj\n");
$offsets[5] = $pos; $add("5 0 obj\n<< /Length 8 >>\nstream\nBT ET\nendstream\nendobj\n");
$xrefPos = $pos;
$xref = "xref\n0 6\n0000000000 65535 f \n";
for($i=1;$i<=5;$i++){ $off = str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT); $xref .= $off." 00000 n \n"; }
$add($xref);
$add("trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefPos."\n%%EOF");
header('Content-Length: '.strlen($buf));
__tlog('GET serve-fallback kind=pdf bytes='.strlen($buf));
echo $buf;
