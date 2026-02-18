<?php
require_once 'includes/db.php';

try {
    $sql = "SELECT id, email, role, created_at FROM users WHERE role = 'doctor' LIMIT 1";
    $user = db_select_one($sql);
    
    if ($user) {
        echo "--- Doctor User Found ---\n";
        echo "ID: " . $user['id'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Role: " . $user['role'] . "\n";
    } else {
        echo "No doctor user found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
