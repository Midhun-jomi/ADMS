<?php
// apply_migration_010.php
require_once 'includes/db.php';

echo "Applying Migration 010 (Nurse Allocation)...\n";

$sql = file_get_contents(__DIR__ . '/database/migrations/010_nurse_allocation.sql');

try {
    // Split by statement if needed, or execute block
    // pg_query performs multiple statements in one go usually
    db_query($sql);
    echo "✅ Migration 010 applied successfully.\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
?>
