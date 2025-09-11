<?php
// Test-only download endpoint. Enabled only when CERTREG_TEST_MODE=1
// Allows client to POST actual bytes to be downloaded later via GET so Playwright can capture the download event.
$enabled = getenv('CERTREG_TEST_MODE') === '1';
if (!$enabled) {
  http_response_code(404);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Store raw body into session (small test files only)
  $raw = file_get_contents('php://input');
  if (!isset($_SESSION['__test_downloads'])) { $_SESSION['__test_downloads'] = []; }
  $_SESSION['__test_downloads'][$key] = $raw;
  header('Content-Type: application/json');
  echo json_encode(['ok'=>true, 'stored'=>strlen($raw)]);
  exit;
}

// GET: serve stored bytes if available, otherwise fallback tiny artifact
$stored = $_SESSION['__test_downloads'][$key] ?? null;
// Release session lock early to allow concurrent POST to write while we potentially wait
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
// Optional explicit filename override
$name = isset($_GET['name']) ? preg_replace('/[^A-Za-z0-9_.-]/','', $_GET['name']) : '';
if ($kind === 'jpg') {
  header('Content-Type: image/jpeg');
  $fname = $name !== '' ? $name : ('certificate_' . $cid . '.jpg');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  if ($stored !== null) { echo $stored; exit; }
  // Optional wait for JPG as well
  $wait = isset($_GET['wait']) ? (int)$_GET['wait'] : 0;
  if ($wait) {
    $deadline = microtime(true) + min($wait, 30);
    while (microtime(true) < $deadline) {
      @session_start();
      $stored = $_SESSION['__test_downloads'][$key] ?? null;
      @session_write_close();
      if ($stored !== null) { echo $stored; exit; }
      usleep(50_000);
    }
  }
  // Minimal JPEG (1x1 px) valid binary
  echo base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxISEhUQEhMVFhUVFRUVFRUVFRUVFRUWFxUVFRUYHSggGBolHRUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGxAQGy0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAAEBQIDBgABB//EADkQAAEDAgMFBgQFBQAAAAAAAAEAAgMEEQUSITFBURMiYXGBkQYyUqGxBxQjQlNicuHxJJOi/8QAGQEAAwEBAQAAAAAAAAAAAAAAAAECAwQF/8QAHREBAQEBAQEBAQEAAAAAAAAAAAERAiExA0ESIv/aAAwDAQACEQMRAD8A9yiiiigAooooAKKKKACiiigAooooAK3p+z3m7l3WlK+8p1mR6b9c0r9wq1p6u8lB6Cz5I8JqjZ1v8A9h9l6D2z5XjU0q9w2g6Z7r6lqk5WcJ8hU4p7a0xA9h0kYy8kq1e9x1mcM3K0pE3dWf0lK7kqgqACiiigAooooAKKKKACiiigAooooAKKKKACiiigD//Z');
  exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . ($name !== '' ? $name : ('certificate_' . $cid . '.pdf')) . '"');
if ($stored !== null) { echo $stored; exit; }
// If waiting was requested, hold the connection briefly for content to arrive
$wait = isset($_GET['wait']) ? (int)$_GET['wait'] : 0;
if ($wait) {
  $deadline = microtime(true) + min($wait, 30);
  // simple poll loop; session writes by POST are visible within same session
  while (microtime(true) < $deadline) {
  @session_start();
  $stored = $_SESSION['__test_downloads'][$key] ?? null;
  @session_write_close();
    if ($stored !== null) { echo $stored; exit; }
    usleep(50_000); // 50ms
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
echo $buf;
