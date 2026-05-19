<?php
// modules/ehr/get_ai_recommendation.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $reason = $input['reason'] ?? '';
    $history = $input['history'] ?? '';
    $vitals = $input['vitals'] ?? null;
    $selected_date = $input['date'] ?? date('Y-m-d'); // Allow passing date from frontend

    if (empty($reason) && empty($vitals) && empty($history)) {
        echo json_encode(['error' => 'No data provided for analysis']);
        exit;
    }

    // Call Python AI Service
    $url = 'http://127.0.0.1:5001/predict';
    $data = [
        'symptoms' => $reason, 
        'history' => $history, 
        'vitals' => $vitals ?: new stdClass()
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $http_code === 0) {
        echo json_encode(['error' => 'AI Service unavailable.']);
        exit;
    }

    $ai_data = json_decode($result, true);
    $specialization = $ai_data['specialization'] ?? 'General Medicine';

    // --- DOCTOR RECOMMENDATION LOGIC ---
    $recommended_doctor = null;
    $rec_reason = "";

    $user_id = $_SESSION['user_id'];
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);

    if ($patient) {
        // Priority 1: Previously consulted doctor in this specialization
        $prev_doc = db_select_one("
            SELECT d.id, d.first_name, d.last_name 
            FROM staff d
            JOIN appointments a ON d.id = a.doctor_id
            WHERE a.patient_id = $1 AND d.specialization = $2 AND d.role = 'doctor'
            ORDER BY a.appointment_time DESC LIMIT 1
        ", [$patient['id'], $specialization]);

        if ($prev_doc) {
            $recommended_doctor = $prev_doc;
            $rec_reason = "You have consulted Dr. {$prev_doc['last_name']} before for similar specialization.";
        }
    }

    // Priority 2: Doctor who is free (available and has fewest appointments on selected date)
    if (!$recommended_doctor) {
        $free_doc = db_select_one("
            SELECT d.id, d.first_name, d.last_name, COUNT(a.id) as appt_count
            FROM staff d
            LEFT JOIN appointments a ON d.id = a.doctor_id AND DATE(a.appointment_time) = $2
            WHERE d.specialization = $1 AND d.role = 'doctor'
            AND d.id NOT IN (
                SELECT doctor_id FROM doctor_availability 
                WHERE available_date = $2 AND is_available = false AND approval_status = 'approved'
            )
            GROUP BY d.id, d.first_name, d.last_name
            ORDER BY appt_count ASC LIMIT 1
        ", [$specialization, $selected_date]);

        if ($free_doc) {
            $recommended_doctor = $free_doc;
            $rec_reason = "Dr. {$free_doc['last_name']} is currently available and highly recommended for your symptoms.";
        }
    }

    $ai_data['recommended_doctor'] = $recommended_doctor;
    $ai_data['recommendation_reason'] = $rec_reason;

    echo json_encode($ai_data);

} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>
