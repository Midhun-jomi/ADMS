<?php
// api_save_fcm_token.php
require_once 'includes/db.php';
require_once 'includes/auth_session.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? $_POST['token'] ?? '';

    if (!empty($token)) {
        $user_id = get_user_id();
        // Update user record
        $sql = "UPDATE users SET fcm_token = $1 WHERE id = $2";
        $res = db_query($sql, [$token, $user_id]);
        
        if ($res) {
            echo json_encode(['status' => 'success', 'message' => 'Token saved']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Token missing']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>
