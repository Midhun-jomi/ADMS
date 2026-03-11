<?php
// tools/list_users.php — Admin-only tool
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['admin']);

try {
    $sql = "SELECT id, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 5";
    $users = db_select($sql);
    
    echo "--- Users found ---\n";
    foreach ($users as $user) {
        echo "ID: " . $user['id'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Role: " . $user['role'] . "\n";
        echo "-------------------\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
