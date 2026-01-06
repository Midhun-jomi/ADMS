<?php
// tools/test_workflow_ehr.php
define('ROOT_PATH', __DIR__ . '/..');
require_once ROOT_PATH . '/includes/db.php';

echo "🏥 Running EHR Workflow Test (Patient -> Appointment -> Prescription)...\n";

$errors = [];
$startTime = microtime(true);

try {
    // 1. Create Dummy Patient
    $patientData = [
        'first_name' => 'TestPatient_' . uniqid(),
        'last_name' => 'Workflow',
        'date_of_birth' => '1990-01-01',
        'gender' => 'Male',
        'phone' => '1234567890',
        'email' => 'test_' . uniqid() . '@example.com'
    ];
    
    // We need to insert into users first if the schema requires it?
    // Looking at schema.sql: patients has user_id REFERENCES users(id).
    // So we must create a user first.
    
    // Create User
    $userData = [
        'email' => $patientData['email'],
        'password_hash' => password_hash('testpass', PASSWORD_DEFAULT),
        'role' => 'patient'
    ];
    // db_insert('users', $userData) - wait, db_insert returns true, doesn't return ID.
    // We need the ID. Let's use db_query "RETURNING id" if postgres.
    
    $res = db_query("INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id", 
        [$userData['email'], $userData['password_hash'], $userData['role']]);
    $userId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 1: User Created (ID: $userId)\n";
    
    // Create Patient Linked to User
    $patientData['user_id'] = $userId;
    // We need 'address', 'medical_history', 'emergency_contact'? Schema says they are nullable or text. OK.
    
    $res = db_query("INSERT INTO patients (user_id, first_name, last_name, date_of_birth, gender, phone) 
        VALUES ($1, $2, $3, $4, $5, $6) RETURNING id", 
        [$userId, $patientData['first_name'], $patientData['last_name'], $patientData['date_of_birth'], $patientData['gender'], $patientData['phone']]);
    $patientId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 2: Patient Profile Created (ID: $patientId)\n";
    
    // 2. Book Appointment
    // Need a doctor first? Let's check if any exists.
    $docRes = db_query("SELECT id FROM staff WHERE role='doctor' LIMIT 1");
    $docId = pg_fetch_result($docRes, 0, 0);
    if (!$docId) {
        // Create dummy doctor if none
        echo "⚠️ No doctors found, creating dummy doctor...\n";
        // Create Doc User
        $docUserRes = db_query("INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id",
             ['doc_test_@example.com', 'pass', 'doctor']);
        $docUserId = pg_fetch_result($docUserRes, 0, 0);
        $docRes = db_query("INSERT INTO staff (user_id, first_name, last_name, role) VALUES ($1, $2, $3, $4) RETURNING id",
             [$docUserId, 'Doc', 'Test', 'doctor']);
        $docId = pg_fetch_result($docRes, 0, 0);
    }
    
    $appointmentTime = date('Y-m-d H:i:s', strtotime('+1 day'));
    $res = db_query("INSERT INTO appointments (patient_id, doctor_id, appointment_time, status) VALUES ($1, $2, $3, $4) RETURNING id",
        [$patientId, $docId, $appointmentTime, 'scheduled']);
    $apptId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 3: Appointment Booked (ID: $apptId)\n";
    
    // 3. Add Prescription
    $medDetails = json_encode([
        ['name' => 'Paracetamol', 'dosage' => '500mg', 'frequency' => 'BID', 'duration' => '5 days']
    ]);
    $res = db_query("INSERT INTO prescriptions (appointment_id, patient_id, doctor_id, medication_details, notes) VALUES ($1, $2, $3, $4, $5) RETURNING id",
        [$apptId, $patientId, $docId, $medDetails, 'Take rest']);
    $rxId = pg_fetch_result($res, 0, 0);
    echo "✅ Step 4: Prescription Created (ID: $rxId)\n";
    
    // Cleanup (Optional - remove comment to clean up)
    /*
    db_query("DELETE FROM users WHERE id = $1", [$userId]); // Cascades to patient
    // If we kept doctor, we might delete too but reusing is fine.
    // Appointment/Rx cascade from patient delete?
    // Schema: patients delete -> appointments cascade? yes.
    echo "🧹 Cleanup Completed.\n";
    */
    
    echo "\n🎉 EHR Workflow Test PASSED in " . round(microtime(true) - $startTime, 2) . "s\n";
    
} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
?>
