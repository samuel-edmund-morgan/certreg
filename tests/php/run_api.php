<?php
// CLI helper to execute API endpoints within an isolated process for tests.
// Usage: php tests/php/run_api.php /api/org_create.php '{"method":"POST","post":{"name":"..."}}'

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "run_api.php must be executed from CLI\n");
    exit(1);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tests/php/run_api.php <script> <json-payload>\n");
    exit(1);
}

$relPath = $argv[1];
$jsonPayload = $argv[2];
$payload = json_decode($jsonPayload, true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON payload\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
// tests/php -> repo root: up two levels
$root = realpath($root . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root\n");
    exit(1);
}

$scriptPath = realpath($root . $relPath);
if ($scriptPath === false || !is_file($scriptPath)) {
    fwrite(STDERR, "Script not found: {$relPath}\n");
    exit(1);
}

// Ensure test mode flags
putenv('CERTREG_TEST_MODE=1');
$_ENV['CERTREG_TEST_MODE'] = '1';
$_SERVER['CERTREG_TEST_MODE'] = '1';

// Emulate web env basics
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'certreg-test-harness';
$_SERVER['REQUEST_METHOD'] = strtoupper($payload['method'] ?? 'GET');
$_SERVER['HTTP_ACCEPT'] = $payload['headers']['Accept'] ?? 'application/json';
$_SERVER['CONTENT_TYPE'] = $payload['headers']['Content-Type'] ?? 'application/x-www-form-urlencoded';

require_once $root . '/auth.php';
require_once $root . '/db.php';

// Seed admin session so require_admin() passes
$_SESSION['admin_id'] = $_SESSION['admin_id'] ?? 1;
$_SESSION['admin_user'] = $_SESSION['admin_user'] ?? 'testadmin';
$_SESSION['admin_role'] = 'admin';
$_SESSION['org_id'] = $_SESSION['org_id'] ?? null;

$csrf = csrf_token();

// Populate superglobals
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST = $payload['post'] ?? [];
    if (!isset($_POST['_csrf'])) {
        $_POST['_csrf'] = $csrf;
    }
    // Support JSON body (for API expecting raw JSON)
    if (!empty($payload['json'])) {
        $GLOBALS['__TEST_JSON_BODY'] = json_encode($payload['json'], JSON_UNESCAPED_UNICODE);
        $_SERVER['CONTENT_TYPE'] = 'application/json';
    }
} else {
    $_GET = $payload['get'] ?? [];
}

$_FILES = $payload['files'] ?? [];

$__run_state = [
    'finalized' => false,
    'exception' => null,
];

ob_start();

register_shutdown_function(function () use (&$__run_state) {
    if ($__run_state['finalized'] ?? false) {
        return;
    }

    $output = ob_get_level() > 0 ? ob_get_clean() : '';
    $status = http_response_code();
    if ($status === false) {
        $status = 200;
    }

    $response = [
        'status' => $status,
        'output' => $output,
        'json' => null,
    ];

    if (($__run_state['exception'] ?? null) instanceof Throwable) {
        $response['exception'] = [
            'type' => get_class($__run_state['exception']),
            'message' => $__run_state['exception']->getMessage(),
        ];
    }

    if ($output !== '') {
        $decoded = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE || $output === 'null') {
            $response['json'] = $decoded;
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

$thrown = null;
try {
    include $scriptPath;
} catch (Throwable $e) {
    $thrown = $e;
}

$output = ob_get_level() > 0 ? ob_get_clean() : '';
$status = http_response_code();
if ($status === false) {
    $status = 200;
}

$response = [
    'status' => $status,
    'output' => $output,
    'json' => null,
];

if ($thrown !== null) {
    $response['exception'] = [
        'type' => get_class($thrown),
        'message' => $thrown->getMessage(),
    ];
    $__run_state['exception'] = $thrown;
}

if ($output !== '') {
    $decoded = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE || $output === 'null') {
        $response['json'] = $decoded;
    }
}

$__run_state['finalized'] = true;

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit(0);
