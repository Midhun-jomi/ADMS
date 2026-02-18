<?php
// modules/ehr/get_ai_recommendation.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input = json_decode(file_get_contents('php://input'), true);
    $reason = $input['reason'] ?? '';

    if (empty($reason)) {
        echo json_encode(['error' => 'No reason provided']);
        exit;
    }

    // Call Python AI Service
    $url = 'http://localhost:5001/predict';
    $data = ['symptoms' => $reason, 'history' => '', 'keywords' => ''];

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
            'timeout' => 5 // 5 seconds timeout
        ]
    ];

    $context  = stream_context_create($options);
    
    // Suppress warnings to handle connection errors gracefully
    $result = @file_get_contents($url, false, $context);

    if ($result === FALSE) {
        // AI Service might be down
        echo json_encode(['error' => 'AI Service unavailable. Please ensure the AI server is running.']);
    } else {
        echo $result;
    }
} else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>
