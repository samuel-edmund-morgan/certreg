<?php
// Migration 002: add revoked_at, revoke_reason columns
// Usage: php migrations/002_add_revocation.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../db.php';
echo "[migration] 002 start".PHP_EOL;
try {
    $cols = [];
    $st = $pdo->query("SHOW COLUMNS FROM data");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $cols[$r['Field']] = true; }
    $alter = [];
    if (!isset($cols['revoked_at'])) {
        $alter[] = 'ADD COLUMN revoked_at DATETIME NULL AFTER hash_version';
    }
    if (!isset($cols['revoke_reason'])) {
        $alter[] = 'ADD COLUMN revoke_reason VARCHAR(255) NULL AFTER revoked_at';
    }
    if ($alter) {
        $sql = 'ALTER TABLE data ' . implode(', ', $alter);
        $pdo->exec($sql);
        echo "[migration] columns added".PHP_EOL;
    } else {
        echo "[migration] already present".PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migration] Failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
