<?php
// Server-side QR generator (data contains NO PІБ). Accepts ?data= (URL encoded) up to 512 chars.
require_once __DIR__.'/lib/phpqrcode.php';
$data = $_GET['data'] ?? '';
if (strlen($data) > 512) { http_response_code(400); exit('too long'); }
header('Content-Type: image/png');
\QRcode::png($data, false, QR_ECLEVEL_M, 6, 2);
