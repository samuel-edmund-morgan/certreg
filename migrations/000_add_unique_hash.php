<?php
// Migration 000: Clean slate + UNIQUE index on data.hash
// Usage (CLI only): php migrations/000_add_unique_hash.php
// Safety: refuses to run via web SAPI.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Run from CLI only"; 
    exit; 
}

require_once __DIR__ . '/../db.php';

echo "[migration] Starting migration 000 (unique hash)" . PHP_EOL;

$pdo->beginTransaction();
try {
    // 1. Clear table (cannot TRUNCATE due to privileges; fallback to DELETE)
    $pdo->exec('DELETE FROM data');
    echo "[migration] Table data cleared with DELETE" . PHP_EOL;

    // 2. Add unique index on hash if not exists
    $check = $pdo->prepare("SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'data' AND index_name = 'uq_data_hash'");
    $check->execute();
    $exists = (int)$check->fetchColumn() === 1;
    if (!$exists) {
        $pdo->exec("ALTER TABLE data ADD UNIQUE KEY uq_data_hash (hash)");
        echo "[migration] Unique index uq_data_hash created" . PHP_EOL;
    } else {
        echo "[migration] Unique index uq_data_hash already exists, skipping" . PHP_EOL;
    }

    $pdo->commit();
    echo "[migration] Migration 000 completed successfully" . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migration] Failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
