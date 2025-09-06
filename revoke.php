<?php
require_once __DIR__.'/auth.php';
require_admin();
require_once __DIR__.'/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Метод не дозволено'); }
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');
if ($id <= 0) { http_response_code(400); exit('Bad id'); }

if ($action === 'revoke') {
    if ($reason === '') { http_response_code(400); exit('Порожня причина'); }
    $st = $pdo->prepare("UPDATE data SET revoked_at=NOW(), revoke_reason=? WHERE id=?");
    $st->execute([$reason, $id]);
} elseif ($action === 'restore') {
    $st = $pdo->prepare("UPDATE data SET revoked_at=NULL, revoke_reason=NULL WHERE id=?");
    $st->execute([$id]);
} else {
    http_response_code(400); exit('Bad action');
}

header('Location: /admin.php');
exit;
