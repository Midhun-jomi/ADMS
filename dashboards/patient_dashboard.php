<?php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['patient']);

$page_title = "Patient Dashboard";
include '../includes/header.php';

$user_id = get_user_id();
$patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
$patient_id = $patient['id'];

// 1. Upcoming Visits (Scheduled, Future)
$upcoming_count = db_select_one("SELECT COUNT(*) as c FROM appointments 
                                 WHERE patient_id = $1 AND appointment_time > NOW() AND status = 'scheduled'", 
                                 [$patient_id])['c'];

// Get Next Appointment for Wait Time
// Get Next Appointment for Wait Time
require_once '../includes/queue_logic.php';
$next_appt = db_select_one("SELECT id, status, appointment_time FROM appointments 
                            WHERE patient_id = $1 
                            AND appointment_time >= CURRENT_DATE 
                            AND status IN ('scheduled', 'waiting', 'ready') 
                            ORDER BY appointment_time ASC LIMIT 1", [$patient_id]);
$wait_time_display = ''; // Legacy variable if needed elsewhere

// 2. Active Prescriptions — calculated from quantity and dosage frequency (e.g. "1-0-1")
function rx_doses_per_day($dosage) {
    $parts = explode('-', preg_replace('/[^0-9\-]/', '', $dosage));
    $total = array_sum(array_map('intval', $parts));
    return $total > 0 ? $total : 1;
}
$all_rx = db_select("SELECT medication_details, created_at FROM prescriptions WHERE patient_id = $1", [$patient_id]);
$rx_count = 0;
foreach ($all_rx as $rx) {
    $meds = json_decode($rx['medication_details'], true);
    if (!is_array($meds)) continue;
    $created = strtotime($rx['created_at']);
    foreach ($meds as $med) {
        $qty = (int)($med['quantity'] ?? 0);
        $dpd = rx_doses_per_day($med['dosage'] ?? '1');
        if ($qty > 0) {
            $expiry = $created + (ceil($qty / $dpd) * 86400);
            if ($expiry >= time()) { $rx_count++; break; }
        }
    }
}

// 3. Past Visits (Completed or Past)
$past_count = db_select_one("SELECT COUNT(*) as c FROM appointments 
                             WHERE patient_id = $1 AND (status = 'completed' OR (appointment_time < NOW() AND status != 'cancelled'))", 
                             [$patient_id])['c'];

// 4. Pending Bills (Count of unpaid invoices)
$pending_bills_count = db_select_one("SELECT COUNT(*) as c FROM billing WHERE patient_id = $1 AND status = 'pending'", [$patient_id])['c'];

// Prepare Top Bar Wait Time
$wait_banner = "";
if ($next_appt) {
    // Updated Logic using get_queue_details
    $queue_data = get_queue_details($next_appt['id']);
    $mins = $queue_data['wait_time'];
    $token = $queue_data['token'];
    $ahead = $queue_data['patients_ahead'];
    
    // Calculate total delay (Time already passed + Future wait)
    $appt_time = strtotime($next_appt['appointment_time']);
    $now = time();
    $past_due_mins = 0;
    
    if ($now > $appt_time) {
        $past_due_mins = round(($now - $appt_time) / 60);
    }
    
    $total_delay_metric = $mins + $past_due_mins;
    $status_detail = "Waiting for Doctor";
    $status_icon = "fa-user-md";
    
    // Status Logic
    switch($next_appt['status']) {
        case 'scheduled':
            $status_detail = "Scheduled - Please Check-in at Reception";
            $status_icon = "fa-calendar-check";
            break;
        case 'waiting':
            $status_detail = "Checked In - Waiting for Nurse (Vitals Pending)";
            $status_icon = "fa-user-nurse";
            break;
        case 'ready':
            $status_detail = "Vitals Completed - Waiting for Doctor";
            $status_icon = "fa-stethoscope";
            break;
    }

    // Color Logic
    $bg_color = '#d1fae5'; // Green-100
    $text_color = '#065f46'; // Green-800
    $icon_color = '#059669'; // Green-600
    $msg = "On Time";
    
    // Using 10 mins as threshold since user complained about 11 mins
    if ($total_delay_metric > 10) {
        $bg_color = '#fef3c7'; // Yellow-100
        $text_color = '#92400e'; // Yellow-800
        $icon_color = '#d97706'; // Yellow-600
        $msg = "Delayed";
    }
    if ($total_delay_metric > 15) {
        $bg_color = '#fee2e2'; // Red-100
        $text_color = '#b91c1c'; // Red-800
        $icon_color = '#dc2626'; // Red-600
        $msg = "Heavy Delay";
    }

    $wait_banner = "
    <div style='background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);'>
        <div style='display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f3f4f6;'>
            <div style='display: flex; align-items: center; gap: 12px;'>
                <div style='width: 10px; height: 10px; background: $icon_color; border-radius: 50%; box-shadow: 0 0 0 4px $bg_color;'></div>
                <h3 style='margin: 0; font-size: 1.1rem; color: #111827; font-weight: 600;'>Live Queue Status</h3>
            </div>
            <span style='background: $bg_color; color: $text_color; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;'>
                $msg
            </span>
        </div>
        
        <div style='display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; text-align: center;'>
            <!-- Token -->
            <div style='background: #f9fafb; padding: 12px 8px; border-radius: 12px;'>
                <p style='margin: 0 0 5px; font-size: 0.75rem; color: #6b7280; font-weight: 500;'>Token</p>
                <div style='font-size: 1.3rem; font-weight: 700; color: #111827;'>#{$token}</div>
            </div>
            
            <!-- Appt Time -->
            <div style='background: #f9fafb; padding: 12px 8px; border-radius: 12px;'>
                <p style='margin: 0 0 5px; font-size: 0.75rem; color: #6b7280; font-weight: 500;'>Appt. Time</p>
                <div style='font-size: 1.1rem; font-weight: 700; color: #4b5563;'>".date('h:i A', strtotime($next_appt['appointment_time']))."</div>
            </div>

            <!-- Expected Time -->
            <div style='background: #eff6ff; padding: 12px 8px; border-radius: 12px; border: 1px solid #dbeafe;'>
                <p style='margin: 0 0 5px; font-size: 0.75rem; color: #1e40af; font-weight: 600;'>Expected</p>
                <div style='font-size: 1.1rem; font-weight: 700; color: #1e40af;'>".date('h:i A', max(time(), strtotime($next_appt['appointment_time'])) + ($mins * 60))."</div>
            </div>

            <!-- Patients Ahead -->
            <div style='background: #f9fafb; padding: 12px 8px; border-radius: 12px;'>
                <p style='margin: 0 0 5px; font-size: 0.75rem; color: #6b7280; font-weight: 500;'>Ahead</p>
                <div style='font-size: 1.3rem; font-weight: 700; color: #4b5563;'>{$ahead}</div>
            </div>
            
            <!-- Est Wait -->
            <div style='background: $bg_color; padding: 12px 8px; border-radius: 12px;'>
                <p style='margin: 0 0 5px; font-size: 0.75rem; color: $text_color; font-weight: 500;'>Est. Wait</p>
                <div style='font-size: 1.3rem; font-weight: 700; color: $text_color;'>{$mins}<span style='font-size: 0.8rem;'>m</span></div>
            </div>
        </div>
        
        <div style='margin-top: 15px; text-align: center; font-size: 0.9rem; color: #6b7280;'>
            <i class='fas $status_icon' style='margin-right: 6px;'></i> $status_detail
        </div>
    </div>
    ";
}
?>

<?php echo $wait_banner; ?>

<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        border-radius: 16px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 160px;
        position: relative;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border: none;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.05);
    }
    .stat-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }
    .stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    .stat-value {
        font-size: 36px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #1e293b;
    }
    .stat-label {
        font-size: 14px;
        color: #64748b;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .stat-badge {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Card Variants */
    .card-purple { background-color: #EADCF8; }
    .card-purple .stat-icon { background-color: rgba(255,255,255,0.6); color: #7E22CE; }
    .card-purple .stat-badge { background-color: rgba(255,255,255,0.6); color: #6B21A8; }
    
    .card-blue { background-color: #D6E4FF; }
    .card-blue .stat-icon { background-color: rgba(255,255,255,0.6); color: #2563EB; }
    .card-blue .stat-badge { background-color: rgba(255,255,255,0.6); color: #1E40AF; }
    
    .card-yellow { background-color: #FEF9C3; }
    .card-yellow .stat-icon { background-color: rgba(255,255,255,0.6); color: #CA8A04; }
    .card-yellow .stat-badge { background-color: rgba(255,255,255,0.6); color: #854D0E; }
    
    .card-gray { background-color: #F1F5F9; }
    .card-gray .stat-icon { background-color: rgba(255,255,255,0.6); color: #475569; }
    .card-gray .stat-badge { background-color: rgba(255,255,255,0.6); color: #334155; }
</style>

<div class="dashboard-stats">
    <!-- 1. Upcoming Visits -->
    <a href="/modules/ehr/appointments.php" class="stat-card card-purple" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">📅</div>
            <div style="color: #6B21A8;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $upcoming_count; ?></div>
            <div class="stat-label">
                Upcoming Visits <span class="stat-badge">Next</span>
            </div>
            <div style="font-size: 12px; color: #6B21A8; margin-top: 4px;">Scheduled appointments</div>
        </div>
    </a>

    <!-- 2. Prescriptions -->
    <a href="/modules/ehr/prescriptions.php" class="stat-card card-blue" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">💊</div>
            <div style="color: #1E40AF;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $rx_count; ?></div>
            <div class="stat-label">
                Prescriptions <span class="stat-badge">Active</span>
            </div>
            <div style="font-size: 12px; color: #1E40AF; margin-top: 4px;">Active medications</div>
        </div>
    </a>

    <!-- 3. Past Visits -->
    <a href="/modules/ehr/appointments.php" class="stat-card card-yellow" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">📁</div>
            <div style="color: #854D0E;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $past_count; ?></div>
            <div class="stat-label">
                Past Visits <span class="stat-badge">Total</span>
            </div>
            <div style="font-size: 12px; color: #854D0E; margin-top: 4px;">Completed appointments</div>
        </div>
    </a>

    <!-- 4. Pending Bills -->
    <a href="/modules/billing/invoices.php" class="stat-card" style="text-decoration: none; color: inherit; background-color: #fee2e2;">
        <div class="stat-card-header">
            <div class="stat-icon" style="color: #dc2626; background: rgba(255,255,255,0.6);">💳</div>
            <div style="color: #991b1b;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $pending_bills_count; ?></div>
            <div class="stat-label">
                Pending Bills <span class="stat-badge" style="background: rgba(255,255,255,0.6); color: #7f1d1d;">Due</span>
            </div>
            <div style="font-size: 12px; color: #991b1b; margin-top: 4px;">Unpaid invoices</div>
        </div>
    </a>
    </div>

<?php
// Fetch recent AI Triage Result
$latest_triage = db_select_one("SELECT * FROM triage_analysis WHERE patient_id = $1 ORDER BY created_at DESC LIMIT 1", [$patient_id]);

// Fetch Past Lab Results
$recent_labs = db_select("SELECT test_type, status, result_data, created_at FROM laboratory_tests WHERE patient_id = $1 ORDER BY created_at DESC LIMIT 3", [$patient_id]);

// Fetch Last Prescribed Medications
$latest_rx = db_select_one("SELECT medication_details, created_at FROM prescriptions WHERE patient_id = $1 ORDER BY created_at DESC LIMIT 1", [$patient_id]);
$last_meds = $latest_rx ? json_decode($latest_rx['medication_details'], true) : [];

// Fetch All Future Appointments (excluding today's live one if it's already shown)
$future_appointments = db_select("SELECT a.*, s.first_name as doc_first, s.last_name as doc_last 
                                 FROM appointments a 
                                 JOIN staff s ON a.doctor_id = s.id
                                 WHERE a.patient_id = $1 AND a.appointment_time > NOW() 
                                 AND a.status = 'scheduled'
                                 ORDER BY a.appointment_time ASC LIMIT 3", [$patient_id]);
?>

<?php
// Fetch Triage Trends
$triage_trend = db_select("SELECT severity_score, created_at FROM triage_analysis WHERE patient_id = $1 ORDER BY created_at DESC LIMIT 2", [$patient_id]);
$health_improvement = null;
if (count($triage_trend) >= 2) {
    $curr_score = (int)$triage_trend[0]['severity_score'];
    $prev_score = (int)$triage_trend[1]['severity_score'];
    if ($curr_score < $prev_score) {
        $health_improvement = [
            'type' => 'score',
            'diff' => $prev_score - $curr_score,
            'msg' => "Overall health risk decreased by " . ($prev_score - $curr_score) . " points."
        ];
    }
}

// Lab Result Improvement Check
$lab_improvements = [];
$completed_labs = db_select("SELECT test_type, result_data, created_at FROM laboratory_tests WHERE patient_id = $1 AND status = 'completed' ORDER BY created_at DESC LIMIT 10", [$patient_id]);
$test_groups = [];
foreach ($completed_labs as $l) {
    $test_groups[$l['test_type']][] = $l;
}
foreach ($test_groups as $type => $tests) {
    if (count($tests) >= 2) {
        $curr_res = json_decode($tests[0]['result_data'], true);
        $prev_res = json_decode($tests[1]['result_data'], true);
        $curr_findings = strtolower($curr_res['findings'] ?? $curr_res['summary'] ?? '');
        $prev_findings = strtolower($prev_res['findings'] ?? $prev_res['summary'] ?? '');
        
        if ((strpos($curr_findings, 'normal') !== false || strpos($curr_findings, 'stable') !== false) && 
            (strpos($prev_findings, 'abnormal') !== false || strpos($prev_findings, 'elevated') !== false || strpos($prev_findings, 'high') !== false)) {
            $lab_improvements[] = [
                'test' => $type,
                'msg' => "Your $type results have normalized since " . date('M d', strtotime($tests[1]['created_at'])) . "!"
            ];
        }
    }
}

// Vitals Improvement Check
$vital_trends = [];
$v_metrics = ['bp_systolic' => [90, 120, 'BP'], 'heart_rate' => [60, 100, 'Heart Rate'], 'glucose' => [70, 140, 'Glucose']];
foreach ($v_metrics as $m_type => $meta) {
    $data = db_select("SELECT metric_value, recorded_at FROM patient_health_metrics WHERE patient_id = $1 AND metric_type = $2 ORDER BY recorded_at DESC LIMIT 2", [$patient_id, $m_type]);
    if (count($data) >= 2) {
        $curr = (float)json_decode($data[0]['metric_value'], true)['value'];
        $prev = (float)json_decode($data[1]['metric_value'], true)['value'];
        $low = $meta[0]; $high = $meta[1];
        
        // Improvement if previous was out of range and current is closer to or inside range
        $prev_out = ($prev < $low || $prev > $high);
        $curr_dist = min(abs($curr - $low), abs($curr - $high));
        if ($curr >= $low && $curr <= $high) $curr_dist = 0;
        
        $prev_dist = min(abs($prev - $low), abs($prev - $high));
        if ($prev >= $low && $prev <= $high) $prev_dist = 0;

        if ($prev_out && $curr_dist < $prev_dist) {
            $vital_trends[] = [
                'type' => $meta[2],
                'msg' => "Your " . $meta[2] . " is normalizing and moving closer to the target range."
            ];
        }
    }
}
?>

<?php if ($latest_triage || $health_improvement || !empty($lab_improvements) || !empty($vital_trends)): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px;">
        
        <!-- AI Health Analysis -->
        <?php if ($latest_triage): ?>
        <div class="card" style="border-left: 5px solid #8e44ad; height: 100%;">
            <div class="card-header" style="background: linear-gradient(to right, #f3e5f5, #fff); display: flex; justify-content: space-between; align-items: center;">
                <h5 style="margin:0; color: #8e44ad; font-size: 1rem;"><i class="fas fa-robot"></i> AI Health Analysis</h5>
                <span class="badge" style="background: <?php echo ($latest_triage['severity_score'] >= 7) ? '#fee2e2' : (($latest_triage['severity_score'] >= 4) ? '#fef3c7' : '#dcfce7'); ?>; color: <?php echo ($latest_triage['severity_score'] >= 7) ? '#b91c1c' : (($latest_triage['severity_score'] >= 4) ? '#92400e' : '#166534'); ?>;">
                    Score: <?php echo $latest_triage['severity_score']; ?>/10
                </span>
            </div>
            <div class="card-body" style="padding: 15px;">
                <p style="font-size: 0.9rem; margin-bottom: 10px;"><strong>Finding:</strong> <?php echo nl2br(htmlspecialchars($latest_triage['ai_findings'] ?? 'No findings.')); ?></p>
                <small class="text-muted">Analyzed: <?php echo date('M d, h:i A', strtotime($latest_triage['created_at'])); ?></small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Health Trends & Improvements -->
        <div class="card" style="border-left: 5px solid #10b981; height: 100%;">
            <div class="card-header" style="background: linear-gradient(to right, #ecfdf5, #fff);">
                <h5 style="margin:0; color: #059669; font-size: 1rem;"><i class="fas fa-chart-line"></i> Health Progress</h5>
            </div>
            <div class="card-body" style="padding: 15px;">
                <?php if (!$health_improvement && empty($lab_improvements)): ?>
                    <p style="font-size: 0.9rem; color: #64748b; font-style: italic;">Monitoring your progress. Keep following your treatment plan!</p>
                <?php else: ?>
                    <?php if ($health_improvement): ?>
                        <div style="background: #f0fdf4; border-radius: 8px; padding: 10px; margin-bottom: 10px; border-left: 3px solid #10b981;">
                            <div style="color: #166534; font-weight: 700; font-size: 0.9rem;">
                                <i class="fas fa-arrow-up"></i> Health Score Improved!
                            </div>
                            <div style="font-size: 0.85rem; color: #15803d;"><?php echo $health_improvement['msg']; ?></div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($lab_improvements as $imp): ?>
                        <div style="background: #eff6ff; border-radius: 8px; padding: 10px; margin-bottom: 10px; border-left: 3px solid #3b82f6;">
                            <div style="color: #1e40af; font-weight: 700; font-size: 0.9rem;">
                                <i class="fas fa-check-circle"></i> Lab Improvement
                            </div>
                            <div style="font-size: 0.85rem; color: #1e3a8a;"><?php echo $imp['msg']; ?></div>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($vital_trends as $vt): ?>
                        <div style="background: #fff7ed; border-radius: 8px; padding: 10px; margin-bottom: 10px; border-left: 3px solid #f97316;">
                            <div style="color: #9a3412; font-weight: 700; font-size: 0.9rem;">
                                <i class="fas fa-heartbeat"></i> Vital Sign Trend
                            </div>
                            <div style="font-size: 0.85rem; color: #7c2d12;"><?php echo $vt['msg']; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        My Health Overview
        <div>
            <a href="/modules/patient_management/symptom_checker.php" class="btn-sm btn-info" style="text-decoration: none; color: white; background-color: #0284c7; border: none;">
                <i class="fas fa-robot"></i> AI Symptom Checker
            </a>
        </div>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            
            <!-- 1. Last Prescribed Meds -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px;">
                <h6 style="margin-top: 0; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-pills" style="color: #3b82f6;"></i> Last Prescribed Meds
                </h6>
                <?php if (empty($last_meds)): ?>
                    <p style="font-size: 0.85rem; color: #64748b;">No recent prescriptions found.</p>
                <?php else: ?>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <?php foreach (array_slice($last_meds, 0, 3) as $med): ?>
                            <li style="font-size: 0.85rem; margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px;">
                                <strong style="color: #334155;"><?php echo htmlspecialchars($med['name']); ?></strong><br>
                                <span style="color: #64748b; font-size: 0.75rem;"><?php echo htmlspecialchars($med['dosage']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/modules/ehr/prescriptions.php" style="font-size: 0.75rem; color: #3b82f6; text-decoration: none; font-weight: 600;">View All Prescriptions &rarr;</a>
                <?php endif; ?>
            </div>

            <!-- 2. Past Lab Results -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px;">
                <h6 style="margin-top: 0; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-flask" style="color: #8b5cf6;"></i> Past Lab Results
                </h6>
                <?php if (empty($recent_labs)): ?>
                    <p style="font-size: 0.85rem; color: #64748b;">No recent lab tests found.</p>
                <?php else: ?>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <?php foreach ($recent_labs as $lab): ?>
                            <li style="font-size: 0.85rem; margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px; display: flex; justify-content: space-between;">
                                <span>
                                    <strong style="color: #334155;"><?php echo htmlspecialchars($lab['test_type']); ?></strong><br>
                                    <span style="color: #64748b; font-size: 0.75rem;"><?php echo date('M d, Y', strtotime($lab['created_at'])); ?></span>
                                </span>
                                <span class="badge" style="height: fit-content; background: <?php echo $lab['status'] == 'completed' ? '#dcfce7' : '#fef3c7'; ?>; color: <?php echo $lab['status'] == 'completed' ? '#166534' : '#92400e'; ?>; font-size: 0.7rem;">
                                    <?php echo ucfirst($lab['status']); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/modules/lab/results.php" style="font-size: 0.75rem; color: #3b82f6; text-decoration: none; font-weight: 600;">View All Lab Results &rarr;</a>
                <?php endif; ?>
            </div>

            <!-- 3. Upcoming Appointments -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px;">
                <h6 style="margin-top: 0; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-calendar-alt" style="color: #f59e0b;"></i> Next Appointments
                </h6>
                <?php if (empty($future_appointments)): ?>
                    <p style="font-size: 0.85rem; color: #64748b;">No upcoming appointments scheduled.</p>
                <?php else: ?>
                    <ul style="list-style: none; padding: 0; margin: 0;">
                        <?php foreach ($future_appointments as $fa): ?>
                            <li style="font-size: 0.85rem; margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px;">
                                <strong style="color: #334155;">Dr. <?php echo htmlspecialchars($fa['doc_last']); ?></strong><br>
                                <span style="color: #64748b; font-size: 0.75rem;">
                                    <?php echo date('M d, Y', strtotime($fa['appointment_time'])); ?> at <?php echo date('h:i A', strtotime($fa['appointment_time'])); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/modules/ehr/appointments.php" style="font-size: 0.75rem; color: #3b82f6; text-decoration: none; font-weight: 600;">Manage Appointments &rarr;</a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Message Doctor Floating Button -->
<div onclick="openDoctorChat()" style="position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background: #2563eb; color: white; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 24px; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4); cursor: pointer; z-index: 1000; transition: transform 0.2s;">
    <i class="fas fa-comment-medical"></i>
</div>

<!-- Chat Modal -->
<div id="chatModal" class="modal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(5px);">
    <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 0; border: none; width: 500px; max-width: 95%; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; height: 70vh;">
        
        <!-- Header -->
        <div style="padding: 15px 20px; background: #fff; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 40px; height: 40px; background: #e0f2fe; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #0284c7;">
                    <i class="fas fa-user-md"></i>
                </div>
                <div>
                    <h3 id="chatName" style="margin: 0; font-size: 1.1em;">Dr. Physician</h3>
                    <span style="font-size: 0.8em; color: #2ecc71;"><i class="fas fa-circle" style="font-size: 0.6em;"></i> Online</span>
                </div>
            </div>
            <span class="close" onclick="document.getElementById('chatModal').style.display='none'" style="font-size: 28px; cursor: pointer; color: #aaa;">&times;</span>
        </div>

        <!-- Body -->
        <div id="chatBody" style="flex-grow: 1; padding: 20px; overflow-y: auto; background: #f9f9f9; display: flex; flex-direction: column; gap: 15px;">
            <!-- Messages go here -->
        </div>

        <!-- Footer -->
        <div style="padding: 15px; background: #fff; border-top: 1px solid #eee;">
            <form id="chatForm" onsubmit="sendMessage(event)" style="display: flex; gap: 10px;">
                <input type="hidden" id="chatRecipientId" value="">
                <input type="text" id="chatInput" class="form-control" placeholder="Type a message..." style="border-radius: 25px; padding-left: 20px;" required>
                <button type="submit" class="btn btn-primary" style="border-radius: 50%; width: 45px; height: 45px; display: flex; justify-content: center; align-items: center;">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const currentUserId = <?php echo $user_id; ?>;
let activeDoctorId = null;

function openDoctorChat() {
    fetch('../modules/messaging/api.php?action=get_conversations')
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            document.getElementById('chatModal').style.display = 'block';
            
            if(data.data.length > 0) {
                const doc = data.data[0];
                setupChat(doc.user_id, doc.name);
            } else {
                <?php if($next_appt): 
                       $doc_id_q = db_select_one("SELECT doctor_id FROM appointments WHERE id = $1", [$next_appt['id']]);
                       if($doc_id_q):
                           $doc_user = db_select_one("SELECT user_id FROM staff WHERE id = $1", [$doc_id_q['doctor_id']]);
                           if($doc_user):
                ?>
                    setupChat(<?php echo $doc_user['user_id']; ?>, 'My Doctor');
                <?php else: ?>
                    alert("No doctor assigned or found on upcoming appointment.");
                <?php endif; endif; else: ?>
                    document.getElementById('chatBody').innerHTML = '<div style="text-align:center;color:#888;">No history found. Please book an appointment first.</div>';
                <?php endif; ?>
            }
        }
    });
}

function setupChat(userId, name) {
    activeDoctorId = userId;
    document.getElementById('chatRecipientId').value = userId;
    document.getElementById('chatName').innerText = name;
    loadThread(userId);
    
    if(window.chatInterval) clearInterval(window.chatInterval);
    window.chatInterval = setInterval(() => {
        if(document.getElementById('chatModal').style.display !== 'none') {
            loadThread(userId);
        }
    }, 3000);
}

function loadThread(userId) {
    fetch(`../modules/messaging/api.php?action=get_thread&user_id=${userId}`)
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            const body = document.getElementById('chatBody');
            const isScrolledToBottom = body.scrollHeight - body.scrollTop <= body.clientHeight + 100;
            
            let html = '';
            if(data.data.length === 0) html = '<div style="text-align:center;color:#ccc;margin-top:20px;">Start a conversation...</div>';
            
            data.data.forEach(msg => {
                const isMe = msg.sender_id == currentUserId;
                html += `
                <div style="display: flex; justify-content: ${isMe ? 'flex-end' : 'flex-start'};">
                    <div style="max-width: 70%; padding: 10px 15px; border-radius: 15px; font-size: 0.95em; line-height: 1.4; 
                        ${isMe ? 'background: #2563eb; color: white; border-bottom-right-radius: 2px;' : 'background: #fff; border: 1px solid #eee; color: #333; border-bottom-left-radius: 2px; box-shadow: 0 2px 5px rgba(0,0,0,0.02);'}">
                        ${msg.message_body}
                        <div style="font-size: 0.7em; margin-top: 5px; opacity: 0.7; text-align: right;">
                             ${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                        </div>
                    </div>
                </div>`;
            });
            
            body.innerHTML = html;
            if(isScrolledToBottom) body.scrollTop = body.scrollHeight;
        }
    });
}

function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if(!msg) return;
    
    const recipientId = document.getElementById('chatRecipientId').value;
    
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('recipient_id', recipientId);
    formData.append('message', msg);
    
    fetch('../modules/messaging/api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            input.value = '';
            loadThread(recipientId);
        } else {
            alert('Error sending: ' + data.message);
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
