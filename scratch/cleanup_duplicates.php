<?php
// scratch/cleanup_duplicates.php
require_once __DIR__ . '/../includes/db.php';

try {
    // 1. Find patients with more than one 'admitted' status
    $sql = "SELECT patient_id, COUNT(*) as c 
            FROM admissions 
            WHERE status = 'admitted' 
            GROUP BY patient_id 
            HAVING COUNT(*) > 1";
    $duplicates = db_select($sql);

    echo "Found " . count($duplicates) . " patients with duplicate admissions.\n";

    foreach ($duplicates as $dup) {
        $pid = $dup['patient_id'];
        
        // Get all active admissions for this patient
        $all_admissions = db_select("SELECT id, admission_date, room_id FROM admissions WHERE patient_id = $1 AND status = 'admitted' ORDER BY admission_date DESC", [$pid]);
        
        // Keep the LATEST one, mark others as discharged (or deleted)
        // We'll mark them as 'discharged' to be safe, but they really shouldn't have existed.
        $latest = array_shift($all_admissions);
        echo "Patient ID $pid: Keeping Admission ID {$latest['id']} (Room {$latest['room_id']})\n";
        
        foreach ($all_admissions as $old) {
            echo " - Removing duplicate Admission ID {$old['id']} (Room {$old['room_id']})\n";
            // Mark as discharged and free the room
            db_query("UPDATE admissions SET status = 'discharged' WHERE id = $1", [$old['id']]);
            db_query("UPDATE rooms SET status = 'available' WHERE id = $1", [$old['room_id']]);
        }
    }

    echo "\nCleanup completed.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
