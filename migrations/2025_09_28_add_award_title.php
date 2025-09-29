<?php
// Migration: add award_title support to templates and tokens tables.
// Run via: php migrations/2025_09_28_add_award_title.php

require_once __DIR__.'/../db.php';

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

echo "[INFO] Starting award_title migration...\n";

try {
    $pdo->beginTransaction();

    // 1. templates.award_title
    if (!tableHasColumn($pdo, 'templates', 'award_title')) {
        $pdo->exec("ALTER TABLE templates ADD COLUMN award_title VARCHAR(160) NOT NULL DEFAULT 'Нагорода'");
        echo "[OK] Added templates.award_title column.\n";
    } else {
        echo "[INFO] templates.award_title already exists.\n";
    }

    // 2. tokens.award_title
    if (!tableHasColumn($pdo, 'tokens', 'award_title')) {
        $pdo->exec("ALTER TABLE tokens ADD COLUMN award_title VARCHAR(160) NOT NULL DEFAULT 'Нагорода'");
        echo "[OK] Added tokens.award_title column.\n";
    } else {
        echo "[INFO] tokens.award_title already exists.\n";
    }

    // Backfill tokens award_title from templates if possible
    if (tableHasColumn($pdo, 'tokens', 'template_id')) {
        $stmt = $pdo->prepare("UPDATE tokens t JOIN templates tpl ON tpl.id = t.template_id SET t.award_title = tpl.award_title WHERE t.award_title = 'Нагорода' AND tpl.award_title <> 'Нагорода'");
        $stmt->execute();
        $rows = $stmt->rowCount();
        if ($rows > 0) {
            echo "[OK] Backfilled $rows tokens.award_title values from templates.\n";
        } else {
            echo "[INFO] No tokens required award_title backfill.\n";
        }
    } else {
        echo "[INFO] tokens.template_id column missing; skipped backfill step.\n";
    }

    $pdo->commit();
    echo "[DONE] Migration completed successfully.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "[FAIL] Migration error: ".$e->getMessage()."\n";
    exit(1);
}
