<?php
// tools/create_test_users.php — Admin-only tool
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_session.php';
check_role(['admin']);

echo "--- Creating Test Users for Verification ---\n";

function createOrUpdateUser($email, $password, $role, $meta) {
    global $conn;
    
    // Check if user exists
    $existing = db_select_one("SELECT id FROM users WHERE email = $1", [$email]);
    $userId = null;
    
    if ($existing) {
        $userId = $existing['id'];
        echo "User {$email} already exists. Updating password...\n";
        // Update password
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db_query("UPDATE users SET password_hash = $1 WHERE id = $2", [$hash, $userId]);
    } else {
        echo "Creating new user {$email}...\n";
        $hash = password_hash($password, PASSWORD_BCRYPT);
        // Insert into users
        $res = pg_query_params($conn, 
            "INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id",
            [$email, $hash, $role]
        );
        if (!$res) throw new Exception(pg_last_error($conn));
        $row = pg_fetch_assoc($res);
        $userId = $row['id'];
    }

    // Role specific data
    if ($role === 'doctor') {
        // Check if staff record exists
        $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$userId]);
        if (!$staff) {
            echo "Creating Staff profile for Doctor...\n";
            db_insert('staff', [
                'user_id' => $userId,
                'first_name' => $meta['first_name'],
                'last_name' => $meta['last_name'],
                'role' => 'doctor',
                'specialization' => 'General Medicine',
                'department' => 'General',
                'phone' => '555-0101'
            ]);
        }
    } elseif ($role === 'patient') {
        // Check if patient record exists
        $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$userId]);
        if (!$patient) {
            echo "Creating Patient profile...\n";
            db_insert('patients', [
                'user_id' => $userId,
                'first_name' => $meta['first_name'],
                'last_name' => $meta['last_name'],
                'date_of_birth' => '1990-01-01',
                'gender' => 'Other',
                'phone' => '555-0102',
                'address' => '123 Test Lane'
            ]);
        }
    }
    
    echo "✓ User {$email} ready.\n";
    return $userId;
}

try {
    // 1. Create Test Doctor
    createOrUpdateUser(
        'test_doctor@hospital.com', 
        'Test@123', 
        'doctor', 
        ['first_name' => 'Test', 'last_name' => 'Doctor']
    );

    // 2. Create Test Patient
    createOrUpdateUser(
        'test_patient@hospital.com', 
        'Test@123', 
        'patient', 
        ['first_name' => 'Test', 'last_name' => 'Patient']
    );

    echo "\nVerification Users Setup Complete.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
