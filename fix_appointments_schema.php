<?php
require_once 'includes/db.php';

echo "Checking 'appointments' table structure...\n";

// Function to check and add column
function check_and_add_column($table, $column, $type) {
    global $pdo; // Assuming $pdo is available from db.php, otherwise we use db_query for everything if db.php abstracts it well.
    // Actually, let's just use raw SQL with db_query to be safe assuming postgres
    
    // Check if column exists
    $check_sql = "SELECT column_name FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column'";
    $exists = db_select($check_sql);
    
    if (empty($exists)) {
        echo "Adding column '$column' to '$table'...\n";
        $sql = "ALTER TABLE $table ADD COLUMN $column $type";
        try {
            db_query($sql);
            echo "Successfully added '$column'.\n";
        } catch (Exception $e) {
            echo "Error adding '$column': " . $e->getMessage() . "\n";
        }
    } else {
        echo "Column '$column' already exists in '$table'.\n";
    }
}

// Add columns if missing
check_and_add_column('appointments', 'consultation_start', 'TIMESTAMP');
check_and_add_column('appointments', 'consultation_end', 'TIMESTAMP');
check_and_add_column('appointments', 'checked_in_at', 'TIMESTAMP');

// Also verify status column and default
check_and_add_column('appointments', 'status', "TEXT DEFAULT 'waiting'");

echo "Database verification complete.\n";
?>
