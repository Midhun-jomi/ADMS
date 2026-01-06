<?php
require 'includes/db.php';

echo "Applying migration 008_update_rooms_table.sql...\n";

$sql = file_get_contents('database/migrations/008_update_rooms_table.sql');

if (!$sql) {
    die("❌ Error: Could not read migration file.\n");
}

try {
    db_query($sql);
    echo "✅ Migration applied successfully!\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
?>
