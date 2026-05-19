<?php
require_once 'includes/db.php';

// 1. Ensure table exists (just in case)
try {
    db_query("CREATE TABLE IF NOT EXISTS rosters (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        staff_id UUID REFERENCES staff(id) ON DELETE CASCADE,
        shift_date DATE NOT NULL,
        shift_type VARCHAR(20) NOT NULL CHECK (shift_type IN ('morning', 'afternoon', 'night', 'off')),
        area VARCHAR(100),
        notes TEXT,
        created_at TIMESTAMPTZ DEFAULT NOW(),
        UNIQUE(staff_id, shift_date)
    )");
} catch (Exception $e) { echo "Table check error: " . $e->getMessage() . "\n"; }

// 2. Fetch some staff
$staff_members = db_select("SELECT id, first_name, last_name, role FROM staff LIMIT 15");
if (empty($staff_members)) {
    die("No staff found to assign shifts.\n");
}

$shifts = ['morning', 'afternoon', 'night', 'off'];
$areas = ['Emergency', 'ICU', 'General Ward', 'OPD', 'Radiology', 'Pharmacy'];

// 3. Set shifts for the next 7 days
$start_date = date('Y-m-d');
$days = 7;

echo "Seeding random shifts for " . count($staff_members) . " staff members over $days days...\n";

$count = 0;
for ($i = 0; $i < $days; $i++) {
    $current_date = date('Y-m-d', strtotime("$start_date + $i days"));
    
    foreach ($staff_members as $st) {
        $shift = $shifts[array_rand($shifts)];
        $area = $areas[array_rand($areas)];
        
        try {
            db_query(
                "INSERT INTO rosters (staff_id, shift_date, shift_type, area) 
                 VALUES ($1, $2, $3, $4)
                 ON CONFLICT (staff_id, shift_date) DO UPDATE 
                 SET shift_type = EXCLUDED.shift_type, area = EXCLUDED.area",
                [$st['id'], $current_date, $shift, $area]
            );
            $count++;
        } catch (Exception $e) {
            // Skip if error
        }
    }
}

echo "Successfully assigned $count shifts.\n";
?>
