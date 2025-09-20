<?php
// Migration script: run via CLI `php scripts/001_add_roles_and_settings.php`
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__.'/../db.php';

try {
    $pdo->beginTransaction();

    echo "Starting migration: Adding roles and settings...\n";

    // 1. Add 'role' column to 'creds' table
    $stmt = $pdo->query("SHOW COLUMNS FROM `creds` LIKE 'role'");
    if ($stmt->rowCount() == 0) {
        echo "Altering 'creds' table to add 'role' column...\n";
        $pdo->exec("ALTER TABLE `creds` ADD COLUMN `role` ENUM('admin', 'operator') NOT NULL DEFAULT 'operator' AFTER `passhash`;");
        echo "'creds' table altered successfully.\n";

        // Set the first user as 'admin'
        echo "Setting first user as admin...\n";
        $updateStmt = $pdo->exec("UPDATE `creds` SET `role` = 'admin' ORDER BY `id` ASC LIMIT 1");
        if ($updateStmt > 0) {
            echo "First user is now an administrator.\n";
        } else {
            echo "No users found in 'creds' table to promote to admin.\n";
        }
    } else {
        echo "'role' column already exists in 'creds' table. Skipping.\n";
    }


    // 2. Create 'templates' table
    echo "Creating 'templates' table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `templates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `coordinates` JSON DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT NULL,
            `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
            INDEX `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "'templates' table created or already exists.\n";

    // 3. Create 'branding_settings' table
    echo "Creating 'branding_settings' table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `branding_settings` (
            `setting_key` VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "'branding_settings' table created or already exists.\n";

    $pdo->commit();
    echo "\nMigration completed successfully!\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nMigration failed: " . $e->getMessage() . "\n";
    exit(1);
}
