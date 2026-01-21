<?php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = get_user_id();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'send_message':
        $recipient_id = $_POST['recipient_id'] ?? 0;
        $message_body = trim($_POST['message'] ?? '');
        $related_appt = $_POST['appointment_id'] ?? null;

        if (empty($recipient_id) || empty($message_body)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing recipient or message']);
            exit;
        }

        $sql = "INSERT INTO messages (sender_id, recipient_id, message_body, related_appointment_id) VALUES ($1, $2, $3, $4)";
        $res = db_query($sql, [$user_id, $recipient_id, $message_body, $related_appt]);

        if ($res) {
            echo json_encode(['status' => 'success']);
            
            // Send SMS Notification
            // 1. Get recipient phone number
            // Check 'patients' first, then 'staff' (users table might store it too, but let's check profile tables)
            $phone = '';
            
            // Try Patient
            $p = db_select_one("SELECT phone_number FROM patients WHERE user_id = $1", [$recipient_id]);
            if ($p) {
                $phone = $p['phone_number'];
            } else {
                // Try Staff
                $s = db_select_one("SELECT phone_number FROM staff WHERE user_id = $1", [$recipient_id]);
                if ($s) {
                    $phone = $s['phone_number'];
                }
            }
            
            if (!empty($phone)) {
                require_once '../../includes/sms_service.php';
                // Customize message: "New message from [Sender Name]: [Body]"
                // Fetch sender name
                $sender_name = "ADMS User";
                $sender_p = db_select_one("SELECT first_name, last_name FROM patients WHERE user_id = $1", [$user_id]);
                if ($sender_p) $sender_name = $sender_p['first_name'] . ' ' . $sender_p['last_name'];
                else {
                    $sender_s = db_select_one("SELECT first_name, last_name FROM staff WHERE user_id = $1", [$user_id]);
                    if ($sender_s) $sender_name = "Dr. " . $sender_s['last_name'];
                }
                
                $sms_body = "New message from $sender_name: " . substr($message_body, 0, 50) . (strlen($message_body)>50 ? '...' : '');
                
                // Fire and forget (or log)
                SMSService::send($phone, $sms_body);
            }
            
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send']);
        }
        break;

    case 'get_conversations':
        // Get list of users who have exchanged messages with current user, ordered by latest message
        // This is complex in SQL. Simplified: Get distinct users from messages where sender or recipient is current user.
        // For Doctors: List Patients. For Patients: List Doctors.
        
        $role = $_SESSION['role'] ?? '';
        
        if ($role == 'doctor') {
            // Get patients who have messaged or been messaged
            // SELECT DISTINCT user_id ... is tricky. 
            // Let's get all messages involving this user, order by date desc
            $sql = "SELECT m.*, 
                           CASE WHEN m.sender_id = $1 THEN m.recipient_id ELSE m.sender_id END as other_user_id,
                           u.username, u.profile_image, 
                           p.first_name, p.last_name
                    FROM messages m
                    JOIN users u ON (CASE WHEN m.sender_id = $1 THEN m.recipient_id ELSE m.sender_id END) = u.id
                    LEFT JOIN patients p ON u.id = p.user_id
                    WHERE m.sender_id = $1 OR m.recipient_id = $1
                    ORDER BY m.created_at DESC";
            
            $messages = db_select($sql, [$user_id]);
            
            // Group by conversation
            $conversations = [];
            foreach ($messages as $msg) {
                $other_id = $msg['other_user_id'];
                if (!isset($conversations[$other_id])) {
                    $conversations[$other_id] = [
                        'user_id' => $other_id,
                        'name' => ($msg['first_name'] ? $msg['first_name'] . ' ' . $msg['last_name'] : $msg['username']),
                        'avatar' => $msg['profile_image'],
                        'last_message' => $msg['message_body'],
                        'time' => $msg['created_at'],
                        'unread_count' => 0
                    ];
                }
                
                if ($msg['recipient_id'] == $user_id && !$msg['is_read']) {
                    $conversations[$other_id]['unread_count']++;
                }
            }
            
            echo json_encode(['status' => 'success', 'data' => array_values($conversations)]);
            
        } else {
            // Patient view - similar but join with staff table if needed, or just users
             $sql = "SELECT m.*, 
                           CASE WHEN m.sender_id = $1 THEN m.recipient_id ELSE m.sender_id END as other_user_id,
                           u.username, u.profile_image,
                           s.first_name, s.last_name
                    FROM messages m
                    JOIN users u ON (CASE WHEN m.sender_id = $1 THEN m.recipient_id ELSE m.sender_id END) = u.id
                    LEFT JOIN staff s ON u.id = s.user_id
                    WHERE m.sender_id = $1 OR m.recipient_id = $1
                    ORDER BY m.created_at DESC";
            
            $messages = db_select($sql, [$user_id]);
            
            // Group by conversation (likely only 1 for patient usually, but good to handle multiple)
             $conversations = [];
            foreach ($messages as $msg) {
                $other_id = $msg['other_user_id'];
                if (!isset($conversations[$other_id])) {
                    $conversations[$other_id] = [
                        'user_id' => $other_id,
                        'name' => ($msg['first_name'] ? 'Dr. '.$msg['first_name'] . ' ' . $msg['last_name'] : $msg['username']),
                        'avatar' => $msg['profile_image'],
                        'last_message' => $msg['message_body'],
                        'time' => $msg['created_at'],
                        'unread_count' => 0
                    ];
                }
                if ($msg['recipient_id'] == $user_id && !$msg['is_read']) {
                    $conversations[$other_id]['unread_count']++;
                }
            }
             echo json_encode(['status' => 'success', 'data' => array_values($conversations)]);
        }
        break;

    case 'get_thread':
        $other_user_id = $_GET['user_id'] ?? 0;
        if (!$other_user_id) {
             echo json_encode(['status' => 'error', 'message' => 'User ID required']);
             exit;
        }
        
        // Mark as read
        db_query("UPDATE messages SET is_read = TRUE WHERE recipient_id = $1 AND sender_id = $2", [$user_id, $other_user_id]);

        $sql = "SELECT * FROM messages 
                WHERE (sender_id = $1 AND recipient_id = $2) 
                   OR (sender_id = $2 AND recipient_id = $1) 
                ORDER BY created_at ASC";
        
        $messages = db_select($sql, [$user_id, $other_user_id]);
        echo json_encode(['status' => 'success', 'data' => $messages]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        break;
}
?>
