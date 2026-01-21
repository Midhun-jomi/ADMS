<?php
// includes/queue_logic.php
require_once 'db.php';

function get_estimated_wait_time($appointment_id) {
    if (!$appointment_id) return 0;

    // 1. Get current appointment details
    $appt = db_select_one("SELECT id, doctor_id, appointment_time, priority, status FROM appointments WHERE id = $1", [$appointment_id]);
    if (!$appt) return 0;

    // RULE: Emergency or high-risk patients jump to top. Wait time = 0.
    if (in_array(strtolower($appt['priority'] ?? ''), ['emergency', 'high', 'high-risk'])) {
        return 0;
    }

    $doctor_id = $appt['doctor_id'];
    $current_time = $appt['appointment_time'];
    $current_id = $appt['id'];

    // 2. Get Doctor's Avg Consult Time
    $stats = db_select_one("SELECT avg_consult_time FROM doctor_stats WHERE doctor_id = $1", [$doctor_id]);
    $avg_time = $stats['avg_consult_time'] ?? 10; // Default 10 min

    // 3. Count patients ahead
    // "Ahead" means:
    // - Active status (scheduled, waiting, ready)
    // - NOT cancelled, completed
    // - Higher priority OR (Same priority AND Earlier time)
    // - Exclude self
    
    // We treat 'scheduled' same as 'waiting' for queue purposes if they are checked in?
    // Assumption: All appointments for today are in the queue.
    // Filter by TODAY? Usually queue is for the current session.
    // Let's assume we look at all active appointments for the day.
    
    $date_start = date('Y-m-d 00:00:00', strtotime($current_time)); // specific day
    $date_end   = date('Y-m-d 23:59:59', strtotime($current_time));

    // Simple sorting: Emergency first, then time.
    $sql = "SELECT id, priority, appointment_time FROM appointments 
            WHERE doctor_id = $1 
            AND appointment_time >= '$date_start' AND appointment_time <= '$date_end'
            AND status IN ('scheduled', 'waiting', 'ready', 'consulting')
            AND id != $2";
            
    $queue = db_select($sql, [$doctor_id, $current_id]);
    
    $ahead_count = 0;
    foreach ($queue as $q) {
        // Is $q ahead of $appt?
        $q_prio = strtolower($q['priority'] ?? 'normal');
        $my_prio = strtolower($appt['priority'] ?? 'normal');
        
        $is_q_emergency = in_array($q_prio, ['emergency', 'high', 'high-risk']);
        $is_my_emergency = in_array($my_prio, ['emergency', 'high', 'high-risk']); // Should be false here due to top check

        if ($is_q_emergency) {
            $ahead_count++;
        } elseif (!$is_my_emergency) {
            // Both normal (or low)
            if (strtotime($q['appointment_time']) < strtotime($current_time)) {
                $ahead_count++;
            }
        }
    }

    // 4. Calculate
    // Formula: (Patients Ahead * Avg Consult) + (Nurse Prep if not ready)
    $nurse_prep_time = ($appt['status'] === 'waiting' || $appt['status'] === 'scheduled') ? 5 : 0; 
    // Assuming 'waiting' is default. 'ready' means nurse is done.
    
    $est_wait = ($ahead_count * $avg_time) + $nurse_prep_time;
    
    return $est_wait;
}

function update_appointment_status($id, $new_status) {
    // Valid statuses: scheduled, waiting, ready, consulting, completed, cancelled
    $sql = "UPDATE appointments SET status = $1";
    $params = [$new_status];
    
    if ($new_status === 'waiting') {
         $sql .= ", checked_in_at = NOW()";
    }
    elseif ($new_status === 'consulting') {
        $sql .= ", consultation_start = NOW()";
    }
    elseif ($new_status === 'completed') {
        $sql .= ", consultation_end = NOW()";
    }
    
    $sql .= " WHERE id = $" . (count($params) + 1);
    $params[] = $id;
    
    db_query($sql, $params);
    
    // Trigger auto-update of avg time on completion
    if ($new_status === 'completed') {
        // Get doctor_id
        $appt = db_select_one("SELECT doctor_id, consultation_start, consultation_end FROM appointments WHERE id = $1", [$id]);
        if ($appt && $appt['consultation_start'] && $appt['consultation_end']) {
            recalculate_doctor_avg($appt['doctor_id']);
        }
    }
}

function recalculate_doctor_avg($doctor_id) {
    if (!$doctor_id) return;
    
    // Avg of last 50 completed consultations ? Or all? 
    // Let's take last 20 for responsiveness
    $sql = "SELECT consultation_start, consultation_end FROM appointments 
            WHERE doctor_id = $1 AND status = 'completed' 
            AND consultation_start IS NOT NULL AND consultation_end IS NOT NULL
            ORDER BY consultation_end DESC LIMIT 20";
            
    $history = db_select($sql, [$doctor_id]);
    
    if (empty($history)) return;
    
    $total_minutes = 0;
    $count = 0;
    
    foreach ($history as $h) {
        $start = strtotime($h['consultation_start']);
        $end = strtotime($h['consultation_end']);
        $duration =  ($end - $start) / 60; // minutes
        
        if ($duration > 0 && $duration < 120) { // Filter outliers (e.g. forgot to close)
            $total_minutes += $duration;
            $count++;
        }
    }
    
    if ($count > 0) {
        $new_avg = round($total_minutes / $count);
        // Update stats
        // Check if exists
        $exists = db_select_one("SELECT doctor_id FROM doctor_stats WHERE doctor_id = $1", [$doctor_id]);
        if ($exists) {
            db_query("UPDATE doctor_stats SET avg_consult_time = $1 WHERE doctor_id = $2", [$new_avg, $doctor_id]);
        } else {
            db_query("INSERT INTO doctor_stats (doctor_id, avg_consult_time) VALUES ($1, $2)", [$doctor_id, $new_avg]);
        }
    }
}
?>
