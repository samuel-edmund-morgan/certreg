<?php
// Migration 006: create token_events audit table
require_once __DIR__.'/../db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS token_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cid VARCHAR(64) NOT NULL,
  event_type ENUM('revoke','unrevoke','delete') NOT NULL,
  reason VARCHAR(255) NULL,
  admin_id INT NULL,
  admin_user VARCHAR(64) NULL,
  prev_revoked_at DATETIME NULL,
  prev_revoke_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cid (cid),
  INDEX idx_event (event_type),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$pdo->exec($sql);

echo "token_events table ensured\n";
