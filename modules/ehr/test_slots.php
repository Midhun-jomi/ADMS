<?php
// modules/ehr/test_slots.php
require_once __DIR__ . '/../../includes/db.php';

// Fetch a doctor to get a valid ID
$doctor = db_select_one("SELECT id FROM staff WHERE role = 'doctor' LIMIT 1");
if (!$doctor) {
    die("No doctors found.\n");
}
$doctor_id = $doctor['id'];
$date = date('Y-m-d'); // Use today

echo "Testing query for Doctor $doctor_id on $date...\n";

try {
    $sql = "SELECT appointment_time FROM appointments 
            WHERE doctor_id = $1 
            AND appointment_time::date = $2
            AND status != 'cancelled'";
            
    $result = db_select($sql, [$doctor_id, $date]);
    
    echo "Query successful. Rows found: " . count($result) . "\n";
    print_r($result);
    
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
}
?>
