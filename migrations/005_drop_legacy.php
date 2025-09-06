<?php
// Migration 005: Drop (or archive) legacy tables from pre privacy-first version.
// Usage:
//   php migrations/005_drop_legacy.php            # archive then drop = default (rename copy kept)
//   php migrations/005_drop_legacy.php --drop     # direct DROP (no archive)
//   php migrations/005_drop_legacy.php --archive  # ONLY archive (rename) without dropping
//
// Legacy tables targeted:
//   data                (held PII previously; now replaced by tokens)
//   verification_logs   (request logging no longer used)
//
// Strategy (default):
//  For each legacy table that exists:
//    1) Create archival name: <table>_legacy_YYYYMMDDHHMMSS
//    2) RENAME TABLE <table> TO <archival>
//    3) (If mode is full) DROP TABLE <archival>
// This keeps a window for quick recovery unless --drop is used directly.
//
// Exit codes: 0 success, 1 error.

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../db.php';

$args = $argv; array_shift($args);
$mode = 'archive_then_drop'; // default
foreach ($args as $a) {
    if ($a === '--drop') { $mode = 'drop_direct'; }
    elseif ($a === '--archive') { $mode = 'archive_only'; }
    elseif ($a === '--help' || $a === '-h') {
        echo "Migration 005 – options:\n";
        echo "  --archive    Only rename tables (keep data)\n";
        echo "  --drop       Directly DROP tables (irreversible)\n";
        echo "(no flags)     Archive then DROP (safer default)\n";
        exit(0);
    }
}

$legacyTables = ['data','verification_logs'];
$ts = date('YmdHis');

echo "[migration] 005 start (mode=$mode)".PHP_EOL;
try {
    // Not using explicit transaction: RENAME + DROP may cause implicit commits in MySQL.
    foreach ($legacyTables as $tbl) {
        $exists = $pdo->query("SHOW TABLES LIKE '".addslashes($tbl)."'")->fetchColumn();
        if (!$exists) { echo "[migration] table $tbl not found – skip".PHP_EOL; continue; }
        if ($mode === 'drop_direct') {
            $pdo->exec("DROP TABLE `{$tbl}`");
            echo "[migration] DROPPED $tbl".PHP_EOL;
            continue;
        }
        $arch = $tbl.'_legacy_'.$ts;
        $pdo->exec("RENAME TABLE `{$tbl}` TO `{$arch}`");
        echo "[migration] RENAMED $tbl -> $arch".PHP_EOL;
        if ($mode === 'archive_then_drop') {
            $pdo->exec("DROP TABLE `{$arch}`");
            echo "[migration] DROPPED archived $arch".PHP_EOL;
        }
    }
    echo "[migration] 005 complete".PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migration] Failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
