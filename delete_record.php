<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';
$cfg = require __DIR__.'/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Метод не дозволено');
}

if (!function_exists('require_csrf')) {
    http_response_code(500);
    exit('CSRF не ініціалізовано');
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Невірний id');
}

/* (опційно) перед видаленням дістанемо запис, щоб почистити файл сертифіката, якщо є */
$st = $pdo->prepare("SELECT hash FROM data WHERE id=?");
$st->execute([$id]);
$row = $st->fetch();

$del = $pdo->prepare("DELETE FROM data WHERE id=?");
$del->execute([$id]);

/* (опційно) почистити згенеровані JPG, якщо були:
   шаблон імені у generate_cert.php: cert_{id}_{hash}.jpg */
if ($row && !empty($cfg['output_dir'])) {
    $pattern = rtrim($cfg['output_dir'], '/')."/cert_{$id}_*.jpg";
    foreach (glob($pattern) ?: [] as $file) {
        @unlink($file);
    }
}

header('Location: /admin.php?deleted=1');
exit;
