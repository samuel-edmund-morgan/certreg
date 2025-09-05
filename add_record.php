<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';

$name = trim($_POST['name'] ?? '');
$score = trim($_POST['score'] ?? '');
$course = trim($_POST['course'] ?? '');
$date = trim($_POST['date'] ?? '');

if ($name==='' || $score==='' || $course==='' || $date==='') {
  header('Location: /admin.php?err=empty'); exit;
}

$st = $pdo->prepare("INSERT INTO data (name,score,course,date) VALUES (?,?,?,?)");
$st->execute([$name,$score,$course,$date]);
header('Location: /admin.php?ok=1');
