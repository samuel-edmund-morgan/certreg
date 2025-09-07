<?php
// Migration 008: add valid_until DATE column for expiry (canonical v2)
require_once __DIR__.'/../db.php';
$sentinel = '4000-01-01';
try {
    $pdo->exec("ALTER TABLE tokens ADD COLUMN valid_until DATE NULL AFTER issued_date");
    // Backfill existing rows as infinite
    $stmt = $pdo->prepare("UPDATE tokens SET valid_until=? WHERE valid_until IS NULL");
    $stmt->execute([$sentinel]);
    echo "Migration 008: valid_until added and backfilled to $sentinel\n";
} catch (Throwable $e) {
    echo "Migration 008 notice: ".$e->getMessage()."\n";
}
