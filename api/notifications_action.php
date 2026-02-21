<?php
// api/notifications_action.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_auth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$uid = $_SESSION['user_id'];

switch ($action) {
    case 'mark_read':
        $notif_id = intval($input['id'] ?? 0);
        if ($notif_id > 0) {
            db_query(
                "UPDATE notifications SET is_read = TRUE WHERE id = $1 AND user_id = $2",
                [$notif_id, $uid]
            );
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
        }
        break;

    case 'mark_all_read':
        db_query(
            "UPDATE notifications SET is_read = TRUE WHERE user_id = $1",
            [$uid]
        );
        echo json_encode(['success' => true]);
        break;

    case 'clear_all':
        db_query(
            "DELETE FROM notifications WHERE user_id = $1",
            [$uid]
        );
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
?>
