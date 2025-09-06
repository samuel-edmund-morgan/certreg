<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Legacy delete endpoint removed. Tokens are managed via tokens.php (revoke/unrevoke only).";
