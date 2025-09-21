#!/usr/bin/env php
<?php
/**
 * Migration: Add created_at column to creds and backfill values.
 * Idempotent: safe to run multiple times.
 * Usage: php scripts/migrations/2025_09_20_add_creds_created_at.php
 */

$root = dirname(__DIR__, 2);
chdir($root);

require_once $root.'/config.php';

$config = require $root.'/config.php';
$dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
  $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
} catch (PDOException $e) {
  fwrite(STDERR, "[ERR] DB connect failed: ".$e->getMessage()."\n");
  exit(1);
}

function columnExists(PDO $pdo, string $table, string $col): bool {
  $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $stmt->execute([$col]);
  return (bool)$stmt->fetch();
}

function tableExists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
  $stmt->execute([$table]);
  return (bool)$stmt->fetch();
}

$table = 'creds';
if (!tableExists($pdo, $table)) {
  fwrite(STDERR, "[ERR] Table '$table' not found. Aborting.\n");
  exit(2);
}

$added = false;
if (!columnExists($pdo, $table, 'created_at')) {
  fwrite(STDOUT, "[INFO] Adding column created_at to $table ...\n");
  $pdo->exec("ALTER TABLE `$table` ADD COLUMN created_at DATETIME NULL DEFAULT NULL AFTER `role`");
  $added = true;
} else {
  fwrite(STDOUT, "[INFO] Column created_at already exists.\n");
}

// Backfill NULL dates (avoid zero-date literal for strict modes)
try {
  $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `$table` WHERE created_at IS NULL");
  $count = (int)$stmt->fetch()['c'];
  if ($count > 0) {
    fwrite(STDOUT, "[INFO] Backfilling $count NULL rows with NOW() ...\n");
    $pdo->exec("UPDATE `$table` SET created_at = NOW() WHERE created_at IS NULL");
  } else {
    fwrite(STDOUT, "[INFO] No rows need backfill (NULL).\n");
  }
} catch (PDOException $e) {
  fwrite(STDERR, "[WARN] Could not evaluate/backfill NULL rows (".$e->getMessage()."). Continuing.\n");
}

// Make column NOT NULL with default if we just added (fresh) or if any NULLs were fixed
try {
  fwrite(STDOUT, "[INFO] Ensuring NOT NULL + default CURRENT_TIMESTAMP ...\n");
  $pdo->exec("ALTER TABLE `$table` MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
} catch (PDOException $e) {
  fwrite(STDERR, "[WARN] Could not enforce NOT NULL (".$e->getMessage().") – continuing.\n");
}

// Optional index
try {
  $idx = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name='idx_creds_created_at'")->fetch();
  if (!$idx) {
    fwrite(STDOUT, "[INFO] Adding index idx_creds_created_at ...\n");
    $pdo->exec("CREATE INDEX idx_creds_created_at ON `$table` (created_at)");
  } else {
    fwrite(STDOUT, "[INFO] Index idx_creds_created_at already exists.\n");
  }
} catch (PDOException $e) {
  fwrite(STDERR, "[WARN] Could not create index (".$e->getMessage().").\n");
}

fwrite(STDOUT, "[DONE] Migration complete." . ($added ? " Column added." : "") . "\n");
exit(0);
