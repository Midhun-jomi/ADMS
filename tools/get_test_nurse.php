<?php
require_once __DIR__ . '/../includes/db.php';

// Find a nurse
$nurse = db_select_one("SELECT * FROM users WHERE role = 'nurse' LIMIT 1");

if ($nurse) {
    // Reset password to 'password123'
    $new_hash = password_hash('password123', PASSWORD_DEFAULT);
    db_query("UPDATE users SET password_hash = $1 WHERE id = $2", [$new_hash, $nurse['id']]);
    
    echo "Nurse Found:\n";
    echo "Email: " . $nurse['email'] . "\n";
    echo "Password: password123\n";
} else {
    // Create new nurse
    $email = 'nurse_test@hospital.com';
    $hash = password_hash('password123', PASSWORD_DEFAULT);
    
    // Insert into users
    $user_id = db_insert('users', [
        'email' => $email,
        'password_hash' => $hash,
        'role' => 'nurse'
    ]);
    
    // Insert into staff
    db_insert('staff', [
        'user_id' => $user_id,
        'first_name' => 'Florence',
        'last_name' => 'Nightingale',
        'role' => 'nurse',
        'email' => $email,
        'phone' => '1234567890',
        'status' => 'active'
    ]);
    
    echo "New Nurse Created:\n";
    echo "Email: " . $email . "\n";
    echo "Password: password123\n";
}
?>
