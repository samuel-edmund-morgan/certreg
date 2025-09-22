<?php
// Idempotent migration: add tokens.template_id (if missing), index, and optional FK to templates.id
// Usage: php scripts/migrations/2025_09_22_add_tokens_template_id.php

require __DIR__.'/../../db.php';

function column_exists(PDO $pdo,$table,$col){$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->execute([$table,$col]);return (bool)$s->fetchColumn();}
function index_exists(PDO $pdo,$table,$idx){$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");$s->execute([$table,$idx]);return (bool)$s->fetchColumn();}
function fk_exists(PDO $pdo,$table,$col,$ref){$s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=?");$s->execute([$table,$col,$ref]);return (bool)$s->fetchColumn();}
function column_type(PDO $pdo,$table,$col){$s=$pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->execute([$table,$col]);return $s->fetchColumn();}

try {
  if(!column_exists($pdo,'tokens','template_id')){
    $pdo->exec("ALTER TABLE tokens ADD COLUMN template_id BIGINT UNSIGNED NULL");
    echo "Added column tokens.template_id\n";
  } else { echo "tokens.template_id exists (skip)\n"; }
  if(!index_exists($pdo,'tokens','idx_template_id')){
    $pdo->exec("CREATE INDEX idx_template_id ON tokens(template_id)");
    echo "Created index idx_template_id\n";
  } else { echo "Index idx_template_id exists (skip)\n"; }
  if(!fk_exists($pdo,'tokens','template_id','templates')){
    $tplType = column_type($pdo,'templates','id');
    $tokType = column_type($pdo,'tokens','template_id');
    if($tplType && $tokType && stripos($tplType,'bigint')!==false && stripos($tokType,'bigint')!==false){
      try {
        $pdo->exec("ALTER TABLE tokens ADD CONSTRAINT fk_tokens_template FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL");
        echo "Added FK fk_tokens_template\n";
      } catch(Throwable $e){ echo "Warning: cannot add FK (".$e->getMessage().")\n"; }
    } else {
      echo "Warning: type mismatch prevents FK (templates.id=$tplType tokens.template_id=$tokType)\n";
    }
  } else { echo "FK fk_tokens_template exists (skip)\n"; }
  echo "Migration done.\n";
} catch(Throwable $e){
  fwrite(STDERR, "Migration failed: ".$e->getMessage()."\n");
  exit(1);
}
