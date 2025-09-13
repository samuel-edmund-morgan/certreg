<?php
// Safe DB migration runner for v3 changes
// - Adds tokens.extra_info if missing
// - Removes legacy token fields no longer used in v3-only model
// Usage: php scripts/migrate.php

$cfg = require __DIR__.'/../config.php';

// Reuse app's PDO (full-priv user). Do NOT enable public DB here.
require_once __DIR__.'/../db.php';

function column_exists(PDO $pdo, $dbName, $table, $column){
    $sql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?';
    $st = $pdo->prepare($sql);
    $st->execute([$dbName, $table, $column]);
    return (int)$st->fetchColumn() > 0;
}

try {
    $dbName = $cfg['db_name'];
    echo "Checking migrations for DB '{$dbName}'...\n";
    $changed = false;

    // 1) Add tokens.extra_info if missing (place after `h`)
    if (!column_exists($pdo, $dbName, 'tokens', 'extra_info')) {
        echo " - Adding column tokens.extra_info... ";
        $pdo->exec("ALTER TABLE `tokens` ADD COLUMN `extra_info` VARCHAR(255) NULL AFTER `h`");
        echo "done.\n";
        $changed = true;
    } else {
        echo " - Column tokens.extra_info already exists.\n";
    }

    // 2) Drop legacy columns if present
    if (column_exists($pdo, $dbName, 'tokens', 'course')) {
        echo " - Dropping column tokens.course... ";
        $pdo->exec("ALTER TABLE `tokens` DROP COLUMN `course`");
        echo "done.\n";
        $changed = true;
    } else {
        echo " - Column tokens.course already absent.\n";
    }
    if (column_exists($pdo, $dbName, 'tokens', 'grade')) {
        echo " - Dropping column tokens.grade... ";
        $pdo->exec("ALTER TABLE `tokens` DROP COLUMN `grade`");
        echo "done.\n";
        $changed = true;
    } else {
        echo " - Column tokens.grade already absent.\n";
    }

    if(!$changed){
        echo "No changes needed.\n";
    } else {
        echo "Migration completed successfully.\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: ".$e->getMessage()."\n");
    exit(1);
}
