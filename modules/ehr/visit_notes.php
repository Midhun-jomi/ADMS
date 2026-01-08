<?php
// modules/ehr/visit_notes.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['doctor', 'patient']);

$role = get_user_role();

$appointment_id = $_GET['appointment_id'] ?? null;

$is_embedded = isset($_GET['embedded']);

if (!$appointment_id) {
    if (!$is_embedded) {
        $page_title = "Consultation & Notes";
        include '../../includes/header.php';
    } else {
        echo '<link rel="stylesheet" href="../../assets/css/style.css">';
    }
    echo "<div class='alert alert-danger'>Appointment ID required.</div>";
    if (!$is_embedded) include '../../includes/footer.php';
    exit();
}

// Fetch appointment + patient details
$appt = db_select_one("SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.gender, p.phone, u.email as p_email, p.address, p.id as patient_id, p.uhid, p.medical_history, u.profile_image 
                       FROM appointments a 
                       JOIN patients p ON a.patient_id = p.id 
                       JOIN users u ON p.user_id = u.id
                       WHERE a.id = $1", [$appointment_id]);

if (!$appt) {
    $page_title = "Consultation & Notes";
    include '../../includes/header.php';
    echo "<div class='alert alert-danger'>Appointment not found.</div>";
    include '../../includes/footer.php';
    exit();
}

$patient_id = $appt['patient_id'];

// --- SMART ASSISTANT LOGIC (AI Sum-up) ---
$past_visits = db_select("SELECT reason, updated_at FROM appointments WHERE patient_id = $1 AND status = 'completed' AND id != $2 ORDER BY updated_at DESC", [$patient_id, $appointment_id]);

// Extract ALL Nurse Observations from current appointment reason
$nurse_notes_found = [];
if (preg_match_all('/\[Nurse Entry .*?\]: (.*?)(?=\n\n|$)/s', $appt['reason'] ?? '', $matches)) {
    foreach ($matches[1] as $n) {
        $nurse_notes_found[] = trim($n);
    }
}

// Structured UI Components
$ai_patient_history = $appt['medical_history'] ?: 'No chronic conditions recorded.';
$ai_nurse_triage = !empty($nurse_notes_found) ? implode(" | ", $nurse_notes_found) : "Not yet provided.";
$ai_lab_insight = "No recent lab results.";

// Cross-Call Intelligence: Pattern Recognition
$ai_risk_tags = [];
$med_hist_lower = strtolower($ai_patient_history);

// Fetch Vitals for AI Analysis - STRICTLY for this visit
$vitals_raw = db_select("SELECT metric_type, metric_value, recorded_at FROM patient_health_metrics WHERE appointment_id = $1 ORDER BY recorded_at DESC", [$appointment_id]);

$latest_vitals = [];
$vital_alerts = [];
$vitals_taken = false;

if (!empty($vitals_raw)) {
    $vitals_taken = true;
    foreach ($vitals_raw as $v) {
        if (!isset($latest_vitals[$v['metric_type']])) {
            $val_data = json_decode($v['metric_value'], true);
            $latest_vitals[$v['metric_type']] = $val_data;
            
            $val = floatval($val_data['value']);
            if ($v['metric_type'] == 'heart_rate' && ($val > 100 || $val < 60)) $vital_alerts[] = "Heart Rate ($val) abnormal.";
            if ($v['metric_type'] == 'bp_systolic' && $val > 140) {
                $vital_alerts[] = "Hypertension detected.";
                if (strpos($med_hist_lower, 'heart') !== false) {
                    $ai_risk_tags[] = "⚠️ Cardiac Correlation: High BP + Cardiac History.";
                }
            }
            if ($v['metric_type'] == 'glucose' && $val > 180) {
                $vital_alerts[] = "Hyperglycemia detected.";
                if (strpos($med_hist_lower, 'diabet') !== false) {
                    $ai_risk_tags[] = "⚠️ Diabetic Alert: Elevated glucose + Diabetes History.";
                }
            }
        }
    }
}

// Fetch Latest Lab Details
$latest_lab = db_select_one("SELECT test_type, result_data FROM laboratory_tests WHERE patient_id = $1 AND status = 'completed' ORDER BY updated_at DESC", [$patient_id]);
if ($latest_lab && $latest_lab['result_data']) {
     $lr = json_decode($latest_lab['result_data'], true);
     $sum = $lr['summary'] ?? $lr['findings'] ?? 'Normal';
     $ai_lab_insight = "<strong>" . $latest_lab['test_type'] . ":</strong> " . $sum;
     if (stripos($sum, 'elevated') !== false || stripos($sum, 'high') !== false) {
         $ai_risk_tags[] = "⚠️ Lab Alert: Systemic stress indicated in " . $latest_lab['test_type'];
     }
}

// Handle POST Requests (Doctor Only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    $doc_id = get_user_id();
    $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$doc_id]);
    
    // 1. Save Notes & History
    if (isset($_POST['save_note']) || isset($_POST['complete_visit'])) {
        $notes = $_POST['notes'];
        $history = $_POST['medical_history'] ?? '';
        $is_completion = isset($_POST['complete_visit']);
        
        // Append new note timestamped
        $timestamp = date('M d, Y h:i A');
        $updated_reason = $appt['reason'] . "\n\n[" . $timestamp . "]: " . $notes;
        
        $new_status = $is_completion ? 'completed' : $appt['status'];
        
        // Update Appointment
        $sql = "UPDATE appointments SET reason = $1, status = $2, updated_at = NOW() WHERE id = $3";
        db_query($sql, [$updated_reason, $new_status, $appointment_id]);
        
        // Update Patient History
        $sql_hist = "UPDATE patients SET medical_history = $1 WHERE id = $2";
        db_query($sql_hist, [$history, $patient_id]);
        
        if ($is_completion) {
            // Handle Follow-up Appointment
            if (!empty($_POST['follow_up_date'])) {
                $fu_date = $_POST['follow_up_date'];
                // Create follow up appointment
                db_insert('appointments', [
                    'patient_id' => $patient_id,
                    'doctor_id' => $staff['id'] ?? null,
                    'appointment_time' => $fu_date . ' 10:00:00', // Default follow-up time
                    'status' => 'scheduled',
                    'reason' => 'Follow-up from visit on ' . date('M d, Y')
                ]);
                $success = "Consultation completed and follow-up scheduled for $fu_date.";
            } else {
                $success = "Consultation completed and bill generated.";
            }

            try {
                $bill_data = [
                    'patient_id' => $patient_id,
                    'appointment_id' => $appointment_id,
                    'total_amount' => 50.00,
                    'status' => 'pending',
                    'service_description' => 'Consultation Fee'
                ];
                $check = db_select_one("SELECT id FROM billing WHERE appointment_id = $1 AND service_description = 'Consultation Fee'", [$appointment_id]);
                if (!$check) db_insert('billing', $bill_data);
            } catch (Exception $e) { error_log("Auto-billing failed: " . $e->getMessage()); }
            
            // Find Next Patient in Queue
            $next_appt = db_select_one("SELECT id FROM appointments WHERE doctor_id = $1 AND status = 'scheduled' AND appointment_time > NOW() ORDER BY appointment_time ASC LIMIT 1", [$staff['id'] ?? 0]);
            $next_url = $next_appt ? "?appointment_id=" . $next_appt['id'] : "appointments.php";
            
            $success = "Visit completed successfully. <a href='$next_url' class='btn btn-sm btn-primary'>Proceed to Next Patient</a>";
        } else {
            $success = "Note & History saved successfully.";
        }
    }

    // 2. Handle Lab Order (Support Multiple)
    if (isset($_POST['order_lab_test'])) {
        $tests_input = $_POST['order_lab_test'];
        
        // Handle both array (checkboxes) and string (legacy/single)
        $test_types = is_array($tests_input) ? $tests_input : explode(',', $tests_input);
        
        $doc_id = get_user_id();
        $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$doc_id]);
        $doc_pk = $doctor ? $doctor['id'] : null;

        if ($doc_pk && !empty($test_types)) {
            $count = 0;
            // Lab Test Price Map (Standard Rates)
            $lab_prices = [
                'Complete Blood Count (CBC)' => 300,
                'Lipid Profile' => 550,
                'Liver Function Test' => 600,
                'Renal Function Test' => 600,
                'Thyroid Profile' => 800,
                'Urinalysis' => 150,
                'Blood Sugar (Fasting)' => 100,
                'Blood Sugar (Post Prandial)' => 100,
                'HbA1c' => 400,
                'Electrolytes' => 450
            ];

            foreach ($test_types as $type) {
                $type = trim($type);
                if (!empty($type)) {
                    // 1. Create Lab Order
                    $sql = "INSERT INTO laboratory_tests (patient_id, doctor_id, test_type, status) VALUES ($1, $2, $3, 'ordered')";
                    db_query($sql, [$patient_id, $doc_pk, $type]);
                    
                    // 2. Generate Bill for this test
                    $price = $lab_prices[$type] ?? 250; // Default fallback price
                    db_insert('billing', [
                        'patient_id' => $patient_id,
                        'appointment_id' => $appointment_id,
                        'total_amount' => $price,
                        'status' => 'pending',
                        'service_description' => "Lab Test: $type"
                    ]);

                    $count++;
                }
            }
            if ($count > 0) {
                $success = "$count Lab Order(s) Sent & Billed Successfully.";
            }
        }
    }

    // 2.5 Handle Radiology Order
    if (isset($_POST['order_radiology'])) {
        $rad_input = $_POST['order_radiology'];
        $rad_types = is_array($rad_input) ? $rad_input : explode(',', $rad_input);
        
        $doc_id = get_user_id();
        $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$doc_id]);
        $doc_pk = $doctor ? $doctor['id'] : null;

        if ($doc_pk && !empty($rad_types)) {
            $count = 0;
            // Radiology Price Map
            $rad_prices = [
                'X-Ray (Chest)' => 500,
                'X-Ray (Limb)' => 600,
                'MRI (Brain)' => 3500,
                'CT Scan (Abdomen)' => 2500,
                'Ultrasound' => 1200,
                'ECG' => 300
            ];

            foreach ($rad_types as $type) {
                $type = trim($type);
                if (!empty($type)) {
                    // 1. Create Radiology Request
                    db_insert('radiology_reports', [
                        'patient_id' => $patient_id,
                        'doctor_id' => $doc_pk,
                        'report_type' => $type,
                        'status' => 'ordered'
                    ]);

                    // 2. Billing
                    $price = $rad_prices[$type] ?? 1000;
                    db_insert('billing', [
                        'patient_id' => $patient_id,
                        'appointment_id' => $appointment_id,
                        'total_amount' => $price,
                        'status' => 'pending',
                        'service_description' => "Radiology: $type"
                    ]);

                    $count++;
                }
            }
            if ($count > 0) {
                $success = "$count Radiology Request(s) Sent & Billed.";
            }
        }
    }

    // 3. Handle Medication Prescription (AJAX)
    // We check for a special header or post val to know it's an AJAX call, or just standard POST
    if (isset($_POST['add_medication'])) {
        $med_name = $_POST['med_name'];
        $med_freq = $_POST['med_frequency']; // e.g. 1-0-1
        $med_days = (int)$_POST['med_days']; // Duration
        $med_timing = $_POST['med_timing'];
        $med_note = $_POST['med_note'];
        
        // Calculate Qty: count '1's in frequency string * days
        $pills_per_day = substr_count($med_freq, '1');
        if ($med_freq === '1-1-1') $pills_per_day = 3;
        if ($pills_per_day == 0 && stripos($med_freq, '1') !== false) $pills_per_day = 1; // Fallback
        
        $med_qty = $pills_per_day * $med_days;
        if ($med_freq === 'SOS') $med_qty = $_POST['med_qty_sos'] ?? 5; // Default SOS qty

        $doc_id = get_user_id();
        $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$doc_id]);
        $doc_pk = $doctor ? $doctor['id'] : null;

        if ($doc_pk) {
            $existing_rx = db_select_one("SELECT * FROM prescriptions WHERE appointment_id = $1", [$appointment_id]);
            
            $new_item = [
                'name' => $med_name, 
                'quantity' => $med_qty, 
                'duration' => "$med_days days",
                'dosage' => "$med_freq | $med_timing" . ($med_note ? ". Note: $med_note" : "") 
            ];

            if ($existing_rx) {
                $current_details = json_decode($existing_rx['medication_details'], true) ?: [];
                
                // --- UNIQUE CHECK: Prevent duplicate meds in same visit ---
                foreach ($current_details as $item) {
                    if (strcasecmp($item['name'], $med_name) === 0) {
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['status' => 'error', 'message' => "Medicine '$med_name' is already prescribed for this visit."]);
                            exit;
                        } else {
                            $error = "Medicine '$med_name' is already in the list.";
                            goto skip_med_save;
                        }
                    }
                }

                $current_details[] = $new_item;
                $new_json = json_encode($current_details);
                db_query("UPDATE prescriptions SET medication_details = $1, status = 'pending', created_at = NOW() WHERE id = $2", [$new_json, $existing_rx['id']]);
            } else {
                db_insert('prescriptions', [
                    'patient_id' => $patient_id,
                    'doctor_id' => $doc_pk,
                    'appointment_id' => $appointment_id,
                    'medication_details' => json_encode([$new_item]),
                    'status' => 'pending'
                ]);
            }
            skip_med_save:
            
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Medication saved', 'qty' => $med_qty]);
                exit;
            } else {
                $success = "Medication added to prescription.";
            }
        }
    }

    // Refresh Data
    $appt = db_select_one("SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.gender, p.phone, u.email as p_email, p.address, p.id as patient_id, p.medical_history, u.profile_image 
                           FROM appointments a 
                           JOIN patients p ON a.patient_id = p.id 
                           JOIN users u ON p.user_id = u.id 
                           WHERE a.id = $1", [$appointment_id]);
}

// Fetch Vitals (Strictly for this Visit)
$vitals = db_select("SELECT metric_type, metric_value, recorded_at FROM patient_health_metrics WHERE appointment_id = $1 ORDER BY recorded_at DESC", [$appointment_id]);
// Process vitals...
$latest_vitals = [];
foreach ($vitals as $v) {
    if (!isset($latest_vitals[$v['metric_type']])) {
        $latest_vitals[$v['metric_type']] = json_decode($v['metric_value'], true);
        $latest_vitals[$v['metric_type']]['date'] = $v['recorded_at'];
    }
}

// Fetch Medical History (Past Visits)
$history = db_select("SELECT appointment_time, reason, status FROM appointments WHERE patient_id = $1 AND status = 'completed' AND id != $2 ORDER BY appointment_time DESC LIMIT 5", [$patient_id, $appointment_id]);

// Fetch Inventory for Meds Modal
$inventory = db_select("SELECT medication_name, quantity FROM pharmacy_inventory ORDER BY medication_name");

// Fetch Current Prescriptions for this visit
$current_rx = db_select_one("SELECT medication_details FROM prescriptions WHERE appointment_id = $1", [$appointment_id]);
$prescribed_meds = $current_rx ? json_decode($current_rx['medication_details'], true) : [];

$age = date_diff(date_create($appt['date_of_birth']), date_create('today'))->y;

// Fetch Lab Results for Modal
$lab_results_list = db_select("SELECT * FROM laboratory_tests WHERE patient_id = $1 ORDER BY created_at DESC", [$patient_id]);

$page_title = "Consultation & Notes";
include '../../includes/header.php';
?>

<style>
    .visit-grid { display: grid; grid-template-columns: 350px 1fr; gap: 25px; }
    .profile-card { background: white; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); padding: 25px; text-align: center; border: 1px solid #eee; }
    .profile-img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 15px; border: 4px solid #f0f2f5; }
    .info-label { font-size: 0.85em; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 10px; display: block; }
    .vitals-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px; }
    .vital-box { background: #f8f9fa; border-radius: 10px; padding: 10px; text-align: center; border: 1px solid #e9ecef; }
    .vital-val { font-size: 1.2em; font-weight: 700; color: #21a9af; }
    .vital-unit { font-size: 0.7em; color: #666; }
    .main-panel { background: white; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); padding: 30px; border: 1px solid #eee; }
    .section-title { font-size: 1.1em; font-weight: 700; color: #344767; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    
    /* Lab Modal & Med Modal Styles */
    .lab-modal, .med-modal {
        display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
    }
    .lab-modal-content, .med-modal-content {
        background-color: #fefefe; margin: 5% auto; padding: 25px; border: 1px solid #888; width: 650px; border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: slideDown 0.3s ease-out;
    }
    
    @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    
    .lab-checkbox {
        display: block; padding: 10px; margin: 5px 0; border: 1px solid #ddd; border-radius: 5px; cursor: pointer; transition: 0.2s;
    }
    .lab-checkbox:hover { background: #f0f0f0; }
    .lab-checkbox input { margin-right: 10px; transform: scale(1.2); }
    .lab-checkbox.active { background-color: #e3f2fd; border-color: #2196f3; color: #0d47a1; }
    
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
</style>

<?php if (!$is_embedded): ?>
<div class="header-actions" style="margin-bottom: 20px;">
    <a href="appointments.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Schedule</a>
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if ($is_embedded): ?>
    <!-- QUICK ACTIONS TOOLBAR (EMBEDDED ONLY) -->
    <div style="background: #eef2f7; padding: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border-bottom: 1px solid #dae1e7; position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
        <span style="font-size: 0.85em; font-weight: 600; color: #555; margin-right: 5px;">Quick Actions:</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openLabModal()"><i class="fas fa-flask"></i> Order Lab</button>
        <button type="button" class="btn btn-success btn-sm" onclick="openMedModal()"><i class="fas fa-pills"></i> Prescribe Meds</button>
        <button type="button" class="btn btn-warning btn-sm" onclick="openRadModal()"><i class="fas fa-x-ray"></i> Radiology</button>
        <button type="button" class="btn btn-info btn-sm" onclick="openViewLabModal()"><i class="fas fa-vial"></i> View Results</button>
        
        <div style="flex-grow: 1;"></div>
        
        <button onclick="document.querySelector('[name=complete_visit]').click()" class="btn btn-dark btn-sm"><i class="fas fa-check-circle"></i> Complete Visit</button>
    </div>
<?php endif; ?>

<div class="visit-grid">
    <!-- Left Sidebar -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="profile-card">
            <?php $img_src = $appt['profile_image'] ?: "https://ui-avatars.com/api/?name=" . urlencode($appt['first_name'].' '.$appt['last_name']); ?>
            <img src="<?php echo $img_src; ?>" class="profile-img">
            <h3 style="margin: 0;"><?php echo htmlspecialchars($appt['first_name'].' '.$appt['last_name']); ?></h3>
            <p style="color: #666; font-size: 0.9em; margin: 5px 0 15px;">Patient ID: (P-<?php echo str_pad($appt['uhid'] ?? '0', 4, '0', STR_PAD_LEFT); ?>)</p>
            <div style="text-align: left;">
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0;">
                    <span style="color: #666;">Age/Gender</span><strong><?php echo $age; ?> yrs / <?php echo ucfirst($appt['gender']); ?></strong>
                </div>
                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0;">
                    <span style="color: #666;">DOB</span><strong><?php echo date('M d, Y', strtotime($appt['date_of_birth'])); ?></strong>
                </div>
                <div style="padding: 8px 0;">
                    <span class="info-label">Contact</span><div style="font-size: 0.9em;"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($appt['phone']); ?></div>
                </div>
            </div>
        </div>

        <div class="profile-card" style="text-align: left;">
            <div class="section-title" style="margin-bottom: 15px;"><i class="fas fa-heartbeat" style="color: #e91e63;"></i> Visit Vitals</div>
            <?php if (empty($latest_vitals)): ?>
                <div style="text-align: center; padding: 15px; background: #fffcf0; border: 1px dashed #f6ad55; border-radius: 10px;">
                    <i class="fas fa-user-nurse" style="font-size: 1.5rem; color: #f6ad55; margin-bottom: 10px; display: block;"></i>
                    <p style="color: #9c4221; font-style: italic; font-size: 0.85em; margin: 0;">Vitals Not Taken<br>(Waiting for Nurse)</p>
                </div>
            <?php else: ?>
                <div class="vitals-grid">
                    <?php 
                        $metrics_map = ['heart_rate' => ['label' => 'HR', 'icon' => 'fa-heart'], 'temperature' => ['label' => 'Temp', 'icon' => 'fa-thermometer-half'], 'bp_systolic' => ['label' => 'BP Sys', 'icon' => 'fa-tint'], 'glucose' => ['label' => 'Glu', 'icon' => 'fa-cube'], 'weight' => ['label' => 'Weight', 'icon' => 'fa-weight']];
                        foreach ($metrics_map as $key => $meta): if (isset($latest_vitals[$key])):
                            $val = $latest_vitals[$key]['value']; $unit = $latest_vitals[$key]['unit'];
                    ?>
                    <div class="vital-box"><span class="vital-name"><?php echo $meta['label']; ?></span><div class="vital-val"><?php echo $val; ?></div><span class="vital-unit"><?php echo $unit; ?></span></div>
                    <?php endif; endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="profile-card" style="text-align: left; background: #f0f7ff; border: 1px solid #cce3ff;">
            <div class="section-title" style="margin-bottom: 15px; color: #0056b3;"><i class="fas fa-robot"></i> Smart AI Assistant</div>
            
            <!-- AI Clinical Intelligence -->
            <div style="background: white; border-radius: 12px; padding: 15px; margin-bottom: 15px; border: 1px solid #dce8f5;">
                <label class="info-label" style="margin-top:0; color: #21a9af;"><i class="fas fa-stethoscope"></i> Nurse Triage</label>
                <p style="font-size: 0.9em; margin: 5px 0; color: #333; font-weight: 500;">
                    <?php echo $ai_nurse_triage; ?>
                </p>
            </div>

            <div style="background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 15px; border: 1px solid #eef2f6;">
                <label class="info-label" style="margin-top:0; color: #4a5568;"><i class="fas fa-history"></i> Patient History</label>
                <p style="font-size: 0.85em; margin: 5px 0; color: #4a5568;">
                    <?php echo $ai_patient_history; ?>
                </p>
            </div>

            <div style="background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 15px; border: 1px solid #eef2f6;">
                <label class="info-label" style="margin-top:0; color: #4a5568;"><i class="fas fa-flask"></i> Lab Insight</label>
                <p style="font-size: 0.85em; margin: 5px 0; color: #4a5568;">
                    <?php echo $ai_lab_insight; ?>
                </p>
            </div>

            <!-- Risk Alerts Section -->
            <?php if (!empty($ai_risk_tags) || !empty($vital_alerts)): ?>
                <div style="background: #fff5f5; border-radius: 12px; padding: 15px; border: 1px solid #fed7d7;">
                    <label class="info-label" style="margin-top:0; color: #c53030;"><i class="fas fa-exclamation-circle"></i> AI RISK ANALYSIS</label>
                    <ul style="font-size: 0.85em; margin: 8px 0 0; padding-left: 20px; color: #c53030; font-weight: 600;">
                        <?php foreach($ai_risk_tags as $tag): ?>
                            <li style="margin-bottom: 5px;"><?php echo $tag; ?></li>
                        <?php endforeach; ?>
                        <?php foreach($vital_alerts as $alert): ?>
                            <li style="margin-bottom: 5px;"><?php echo $alert; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div style="background: #f0fff4; border-radius: 12px; padding: 15px; border: 1px solid #c6f6d5;">
                    <p style="font-size: 0.85em; color: #2f855a; margin: 0; font-weight: 600;"><i class="fas fa-check-shield"></i> Stability Confirmed: No active clinical risks detected.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Content -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="main-panel">
            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span><i class="fas fa-user-md"></i> Clinical Notes & Consultation</span>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if ($appt['status'] !== 'completed'): ?>
                        <form method="POST" style="margin: 0;">
                            <input type="hidden" name="call_patient_now" value="1">
                            <button type="submit" class="btn btn-sm" style="background: #ebf8ff; color: #2b6cb0; border: 1px solid #bee3f8; font-weight: 700; height: 32px; padding: 0 15px;">
                                <i class="fas fa-bullhorn"></i> Call Patient
                            </button>
                        </form>
                    <?php endif; ?>
                    <span class="badge <?php echo $appt['status'] == 'completed' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst($appt['status']); ?></span>
                </div>
            </div>

            <?php if ($is_embedded): ?>
                <!-- QUICK ACTIONS TOOLBAR (EMBEDDED ONLY) -->
                <div style="background: #eef2f7; padding: 10px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border: 1px solid #dae1e7;">
                    <span style="font-size: 0.85em; font-weight: 600; color: #555; margin-right: 5px;">Quick Actions:</span>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openLabModal()"><i class="fas fa-flask"></i> Order Lab</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="openMedModal()"><i class="fas fa-pills"></i> Prescribe Meds</button>
                    <button type="button" class="btn btn-warning btn-sm" onclick="openRadModal()"><i class="fas fa-x-ray"></i> Radiology</button>
                    <button type="button" class="btn btn-info btn-sm" onclick="openViewLabModal()"><i class="fas fa-vial"></i> View Results</button>
                    
                    <div style="flex-grow: 1;"></div>
                    
                    <button onclick="document.querySelector('[name=complete_visit]').click()" class="btn btn-dark btn-sm"><i class="fas fa-check-circle"></i> Complete Visit</button>
                </div>
            <?php endif; ?>

            <?php if (isset($_POST['call_patient_now'])): 
                // Display room info if available
                $room_info = db_select_one("SELECT r.room_number FROM staff s JOIN rooms r ON s.primary_room_id = r.id WHERE s.id = $1", [$staff['id'] ?? 0]);
                $final_room = $room_info['room_number'] ?? 'OPD';
                
                // Log to public queue
                db_insert('public_queue', [
                    'patient_name' => $appt['first_name'] . ' ' . $appt['last_name'],
                    'room_number' => $final_room,
                    'status' => 'calling'
                ]);
            ?>
                <div class="alert alert-info animate-pulse" style="background: #e6fffa; border: 1px solid #b2f5ea; color: #2c7a7b; margin-bottom: 20px; border-radius: 12px; font-weight: 600;">
                    <i class="fas fa-satellite-dish"></i> Paging System: Patient has been called to Room <?php echo $final_room; ?>.
                </div>
            <?php endif; ?>

            <!-- Read-Only Visit History -->
            <label style="font-weight: 600; color: #555;">Previous Notes / HPI:</label>
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px solid #e9ecef; margin-bottom: 20px; white-space: pre-wrap; font-family: 'Inter', sans-serif;">
                <?php echo $appt['reason'] ?: 'No notes recorded.'; ?>
            </div>

                <?php if ($role === 'patient' || $role === 'doctor'): ?>
                    <div style="margin-top: 30px;">
                        <label style="font-weight: 600; color: #555;">Prescribed Medications:</label>
                        <?php 
                            $is_paid = db_select_one("SELECT id FROM billing WHERE appointment_id = $1 AND status = 'paid'", [$appointment_id]);
                            if ($role === 'patient' && !$is_paid): 
                        ?>
                            <div style="background: #fff8e6; padding: 20px; border-radius: 10px; border: 1px solid #ffe58f; text-align: center;">
                                <p style="color: #856404; margin-bottom: 10px;"><i class="fas fa-lock"></i> Prescription Locked</p>
                                <a href="../billing/invoices.php" class="btn btn-warning btn-sm">Pay Bill to View</a>
                            </div>
                        <?php else: ?>
                            <div style="background: #f0fdf4; padding: 20px; border-radius: 10px; border: 1px solid #bbf7d0;">
                                <?php if (empty($prescribed_meds)): ?>
                                    <p style="color: #666; font-style: italic; margin: 0;">No medications prescribed for this visit.</p>
                                <?php else: ?>
                                    <ul style="margin: 0; padding-left: 20px;">
                                        <?php foreach ($prescribed_meds as $pm): ?>
                                            <li style="margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f0fdf4;">
                                                <strong><?php echo htmlspecialchars($pm['name']); ?></strong> 
                                                <span class="badge badge-success"><?php echo htmlspecialchars($pm['quantity']); ?> tabs total</span>
                                                <br><small style="color: #065f46; font-weight: 600;"><i class="fas fa-calendar-day"></i> Duration: <?php echo htmlspecialchars($pm['duration'] ?? 'N/A'); ?></small>
                                                <br><small style="color: #555;"><?php echo htmlspecialchars($pm['dosage']); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <button onclick="window.print()" class="btn btn-outline-success btn-sm mt-3"><i class="fas fa-print"></i> Print Prescription</button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($role === 'doctor'): ?>
            <form method="POST" id="mainForm">
                <!-- Patient Medical History (Editable) -->
                <div class="form-group">
                    <label style="font-weight: 600; color: #2c3e50;">Patient Medical History</label>
                    <textarea name="medical_history" class="form-control" rows="4" placeholder="Enter chronic conditions, allergies, past surgeries..." style="border: 2px solid #e0e0e0; background: #fffbe6;"><?php echo htmlspecialchars($appt['medical_history'] ?? ''); ?></textarea>
                </div>

                <!-- Current Visit Note -->
                <div class="form-group">
                    <label style="font-weight: 600; color: #2c3e50;">Add New Clinical Note / Diagnosis</label>
                    <textarea name="notes" class="form-control" rows="6" placeholder="Enter clinical observations, diagnosis, and treatment plan..." style="border: 2px solid #e0e0e0;" required></textarea>
                </div>

                <div class="form-row" style="display: flex; gap: 15px; align-items: center; justify-content: space-between; margin-top: 20px; background: #f8f9fa; padding: 15px; border-radius: 10px;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div style="display: flex; flex-direction: column;">
                            <label style="font-size: 0.8em; color: #666; font-weight: 600;">Schedule Follow-up</label>
                            <input type="date" name="follow_up_date" class="form-control" style="width: 170px;" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="save_note" value="1" class="btn btn-info"><i class="fas fa-save"></i> Save Progress</button>
                            <button type="submit" name="complete_visit" value="1" class="btn btn-success" onclick="return confirm('Mark visit as completed and generate bill?');"><i class="fas fa-check-circle"></i> Complete Visit</button>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 5px;">
                        <!-- Lab Button Triggers Modal -->
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openLabModal()">
                            <i class="fas fa-flask"></i> Order Lab
                        </button>

                        <!-- Radiology Button -->
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openRadModal()">
                            <i class="fas fa-x-ray"></i> Order Radiology
                        </button>
                        
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openViewLabModal()">
                            <i class="fas fa-vial"></i> View Lab Results
                        </button>
                        
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openMedModal()"><i class="fas fa-pills"></i> Meds</button>
                    </div>
                </div>
            </form>

            <!-- Waiting Queue Section -->
            <div style="margin-top: 40px; border-top: 2px dashed #eee; padding-top: 20px;">
                <h5 style="color: #344767;"><i class="fas fa-list-ol"></i> Next in Queue</h5>
                <div style="display: flex; gap: 15px; overflow-x: auto; padding: 10px 0;">
                    <?php 
                        $d_id = get_user_id();
                        $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$d_id]);
                        $queue = db_select("SELECT a.id, a.appointment_time, p.first_name, p.last_name FROM appointments a JOIN patients p ON a.patient_id = p.id WHERE a.doctor_id = $1 AND a.status = 'scheduled' AND a.id != $2 AND a.appointment_time > (NOW() - INTERVAL '30 minutes') ORDER BY a.appointment_time ASC LIMIT 4", [$staff['id'] ?? 0, $appointment_id]);
                        if (empty($queue)):
                    ?>
                        <p style="color: #999; font-style: italic;">No other appointments pending today.</p>
                    <?php else: foreach ($queue as $q): ?>
                        <a href="?appointment_id=<?php echo $q['id']; ?>" style="text-decoration: none; min-width: 150px;">
                            <div style="background: #f8fbff; border: 1px solid #e1e8f0; padding: 10px; border-radius: 10px; text-align: center;">
                                <div style="font-size: 0.7em; color: #21a9af; font-weight: 700;"><?php echo date('h:i A', strtotime($q['appointment_time'])); ?></div>
                                <div style="font-size: 0.85em; font-weight: 600; color: #333;"><?php echo htmlspecialchars($q['first_name']); ?></div>
                            </div>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lab Order Modal -->
<div id="labModal" class="lab-modal">
    <div class="lab-modal-content">
        <h4 style="margin-top: 0;">Order Laboratory Test</h4>
        <p style="color: #666; font-size: 0.9em;">Select tests to order:</p>
        
        <form method="POST" id="labForm">
            <div id="labList">
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Complete Blood Count (CBC)"> Complete Blood Count (CBC)</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Lipid Profile"> Lipid Profile</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Liver Function Test"> Liver Function Test</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Blood Sugar (Fasting)"> Blood Sugar (Fasting)</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Urinalysis"> Urinalysis</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Thyroid Profile"> Thyroid Profile</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_lab_test[]" value="Electrolytes"> Electrolytes</label>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeLabModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitLabOrder()">Submit Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Medication Modal -->
<div id="medModal" class="med-modal">
    <div class="med-modal-content">
        <h4 style="margin-top: 0; color: #21a9af;"><i class="fas fa-prescription"></i> Clinical Prescription Panel</h4>
        
        <!-- AI Smart Suggestions based on Medical History/Vitals -->
        <div id="aiMedSuggestions" style="background: #f0f7ff; border: 1px solid #cce3ff; border-radius: 10px; padding: 12px; margin-bottom: 20px; font-size: 0.85em;">
            <div style="font-weight: 700; color: #0056b3; margin-bottom: 5px;"><i class="fas fa-magic"></i> Smart Recommendations</div>
            <div id="aiSuggestionContent">
                <?php
                    $suggestions = [];
                    $hist_lower = strtolower($appt['medical_history'] ?? '');
                    if (strpos($hist_lower, 'fever') !== false || (isset($latest_vitals['temperature']) && $latest_vitals['temperature']['value'] > 100)) 
                        $suggestions[] = "<strong>Fever/Inflammation:</strong> Consider PCM 500mg or Ibuprofen.";
                    if (strpos($hist_lower, 'hypertension') !== false || (isset($latest_vitals['bp_systolic']) && $latest_vitals['bp_systolic']['value'] > 140))
                        $suggestions[] = "<strong>Blood Pressure:</strong> Review Telmisartan or Amlodipine status.";
                    if (strpos($hist_lower, 'allergy') !== false)
                        $suggestions[] = "<strong><span style='color:red;'>⚠️ ALLERGY ALERT:</span></strong> Patient has recorded allergies. Check history before prescribing.";
                    
                    echo !empty($suggestions) ? implode('<br>', $suggestions) : "No specific patterns detected. Proceed with standard protocol.";
                ?>
            </div>
        </div>

        <form id="medFormAjax" onsubmit="submitMedication(event)">
            <input type="hidden" name="add_medication" value="1">
            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 2;">
                    <label style="font-size: 0.9em; font-weight: 600;">Medication Name</label>
                    <input list="med_list" name="med_name" class="form-control" placeholder="Type to search..." required onchange="checkAllergy(this.value)">
                    <datalist id="med_list">
                        <?php foreach ($inventory as $item): ?>
                            <option value="<?php echo htmlspecialchars($item['medication_name']); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.9em; font-weight: 600;">Dose (Freq)</label>
                    <select name="med_frequency" id="medFreq" class="form-control" required onchange="calculateQty()">
                        <option value="1-0-1">1-0-1 (Twice)</option>
                        <option value="1-1-1">1-1-1 (Thrice)</option>
                        <option value="1-0-0">1-0-0 (Morning)</option>
                        <option value="0-0-1">0-0-1 (Night)</option>
                        <option value="0-1-0">0-1-0 (Afternoon)</option>
                        <option value="SOS">SOS (As needed)</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label style="font-size: 0.9em; font-weight: 600;">Duration (Days)</label>
                    <input type="number" name="med_days" id="medDays" class="form-control" value="5" min="1" required oninput="calculateQty()">
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.9em; font-weight: 600;">Calculated Qty</label>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <input type="number" id="displayQty" class="form-control" readonly style="background: #fdfdfd; font-weight: 700; color: #21a9af;">
                        <span style="font-size: 0.8em; color: #888;">units</span>
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="font-size: 0.9em; font-weight: 600;">Timing</label>
                <div style="display: flex; gap: 20px;">
                    <label><input type="radio" name="med_timing" value="After Food" checked> After Food</label>
                    <label><input type="radio" name="med_timing" value="Before Food"> Before Food</label>
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="font-size: 0.9em; font-weight: 600;">Doctor Note / Pharmacy Instruction</label>
                <textarea name="med_note" class="form-control" rows="2" placeholder="e.g. Dissolve in water, Take after 10 min..."></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block" style="background: #21a9af; border: none; padding: 12px; font-size: 1.1em;">
                <i class="fas fa-check"></i> Finalize & Add to Pharmacy Queue
            </button>
        </form>
        
        <hr>
        
        <h5>Prescribed for this Visit:</h5>
        <div id="medListContainer" style="max-height: 150px; overflow-y: auto;">
            <?php if (empty($prescribed_meds)): ?>
                <p style="color: #999; font-style: italic;" id="noMedsMsg">No medications added yet.</p>
            <?php else: ?>
                <?php foreach ($prescribed_meds as $pm): ?>
                    <div class="med-list-item">
                        <strong><?php echo htmlspecialchars($pm['name']); ?></strong>
                        <span style="color: #666; font-size: 0.85em;"><?php echo htmlspecialchars($pm['quantity'] . ' | ' . $pm['dosage']); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeMedModal()">Close</button>
        </div>
    </div>
</div>

    </div>
</div>

<!-- Radiology Modal -->
<div id="radModal" class="lab-modal">
    <div class="lab-modal-content">
        <h4 style="margin-top: 0;"><i class="fas fa-x-ray"></i> Order Radiology Scan</h4>
        <p style="color: #666; font-size: 0.9em;">Select recommended imaging:</p>
        
        <form method="POST" id="radForm">
            <div id="radList">
                <label class="lab-checkbox"><input type="checkbox" name="order_radiology[]" value="X-Ray"> X-Ray  </label>
                <label class="lab-checkbox"><input type="checkbox" name="order_radiology[]" value="MRI (Brain)"> MRI (Brain)</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_radiology[]" value="CT Scan (Abdomen)"> CT Scan (Abdomen)</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_radiology[]" value="Ultrasound"> Ultrasound</label>
                <label class="lab-checkbox"><input type="checkbox" name="order_radiology[]" value="ECG"> ECG</label>
            </div>
            
            <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-light" onclick="closeRadModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Order Selected</button>
            </div>
        </form>
    </div>
</div>

<!-- View Lab Results Modal -->
<div id="viewLabModal" class="lab-modal">
    <div class="lab-modal-content" style="width: 700px; max-width: 90%;">
        <h4 style="margin-top: 0; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-vial"></i> Laboratory Results</span>
            <button type="button" onclick="closeViewLabModal()" style="background:none; border:none; font-size:1.2em; cursor:pointer;">&times;</button>
        </h4>
        <div style="max-height: 400px; overflow-y: auto; margin-top: 15px;">
            <table class="table table-hover" style="font-size: 0.9em; width: 100%;">
                <thead style="background: #f8f9fa;">
                    <tr>
                        <th style="padding: 10px;">Date</th>
                        <th style="padding: 10px;">Test</th>
                        <th style="padding: 10px;">Status</th>
                        <th style="padding: 10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lab_results_list)): ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px; color: #777;">No lab results found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($lab_results_list as $lr): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($lr['created_at'])); ?></td>
                            <td style="padding: 10px;"><strong><?php echo htmlspecialchars($lr['test_type']); ?></strong></td>
                            <td style="padding: 10px;">
                                <span class="badge <?php echo $lr['status'] == 'completed' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo ucfirst($lr['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 10px;">
                                <?php if ($lr['status'] === 'completed'): ?>
                                    <a href="../../modules/lab/results.php?id=<?php echo $lr['id']; ?>" target="_blank" class="btn btn-sm btn-primary" style="padding: 2px 8px; font-size: 0.8em;">View Report</a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-actions" style="border-top: 1px solid #eee; padding-top: 15px;">
            <button type="button" class="btn btn-secondary" onclick="closeViewLabModal()">Close</button>
        </div>
    </div>
</div>

<script>
    function openViewLabModal() {
        document.getElementById('viewLabModal').style.display = 'flex';
    }

    function closeViewLabModal() {
        document.getElementById('viewLabModal').style.display = 'none';
    }
    function openLabModal() {
        document.getElementById('labModal').style.display = 'flex';
    }
    
    function closeLabModal() {
        document.getElementById('labModal').style.display = 'none';
        // Reset selection visual state
        document.querySelectorAll('.lab-checkbox input').forEach(input => {
            input.checked = false;
            input.parentElement.classList.remove('active');
        });
    }

    function openRadModal() {
        document.getElementById('radModal').style.display = 'flex';
    }
    
    function closeRadModal() {
        document.getElementById('radModal').style.display = 'none';
        // Reset selection visual state
        document.querySelectorAll('#radList .lab-checkbox input').forEach(input => {
            input.checked = false;
            input.parentElement.classList.remove('active');
        });
    }
    
    function submitLabOrder() {
        const checkboxes = document.querySelectorAll('input[name="order_lab_test[]"]:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one test.');
            return;
        }
        document.getElementById('labForm').submit();
    }
    
    // Add visual selection class
    document.querySelectorAll('.lab-checkbox input').forEach(input => {
        input.addEventListener('change', function() {
            if (this.checked) {
                this.parentElement.classList.add('active');
            } else {
                this.parentElement.classList.remove('active');
            }
        });
    });

    // Med Modal JS
    function openMedModal() {
        console.log("Opening Med Modal");
        const modal = document.getElementById('medModal');
        if (modal) {
            modal.style.display = 'block';
        } else {
            console.error("Med Modal element not found!");
            alert("Error: Medication modal could not be loaded.");
        }
    }
    
    function closeMedModal() {
        document.getElementById('medModal').style.display = 'none';
        location.reload(); 
    }
    
    function calculateQty() {
        const freq = document.getElementById('medFreq').value;
        const days = parseInt(document.getElementById('medDays').value) || 0;
        let count = 0;
        if (freq === '1-1-1') count = 3;
        else if (freq === '1-0-1') count = 2;
        else if (freq === 'SOS') count = 0; // Handled separately or default
        else count = 1;

        const total = count * days;
        document.getElementById('displayQty').value = freq === 'SOS' ? 5 : total;
    }

    const patientHistory = <?php echo json_encode(strtolower($appt['medical_history'] ?? '')); ?>;
    function checkAllergy(medName) {
        if (!medName) return;
        if (patientHistory.includes('allergy') || patientHistory.includes('allergic')) {
            const warning = document.getElementById('aiSuggestionContent');
            warning.style.color = "red";
            warning.innerHTML = "<strong>⚠️ CAUTION:</strong> Cross-checking '"+medName+"' with known allergies... please verify manually.";
        }
    }

    function submitMedication(e) {
        e.preventDefault();
        const form = document.getElementById('medFormAjax');
        const formData = new FormData(form);
        
        // Ensure Qty SOS logic
        if (form.med_frequency.value === 'SOS') {
            formData.append('med_qty_sos', '5');
        }

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const container = document.getElementById('medListContainer');
                const noMsg = document.getElementById('noMedsMsg');
                if (noMsg) noMsg.style.display = 'none';
                
                const div = document.createElement('div');
                div.className = 'med-list-item';
                div.style.padding = "10px";
                div.style.borderBottom = "1px solid #eee";
                div.innerHTML = `<strong>${form.med_name.value}</strong> 
                                 <span class="badge badge-info">${data.qty} Tab(s)</span>
                                 <br><small>${form.med_frequency.value} for ${form.med_days.value} days</small>`;
                
                container.prepend(div);
                form.reset();
                calculateQty();
            } else {
                alert('Error: ' + (data.message || 'Saving failed'));
            }
        })
        .catch(err => {
            console.error('Prescription Error:', err);
            alert('Prescription failed to save. Please check connection.');
        });
    }

    // Initialize Qty
    calculateQty();


    // Close modal if clicked outside
    window.onclick = function(event) {
        const labModal = document.getElementById('labModal');
        const medModal = document.getElementById('medModal');
        const viewLabModal = document.getElementById('viewLabModal');
        if (event.target == labModal) closeLabModal();
        if (event.target == medModal) closeMedModal();
        if (event.target == viewLabModal) closeViewLabModal();
    }
</script>

<?php if (!$is_embedded) include '../../includes/footer.php'; ?>
