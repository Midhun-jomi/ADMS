<?php
// modules/ehr/get_ai_recommendation.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Allow empty reason if vitals are provided (for pure vital-based triage)
    $reason = $input['reason'] ?? '';
    $history = $input['history'] ?? '';
    $vitals = $input['vitals'] ?? null; // Expecting string or array

    if (empty($reason) && empty($vitals) && empty($history)) {
        echo json_encode(['error' => 'No data provided for analysis']);
        exit;
    }

    // Call Python AI Service using curl (more reliable than file_get_contents on macOS)
    $url = 'http://127.0.0.1:5001/predict';
    $data = [
        'symptoms' => $reason, 
        'history' => $history, 
        'vitals' => $vitals ?: new stdClass() // send {} not [] for empty
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $http_code === 0) {
        echo json_encode(['error' => 'AI Service unavailable. Please ensure the AI server is running.']);
    } else {
        echo $result;
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>
