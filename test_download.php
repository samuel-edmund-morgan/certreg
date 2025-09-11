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
// Fallback: tiny, valid single-page PDF with one blank page
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]/Contents 4 0 R/Resources<<>>>>endobj\n4 0 obj<</Length 8>>stream\nBT ET\nendstream endobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000056 00000 n \n0000000107 00000 n \n0000000223 00000 n \ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n315\n%%EOF";
echo $pdf;
