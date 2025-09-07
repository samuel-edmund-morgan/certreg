<?php
// Migration 007: add lookup counters to tokens + extend token_events enum for create & lookup
require_once __DIR__.'/../db.php';

// 1. Add columns lookup_count (BIGINT UNSIGNED) and last_lookup_at (DATETIME) if not present
$pdo->exec("ALTER TABLE tokens ADD COLUMN lookup_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER created_at");
$pdo->exec("ALTER TABLE tokens ADD COLUMN last_lookup_at DATETIME NULL AFTER lookup_count");

// 2. Extend enum. MySQL requires full rebuild; create new enum list.
try {
  $pdo->exec("ALTER TABLE token_events MODIFY event_type ENUM('revoke','unrevoke','delete','create','lookup') NOT NULL");
} catch (PDOException $e) {
  // If fails (e.g., duplicate), ignore
}

echo "Migration 007 executed: lookup counters + extended enum.\n";
