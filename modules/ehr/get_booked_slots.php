<?php
// modules/ehr/get_booked_slots.php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_session.php';

error_reporting(0); // Suppress warnings to avoid breaking JSON
ini_set('display_errors', 0);

header('Content-Type: application/json');

if (!isset($_GET['doctor_id']) || !isset($_GET['date'])) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$doctor_id = $_GET['doctor_id'];
$date = $_GET['date'];

try {
    // Check if doctor has an APPROVED unavailability on this date
    $av = db_select_one(
        "SELECT is_available, unavailability_type FROM doctor_availability
         WHERE doctor_id = $1 AND available_date = $2 AND approval_status = 'approved'",
        [$doctor_id, $date]
    );

    $doctor_unavailable  = false;
    $unavailability_type = '';
    if ($av) {
        $is_available = ($av['is_available'] === 't' || $av['is_available'] === true);
        if (!$is_available) {
            $doctor_unavailable  = true;
            $unavailability_type = $av['unavailability_type'] ?? 'leave';
        }
    }

    // Fetch booked appointment slots
    $result = db_select(
        "SELECT appointment_time FROM appointments
         WHERE doctor_id = $1 AND appointment_time::date = $2 AND status != 'cancelled'",
        [$doctor_id, $date]
    );

    $booked_slots = [];
    foreach ($result as $row) {
        $booked_slots[] = date('H:i', strtotime($row['appointment_time']));
    }

    echo json_encode([
        'booked_slots'        => $booked_slots,
        'doctor_unavailable'  => $doctor_unavailable,
        'unavailability_type' => $unavailability_type,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred.']);
}
?>
