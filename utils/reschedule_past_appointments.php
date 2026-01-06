<?php
// utils/reschedule_past_appointments.php
require_once '../includes/db.php';

// Set Headers for streaming output
header('Content-Type: text/plain'); // Plain text for easy reading
ob_implicit_flush(true);
ob_end_flush();

echo "=== Starting Automatic Rescheduling of Past Appointments ===\n\n";

try {
    // 1. Find all 'scheduled' appointments in the past
    // Note: We use NOW() in Postgres which respects our 'Asia/Kolkata' session setting from db.php
    $sql = "SELECT * FROM appointments 
            WHERE status = 'scheduled' 
              AND appointment_time < NOW() 
            ORDER BY appointment_time ASC";
    
    $past_appts = db_select($sql);
    
    if (empty($past_appts)) {
        echo "✅ No past pending appointments found. System is up to date.\n";
        exit;
    }

    echo "Found " . count($past_appts) . " past appointments to reschedule.\n\n";

    $tomorrow = new DateTime('tomorrow');
    $start_hour = 9;
    $end_hour = 17;

    foreach ($past_appts as $appt) {
        $id = $appt['id'];
        $old_time = $appt['appointment_time'];
        $doctor_id = $appt['doctor_id'];
        
        echo "Processing Appt #$id (Was: $old_time) -> ";

        // Find next available slot starting tomorrow
        $rescheduled = false;
        $check_date = clone $tomorrow;
        
        // Safety break: don't look more than 30 days ahead
        for ($day = 0; $day < 30; $day++) {
            $date_str = $check_date->format('Y-m-d');
            
            // Loop through hours 09:00 to 17:00
            for ($h = $start_hour; $h < $end_hour; $h++) {
                for ($m = 0; $m < 60; $m += 30) {
                    $time_str = sprintf("%02d:%02d:00", $h, $m);
                    $candidate_time = "$date_str $time_str";
                    
                    // Check availability
                    $check_sql = "SELECT id FROM appointments 
                                  WHERE doctor_id = $1 
                                    AND appointment_time = $2 
                                    AND status = 'scheduled'";
                    $exists = db_select_one($check_sql, [$doctor_id, $candidate_time]);
                    
                    if (!$exists) {
                        // FOUND SLOT!
                        // Update Appointment
                        db_update('appointments', 
                            ['appointment_time' => $candidate_time], 
                            ['id' => $id]
                        );
                        
                        // Notify Patient (Create Notification)
                        $patient_id = $appt['patient_id'];
                        // Need user_id for notification. Join not available easily here, let's fetch.
                        $pat = db_select_one("SELECT user_id FROM patients WHERE id = $1", [$patient_id]);
                        if ($pat) {
                            $msg = "Your appointment from $old_time has been automatically rescheduled to $candidate_time.";
                            db_insert('notifications', [
                                'user_id' => $pat['user_id'],
                                'title' => 'Appointment Rescheduled',
                                'message' => $msg,
                                'is_read' => 'false'
                            ]);
                        }

                        echo "Moved to: $candidate_time \n";
                        $rescheduled = true;
                        break 3; // Break all loops
                    }
                }
            }
            // Next day
            $check_date->modify('+1 day');
        }
        
        if (!$rescheduled) {
            echo "❌ FAILED (No slots found in next 30 days)\n";
        }
    }

    echo "\n=== Rescheduling Complete ===\n";

} catch (Exception $e) {
    echo "\nCRITICAL ERROR: " . $e->getMessage() . "\n";
}
?>
