<?php
// /Users/mj/Downloads/Project_Main/ADMS/ADMS/database/apply_migration_014_password_reset.php
require_once __DIR__ . '/../includes/db.php';

echo "Applying migration 014: Add password reset fields...\n";

try {
    // 1. Add reset_token column if it doesn't exist
    $sql1 = "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255);";
    db_query($sql1);
    
    // 2. Add reset_expires column if it doesn't exist
    $sql2 = "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires TIMESTAMP WITH TIME ZONE;";
    db_query($sql2);

    echo "Migration 014 applied successfully!\n";
} catch (Exception $e) {
    echo "Error applying migration: " . $e->getMessage() . "\n";
}
?>
