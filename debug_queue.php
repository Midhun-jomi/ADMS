<?php
// debug_queue.php — Admin-only debug tool
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_role(['admin']);

$today = date('Y-m-d');

echo "<h2>Server Time</h2>";
echo "Date: " . date('Y-m-d') . "<br>";
echo "Time: " . date('H:i:s') . "<br>";
echo "Timezone: " . date_default_timezone_get() . "<br>";

echo "<h2>Appointments Today ($today)</h2>";
// FIX: Parameterized query (was SQL injection risk)
$apps = db_select("SELECT a.id, a.status, a.appointment_time, s.first_name as dr_first, p.first_name as patient_first
                   FROM appointments a
                   JOIN staff s ON a.doctor_id = s.id
                   JOIN patients p ON a.patient_id = p.id
                   WHERE a.appointment_time::date = $1", [$today]);
echo "<pre>";
print_r($apps);
echo "</pre>";

echo "<h2>All Active Doctors</h2>";
$docs = db_select("SELECT id, first_name, last_name, role FROM staff WHERE role = 'doctor'");
echo "<pre>";
print_r($docs);
echo "</pre>";

echo "<h2>Queue Query Test</h2>";
// FIX: Parameterized query (was SQL injection risk)
$doctors_data = db_select("
    SELECT
        s.first_name,
        s.last_name,
        COUNT(CASE WHEN a.status IN ('scheduled', 'confirmed') THEN 1 END) as waiting_count,
        MAX(CASE WHEN a.status = 'in_progress' THEN p.first_name END) as current_patient
    FROM staff s
    JOIN appointments a ON s.id = a.doctor_id
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE a.appointment_time::date = $1 AND s.role = 'doctor'
    GROUP BY s.id, s.first_name, s.last_name
    HAVING COUNT(a.id) > 0
", [$today]);
echo "<pre>";
print_r($doctors_data);
echo "</pre>";
?>
