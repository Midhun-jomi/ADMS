<?php
require_once 'includes/db.php';
try {
    db_query("ALTER TABLE blood_inventory ADD COLUMN IF NOT EXISTS donor_id UUID REFERENCES blood_donors(id) ON DELETE SET NULL");
    echo "✅ donor_id column added to blood_inventory.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
