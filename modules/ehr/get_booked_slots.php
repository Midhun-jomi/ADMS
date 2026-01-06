<?php
// modules/ehr/get_booked_slots.php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_session.php';

error_reporting(0); // Suppress warnings to avoid breaking JSON
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Log request for debugging
file_put_contents('debug_slots.log', date('Y-m-d H:i:s') . " Request: " . print_r($_GET, true) . "\n", FILE_APPEND);

if (!isset($_GET['doctor_id']) || !isset($_GET['date'])) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$doctor_id = $_GET['doctor_id'];
$date = $_GET['date'];

try {
    // Fetch all appointments for this doctor on this date
    // We only care about the time part
    $sql = "SELECT appointment_time FROM appointments 
            WHERE doctor_id = $1 
            AND appointment_time::date = $2
            AND status != 'cancelled'";
            
    $result = db_select($sql, [$doctor_id, $date]);
    
    $booked_slots = [];
    foreach ($result as $row) {
        // Extract HH:MM from timestamp
        $booked_slots[] = date('H:i', strtotime($row['appointment_time']));
    }
    
    echo json_encode(['booked_slots' => $booked_slots]);
} catch (Exception $e) {
    file_put_contents('debug_slots.log', $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
