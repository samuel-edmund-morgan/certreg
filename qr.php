<?php
// Server-side QR generator (data contains NO PІБ).
// Використовується тільки під час видачі нагороди (issue_token.php) => робимо його адмінським.
require_once __DIR__.'/auth.php';
require_admin();
require_csrf();
require_once __DIR__.'/lib/phpqrcode.php';
$data = $_GET['data'] ?? '';
if (strlen($data) > 512) { http_response_code(400); exit('too long'); }
header('Content-Type: image/png');
\QRcode::png($data, false, QR_ECLEVEL_M, 6, 2);
