<?php
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Removed legacy generator. Certificates now rendered client-side in issue_token.php.";
