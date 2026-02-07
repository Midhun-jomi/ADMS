<?php
require_once 'includes/db.php';

echo "Adding token_number column to appointments table...\n";

try {
    // 1. Add Column
    db_query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS token_number VARCHAR(10)");
    echo "Column added successfully.\n";

    // 2. Add Index
    db_query("CREATE INDEX IF NOT EXISTS idx_appointments_token ON appointments(token_number)");
    echo "Index created successfully.\n";
    
    // 3. Backfill existing appointments (Optional, but good for testing)
    // Simple logic: Assign arbitrary tokens T001, T002 based on ID for today's appts
    $today = date('Y-m-d');
    $rows = db_select("SELECT id FROM appointments WHERE appointment_time::date = '$today' ORDER BY appointment_time ASC");
    $count = 1;
    foreach($rows as $r) {
        $token = 'T' . str_pad($count, 3, '0', STR_PAD_LEFT);
        db_query("UPDATE appointments SET token_number = $1 WHERE id = $2", [$token, $r['id']]);
        $count++;
    }
    echo "Backfilled $count appointments for today.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
