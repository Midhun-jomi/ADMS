<?php
// database/setup_waiting_time.php
require_once '../includes/db.php';

try {
    // 1. Add columns to appointments table
    // Added priority column for Emergency rule
    $alter_sql = "
        ALTER TABLE appointments ADD COLUMN IF NOT EXISTS status TEXT DEFAULT 'waiting';
        ALTER TABLE appointments ADD COLUMN IF NOT EXISTS checked_in_at TIMESTAMP;
        ALTER TABLE appointments ADD COLUMN IF NOT EXISTS consultation_start TIMESTAMP;
        ALTER TABLE appointments ADD COLUMN IF NOT EXISTS consultation_end TIMESTAMP;
        ALTER TABLE appointments ADD COLUMN IF NOT EXISTS priority TEXT DEFAULT 'normal';
    ";
    db_query($alter_sql);
    echo "Appointments table updated.<br>";

    // 2. Create doctor_stats table
    $create_sql = "
        CREATE TABLE IF NOT EXISTS doctor_stats (
            doctor_id BIGINT PRIMARY KEY,
            avg_consult_time INTEGER DEFAULT 10
        );
    ";
    db_query($create_sql);
    echo "Doctor_stats table created.<br>";

    echo "Database setup completed successfully.";

} catch (Exception $e) {
    echo "Error setting up database: " . $e->getMessage();
}
?>
