<?php
// apply_migration_009.php
require_once 'includes/db.php';

echo "Applying Migration 009 (Smart Nurse Features)...\n";

$sql = file_get_contents(__DIR__ . '/database/migrations/009_smart_nurse_features.sql');

try {
    db_query($sql);
    echo "✅ Migration 009 applied successfully.\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
?>
