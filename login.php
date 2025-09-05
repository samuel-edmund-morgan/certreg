<?php
require_once __DIR__.'/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); exit('Метод не дозволено');
}

$u = trim($_POST['username'] ?? '');
$p = (string)($_POST['password'] ?? '');
if ($u === '' || $p === '') {
  header('Location: /admin.php?err=empty'); exit;
}
if (login_admin($u, $p)) {
  header('Location: /admin.php'); exit;
}
header('Location: /admin.php?err=bad'); exit;
