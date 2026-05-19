<?php
// api/search_patients.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_auth();

$q = $_GET['q'] ?? '';
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Search by name or UHID, and check for today's appointment or current admission
$sql = "SELECT p.id, p.first_name, p.last_name, p.uhid,
               (SELECT id FROM appointments a WHERE a.patient_id = p.id AND a.appointment_time::date = CURRENT_DATE AND a.status != 'cancelled' LIMIT 1) as appointment_id,
               (SELECT r.room_number FROM admissions adm JOIN rooms r ON adm.room_id = r.id WHERE adm.patient_id = p.id AND adm.status = 'admitted' LIMIT 1) as room_number
        FROM patients p 
        WHERE p.first_name ILIKE $1 
           OR p.last_name ILIKE $1 
           OR CAST(p.uhid AS TEXT) ILIKE $1 
        LIMIT 10";

$results = db_select($sql, ["%$q%"]);
header('Content-Type: application/json');
echo json_encode($results ?: []);
?>
