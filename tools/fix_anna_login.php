<?php
require_once __DIR__ . '/../includes/db.php';

$email = 'anna@gmail.com';
$user = db_select_one("SELECT * FROM users WHERE email = $1", [$email]);

if ($user) {
    echo "User Found: " . $user['email'] . " (Role: " . $user['role'] . ")\n";
    
    // Reset to 'Staff123!'
    $new_hash = password_hash('Staff123!', PASSWORD_DEFAULT);
    db_query("UPDATE users SET password_hash = $1 WHERE id = $2", [$new_hash, $user['id']]);
    
    // Ensure she is in 'staff' table
    $staff = db_select_one("SELECT * FROM staff WHERE user_id = $1", [$user['id']]);
    if ($staff) {
        echo "Staff Record Found: " . $staff['first_name'] . " " . $staff['last_name'] . "\n";
    } else {
        echo "Staff Record MISSING. Creating one...\n";
        db_insert('staff', [
            'user_id' => $user['id'],
            'first_name' => 'Anna',
            'last_name' => 'Nurse',
            'role' => 'nurse',
            'email' => $email,
            'phone' => '555-0199',
            'status' => 'active'
        ]);
    }
    
    echo "Password reset to: Staff123!\n";
} else {
    echo "User $email NOT FOUND. Creating...\n";
    
    $hash = password_hash('Staff123!', PASSWORD_DEFAULT);
    $uid = db_insert('users', ['email' => $email, 'password_hash' => $hash, 'role' => 'nurse']);
    
    db_insert('staff', [
        'user_id' => $uid,
        'first_name' => 'Anna',
        'last_name' => 'Nurse',
        'role' => 'nurse',
        'email' => $email,
        'phone' => '555-0199',
        'status' => 'active'
    ]);
    
    echo "Created user $email with password Staff123!\n";
}
?>
