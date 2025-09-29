<?php
// Integration tests for organization management API endpoints.
// Usage: php tests/org_api_tests.php

putenv('CERTREG_TEST_MODE=1');
$_ENV['CERTREG_TEST_MODE'] = '1';
$_SERVER['CERTREG_TEST_MODE'] = '1';

require_once __DIR__.'/../auth.php';
require_once __DIR__.'/../db.php';

function t_assert($cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "[FAIL] {$msg}\n");
        exit(1);
    }
    fwrite(STDOUT, "[OK] {$msg}\n");
}

function t_equal($expected, $actual, string $msg): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "[FAIL] {$msg} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n");
        exit(1);
    }
    fwrite(STDOUT, "[OK] {$msg}\n");
}

function api_call(string $script, array $payload): array {
    $runner = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__.'/php/run_api.php')
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $output = [];
    $exitCode = 0;
    exec($runner, $output, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "[FAIL] Runner exited with code {$exitCode}\n");
        exit(1);
    }
    $raw = implode("\n", $output);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fwrite(STDERR, "[FAIL] Runner returned invalid JSON: {$raw}\n");
        exit(1);
    }
    return $data;
}

function cleanup_org(PDO $pdo, string $code): void {
    $stmt = $pdo->prepare('DELETE FROM organizations WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
}

function create_operator_for_org(PDO $pdo, int $orgId): array {
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        try {
            $meta = $pdo->query('SHOW COLUMNS FROM creds');
            foreach (($meta ? $meta->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                $cols[$row['Field']] = true;
            }
        } catch (Throwable $e) {
            $cols = [];
        }
    }

    $username = 'testop_' . bin2hex(random_bytes(4));
    $fields = ['username', 'passhash'];
    $values = [$username, password_hash('testpass', PASSWORD_BCRYPT)];

    if (isset($cols['role'])) {
        $fields[] = 'role';
        $values[] = 'operator';
    }

    if (isset($cols['is_active'])) {
        $fields[] = 'is_active';
        $values[] = 1;
    }

    if (isset($cols['org_id'])) {
        $fields[] = 'org_id';
        $values[] = $orgId;
    }

    if (isset($cols['email'])) {
        $fields[] = 'email';
        $values[] = $username . '@example.test';
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $columns = implode(',', array_map(fn ($f) => '`' . $f . '`', $fields));

    $stmt = $pdo->prepare("INSERT INTO creds ($columns) VALUES ($placeholders)");
    $stmt->execute($values);

    $id = (int)$pdo->lastInsertId();

    return ['id' => $id, 'username' => $username];
}

function delete_operator(PDO $pdo, int $operatorId): void {
    $stmt = $pdo->prepare('DELETE FROM creds WHERE id=? LIMIT 1');
    $stmt->execute([$operatorId]);
}

function tokens_support_org_binding(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $chkTable = $pdo->query("SHOW TABLES LIKE 'tokens'");
        if (!$chkTable || !$chkTable->fetch()) {
            $cache = false;
            return $cache;
        }

        $chkCol = $pdo->query("SHOW COLUMNS FROM tokens LIKE 'org_id'");
        $cache = (bool)($chkCol && $chkCol->fetch());
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function cleanup_token(PDO $pdo, string $cid): void {
    $pdo->prepare('DELETE FROM token_events WHERE cid=?')->execute([$cid]);
    $pdo->prepare('DELETE FROM tokens WHERE cid=? LIMIT 1')->execute([$cid]);
}

$pdo->exec('SET NAMES utf8mb4');

$code = 'TAPI_' . strtoupper(bin2hex(random_bytes(3)));
$payload = [
    'method' => 'POST',
    'post' => [
        'name' => 'Test Organization ' . $code,
        'code' => $code,
        'primary_color' => '#112233',
        'accent_color' => '#445566',
        'secondary_color' => '',
        'footer_text' => '© Test',
        'support_contact' => 'test@example.com',
    ],
];

cleanup_org($pdo, $code);

$resCreate = api_call('/api/org_create.php', $payload);
$jsonCreate = $resCreate['json'] ?? null;
t_equal(200, $resCreate['status'], 'org_create returns HTTP 200');
t_assert(isset($jsonCreate['ok']) && $jsonCreate['ok'] === true, 'org_create ok flag true');
t_assert(isset($jsonCreate['org']['id']), 'org_create response has org id');
$orgId = (int)$jsonCreate['org']['id'];

$stmt = $pdo->prepare('SELECT name, code, primary_color, accent_color FROM organizations WHERE id=?');
$stmt->execute([$orgId]);
$orgRow = $stmt->fetch(PDO::FETCH_ASSOC);
t_assert($orgRow !== false, 'organization persisted');
t_equal($code, $orgRow['code'], 'organization code matches');
t_equal('#112233', $orgRow['primary_color'], 'primary color normalized');
t_equal('#445566', $orgRow['accent_color'], 'accent color normalized');

$resDup = api_call('/api/org_create.php', $payload);
t_equal(200, $resDup['status'], 'duplicate org_create returns HTTP 200');
$jsonDup = $resDup['json'] ?? [];
t_assert(isset($jsonDup['ok']) && $jsonDup['ok'] === false, 'duplicate org_create ok flag false');
t_equal('exists', $jsonDup['errors']['code'] ?? null, 'duplicate org_create signals code exists');

$toggleOff = api_call('/api/org_set_active.php', [
    'method' => 'POST',
    'post' => [
        'id' => $orgId,
        'is_active' => 0,
    ],
]);
t_equal(200, $toggleOff['status'], 'org_set_active deactivate HTTP 200');
t_assert(($toggleOff['json']['ok'] ?? false) === true, 'org_set_active deactivate ok');
t_equal(0, $toggleOff['json']['is_active'] ?? null, 'org_set_active deactivate flag');

$toggleOn = api_call('/api/org_set_active.php', [
    'method' => 'POST',
    'post' => [
        'id' => $orgId,
        'is_active' => 1,
    ],
]);
t_equal(200, $toggleOn['status'], 'org_set_active activate HTTP 200');
t_assert(($toggleOn['json']['ok'] ?? false) === true, 'org_set_active activate ok');
t_equal(1, $toggleOn['json']['is_active'] ?? null, 'org_set_active activate flag');

$del = api_call('/api/org_delete.php', [
    'method' => 'POST',
    'post' => [ 'id' => $orgId ],
]);
t_equal(200, $del['status'], 'org_delete HTTP 200');
t_assert(($del['json']['ok'] ?? false) === true, 'org_delete ok flag true');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM organizations WHERE id=?');
$stmt->execute([$orgId]);
t_equal(0, (int)$stmt->fetchColumn(), 'organization removed from DB');

cleanup_org($pdo, $code);

// 3. org_set_active invalid ID handling
$badToggle = api_call('/api/org_set_active.php', [
    'method' => 'POST',
    'post' => [
        'id' => 0,
        'is_active' => 0,
    ],
]);
t_equal(200, $badToggle['status'], 'org_set_active invalid id HTTP 200');
t_equal('bad_id', $badToggle['json']['error'] ?? null, 'org_set_active invalid id error code');

// 4. org_delete blocked when operators exist
$blockCode = 'TAPI_OP_' . strtoupper(bin2hex(random_bytes(3)));
$blockPayload = $payload;
$blockPayload['post']['name'] = 'Operator Block Org ' . $blockCode;
$blockPayload['post']['code'] = $blockCode;

cleanup_org($pdo, $blockCode);

$resBlockCreate = api_call('/api/org_create.php', $blockPayload);
t_equal(200, $resBlockCreate['status'], 'org_create for operator block returns HTTP 200');
$blockJson = $resBlockCreate['json'] ?? [];
t_assert(($blockJson['ok'] ?? false) === true, 'org_create for operator block ok');
$blockOrgId = (int)($blockJson['org']['id'] ?? 0);
t_assert($blockOrgId > 0, 'org_create block scenario returned id');

$operator = create_operator_for_org($pdo, $blockOrgId);
t_assert($operator['id'] > 0, 'test operator inserted for block scenario');

$resDeleteBlocked = api_call('/api/org_delete.php', [
    'method' => 'POST',
    'post' => [ 'id' => $blockOrgId ],
]);
t_equal(200, $resDeleteBlocked['status'], 'org_delete blocked HTTP 200');
t_equal('has_operators', $resDeleteBlocked['json']['error'] ?? null, 'org_delete blocked by operators error');

delete_operator($pdo, $operator['id']);

$resDeleteAfter = api_call('/api/org_delete.php', [
    'method' => 'POST',
    'post' => [ 'id' => $blockOrgId ],
]);
t_equal(200, $resDeleteAfter['status'], 'org_delete after removing operator HTTP 200');
t_assert(($resDeleteAfter['json']['ok'] ?? false) === true, 'org_delete succeeds after removing operator');

cleanup_org($pdo, $blockCode);

// 5. org_delete blocked when tokens reference org (if schema supports)
if (tokens_support_org_binding($pdo)) {
    $tokenCode = 'TAPI_TOK_' . strtoupper(bin2hex(random_bytes(3)));
    $tokenPayload = $payload;
    $tokenPayload['post']['name'] = 'Token Block Org ' . $tokenCode;
    $tokenPayload['post']['code'] = $tokenCode;

    cleanup_org($pdo, $tokenCode);

    $resTokenCreate = api_call('/api/org_create.php', $tokenPayload);
    t_equal(200, $resTokenCreate['status'], 'org_create for token block HTTP 200');
    $tokenJson = $resTokenCreate['json'] ?? [];
    t_assert(($tokenJson['ok'] ?? false) === true, 'org_create for token block ok');
    $tokenOrgId = (int)($tokenJson['org']['id'] ?? 0);

    $cid = 'OT' . strtoupper(bin2hex(random_bytes(4)));
    $issued = date('Y-m-d');
    $valid = '4000-01-01';
    $stmtToken = $pdo->prepare('INSERT INTO tokens (cid, version, h, issued_date, valid_until, org_id) VALUES (?,?,?,?,?,?)');
    $stmtToken->execute([$cid, 3, bin2hex(random_bytes(32)), $issued, $valid, $tokenOrgId]);

    $resDeleteTokenBlocked = api_call('/api/org_delete.php', [
        'method' => 'POST',
        'post' => [ 'id' => $tokenOrgId ],
    ]);
    t_equal(200, $resDeleteTokenBlocked['status'], 'org_delete blocked by tokens HTTP 200');
    t_equal('has_tokens', $resDeleteTokenBlocked['json']['error'] ?? null, 'org_delete blocked by tokens error');

    cleanup_token($pdo, $cid);

    $resDeleteTokenAfter = api_call('/api/org_delete.php', [
        'method' => 'POST',
        'post' => [ 'id' => $tokenOrgId ],
    ]);
    t_equal(200, $resDeleteTokenAfter['status'], 'org_delete after removing token HTTP 200');
    t_assert(($resDeleteTokenAfter['json']['ok'] ?? false) === true, 'org_delete succeeds after removing tokens');

    cleanup_org($pdo, $tokenCode);
} else {
    fwrite(STDOUT, "[SKIP] tokens table without org_id column – skipping token block test\n");
}

fwrite(STDOUT, "\nOrganization API tests passed.\n");