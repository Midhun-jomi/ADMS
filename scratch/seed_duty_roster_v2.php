<?php
require_once 'includes/db.php';

// 1. Drop the incorrect table I created earlier
try {
    db_query("DROP TABLE IF EXISTS rosters");
} catch (Exception $e) {}

// 2. Fetch some staff
$staff_members = db_select("SELECT id FROM staff LIMIT 20");
if (empty($staff_members)) {
    die("No staff found.\n");
}

$shifts = ['Morning', 'Evening', 'Night'];
$depts = ['Emergency', 'ICU', 'General Ward', 'OPD', 'Radiology', 'Pharmacy'];

// Get an admin user for created_by
$admin = db_select_one("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$admin_id = $admin['id'] ?? null;

// 3. Set shifts for the current week (starting from last Monday to next Sunday)
$today = new DateTime();
$dow = (int)$today->format('N');
$week_start = clone $today;
if ($dow !== 1) { $week_start->modify('last monday'); }

echo "Seeding random shifts for " . count($staff_members) . " staff members for the current week...\n";

$count = 0;
for ($i = 0; $i < 7; $i++) {
    $current_date = (clone $week_start)->modify("+$i days")->format('Y-m-d');
    
    foreach ($staff_members as $st) {
        // Randomly decide if staff works today (75% chance)
        if (rand(1, 100) > 75) continue;

        $shift = $shifts[array_rand($shifts)];
        $dept  = $depts[array_rand($depts)];
        
        $st_time = ''; $et_time = '';
        if ($shift === 'Morning') { $st_time = '06:00'; $et_time = '14:00'; }
        elseif ($shift === 'Evening') { $st_time = '14:00'; $et_time = '22:00'; }
        elseif ($shift === 'Night') { $st_time = '22:00'; $et_time = '06:00'; }

        try {
            db_query(
                "INSERT INTO duty_roster (staff_id, shift_date, shift_type, start_time, end_time, department, created_by) 
                 VALUES ($1, $2, $3, $4, $5, $6, $7)
                 ON CONFLICT (staff_id, shift_date, shift_type) DO UPDATE 
                 SET start_time = EXCLUDED.start_time, end_time = EXCLUDED.end_time, department = EXCLUDED.department",
                [$st['id'], $current_date, $shift, $st_time, $et_time, $dept, $admin_id]
            );
            $count++;
        } catch (Exception $e) {
            // echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "Successfully assigned $count shifts to 'duty_roster' table.\n";
?>
