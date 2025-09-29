<?php
// Idempotent migration helper.
// Run: php scripts/migrate.php
// Performs:
//  - Create templates table (if missing)
//  - Add template_id column + index + FK to tokens (if missing)
//  - Future: extend with audit_log, etc.

require __DIR__ . '/../db.php';

function columnExists(PDO $pdo, $table, $col){
  $st = $pdo->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}
function tableExists(PDO $pdo, $table){
  $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}
function indexExists(PDO $pdo, $table, $index){
  $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
  $st->execute([$table,$index]);
  return (bool)$st->fetchColumn();
}
function fkExists(PDO $pdo, $table, $col, $refTable){
  $sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=?";
  $st = $pdo->prepare($sql);
  $st->execute([$table,$col,$refTable]);
  return (bool)$st->fetchColumn();
}
function columnType(PDO $pdo, $table, $col){
  $st = $pdo->prepare("SELECT COLUMN_TYPE, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$table,$col]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
  $pdo->beginTransaction();

  // 1. templates table
  if(!tableExists($pdo,'templates')){
    $pdo->exec("CREATE TABLE templates (\n      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n      org_id BIGINT UNSIGNED NOT NULL,\n      name VARCHAR(160) NOT NULL,\n      code VARCHAR(60) NOT NULL,\n      status ENUM('active','inactive','archived') DEFAULT 'active',\n      filename VARCHAR(255) NULL,\n      file_ext VARCHAR(10) NULL,\n      file_hash CHAR(64) NULL,\n      file_size INT UNSIGNED NULL,\n      width INT UNSIGNED NULL,\n      height INT UNSIGNED NULL,\n      coords JSON NULL,\n      version INT UNSIGNED NOT NULL DEFAULT 1,\n      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n      UNIQUE KEY uq_org_code (org_id, code),\n      INDEX idx_org_status (org_id, status),\n      INDEX idx_status (status),\n      CONSTRAINT fk_templates_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created table: templates\n";
  } else {
    echo "Table exists: templates (skipped)\n";
    // Reconcile legacy schema differences
    // Detect legacy columns: id int, name varchar(255), org_id int, filename, coordinates(json), created_at, created_by, is_active
    $legacyCols = $pdo->query("SHOW COLUMNS FROM templates")->fetchAll(PDO::FETCH_COLUMN);
    $hasCoordinates = in_array('coordinates', $legacyCols, true);
    $hasCoords = in_array('coords', $legacyCols, true);
    // 1. Rename coordinates -> coords if needed
    if(!$hasCoords && $hasCoordinates){
      try { $pdo->exec("ALTER TABLE templates CHANGE coordinates coords JSON NULL"); echo "Renamed column coordinates -> coords\n"; }
      catch(Exception $ie){ echo "Warning: could not rename coordinates -> coords (".$ie->getMessage().")\n"; }
      $legacyCols[]='coords';
    }
    // Refresh after potential rename
    $legacyCols = $pdo->query("SHOW COLUMNS FROM templates")->fetchAll(PDO::FETCH_COLUMN);
    // Helper closure
    $ensureColumn = function($name, $definition) use ($pdo, &$legacyCols){
      if(!in_array($name, $legacyCols, true)){
        $pdo->exec("ALTER TABLE templates ADD COLUMN $name $definition");
        echo "Added column templates.$name\n";
        $legacyCols[] = $name;
      }
    };
    // 2. Add missing columns (nullable first if data backfill needed)
    $ensureColumn('code', "VARCHAR(60) NULL");
    $ensureColumn('status', "ENUM('active','inactive','archived') DEFAULT 'active'");
    $ensureColumn('file_ext', "VARCHAR(10) NULL");
    $ensureColumn('file_hash', "CHAR(64) NULL");
    $ensureColumn('file_size', "INT UNSIGNED NULL");
    $ensureColumn('width', "INT UNSIGNED NULL");
    $ensureColumn('height', "INT UNSIGNED NULL");
    $ensureColumn('version', "INT UNSIGNED NOT NULL DEFAULT 1");
    $ensureColumn('updated_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    if(!in_array('coords',$legacyCols,true) && !in_array('coordinates',$legacyCols,true)){
      // If neither coords nor coordinates existed, create coords
      $ensureColumn('coords', 'JSON NULL');
    }
    // 3. Data backfill
    // Backfill code values
    $missingCode = $pdo->query("SELECT COUNT(*) FROM templates WHERE code IS NULL OR code='' ")->fetchColumn();
    if($missingCode > 0){
      // Generate temporary codes based on id to guarantee uniqueness per org
      $pdo->exec("UPDATE templates SET code = CONCAT('T', id) WHERE code IS NULL OR code='' ");
      echo "Backfilled code values\n";
    }
    // Map is_active->status if is_active column exists
    if(in_array('is_active',$legacyCols,true)){
      $pdo->exec("UPDATE templates SET status = IF(is_active=1,'active','inactive') WHERE status IS NULL OR status='' ");
      echo "Mapped is_active -> status values\n";
    }
    // Derive file_ext from filename if possible
    if(in_array('file_ext',$legacyCols,true) && in_array('filename',$legacyCols,true)){
      $pdo->exec("UPDATE templates SET file_ext = LOWER(SUBSTRING_INDEX(filename,'.',-1)) WHERE (file_ext IS NULL OR file_ext='') AND filename LIKE '%.%' ");
    }
    // 4. Constraints & indexes
    // Make code NOT NULL (only after backfill)
    try { $pdo->exec("ALTER TABLE templates MODIFY code VARCHAR(60) NOT NULL"); } catch(Exception $eMod){ echo "Warning: could not set code NOT NULL (".$eMod->getMessage().")\n"; }
    if(!indexExists($pdo,'templates','uq_org_code')){
      try { $pdo->exec("ALTER TABLE templates ADD UNIQUE KEY uq_org_code (org_id, code)"); echo "Added unique index uq_org_code\n"; }
      catch(Exception $eU){ echo "Warning: could not add uq_org_code (".$eU->getMessage().")\n"; }
    }
    if(!indexExists($pdo,'templates','idx_org_status')){
      try { $pdo->exec("CREATE INDEX idx_org_status ON templates(org_id, status)"); echo "Added index idx_org_status\n"; } catch(Exception $eI){ echo "Warning: could not add idx_org_status (".$eI->getMessage().")\n"; }
    }
    if(!indexExists($pdo,'templates','idx_status')){
      try { $pdo->exec("CREATE INDEX idx_status ON templates(status)"); echo "Added index idx_status\n"; } catch(Exception $eI2){ echo "Warning: could not add idx_status (".$eI2->getMessage().")\n"; }
    }
    // Attempt FK to organizations if org_id exists and permission allows
    if(in_array('org_id',$legacyCols,true)){
      // Check if FK already exists
      if(!fkExists($pdo,'templates','org_id','organizations')){
        try { $pdo->exec("ALTER TABLE templates ADD CONSTRAINT fk_templates_org FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE"); echo "Added FK fk_templates_org\n"; }
        catch(Exception $fkOrg){ if(stripos($fkOrg->getMessage(),'denied')!==false) echo "Warning: could not add FK fk_templates_org (permissions)\n"; else echo "Warning: add FK fk_templates_org failed (".$fkOrg->getMessage().")\n"; }
      }
    }
    // Ensure id and org_id use BIGINT UNSIGNED (legacy might be INT)
    $idType = columnType($pdo,'templates','id');
    if($idType && stripos($idType['COLUMN_TYPE'],'bigint unsigned') === false){
      try { $pdo->exec("ALTER TABLE templates MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT"); echo "Adjusted templates.id to BIGINT UNSIGNED\n"; }
      catch(Exception $eAdj){ echo "Warning: could not modify templates.id (".$eAdj->getMessage().")\n"; }
    }
    $orgType = columnType($pdo,'templates','org_id');
    if($orgType && stripos($orgType['COLUMN_TYPE'],'bigint unsigned') === false){
      try { $pdo->exec("ALTER TABLE templates MODIFY org_id BIGINT UNSIGNED"); echo "Adjusted templates.org_id to BIGINT UNSIGNED\n"; }
      catch(Exception $eAdj2){ echo "Warning: could not modify templates.org_id (".$eAdj2->getMessage().")\n"; }
    }
  }
  // 2. tokens.template_id
  if(!columnExists($pdo,'tokens','template_id')){
    // Use simple ADD COLUMN without AFTER for wider MySQL compatibility
    $pdo->exec("ALTER TABLE tokens ADD COLUMN template_id BIGINT UNSIGNED NULL");
    echo "Added column tokens.template_id\n";
  } else {
    echo "Column exists: tokens.template_id (skipped)\n";
  }
  if(!indexExists($pdo,'tokens','idx_template_id')){
    $pdo->exec("CREATE INDEX idx_template_id ON tokens(template_id)");
    echo "Created index idx_template_id on tokens\n";
  } else {
    echo "Index exists: idx_template_id (skipped)\n";
  }
  if(!fkExists($pdo,'tokens','template_id','templates')){
    // Check column type compatibility first
    $tplId = columnType($pdo,'templates','id');
    $tokTplId = columnType($pdo,'tokens','template_id');
    $compatible = $tplId && $tokTplId && stripos($tplId['COLUMN_TYPE'],'bigint unsigned') !== false && stripos($tokTplId['COLUMN_TYPE'],'bigint unsigned') !== false;
    if(!$compatible){
      echo "Warning: Skipping FK fk_tokens_template (incompatible column types: templates.id={$tplId['COLUMN_TYPE']} tokens.template_id={$tokTplId['COLUMN_TYPE']})\n";
    } else {
      try {
        $pdo->exec("ALTER TABLE tokens ADD CONSTRAINT fk_tokens_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL");
        echo "Added FK fk_tokens_template\n";
      } catch(Exception $fkE){
        $msg = $fkE->getMessage();
        if(stripos($msg,'denied') !== false || stripos($msg,'1142') !== false){
          echo "Warning: Could not add FK fk_tokens_template (permissions). Proceeding without FK. Message: $msg\n";
        } else {
          echo "Warning: Failed adding FK fk_tokens_template ($msg)\n"; // do not abort full migration
        }
      }
    }
  } else {
    echo "FK exists for tokens.template_id -> templates (skipped)\n";
  }

  // 3. Award title support
  if(columnExists($pdo,'templates','award_title')){
    echo "Column exists: templates.award_title (skipped)\n";
  } else {
    $pdo->exec("ALTER TABLE templates ADD COLUMN award_title VARCHAR(160) NOT NULL DEFAULT 'Нагорода'");
    echo "Added column templates.award_title\n";
  }
  if(columnExists($pdo,'tokens','award_title')){
    echo "Column exists: tokens.award_title (skipped)\n";
  } else {
    $pdo->exec("ALTER TABLE tokens ADD COLUMN award_title VARCHAR(160) NOT NULL DEFAULT 'Нагорода'");
    echo "Added column tokens.award_title\n";
  }
  if(columnExists($pdo,'tokens','template_id') && columnExists($pdo,'tokens','award_title') && columnExists($pdo,'templates','award_title')){
    $rows = $pdo->exec("UPDATE tokens t JOIN templates tpl ON tpl.id = t.template_id SET t.award_title = tpl.award_title WHERE t.award_title = 'Нагорода' AND tpl.award_title <> 'Нагорода'");
    if($rows === false){
      echo "Warning: award_title backfill query failed\n";
    } elseif($rows > 0){
      echo "Backfilled tokens.award_title from templates ({$rows} rows)\n";
    } else {
      echo "No award_title backfill required\n";
    }
  } else {
    echo "Skipping award_title backfill (dependencies missing)\n";
  }

  if($pdo->inTransaction()){
    $pdo->commit();
  } else {
    echo "Note: transaction already closed earlier.\n";
  }
  echo "Migration complete.\n";
} catch(Exception $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, "Migration failed: ".$e->getMessage()."\n");
  // Basic diagnostics: list existing columns for tokens & presence of templates table
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM tokens")->fetchAll(PDO::FETCH_COLUMN);
    fwrite(STDERR, "Tokens columns: ".implode(',', $cols)."\n");
  } catch(Exception $ie) {
    fwrite(STDERR, "Could not list tokens columns: ".$ie->getMessage()."\n");
  }
  exit(1);
}
