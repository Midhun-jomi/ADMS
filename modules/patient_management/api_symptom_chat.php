<?php
// modules/patient_management/api_symptom_chat.php
header('Content-Type: application/json');

// Get JSON input
$input = file_get_contents('php://input');

$url = 'http://127.0.0.1:5001/symptom_chat';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($input)
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(503);
    echo json_encode(['error' => 'AI Service Unavailable', 'details' => curl_error($ch)]);
} else {
    echo $response;
}
curl_close($ch);
?>
