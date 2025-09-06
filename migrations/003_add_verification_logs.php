<?php
// Migration 003: create verification_logs table
// Usage: php migrations/003_add_verification_logs.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../db.php';
echo "[migration] 003 start".PHP_EOL;
try {
    $exists = $pdo->query("SHOW TABLES LIKE 'verification_logs'")->fetchColumn();
    if ($exists) { echo "[migration] table already exists".PHP_EOL; exit(0);}    
    $pdo->exec(<<<SQL
CREATE TABLE verification_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  requested_id INT NULL,
  requested_hash CHAR(64) NULL,
  data_id INT NULL,
  success TINYINT(1) NOT NULL,
  status VARCHAR(32) NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  remote_ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created_at (created_at),
  INDEX idx_hash (requested_hash),
  INDEX idx_data (data_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    echo "[migration] table verification_logs created".PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migration] Failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
