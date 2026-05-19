<?php
require_once 'includes/db.php';
require_once 'includes/specializations.php';

$specializations = get_specializations();
$password_hash = password_hash('password123', PASSWORD_DEFAULT);

foreach ($specializations as $spec) {
    // Check if we already have 2 doctors for this specialization
    $existing = db_select("SELECT COUNT(*) as count FROM staff WHERE role = 'doctor' AND specialization = $1", [$spec]);
    $count = $existing[0]['count'];
    
    $needed = 2 - $count;
    
    for ($i = 0; $i < $needed; $i++) {
        $suffix = chr(65 + $i + $count); // A, B, C...
        $firstName = explode(' ', $spec)[0] . "Expert";
        $lastName = $suffix;
        $email = strtolower(str_replace([' ', '(', ')'], '', $spec)) . "." . strtolower($suffix) . "@adms.com";
        $username = strtolower(str_replace([' ', '(', ')'], '', $spec)) . "_" . strtolower($suffix);

        // 1. Create User
        // Note: db_insert might return void or boolean. I need the UUID.
        // I'll use raw pg_query to get the ID if db_insert doesn't support it.
        // Looking at common patterns, I'll just use db_query for the insert with RETURNING id.
        
        $user_res = db_query("INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id", [$email, $password_hash, 'doctor']);
        if ($user_res) {
            $user_id = pg_fetch_result($user_res, 0, 'id');
            
            // 2. Create Staff
            db_insert('staff', [
                'user_id' => $user_id,
                'first_name' => $firstName,
                'last_name' => "Specialist " . $suffix,
                'role' => 'doctor',
                'specialization' => $spec,
                'status' => 'active'
            ]);
            echo "Created Dr. $firstName Specialist $suffix for $spec\n";
        }
    }
}
echo "Seeding completed.\n";
?>
