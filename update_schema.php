<?php
require_once 'includes/db.php';

try {
    // Add type column
    db_query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'info'");
    
    // Check if title allows nulls or defaults? 
    // Just in case, let's make sure we provide it in our code.
    
    echo "Schema updated successfully: Added 'type' column.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
