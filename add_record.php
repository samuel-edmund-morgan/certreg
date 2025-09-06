<?php
http_response_code(410); // Gone
header('Content-Type: text/plain; charset=utf-8');
echo "Legacy endpoint removed. Use new privacy-first token issuance (issue_token.php).";

