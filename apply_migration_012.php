<?php
// apply_migration_012.php
require_once 'includes/db.php';

echo "Applying migration 012...\n";

$sql = file_get_contents('database/migrations/012_add_medical_history.sql');

try {
    db_query($sql);
    echo "SUCCESS: Migration 012 applied.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
