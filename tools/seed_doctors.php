<?php
// tools/seed_doctors.php — Seed doctors for all clinical departments
// Run from CLI: php tools/seed_doctors.php
// Or visit: http://localhost:8000/tools/seed_doctors.php (admin only)
require_once __DIR__ . '/../includes/db.php';

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/auth_session.php';
    check_role(['admin']);
}

$password_hash = password_hash('Doctor@123', PASSWORD_BCRYPT);

// ── Doctors to seed ────────────────────────────────────────────────────────
// Format: [first_name, last_name, email_suffix, department, specialization]
$doctors = [
    // Endocrinology
    ['Rahul',    'Sharma',    'rahul.sharma',    'Endocrinology', 'Endocrinology'],
    ['Priya',    'Nair',      'priya.nair',      'Endocrinology', 'Endocrinology'],
    ['James',    'Wilson',    'james.wilson',    'Endocrinology', 'Endocrinology'],
    // ENT
    ['Kevin',    'Patel',     'kevin.patel',     'ENT', 'ENT'],
    ['Susan',    'Clark',     'susan.clark',     'ENT', 'ENT'],
    ['Robert',   'Chen',      'robert.chen',     'ENT', 'ENT'],
    // Dermatology
    ['Aisha',    'Khan',      'aisha.khan',      'Dermatology', 'Dermatology'],
    ['Michael',  'Brown',     'michael.brown',   'Dermatology', 'Dermatology'],
    ['Lisa',     'Park',      'lisa.park',       'Dermatology', 'Dermatology'],
    // Nephrology
    ['David',    'Kim',       'david.kim',       'Nephrology', 'Nephrology'],
    ['Fatima',   'Hassan',    'fatima.hassan',   'Nephrology', 'Nephrology'],
    ['Carlos',   'Rivera',    'carlos.rivera',   'Nephrology', 'Nephrology'],
    // Urology
    ['Benjamin', 'Scott',     'benjamin.scott',  'Urology', 'Urology'],
    ['Ananya',   'Rao',       'ananya.rao',      'Urology', 'Urology'],
    ['Patrick',  'Walsh',     'patrick.walsh',   'Urology', 'Urology'],
    // Ophthalmology
    ['Sarah',    'White',     'sarah.white',     'Ophthalmology', 'Ophthalmology'],
    ['Ravi',     'Kumar',     'ravi.kumar',      'Ophthalmology', 'Ophthalmology'],
    ['Jessica',  'Lee',       'jessica.lee',     'Ophthalmology', 'Ophthalmology'],
    // Psychiatry
    ['Emma',     'Collins',   'emma.collins',    'Psychiatry', 'Psychiatry'],
    ['Arjun',    'Singh',     'arjun.singh',     'Psychiatry', 'Psychiatry'],
    ['Natasha',  'Petrov',    'natasha.petrov',  'Psychiatry', 'Psychiatry'],
    // Haematology
    ['Vincent',  'Osei',      'vincent.osei',    'Haematology', 'Haematology'],
    ['Maria',    'Santos',    'maria.santos',    'Haematology', 'Haematology'],
    ['Diana',    'Fletcher',  'diana.fletcher',  'Haematology', 'Haematology'],
    // Hepatology
    ['Tariq',    'Ahmed',     'tariq.ahmed',     'Hepatology', 'Hepatology'],
    ['Grace',    'Okonkwo',   'grace.okonkwo',   'Hepatology', 'Hepatology'],
    ['Simon',    'Dubois',    'simon.dubois',    'Hepatology', 'Hepatology'],
    // Gastroenterology
    ['Alan',     'Chen',      'alan.chen',       'Gastroenterology', 'Gastroenterology'],
    ['Meera',    'Reddy',     'meera.reddy',     'Gastroenterology', 'Gastroenterology'],
    ['Patrick',  'OBrien',    'patrick.obrien',  'Gastroenterology', 'Gastroenterology'],
    // General Surgery
    ['Thomas',   'Baker',     'thomas.baker',    'General Surgery', 'General Surgery'],
    ['Yuki',     'Tanaka',    'yuki.tanaka',     'General Surgery', 'General Surgery'],
    ['Fatou',    'Diallo',    'fatou.diallo',    'General Surgery', 'General Surgery'],
    // Cardiology (extra)
    ['George',   'Martinez',  'george.martinez', 'Cardiology', 'Cardiology'],
    ['Helen',    'Zhao',      'helen.zhao',      'Cardiology', 'Cardiology'],
    ['Victor',   'Adeyemi',   'victor.adeyemi',  'Cardiology', 'Cardiology'],
    // Neurology (extra)
    ['Nathan',   'Ford',      'nathan.ford',     'Neurology', 'Neurology'],
    ['Priya',    'Kapoor',    'priya.kapoor',    'Neurology', 'Neurology'],
    ['Lucas',    'Moreau',    'lucas.moreau',    'Neurology', 'Neurology'],
    // Orthopedics (extra)
    ['Andrew',   'Walsh',     'andrew.walsh',    'Orthopedics', 'Orthopedics'],
    ['Sneha',    'Pillai',    'sneha.pillai',    'Orthopedics', 'Orthopedics'],
    ['Marcus',   'Nguyen',    'marcus.nguyen',   'Orthopedics', 'Orthopedics'],
    // Oncology (extra)
    ['Richard',  'Onwuachi',  'richard.onwuachi','Oncology', 'Oncology'],
    ['Claire',   'Dupont',    'claire.dupont',   'Oncology', 'Oncology'],
    ['Joseph',   'Akosile',   'joseph.akosile',  'Oncology', 'Oncology'],
    // Pulmonology (extra)
    ['Nina',     'Johansson', 'nina.johansson',  'Pulmonology', 'Pulmonology'],
    ['Omar',     'Farouq',    'omar.farouq',     'Pulmonology', 'Pulmonology'],
    // Gynaecology (extra)
    ['Sunita',   'Verma',     'sunita.verma',    'Gynaecology', 'Gynaecology'],
    ['Carmen',   'Flores',    'carmen.flores',   'Gynaecology', 'Gynaecology'],
    ['Aditi',    'Joshi',     'aditi.joshi',     'Gynaecology', 'Gynaecology'],
    // General Medicine (extra)
    ['Daniel',   'Okafor',    'daniel.okafor',   'General Medicine', 'General Medicine'],
    ['Shreya',   'Mehta',     'shreya.mehta',    'General Medicine', 'General Medicine'],
    ['Lucas',    'Ferreira',  'lucas.ferreira',  'General Medicine', 'General Medicine'],
];

$inserted = 0;
$skipped  = 0;
$errors   = 0;

foreach ($doctors as $doc) {
    [$first, $last, $slug, $dept, $spec] = $doc;
    $email = "dr.{$slug}@admshospital.com";

    // Check if user already exists
    $existing = db_select_one("SELECT id FROM users WHERE email = $1", [$email]);
    if ($existing) {
        echo "SKIP  : $email (already exists)\n";
        $skipped++;
        continue;
    }

    try {
        // 1. Insert user and get back the new ID
        $result = db_query(
            "INSERT INTO users (email, password_hash, role) VALUES ($1, $2, 'doctor') RETURNING id",
            [$email, $password_hash]
        );
        $row = pg_fetch_assoc($result);
        $user_id = $row['id'];

        // 2. Insert staff record
        db_insert('staff', [
            'user_id'        => $user_id,
            'first_name'     => $first,
            'last_name'      => $last,
            'role'           => 'doctor',
            'department'     => $dept,
            'specialization' => $spec,
            'status'         => 'active',
        ]);

        echo "INSERT: Dr. $first $last — $spec ($email)\n";
        $inserted++;
    } catch (Exception $e) {
        echo "ERROR : $email — " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n── Summary ─────────────────────────────\n";
echo "Inserted : $inserted\n";
echo "Skipped  : $skipped\n";
echo "Errors   : $errors\n";
echo "────────────────────────────────────────\n";
