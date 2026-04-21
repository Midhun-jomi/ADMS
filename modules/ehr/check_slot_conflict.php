<?php
// modules/ehr/check_slot_conflict.php — AJAX: check if patient has a conflict at the chosen time
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_session.php';

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

check_role(['patient']);

$date      = $_GET['date']      ?? '';
$time      = $_GET['time']      ?? '';
$doctor_id = $_GET['doctor_id'] ?? '';

if (!$date || !$time || !$doctor_id) {
    echo json_encode(['conflict' => false]);
    exit;
}

$appointment_time = $date . ' ' . $time;
$user_id          = $_SESSION['user_id'];

$patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
if (!$patient) {
    echo json_encode(['conflict' => false]);
    exit;
}

// Check if patient already has ANY appointment at this exact time with a DIFFERENT doctor
$conflict = db_select_one(
    "SELECT id FROM appointments
     WHERE patient_id = $1
       AND appointment_time = $2
       AND doctor_id != $3
       AND status = 'scheduled'",
    [$patient['id'], $appointment_time, $doctor_id]
);

echo json_encode(['conflict' => (bool)$conflict]);
