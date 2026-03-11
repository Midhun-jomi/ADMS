<?php
// modules/patient_management/api_suggest_doctors.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']);
header('Content-Type: application/json');

$spec = trim($_GET['specialization'] ?? '');
if (!$spec) {
    echo json_encode(['doctors' => []]);
    exit;
}

// Fetch doctors in the given specialization with their today's appointment count (ascending)
$doctors = db_select(
    "SELECT s.id, s.first_name, s.last_name, s.specialization,
            COUNT(a.id) AS appt_count
     FROM staff s
     LEFT JOIN appointments a
        ON a.doctor_id = s.id
        AND DATE(a.appointment_time) = CURRENT_DATE
        AND a.status NOT IN ('cancelled', 'completed')
     WHERE s.role = 'doctor'
       AND s.specialization ILIKE $1
     GROUP BY s.id, s.first_name, s.last_name, s.specialization
     ORDER BY appt_count ASC
     LIMIT 3",
    ["%$spec%"]
);

echo json_encode(['doctors' => $doctors ?: []]);
