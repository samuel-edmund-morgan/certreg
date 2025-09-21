<?php
/**
 * Migration: Add organizations table and org_id columns.
 * Usage: php scripts/migrations/2025_09_21_add_organizations.php
 * Idempotent: safe to re-run.
 *
 * Strategy:
 * 1. Create organizations if missing.
 * 2. Ensure default org (from config org_code) exists (code immutable).
 * 3. Add org_id to creds (NULLable) if missing; backfill operators (role=operator) with default org; leave admins NULL (global scope).
 * 4. Add org_id to tokens if missing; backfill all rows with default org (future tokens get it too).
 * 5. (Templates table already contains org separation in future – if org_id column missing add + backfill.)
 */

require __DIR__.'/../../config.php';
require __DIR__.'/../../db.php';

function colExists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

function tableExists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetch();
}

try {
    // NOTE: MySQL implicitly commits around DDL (CREATE/ALTER TABLE), so a big encompassing
    // transaction is pointless and leads to "There is no active transaction" on commit.
    // We therefore run DDL autocommit and (optionally) could wrap pure DML backfills later.

    // 1. organizations table
    if (!tableExists($pdo, 'organizations')) {
        fwrite(STDOUT, "[INFO] Creating organizations table...\n");
        $pdo->exec(<<<SQL
CREATE TABLE organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE,
  code VARCHAR(32) NOT NULL UNIQUE,
  logo_path VARCHAR(255) NULL,
  favicon_path VARCHAR(255) NULL,
  primary_color CHAR(7) NULL,
  accent_color CHAR(7) NULL,
  secondary_color CHAR(7) NULL,
  footer_text VARCHAR(255) NULL,
  support_contact VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_org_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    } else {
        fwrite(STDOUT, "[INFO] organizations table already exists.\n");
    }

    // 2. default org
    $orgCode = $config['org_code'] ?? 'DEFAULT';
    $siteName = $config['site_name'] ?? 'Default Org';
    $sel = $pdo->prepare('SELECT id FROM organizations WHERE code=? LIMIT 1');
    $sel->execute([$orgCode]);
    $orgId = $sel->fetchColumn();
    if (!$orgId) {
        fwrite(STDOUT, "[INFO] Inserting default organization (code=$orgCode)...\n");
        $ins = $pdo->prepare('INSERT INTO organizations(name, code) VALUES(?,?)');
        $ins->execute([$siteName, $orgCode]);
        $orgId = $pdo->lastInsertId();
    } else {
        fwrite(STDOUT, "[INFO] Default organization already present (id=$orgId).\n");
    }

    // 3. creds.org_id
    $credsHas = colExists($pdo, 'creds', 'org_id');
    if (!$credsHas) {
        fwrite(STDOUT, "[INFO] Adding org_id to creds (NULLable)...\n");
        $pdo->exec('ALTER TABLE creds ADD COLUMN org_id INT NULL AFTER role');
        $pdo->exec('ALTER TABLE creds ADD KEY idx_creds_org (org_id)');
    } else {
        fwrite(STDOUT, "[INFO] creds.org_id already exists.\n");
    }

    // Backfill operators only (admins remain NULL global scope)
    $bf = $pdo->prepare('UPDATE creds SET org_id=? WHERE role="operator" AND (org_id IS NULL)');
    $bf->execute([$orgId]);
    $affectedOperators = $bf->rowCount();
    fwrite(STDOUT, "[INFO] Backfilled $affectedOperators operator rows with default org id $orgId.\n");

    // Enforce NOT NULL for operators (allow NULL for admins) via trigger-like check? Simpler: leave column NULLable and enforce in app.

    // 4. tokens.org_id
    if (tableExists($pdo,'tokens')) {
        $tokHas = colExists($pdo,'tokens','org_id');
        if (!$tokHas) {
            fwrite(STDOUT, "[INFO] Adding org_id to tokens...\n");
            $pdo->exec('ALTER TABLE tokens ADD COLUMN org_id INT NULL AFTER version');
            $pdo->exec('ALTER TABLE tokens ADD KEY idx_tokens_org (org_id)');
        } else {
            fwrite(STDOUT, "[INFO] tokens.org_id already exists.\n");
        }
        // Backfill all tokens with default org if NULL
        $bt = $pdo->prepare('UPDATE tokens SET org_id=? WHERE org_id IS NULL');
        $bt->execute([$orgId]);
        fwrite(STDOUT, "[INFO] Backfilled " . $bt->rowCount() . " tokens with default org id $orgId.\n");
    } else {
        fwrite(STDOUT, "[WARN] tokens table not found, skipping tokens org_id.\n");
    }

    // 5. templates.org_id (if templates table exists and column absent)
    if (tableExists($pdo,'templates')) {
        $tplHas = colExists($pdo,'templates','org_id');
        if (!$tplHas) {
            fwrite(STDOUT, "[INFO] Adding org_id to templates...\n");
            $pdo->exec('ALTER TABLE templates ADD COLUMN org_id INT NULL AFTER name');
            $pdo->exec('ALTER TABLE templates ADD KEY idx_templates_org (org_id)');
        } else {
            fwrite(STDOUT, "[INFO] templates.org_id already exists.\n");
        }
        $btpl = $pdo->prepare('UPDATE templates SET org_id=? WHERE org_id IS NULL');
        $btpl->execute([$orgId]);
        fwrite(STDOUT, "[INFO] Backfilled " . $btpl->rowCount() . " templates with default org id $orgId.\n");
    } else {
        fwrite(STDOUT, "[WARN] templates table not found, skipping templates org_id.\n");
    }

    // No global transaction to commit (DDL auto-committed). Just report success.
    fwrite(STDOUT, "[DONE] Migration complete.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "[ERROR] ".$e->getMessage()."\n");
    exit(1);
}
