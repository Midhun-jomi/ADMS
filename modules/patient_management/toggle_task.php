<?php
// modules/patient_management/toggle_task.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$task_id = $_POST['task_id'] ?? null;
$status = $_POST['status'] ?? 'false'; // string 'true' or 'false'

// Postgres boolean accepts 't'/'f', 'true'/'false', 1/0. 
// Let's ensure strict boolean mapping for safety
$bool_val = ($status === 'true') ? 't' : 'f';

if ($task_id) {
    try {
        db_query("UPDATE nurse_tasks SET completed = $1, updated_at = NOW() WHERE id = $2", [$bool_val, $task_id]);
        
        // If task is "Patient prepared", verify others? 
        // For now simple toggle.
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Missing ID']);
}
?>
