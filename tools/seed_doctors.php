<?php
// tools/seed_doctors.php — Seeds one doctor per specialization (matches includes/specializations.php exactly)
// Visit: http://localhost:8000/tools/seed_doctors.php (admin login required)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_session.php';
check_role(['admin']);

$password_hash = password_hash('Doctor@123', PASSWORD_BCRYPT);

// One doctor per specialization — names match specializations.php exactly
$doctors = [
    ['James',     'Carter',    'james.carter',     'General Practice'],
    ['Olivia',    'Harrison',  'olivia.harrison',  'Cardiology'],
    ['Ethan',     'Brooks',    'ethan.brooks',     'Dermatology'],
    ['Sophia',    'Mitchell',  'sophia.mitchell',  'Pediatrics'],
    ['Liam',      'Anderson',  'liam.anderson',    'Neurology'],
    ['Emma',      'Thompson',  'emma.thompson',    'Orthopedics'],
    ['Noah',      'Williams',  'noah.williams',    'Psychiatry'],
    ['Ava',       'Johnson',   'ava.johnson',      'Oncology'],
    ['William',   'Davis',     'william.davis',    'Radiology'],
    ['Isabella',  'Martinez',  'isabella.martinez','Anesthesiology'],
    ['Benjamin',  'Garcia',    'benjamin.garcia',  'Gastroenterology'],
    ['Mia',       'Rodriguez', 'mia.rodriguez',    'Ophthalmology'],
    ['Lucas',     'Wilson',    'lucas.wilson',     'Urology'],
    ['Charlotte', 'Moore',     'charlotte.moore',  'Obstetrics and Gynecology'],
    ['Henry',     'Taylor',    'henry.taylor',     'Emergency Medicine'],
    ['Amelia',    'Jackson',   'amelia.jackson',   'Endocrinology'],
    ['Alexander', 'White',     'alexander.white',  'Pulmonology'],
    ['Harper',    'Harris',    'harper.harris',    'Nephrology'],
    ['Mason',     'Clark',     'mason.clark',      'Rheumatology'],
    ['Evelyn',    'Lewis',     'evelyn.lewis',     'Infectious Disease'],
    ['Daniel',    'Robinson',  'daniel.robinson',  'Hematology'],
    ['Abigail',   'Walker',    'abigail.walker',   'Hepatology'],
    ['Michael',   'Hall',      'michael.hall',     'Neonatology'],
    ['Emily',     'Allen',     'emily.allen',      'Geriatrics'],
    ['Elijah',    'Young',     'elijah.young',     'Palliative Care'],
    ['Elizabeth', 'King',      'elizabeth.king',   'Sports Medicine'],
    ['Owen',      'Wright',    'owen.wright',      'Plastic Surgery'],
    ['Sofia',     'Scott',     'sofia.scott',      'Vascular Surgery'],
    ['Logan',     'Green',     'logan.green',      'Cardiothoracic Surgery'],
    ['Scarlett',  'Baker',     'scarlett.baker',   'Colorectal Surgery'],
    ['Aaron',     'Adams',     'aaron.adams',      'Transplant Surgery'],
    ['Victoria',  'Nelson',    'victoria.nelson',  'Allergy and Immunology'],
    ['Aiden',     'Hill',      'aiden.hill',       'Nuclear Medicine'],
    ['Grace',     'Ramirez',   'grace.ramirez',    'Pathology'],
    ['Jackson',   'Campbell',  'jackson.campbell', 'Clinical Pharmacology'],
    ['Chloe',     'Roberts',   'chloe.roberts',    'Otolaryngology (ENT)'],
    ['Sebastian', 'Turner',    'sebastian.turner', 'Maxillofacial Surgery'],
    ['Penelope',  'Phillips',  'penelope.phillips','Reproductive Medicine'],
];

$inserted = 0;
$skipped  = 0;
$errors   = 0;

header('Content-Type: text/plain');

foreach ($doctors as [$first, $last, $slug, $spec]) {
    $email = "dr.{$slug}@admshospital.com";

    $existing = db_select_one("SELECT id FROM users WHERE email = $1", [$email]);
    if ($existing) {
        echo "SKIP  : $email (already exists)\n";
        $skipped++;
        continue;
    }

    try {
        $result  = db_query(
            "INSERT INTO users (email, password_hash, role) VALUES ($1, $2, 'doctor') RETURNING id",
            [$email, $password_hash]
        );
        $user_id = pg_fetch_assoc($result)['id'];

        db_insert('staff', [
            'user_id'        => $user_id,
            'first_name'     => $first,
            'last_name'      => $last,
            'role'           => 'doctor',
            'department'     => $spec,
            'specialization' => $spec,
            'status'         => 'active',
        ]);

        echo "INSERT: Dr. $first $last — $spec\n";
        $inserted++;
    } catch (Exception $e) {
        echo "ERROR : $email — " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n── Summary ─────────────────────────────\n";
echo "Inserted : $inserted\n";
echo "Skipped  : $skipped (already in DB)\n";
echo "Errors   : $errors\n";
echo "────────────────────────────────────────\n";
echo "Password for all new doctors: Doctor@123\n";
