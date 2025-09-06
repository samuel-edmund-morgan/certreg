<?php
// Migration 001: add hash_version column
// Usage: php migrations/001_add_hash_version.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../db.php';

echo "[migration] 001 start".PHP_EOL;

try {
    // Detect column
    $q = $pdo->query("SHOW COLUMNS FROM data LIKE 'hash_version'");
    if ($q->fetch()) {
        echo "[migration] hash_version already exists".PHP_EOL;
        exit(0);
    }
    $pdo->exec("ALTER TABLE data ADD COLUMN hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER hash");
    echo "[migration] hash_version added (default=1)".PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migration] Failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
