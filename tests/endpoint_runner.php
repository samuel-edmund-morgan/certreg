<?php
// Endpoint runner for tests: executes an API endpoint in an isolated PHP process context.
// Usage: php tests/endpoint_runner.php api/register.php '{"json":"body"}'

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';

// Minimal admin session for API access
$_SESSION['admin_id'] = 1;
$_SESSION['admin_user'] = 'endpoint_runner';
$_SESSION['admin_role'] = 'admin';

$path = $argv[1] ?? null;
$json = $argv[2] ?? null;
$orgIdArg = $argv[3] ?? null;
if(!$path){ fwrite(STDERR, "No endpoint path provided\n"); exit(2); }

// Simulate POST with CSRF for JSON endpoints
$_SERVER['REQUEST_METHOD'] = 'POST';
if($json !== null){
  $GLOBALS['__TEST_JSON_BODY'] = $json;
  $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
}
// If org_id provided, set operator organization context
if($orgIdArg !== null && $orgIdArg !== ''){
  $_SESSION['org_id'] = (int)$orgIdArg;
}

// Include the endpoint; any exit() inside will terminate this isolated process only
include __DIR__.'/../'.$path;
