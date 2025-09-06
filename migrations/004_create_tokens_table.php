<?php
require_once __DIR__.'/../db.php';
/*
 * Migration 004: Create minimal tokens table for Variant A (no PII stored)
 * Table: tokens
 *  id INT AUTO_INCREMENT PK
 *  cid VARCHAR(64) UNIQUE NOT NULL  -- public certificate id / code shown on paper (could reuse old data.id format or prefixed)
 *  version TINYINT NOT NULL DEFAULT 1
 *  h CHAR(64) NOT NULL              -- hex HMAC-SHA256 (32 bytes => 64 hex chars)
 *  course VARCHAR(100) DEFAULT NULL -- non-PII course identifier (keep short / normalized)
 *  grade  VARCHAR(32)  DEFAULT NULL -- non-PII grade code
 *  issued_date DATE DEFAULT NULL    -- date displayed (not PII by itself)
 *  revoked_at DATETIME NULL
 *  revoke_reason VARCHAR(255) NULL
 *  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
 * Indexes to support lookups + revocation scans.
 */
$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cid VARCHAR(64) NOT NULL,
  version TINYINT NOT NULL DEFAULT 1,
  h CHAR(64) NOT NULL,
  course VARCHAR(100) DEFAULT NULL,
  grade VARCHAR(32) DEFAULT NULL,
  issued_date DATE DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  revoke_reason VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tokens_cid (cid),
  UNIQUE KEY uq_tokens_h (h),
  KEY idx_tokens_revoked_at (revoked_at),
  KEY idx_tokens_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
$pdo->exec($sql);

echo "Migration 004 executed: tokens table ready\n";
