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
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    }
    echo "<div class='alert alert-danger'>Appointment ID required.</div>";
    if (!$is_embedded) include '../../includes/footer.php';
    exit();
}

// Fetch appointment + patient details
$appt = db_select_one("SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.gender, p.phone, u.email as p_email, p.address, p.id as patient_id, p.uhid, p.medical_history, u.profile_image, p.user_id 
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

// Fetch Latest AI Triage Data
$triage_data = db_select_one("SELECT * FROM triage_analysis WHERE patient_id = $1 ORDER BY id DESC LIMIT 1", [$patient_id]);

// 0. Handle AJAX Call Patient
if (isset($_POST['call_patient_ajax'])) {
    $role = get_user_role();
    if ($role === 'doctor') {
        $doc_id = get_user_id();
        $staff = db_select_one("SELECT id, primary_room_id, last_name FROM staff WHERE user_id = $1", [$doc_id]);
        
        $final_room = 'OPD'; // Default
        if (!empty($staff['primary_room_id'])) {
            $room_info = db_select_one("SELECT room_number FROM rooms WHERE id = $1", [$staff['primary_room_id']]);
            if ($room_info) {
                $final_room = $room_info['room_number'];
            }
        }

        // Log to public queue
        db_insert('public_queue', [
            'patient_name' => $appt['first_name'] . ' ' . $appt['last_name'],
            'room_number' => $final_room,
            'status' => 'calling'
        ]);
        
        // --- NEW: Send In-App Web Notification to Patient ---
        if (!empty($appt['user_id'])) {
            $doc_name = "Dr. " . htmlspecialchars($staff['last_name'] ?? '');
            db_insert('notifications', [
                'user_id' => $appt['user_id'],
                'title' => 'Doctor Ready',
                'message' => "<strong>$doc_name</strong> is ready. Please proceed to <strong>Room $final_room</strong> immediately.",
                'type' => 'info'
            ]);
        }
        
        // --- Optional Email Notification (Disabled per request) ---
        // if (!empty($appt['p_email'])) {
        //     require_once '../../includes/mail_service.php';
        //     @send_email_smtp($appt['p_email'], "Please Proceed to Room: $final_room", "...");
        // }

        echo json_encode(['status' => 'success', 'room' => $final_room]);
        exit;
    }
}

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
    $staff = db_select_one("SELECT id, first_name, last_name FROM staff WHERE user_id = $1", [$doc_id]);
    
    // 1. Save Notes & History
    if (isset($_POST['save_note']) || isset($_POST['complete_visit']) || isset($_POST['finalize_meds'])) {
        $notes = $_POST['notes'] ?? '';
        $history = $_POST['medical_history'] ?? '';
        $is_completion = isset($_POST['complete_visit']);
        
        // --- ADMIN ALERT: Deviation Check ---
        if (isset($_POST['finalize_meds'])) {
             $prescribed_json = $_POST['medication_list_json'] ?? '[]';
             $ai_suggested_json = $_POST['ai_suggested_json'] ?? '[]';
             
             $prescribed_meds_arr = json_decode($prescribed_json, true) ?? [];
             $ai_meds_arr = json_decode($ai_suggested_json, true) ?? [];
             
             // Extract names implies normalization
             $p_names = array_map(function($m){ return strtolower($m['name']); }, $prescribed_meds_arr);
             $ai_names = array_map('strtolower', $ai_meds_arr);
             
             // Check if ANY prescribed med is in AI list
             $match_found = false;
             if (empty($ai_names)) {
                 $match_found = true; // AI had no suggestions, so no deviation
             } else {
                 foreach ($p_names as $p) {
                     foreach ($ai_names as $ai) {
                         if (strpos($ai, $p) !== false || strpos($p, $ai) !== false) {
                             $match_found = true; 
                             break;
                         }
                     }
                 }
             }
             
             if (!$match_found && !empty($prescribed_meds_arr)) {
                 // Deviation Detected: Doctor prescribed things that DO NOT match AI suggestions
                 db_insert('admin_alerts', [
                     'alert_type' => 'AI_DEVIATION',
                     'severity' => 'High',
                     'message' => "Doctor " . $staff['first_name'] . " ignored AI suggestions for Patient " . $appt['first_name'] . ". AI Suggested: " . implode(", ", $ai_names) . ". Prescribed: " . implode(", ", $p_names),
                     'reference_id' => $appointment_id,
                     'created_at' => date('Y-m-d H:i:s')
                 ]);
             }
        }
        
        // --- CONTINUOUS LEARNING FEEDBACK ---
        if ($is_completion && !empty($_POST['final_diagnosis']) && !empty($_POST['urgency_rating'])) {
            // Trigger background AI learning
            $learning_data = [
                'history' => $appt['medical_history'],
                'symptoms' => $appt['reason'] . " " . $notes,
                'diagnosis' => $_POST['final_diagnosis'],
                'urgency' => $_POST['urgency_rating']
            ];
            
            // Log for debug
            error_log("Sending AI Feedback: " . json_encode($learning_data));
            
            $url = 'http://127.0.0.1:5001/learn';
            // Simple fire-and-forget logic using stream context with small timeout
            $options = [
                'http' => [
                    'header'  => "Content-type: application/json\r\n",
                    'method'  => 'POST',
                    'content' => json_encode($learning_data),
                    'timeout' => 0.5 // Non-blocking-ish
                ]
            ];
            $context  = stream_context_create($options);
            @file_get_contents($url, false, $context);
        }

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
            
            $success = "Visit completed successfully. Redirecting in <strong id='countdown_timer'>6</strong> seconds... <a href='$next_url' class='btn btn-sm btn-primary ml-3'>Proceed to Next Patient Now</a>";
            $success .= "<script>
                setTimeout(function() { window.location.href = '$next_url'; }, 6000);
                let secs = 6;
                setInterval(function() {
                    secs--;
                    let el = document.getElementById('countdown_timer');
                    if (el && secs >= 0) el.innerText = secs;
                }, 1000);
            </script>";
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
        file_put_contents('debug_med_post.log', print_r($_POST, true), FILE_APPEND);
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
    // 4. Handle Vitals Submission (Doctor/Nurse Entry)
    if (isset($_POST['record_vitals']) || isset($_POST['edit_vitals'])) {
        $metrics = [
            'heart_rate' => ['val' => $_POST['heart_rate'], 'unit' => 'bpm'],
            'bp_systolic' => ['val' => $_POST['bp_systolic'], 'unit' => 'mmHg'],
            'bp_diastolic' => ['val' => $_POST['bp_diastolic'], 'unit' => 'mmHg'],
            'temperature' => ['val' => $_POST['temperature'] ?? 0, 'unit' => '°F'],
            'oxygen_saturation' => ['val' => $_POST['oxygen_saturation'] ?? 0, 'unit' => '%'],
            'weight' => ['val' => $_POST['weight'] ?? 0, 'unit' => 'kg'],
            'glucose' => ['val' => $_POST['glucose'] ?? 0, 'unit' => 'mg/dL']
        ];

        foreach ($metrics as $type => $data) {
            if ($data['val'] !== '') {
                $json_val = json_encode(['value' => $data['val'], 'unit' => $data['unit']]);
                
                if (isset($_POST['edit_vitals'])) {
                    $exists = db_select_one("SELECT id FROM patient_health_metrics WHERE appointment_id = $1 AND metric_type = $2", [$appointment_id, $type]);
                    if ($exists) {
                        db_query("UPDATE patient_health_metrics SET metric_value = $1, recorded_by = $2 WHERE id = $3", [$json_val, get_user_id(), $exists['id']]);
                        continue;
                    }
                }
                
                db_insert('patient_health_metrics', [
                    'patient_id' => $patient_id,
                    'appointment_id' => $appointment_id,
                    'metric_type' => $type,
                    'metric_value' => $json_val,
                    'recorded_by' => get_user_id()
                ]);
            }
        }
        
        // Refresh Vitals Data immediately for the view below
        $vitals_raw = db_select("SELECT metric_type, metric_value, recorded_at FROM patient_health_metrics WHERE appointment_id = $1 ORDER BY recorded_at DESC", [$appointment_id]);
        $latest_vitals = [];
        if (!empty($vitals_raw)) {
            $vitals_taken = true;
            foreach ($vitals_raw as $v) {
                if (!isset($latest_vitals[$v['metric_type']])) {
                    $latest_vitals[$v['metric_type']] = json_decode($v['metric_value'], true);
                }
            }
        }

        $success = isset($_POST['edit_vitals']) ? "Vitals updated successfully." : "Vitals recorded successfully. You may now proceed with the consultation.";
    }

    // 5. Handle Refer to Doctor
    if (isset($_POST['refer_patient'])) {
        $ref_doc_id = $_POST['referral_doctor_id'];
        $ref_date = $_POST['referral_date'];
        $ref_time = $_POST['referral_time'];
        $ref_reason = $_POST['referral_reason'];

        if ($ref_doc_id && $ref_date && $ref_time) {
            $appointment_time = $ref_date . ' ' . $ref_time;
            try {
                // Check if Doctor is already booked
                $existing = db_select_one("SELECT id FROM appointments WHERE doctor_id = $1 AND appointment_time = $2 AND status = 'scheduled'", [$ref_doc_id, $appointment_time]);
                
                // Check if Patient is already booked somewhere else at this time
                $patient_booked = db_select_one("SELECT id FROM appointments WHERE patient_id = $1 AND appointment_time = $2 AND status = 'scheduled'", [$patient_id, $appointment_time]);
                
                if ($existing) {
                    $error = "The selected time slot is already booked for that doctor. Please choose a different time.";
                } elseif ($patient_booked) {
                    $error = "This patient already has an appointment scheduled at this exact time with another doctor.";
                } else {
                    db_insert('appointments', [
                        'patient_id' => $patient_id,
                        'doctor_id' => $ref_doc_id,
                        'appointment_time' => $appointment_time,
                        'reason' => "Referral from Dr. " . ($staff['last_name'] ?? 'Unknown') . ". " . $ref_reason,
                        'status' => 'scheduled'
                    ]);
                    
                    // Notify Referred Doctor
                    $ref_doc = db_select_one("SELECT user_id, last_name FROM staff WHERE id = $1", [$ref_doc_id]);
                    if ($ref_doc) {
                        if (file_exists('../../includes/fcm_service.php')) {
                            require_once '../../includes/fcm_service.php';
                            $doc_token_row = db_select_one("SELECT fcm_token FROM users WHERE id = $1", [$ref_doc['user_id']]);
                            $pat_name = $appt['first_name'] . ' ' . $appt['last_name'];
                            if($doc_token_row && !empty($doc_token_row['fcm_token'])) {
                                FCMService::send($doc_token_row['fcm_token'], 'New Referral', "Patient $pat_name referred to you for " . date('M d, h:i A', strtotime($appointment_time)));
                            }
                        }
                    }
                    $success = "Patient successfully referred to another doctor.";
                }
            } catch (Exception $e) {
                $error = "Referral failed: " . $e->getMessage();
            }
        } else {
            $error = "Please fill all required referral fields.";
        }
    }
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

// Fetch all doctors for Referral Modal
$referral_doctors = db_select("SELECT id, first_name, last_name, specialization FROM staff WHERE role = 'doctor'");
$specializations = array_unique(array_column($referral_doctors, 'specialization'));
sort($specializations);

// Fetch Inventory for Meds Modal
$inventory = db_select("SELECT medication_name, quantity FROM pharmacy_inventory ORDER BY medication_name");

// Fetch Current Prescriptions for this visit
$current_rx = db_select_one("SELECT medication_details FROM prescriptions WHERE appointment_id = $1", [$appointment_id]);
$prescribed_meds = $current_rx ? json_decode($current_rx['medication_details'], true) : [];

$age = date_diff(date_create($appt['date_of_birth']), date_create('today'))->y;

// Fetch Lab and Radiology Results for Modal
$lab_results_list = db_select("SELECT * FROM laboratory_tests WHERE patient_id = $1 ORDER BY created_at DESC", [$patient_id]);
$radiology_results_list = db_select("SELECT * FROM radiology_reports WHERE patient_id = $1 ORDER BY created_at DESC", [$patient_id]);

// Combine and sort by date descending
$combined_results = [];
foreach ($lab_results_list as $lr) {
    $lr['source_type'] = 'lab';
    $combined_results[] = $lr;
}
foreach ($radiology_results_list as $rad) {
    $rad['source_type'] = 'rad';
    $combined_results[] = $rad;
}
usort($combined_results, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

$page_title = "Consultation & Notes";
include '../../includes/header.php';
?>

<style>
    /* Updated Grid for Fit-to-Page */
    .visit-grid { 
        display: grid; 
        grid-template-columns: 350px 1fr; 
        gap: 20px; 
        height: calc(100vh - 140px); /* Adjusted for header/footer margins */
        overflow: hidden; 
        padding-bottom: 20px;
    }
    
    /* Scrollable Columns */
    .visit-col-scroll {
        height: 100%;
        overflow-y: auto;
        padding-right: 5px; /* Space for scrollbar */
        padding-bottom: 20px;
    }

    .profile-card { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); padding: 20px; text-align: center; border: 1px solid #eee; margin-bottom: 20px; }
    
    .profile-img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 15px; border: 4px solid #f0f2f5; }
    .info-label { font-size: 0.85em; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 10px; display: block; }
    .vitals-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 15px; }
    .vital-box { background: #f8f9fa; border-radius: 10px; padding: 10px; text-align: center; border: 1px solid #e9ecef; }
    .vital-val { font-size: 1.2em; font-weight: 700; color: #21a9af; }
    .vital-unit { font-size: 0.7em; color: #666; }
    /* .main-panel no longer needs max-height/overflow since parent handles it */
    .main-panel { background: white; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); padding: 25px; border: 1px solid #eee; }
    .section-title { font-size: 1.1em; font-weight: 700; color: #344767; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    /* Lab Modal & Med Modal Styles */
    .lab-modal, .med-modal {
        display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
    }
    .lab-modal-content, .med-modal-content {
        background-color: #fefefe; margin: 5% auto; padding: 25px; border: 1px solid #888; width: 650px; max-width: 95%; border-radius: 12px;
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

<script>
function callPatient() {
    const btn = document.getElementById('callBtn');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calling...';

    const formData = new FormData();
    formData.append('call_patient_ajax', '1');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check"></i> Called';
            btn.style.background = '#10b981'; // Green
            btn.style.borderColor = '#10b981';
            btn.style.color = 'white';
            
            // Show toast/banner
            const banner = document.getElementById('pagingBanner');
            banner.style.display = 'block';
            banner.innerHTML = '<i class="fas fa-satellite-dish"></i> Paging System: Patient has been called to ' + data.room + '.';
        } else {
            alert('Error calling patient');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error(err);
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}
</script>

<?php if (!$is_embedded): ?>
<div class="header-actions" style="margin-bottom: 10px;">
    <a href="appointments.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Schedule</a>
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<!-- Hidden Paging Banner -->
<div id="pagingBanner" class="alert alert-info animate-pulse" style="display:none; background: #e6fffa; border: 1px solid #b2f5ea; color: #2c7a7b; margin-bottom: 20px; border-radius: 12px; font-weight: 600;"></div>

<?php if ($is_embedded): ?>
    <!-- QUICK ACTIONS TOOLBAR (EMBEDDED ONLY) -->
    <div style="background: #eef2f7; padding: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border-bottom: 1px solid #dae1e7; position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
        <span style="font-size: 0.85em; font-weight: 600; color: #555; margin-right: 5px;">Quick Actions:</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openLabModal()"><i class="fas fa-flask"></i> Order Lab</button>
        <button type="button" class="btn btn-success btn-sm" onclick="openMedModal()"><i class="fas fa-pills"></i> Prescribe Meds</button>
        <button type="button" class="btn btn-warning btn-sm" onclick="openRadModal()"><i class="fas fa-x-ray"></i> Radiology</button>
        <button type="button" class="btn btn-info btn-sm" onclick="openViewLabModal()"><i class="fas fa-clipboard-list"></i> View Lab & Scans</button>
        
        <div style="flex-grow: 1;"></div>
        
        <button onclick="document.querySelector('[name=complete_visit]').click()" class="btn btn-dark btn-sm"><i class="fas fa-check-circle"></i> Complete Visit</button>
    </div>
<?php endif; ?>

<div class="visit-grid">
    <!-- Left Sidebar -->
    <div class="visit-col-scroll">
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
            <div class="section-title" style="margin-bottom: 15px;">
                <i class="fas fa-heartbeat" style="color: #e91e63;"></i>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <?php if ($role === 'doctor' && $appt['status'] !== 'completed' && !empty($latest_vitals)): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" style="padding: 2px 10px; font-size: 0.8em; border-radius: 15px;" onclick="openEditVitalsModal()" title="Edit Vitals">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    <?php endif; ?>
                    <span>Visit Vitals</span>
                </div>
            </div>
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
    <div class="visit-col-scroll">
        <div class="main-panel">
            <div class="section-title" style="display: flex; justify-content: space-between; align-items: center;">
                <span><i class="fas fa-user-md"></i> Clinical Notes & Consultation</span>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if ($appt['status'] !== 'completed'): ?>
                        <button id="callBtn" type="button" class="btn btn-sm" onclick="callPatient()" style="background: #ebf8ff; color: #2b6cb0; border: 1px solid #bee3f8; font-weight: 700; height: 32px; padding: 0 15px;">
                            <i class="fas fa-bullhorn"></i> Call Patient
                        </button>
                    <?php endif; ?>
                    <span class="badge <?php echo $appt['status'] == 'completed' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst($appt['status']); ?></span>
                </div>
            </div>

            <?php if ($is_embedded): ?>
                <!-- QUICK ACTIONS TOOLBAR MOVED TO TOP STICKY HEADER -->
            <?php endif; ?>

            <?php 
            // Check if vitals are missing and the user is a doctor trying to consult
            $vitals_missing = empty($latest_vitals);
            if ($vitals_missing && $role === 'doctor' && $appt['status'] !== 'completed'): 
            ?>
                <!-- MANDATORY VITALS FORM -->
                <div class="alert alert-warning" style="border-left: 5px solid #ff9800; background-color: #fff3e0; color: #e65100;">
                    <h4 style="margin-top:0;"><i class="fas fa-exclamation-triangle"></i> Mandatory Action: Record Vitals</h4>
                    <p style="margin-bottom:0;">Nurse has not recorded vitals for this visit. You <strong>must</strong> enter them before utilizing AI tools or prescribing medication.</p>
                </div>

                <div style="background: white; padding: 25px; border-radius: 12px; border: 1px solid #ffe0b2; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
                    <form method="POST">
                        <input type="hidden" name="record_vitals" value="1">
                        <h5 style="color: #555; margin-bottom: 20px;">Enter Patient Vitals</h5>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">Heart Rate (bpm)</label>
                                <input type="number" name="heart_rate" class="form-control" required placeholder="e.g. 72">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">BP Systolic (mmHg)</label>
                                <input type="number" name="bp_systolic" class="form-control" required placeholder="e.g. 120">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">BP Diastolic (mmHg)</label>
                                <input type="number" name="bp_diastolic" class="form-control" required placeholder="e.g. 80">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">Temperature (°F)</label>
                                <input type="number" name="temperature" class="form-control" step="0.1" required placeholder="e.g. 98.6">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">O2 Saturation (%)</label>
                                <input type="number" name="oxygen_saturation" class="form-control" max="100" placeholder="e.g. 98">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">Weight (kg)</label>
                                <input type="number" name="weight" class="form-control" step="0.1" placeholder="e.g. 70">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label style="font-size: 0.9em; font-weight: 600;">Random Glucose (mg/dL)</label>
                                <input type="number" name="glucose" class="form-control" placeholder="e.g. 100">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-warning btn-block" style="font-weight: bold; color: #333; margin-top: 10px;">
                            <i class="fas fa-save"></i> Save Vitals & Unlock Consultation
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <!-- ACTUAL CONSULTATION CONTENT (Only visible if Vitals exist or User is Patient/Admin view) -->


            <!-- Original Paging Banner Removed (Handled by JS now) -->

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
                                        <?php foreach ($prescribed_meds as $pm): 
                                            // Fallback Logic for Duration
                                            if (empty($pm['duration']) || $pm['duration'] === 'N/A') {
                                                // Try to calculate from dosage (e.g. 1-0-1) and quantity
                                                $qty = (int)($pm['quantity'] ?? 0);
                                                $freq_str = explode('|', $pm['dosage'])[0] ?? '';
                                                $daily_count = 0;
                                                
                                                if (strpos($freq_str, '-') !== false) {
                                                    $daily_count = substr_count($freq_str, '1');
                                                } elseif (stripos($freq_str, 'BID') !== false) { $daily_count = 2; }
                                                elseif (stripos($freq_str, 'TID') !== false) { $daily_count = 3; }
                                                elseif (stripos($freq_str, 'QD') !== false || stripos($freq_str, 'OD') !== false) { $daily_count = 1; }
                                                
                                                if ($daily_count > 0 && $qty > 0) {
                                                    $days = ceil($qty / $daily_count);
                                                    $pm['duration'] = "$days days (Calc)";
                                                } else {
                                                    $pm['duration'] = "N/A";
                                                }
                                            }
                                        ?>
                                            <li style="margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f0fdf4;">
                                                <strong><?php echo htmlspecialchars($pm['name']); ?></strong> 
                                                <span class="badge badge-success"><?php echo htmlspecialchars($pm['quantity']); ?> tabs total</span>
                                                <br><small style="color: #065f46; font-weight: 600;"><i class="fas fa-calendar-day"></i> Duration: <?php echo htmlspecialchars($pm['duration']); ?></small>
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
                    <label style="font-weight: 600; color: #2c3e50; display: flex; justify-content: space-between; align-items: center;">
                        <span>Add New Clinical Note / Findings</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius: 20px;" onclick="getAIInsights()">
                            <i class="fas fa-magic"></i> AI Assist
                        </button>
                    </label>
                    <textarea name="notes" id="clinicalNotes" class="form-control" rows="4" placeholder="Enter clinical observations, symptoms..." style="border: 2px solid #e0e0e0; font-family: 'Inter', sans-serif;" required></textarea>
                </div>

                <!-- AI Learning Feedback Loop -->
                <div style="background: #eef2f7; padding: 15px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #dce8f5;">
                    <div style="margin-bottom: 15px; font-weight: 700; color: #004085;"><i class="fas fa-brain text-primary"></i> Final Clinical Assessment</div>
                    <div class="row">
                        <div class="col-md-8">
                            <label style="font-size: 0.9em; font-weight: 600; color: #555;">Final Verified Diagnosis</label>
                            <input type="text" name="final_diagnosis" id="final_diagnosis" class="form-control" placeholder="e.g. Acute Bronchitis">
                            <small class="form-text text-muted">This confirms the AI prediction or corrects it to improve future accuracy.</small>
                        </div>
                        <div class="col-md-4">
                            <label style="font-size: 0.9em; font-weight: 600; color: #555;">Urgency Level</label>
                            <select name="urgency_rating" id="urgency_rating" class="form-control">
                                <option value="">-- Select (Optional) --</option>
                                <option value="Low">Low (Routine)</option>
                                <option selected value="Medium">Medium (Attention Needed)</option>
                                <option value="High">High (Urgent)</option>
                                <option value="Critical">Critical (Emergency)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Uniform Action Grid -->
                <style>
                    .action-grid {
                        display: grid;
                        grid-template-columns: repeat(7, 1fr); /* 7 items equal width */
                        gap: 10px;
                        margin-top: 20px;
                        background: #f8f9fa;
                        padding: 15px;
                        border-radius: 12px;
                    }
                    .action-tile {
                        height: 90px; /* Fixed height for uniformity */
                        width: 100%;
                        border-radius: 10px;
                        border: 1px solid #e0e0e0;
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        text-align: center;
                        font-size: 0.8em;
                        font-weight: 600;
                        padding: 5px;
                        cursor: pointer;
                        transition: all 0.2s;
                    }
                    .action-tile:hover { transform: translateY(-2px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
                    .action-tile i { font-size: 1.4em; margin-bottom: 5px; display: block; }
                    
                    /* Specific Colors */
                    .tile-save { background: #e0f2f1; color: #00695c; border-color: #b2dfdb; }
                    .tile-complete { background: #e8f5e9; color: #1b5e20; border-color: #c8e6c9; }
                    .tile-lab { background: #e3f2fd; color: #0d47a1; border-color: #bbdefb; }
                    .tile-rad { background: #fff3e0; color: #e65100; border-color: #ffe0b2; }
                    .tile-meds { background: #f3e5f5; color: #4a148c; border-color: #e1bee7; }
                    .tile-view { background: #eceff1; color: #37474f; border-color: #cfd8dc; }
                    
                    /* Input Tile Override */
                    .tile-input {
                        background: #fff;
                        align-items: flex-start; /* Align text left */
                        padding: 10px;
                        cursor: default;
                    }
                    .tile-input label { margin: 0; font-size: 0.8em; color: #666; width: 100%; text-align: left; }
                    .tile-input input { 
                        width: 100%; 
                        border: 1px solid #ddd; 
                        padding: 4px; 
                        border-radius: 5px; 
                        margin-top: 5px; 
                        font-size: 0.9em;
                    }
                </style>

                <div class="action-grid">
                    <!-- 1. Follow Up Date -->
                    <div class="action-tile tile-input">
                        <label>Schedule Follow-up</label>
                        <input type="date" name="follow_up_date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                    </div>

                    <!-- 2. Save -->
                    <button type="submit" name="save_note" value="1" class="action-tile tile-save">
                        <i class="fas fa-save"></i> Save Progress
                    </button>

                    <!-- 3. Complete -->
                    <button type="submit" name="complete_visit" value="1" class="action-tile tile-complete">
                        <i class="fas fa-check-circle"></i> Complete Visit
                    </button>

                    <!-- 4. Order Lab -->
                    <button type="button" class="action-tile tile-lab" onclick="openLabModal()">
                        <i class="fas fa-flask"></i> Order Lab
                    </button>

                    <!-- 5. Order Rad -->
                    <button type="button" class="action-tile tile-rad" onclick="openRadModal()">
                        <i class="fas fa-x-ray"></i> Order Radiology
                    </button>

                    <!-- 6. View Results -->
                    <button type="button" class="action-tile tile-view" onclick="openViewLabModal()">
                        <i class="fas fa-clipboard-list"></i> View Lab & Scans
                    </button>

                    <!-- 7. Meds -->
                    <button type="button" class="action-tile tile-meds" onclick="openMedModal()">
                        <i class="fas fa-pills"></i> Meds
                    </button>

                    <!-- 8. Refer to Doctor -->
                    <button type="button" class="action-tile tile-referral" onclick="openReferralModal()" style="border-left: 4px solid #f39c12; background: #fffdf5;">
                        <i class="fas fa-hand-holding-medical" style="color: #f39c12;"></i> Refer to Doctor
                    </button>
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
            
            <!-- AUTOMATIC AI DIAGNOSIS PANEL (Runs if Vitals exist) -->
            <?php if (!$vitals_missing && $role === 'doctor' && $appt['status'] !== 'completed'): ?>
                <div id="auto-ai-panel" style="margin-top: 20px; background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%); border: 1px solid #cce3ff; border-radius: 12px; padding: 15px; display:none;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h5 style="color: #004085; margin:0;"><i class="fas fa-robot"></i> AI Predictive Diagnosis</h5>
                        <span class="badge badge-primary">Beta</span>
                    </div>
                    <div id="ai-loading-state" style="color:#666; font-style:italic;">
                        <i class="fas fa-circle-notch fa-spin"></i> Analyzing vitals and patient history...
                    </div>
                    <div id="ai-results-content" style="display:none;">
                        <!-- JS populates this -->
                    </div>
                </div>
                
                <!-- Script to trigger AI on load -->
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        // Prepare data for AI
                        const history = <?php echo json_encode($appt['medical_history'] ?? ''); ?>;
                        const vitals = {
                            'heart_rate': <?php echo $latest_vitals['heart_rate']['value'] ?? 0; ?>,
                            'bp_systolic': <?php echo $latest_vitals['bp_systolic']['value'] ?? 0; ?>,
                            'temperature': <?php echo $latest_vitals['temperature']['value'] ?? 0; ?>,
                            'glucose': <?php echo $latest_vitals['glucose']['value'] ?? 0; ?>
                        };
                        
                        const aiPanel = document.getElementById('auto-ai-panel');
                        aiPanel.style.display = 'block';
                        
                        fetch('get_ai_recommendation.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ reason: '', history: history, vitals: JSON.stringify(vitals) })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                document.getElementById('ai-loading-state').innerHTML = `<span style="color:red"><i class="fas fa-exclamation-triangle"></i> ${data.error}</span>`;
                                return;
                            }
                            
                            document.getElementById('ai-loading-state').style.display = 'none';
                            const content = document.getElementById('ai-results-content');
                            content.style.display = 'block';
                            
                            let html = `<div class="row">`;
                            
                            // 1. Probabilities
                            html += `<div class="col-md-7">
                                <h6 style="font-size: 0.9em; text-transform:uppercase; color:#888;">Probable Conditions</h6>`;
                            
                            if (data.probabilities && data.probabilities.length > 0) {
                                let max = data.probabilities[0].probability;
                                data.probabilities.forEach(p => {
                                    let color = p.probability > 70 ? '#e74a3b' : (p.probability > 40 ? '#f6c23e' : '#36b9cc');
                                    let width = p.probability + '%';
                                    html += `
                                    <div style="margin-bottom: 8px;">
                                        <div style="display:flex; justify-content:space-between; font-size:0.9em; font-weight:600; margin-bottom:2px;">
                                            <span>${p.label}</span>
                                            <span>${p.probability}%</span>
                                        </div>
                                        <div style="background:#e9ecef; height:6px; border-radius:3px; overflow:hidden;">
                                            <div style="background:${color}; width:${width}; height:100%;"></div>
                                        </div>
                                    </div>`;
                                });
                            } else {
                                html += `<p style="color:#666; font-size:0.9em;">No strong patterns detected yet.</p>`;
                            }
                            html += `</div>`;
                            
                            // 2. Vitals Analysis & Urgency
                            html += `<div class="col-md-5" style="border-left:1px solid #eee; padding-left:15px;">
                                <h6 style="font-size: 0.9em; text-transform:uppercase; color:#888;">Analysis</h6>
                                <div style="margin-bottom:10px;">
                                    <strong>Urgency:</strong> 
                            if (data.disease) {
                                html += `<div style="margin-top: 15px;">
                                    <strong>AI Diagnosis:</strong> <span style="color: #2b6cb0;">${data.disease}</span>
                                </div>`;
                            }
                            if (data.specialization) {
                                html += `<div style="margin-top: 5px;">
                                    <strong>Recommended Dept:</strong> <span style="color: #047857;">${data.specialization}</span>
                                </div>`;
                            }
                            
                            html += `</div></div>`; // End row
                            
                            content.innerHTML = html;
                        })
                        .catch(err => {
                            console.error(err);
                            document.getElementById('ai-loading-state').innerHTML = '<span style="color:red">AI Service Unreachable</span>';
                        });
                    });
                </script>
            <?php endif; ?>
            
            <?php endif; // End else block for vitals check ?>
            <?php endif; // End role check ?> 
        </div>
    </div>
</div>

<!-- Complete Modal removed as per request -->

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
                <?php if ($triage_data): ?>
                    <div style="background: #e0f7fa; border-left: 4px solid #00bcd4; padding: 8px; margin-bottom: 10px; border-radius: 4px;">
                        <strong style="color: #006064;"><i class="fas fa-notes-medical"></i> Initial Triage Assessment:</strong><br>
                        <?php echo nl2br(htmlspecialchars($triage_data['ai_findings'] ?? '')); ?>
                        <div style="margin-top: 5px; font-weight: 600; font-size: 0.9em; color: #00838f;">
                            Severity Score: <?php echo $triage_data['severity_score']; ?>/10
                        </div>
                    </div>
                <?php endif; ?>
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

<!-- Edit Vitals Modal -->
<div id="editVitalsModal" class="lab-modal">
    <div class="lab-modal-content">
        <h4 style="margin-top: 0; color: #21a9af; margin-bottom: 20px;"><i class="fas fa-heartbeat"></i> Edit Patient Vitals</h4>
        
        <form method="POST">
            <input type="hidden" name="edit_vitals" value="1">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">Heart Rate (bpm)</label>
                    <input type="number" name="heart_rate" class="form-control" placeholder="e.g. 72" value="<?php echo htmlspecialchars($latest_vitals['heart_rate']['value'] ?? ''); ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">BP Systolic (mmHg)</label>
                    <input type="number" name="bp_systolic" class="form-control" placeholder="e.g. 120" value="<?php echo htmlspecialchars($latest_vitals['bp_systolic']['value'] ?? ''); ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">BP Diastolic (mmHg)</label>
                    <input type="number" name="bp_diastolic" class="form-control" placeholder="e.g. 80" value="<?php echo htmlspecialchars($latest_vitals['bp_diastolic']['value'] ?? ''); ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">Temperature (°F)</label>
                    <input type="number" name="temperature" class="form-control" step="0.1" placeholder="e.g. 98.6" value="<?php echo htmlspecialchars($latest_vitals['temperature']['value'] ?? ''); ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">O2 Saturation (%)</label>
                    <input type="number" name="oxygen_saturation" class="form-control" max="100" placeholder="e.g. 98" value="<?php echo htmlspecialchars($latest_vitals['oxygen_saturation']['value'] ?? ''); ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">Weight (kg)</label>
                    <input type="number" name="weight" class="form-control" step="0.1" placeholder="e.g. 70" value="<?php echo htmlspecialchars($latest_vitals['weight']['value'] ?? ''); ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label style="font-size: 0.9em; font-weight: 600;">Random Glucose (mg/dL)</label>
                    <input type="number" name="glucose" class="form-control" placeholder="e.g. 100" value="<?php echo htmlspecialchars($latest_vitals['glucose']['value'] ?? ''); ?>">
                </div>
            </div>
            <div class="modal-actions" style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditVitalsModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #21a9af; border: none;">Update Vitals</button>
            </div>
        </form>
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
    <div class="lab-modal-content" style="width: 100%; max-width: 700px;">
        <h4 style="margin-top: 0; display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-clipboard-list text-primary"></i> Lab & Radiology Results</span>
            <button type="button" onclick="closeViewLabModal()" style="background:none; border:none; font-size:1.2em; cursor:pointer;">&times;</button>
        </h4>
        <div style="max-height: 400px; overflow-y: auto; margin-top: 15px;">
            <table class="table table-hover" style="font-size: 0.9em; width: 100%;">
                <thead style="background: #f8f9fa;">
                    <tr>
                        <th style="padding: 10px;">Date & Time</th>
                        <th style="padding: 10px;">Category</th>
                        <th style="padding: 10px;">Test / Scan</th>
                        <th style="padding: 10px;">Status</th>
                        <th style="padding: 10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($combined_results)): ?>
                        <tr><td colspan="5" style="text-align:center; padding: 20px; color: #777;">No results found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($combined_results as $item): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 10px; white-space: nowrap;"><?php echo date('M d, Y H:i', strtotime($item['created_at'])); ?></td>
                            <td style="padding: 10px;">
                                <?php if ($item['source_type'] === 'lab'): ?>
                                    <span class="badge" style="background: #e3f2fd; color: #0d47a1;"><i class="fas fa-flask"></i> Lab</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #fff3e0; color: #e65100;"><i class="fas fa-x-ray"></i> Radiology</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px;"><strong><?php echo htmlspecialchars($item['source_type'] === 'lab' ? $item['test_type'] : $item['report_type']); ?></strong></td>
                            <td style="padding: 10px;">
                                <span class="badge <?php echo $item['status'] == 'completed' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo ucfirst($item['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 10px;">
                                <?php if ($item['status'] === 'completed'): ?>
                                    <?php if ($item['source_type'] === 'lab'): ?>
                                        <a href="../../modules/lab/results.php?id=<?php echo $item['id']; ?>" target="_blank" class="btn btn-sm btn-primary" style="padding: 2px 8px; font-size: 0.8em;">View Report</a>
                                    <?php else: ?>
                                        <?php 
                                            $urls = json_decode($item['image_url'], true);
                                            if (!is_array($urls)) {
                                                $urls = !empty($item['image_url']) ? [$item['image_url']] : [];
                                            }
                                            if (empty($urls)):
                                        ?>
                                            <span class="text-muted">No File Attached</span>
                                        <?php else: ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                                            <?php foreach ($urls as $idx => $url): ?>
                                                <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="btn btn-sm btn-info" style="padding: 2px 8px; font-size: 0.8em; white-space: nowrap;">View Scan <?php echo count($urls) > 1 ? ($idx + 1) : ''; ?></a>
                                            <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($item['source_type'] === 'rad' && !empty($item['findings'])): ?>
                        <tr>
                            <td colspan="5" style="padding: 15px; background: #fafafa; border-bottom: 2px solid #eee;">
                                <div style="font-size: 0.9em; color: #444;">
                                    <strong><i class="fas fa-file-medical-alt text-primary"></i> Radiologist Findings & Interpretation:</strong><br>
                                    <div style="margin-top: 8px; white-space: pre-wrap; padding: 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; font-family: 'Inter', sans-serif;"><?php echo htmlspecialchars($item['findings']); ?></div>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
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
        const warning = document.getElementById('aiSuggestionContent');
        if (!warning) return;
        
        // Reset to default suggestion content if input is empty
        if (!medName || medName.trim() === "") {
            warning.style.color = "#333";
            warning.innerHTML = `<?php 
                $suggestions = [];
                $hist_lower = strtolower($appt['medical_history'] ?? '');
                if (strpos($hist_lower, 'fever') !== false || (isset($latest_vitals['temperature']) && $latest_vitals['temperature']['value'] > 100)) 
                    $suggestions[] = "<strong>Fever/Inflammation:</strong> Consider PCM 500mg or Ibuprofen.";
                if (strpos($hist_lower, 'hypertension') !== false || (isset($latest_vitals['bp_systolic']) && $latest_vitals['bp_systolic']['value'] > 140))
                    $suggestions[] = "<strong>Blood Pressure:</strong> Review Telmisartan or Amlodipine status.";
                if (strpos($hist_lower, 'allergy') !== false)
                    $suggestions[] = "<strong><span style='color:red;'>⚠️ ALLERGY ALERT:</span></strong> Patient has recorded allergies. Check history before prescribing.";
                
                echo !empty($suggestions) ? addslashes(implode('<br>', $suggestions)) : "No specific patterns detected. Proceed with standard protocol.";
            ?>`;
            return;
        }

        const normalizedMed = medName.toLowerCase().trim();
        // Regex to find the medication name within 50 characters of the word "allergy" or "allergic"
        const specificMatch = new RegExp(`(allergy|allergic).{0,50}${normalizedMed}|${normalizedMed}.{0,50}(allergy|allergic)`, 'i');

        if (specificMatch.test(patientHistory)) {
            warning.style.color = "#c53030"; // Dark Red
            warning.style.background = "#fff5f5";
            warning.style.padding = "10px";
            warning.style.borderRadius = "8px";
            warning.style.border = "1px solid #feb2b2";
            warning.innerHTML = "<strong>🛑 CRITICAL ALLERGY ALERT:</strong> Patient record indicates a specific allergy related to '"+medName+"'. Please verify medical history immediately.";
        } else if (patientHistory.includes('allergy') || patientHistory.includes('allergic')) {
            warning.style.color = "#92400e"; // Amber/Brown
            warning.style.background = "#fffbeb";
            warning.style.padding = "10px";
            warning.style.borderRadius = "8px";
            warning.style.border = "1px solid #fef3c7";
            warning.innerHTML = "<strong>ℹ️ General Safety Note:</strong> Patient has documented allergies. No direct match for '"+medName+"' found, but proceed with caution.";
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


    function openEditVitalsModal() {
        document.getElementById('editVitalsModal').style.display = 'flex';
    }
    function closeEditVitalsModal() {
        document.getElementById('editVitalsModal').style.display = 'none';
    }

    // Close modal if clicked outside
    window.onclick = function(event) {
        const labModal = document.getElementById('labModal');
        const medModal = document.getElementById('medModal');
        const viewLabModal = document.getElementById('viewLabModal');
        const editVitalsModal = document.getElementById('editVitalsModal');
        
        if (event.target == labModal) closeLabModal();
        if (event.target == medModal) closeMedModal();
        if (event.target == viewLabModal) closeViewLabModal();
        if (event.target == editVitalsModal) closeEditVitalsModal();
    }
    function getAIInsights() {
        const notes = document.getElementById('clinicalNotes').value;
        if (!notes.trim()) {
            alert('Please enter some clinical notes or symptoms first (e.g., "Severe headache and fever").');
            return;
        }

        const btn = document.querySelector('button[onclick="getAIInsights()"]');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
        btn.disabled = true;

        fetch('get_ai_recommendation.php', { // Ensure this file exists in the same directory
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reason: notes })
        })
        .then(response => response.json())
        .then(data => {
            console.log("AI Response:", data);
            
            if (data.error) {
                alert('AI Error: ' + data.error);
                return;
            }

            // 1. Update Medication Modal Suggestions
            const suggestionBox = document.getElementById('aiSuggestionContent');
            if (suggestionBox) {
                let html = `<div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
                    <strong style="color: #0d47a1;"><i class="fas fa-robot"></i> AI Analysis Result:</strong><br>
                    <strong>Condition:</strong> ${data.disease || 'Unknown'} <br>
                    <strong>Urgency:</strong> <span class="badge badge-${data.urgency === 'High' || data.urgency === 'Critical' ? 'danger' : 'info'}">${data.urgency}</span><br>
                    <strong>Suggested Specialist:</strong> ${data.specialization}
                </div>`;
                
                // Add Medication Suggestions List
                if (data.suggested_medication && data.suggested_medication.length > 0) {
                     // Store for tracking
                     const storage = document.getElementById('ai_suggested_storage');
                     if(storage) storage.value = JSON.stringify(data.suggested_medication);
                     
                     html += `<div style="margin-top:10px;">
                        <strong style="color:#2c3e50;">Suggested Medications:</strong>
                        <ul style="list-style:none; padding:0; margin-top:5px;">`;
                     
                     data.suggested_medication.forEach(med => {
                         // Simple parser to guess dose
                         let parts = med.split(' ');
                         let name = parts[0];
                         let strength = parts.slice(1).join(' ');
                         
                         html += `<li style="display:flex; justify-content:space-between; align-items:center; background:white; padding:5px 8px; border:1px solid #eee; margin-bottom:4px; border-radius:4px;">
                                    <span>${med}</span>
                                    <button type="button" class="btn btn-xs btn-outline-success" onclick="addMedFromAI('${name}', '${strength}')"><i class="fas fa-plus"></i> Use</button>
                                  </li>`;
                     });
                     html += `</ul></div>`;
                }

                html += `<div style="font-size: 0.9em; color: #555; margin-top:5px;">Based on symptoms: "${data.raw_input || notes}"</div>`;
                
                suggestionBox.innerHTML = html;
            }

            // 2. Show a quick toast/alert near the button
            const resultId = 'ai-quick-result';
            const old = document.getElementById(resultId);
            if(old) old.remove();

            const toast = document.createElement('div');
            toast.id = resultId;
            toast.innerHTML = `<i class="fas fa-check-circle"></i> AI: ${data.disease} (${data.urgency})`;
            toast.style.cssText = "position:fixed; bottom:20px; right:20px; background:#333; color:white; padding:10px 20px; border-radius:5px; z-index:9999; animation: slideUp 0.3s ease-out;";
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);

        })
        .catch(err => {
            console.error(err);
            alert('Error communicating with AI service. Check console.');
        })
        .finally(() => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
</script>

<!-- Referral Modal -->
<div id="referralModal" class="lab-modal" style="display:none;">
    <div class="lab-modal-content" style="width: 800px; max-width: 95%;">
        <span class="close" onclick="closeReferralModal()" style="cursor: pointer; float: right; font-size: 28px; font-weight: bold;">&times;</span>
        <h4 style="color: #344767; margin-bottom: 20px;"><i class="fas fa-hand-holding-medical"></i> Refer Patient to Doctor</h4>
        
        <form method="POST" action="">
            <input type="hidden" name="refer_patient" value="1">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <!-- Specialization -->
                <div>
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 5px;">Select Specialization</label>
                    <select id="ref_specialization" class="form-control" onchange="filterRefDoctors()" style="width: 100%;">
                        <option value="">All Specializations</option>
                        <?php foreach ($specializations as $spec): ?>
                            <option value="<?php echo htmlspecialchars($spec); ?>"><?php echo htmlspecialchars($spec); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Doctor -->
                <div>
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 5px;">Select Doctor</label>
                    <select name="referral_doctor_id" id="ref_doctor_id" class="form-control" required onchange="fetchRefSlots()" style="width: 100%;">
                        <option value="">-- Choose Doctor --</option>
                        <?php foreach ($referral_doctors as $doc): ?>
                            <option value="<?php echo $doc['id']; ?>" data-spec="<?php echo htmlspecialchars($doc['specialization']); ?>">
                                Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?> (<?php echo htmlspecialchars($doc['specialization']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Date & Time Grid -->
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 20px; border-top: 1px solid #eee; padding-top: 15px;">
                <div>
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 5px;">Select Date</label>
                    <input type="date" id="ref_date" name="referral_date" class="form-control" required onchange="fetchRefSlots()" min="<?php echo date('Y-m-d'); ?>" style="width: 100%;">
                </div>
                <div>
                    <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 5px;">Available Time Slots (Auto-updating)</label>
                    <div id="ref-slot-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 8px; max-height: 150px; overflow-y: auto; padding: 10px; border: 1px dashed #ccc; background: #f9f9f9; border-radius: 6px; min-height: 50px;">
                        <p style="color: #777; font-size: 0.85em; grid-column: 1/-1; margin: 0;">Select doctor and date first.</p>
                    </div>
                    <input type="hidden" name="referral_time" id="ref_selected_time" required>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 5px;">Referral Reason / Notes for Doctor</label>
                <textarea name="referral_reason" class="form-control" rows="3" required placeholder="State the reason for referral..." style="width: 100%;"></textarea>
            </div>

            <div style="text-align: right; margin-top: 15px;">
                <button type="button" class="btn btn-secondary" onclick="closeReferralModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #333; color: white;"><i class="fas fa-paper-plane"></i> Submit Referral</button>
            </div>
        </form>
    </div>
</div>

<style>
    .ref-slot { background: #e8f5e9; border: 1px solid #c8e6c9; color: #1b5e20; border-radius: 4px; padding: 5px; text-align: center; cursor: pointer; font-size: 0.85em; transition: 0.2s; user-select: none; }
    .ref-slot:hover { background: #c8e6c9; }
    .ref-slot.booked { background: #ffebee; border-color: #ffcdd2; color: #b71c1c; cursor: not-allowed; text-decoration: line-through; }
    .ref-slot.selected { background: #007bff; color: white; border-color: #0056b3; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-weight: bold; }
</style>

<script>
function openReferralModal() { document.getElementById('referralModal').style.display = 'block'; }
function closeReferralModal() { document.getElementById('referralModal').style.display = 'none'; }

function filterRefDoctors() {
    const spec = document.getElementById('ref_specialization').value;
    const select = document.getElementById('ref_doctor_id');
    const options = select.options;
    select.value = "";
    
    for (let i = 1; i < options.length; i++) {
        const option = options[i];
        const docSpec = option.getAttribute('data-spec');
        if (spec === "" || docSpec === spec) {
            option.style.display = "";
            option.disabled = false;
        } else {
            option.style.display = "none";
            option.disabled = true;
        }
    }
    document.getElementById('ref-slot-container').innerHTML = '<p style="color: #777; font-size: 0.85em; grid-column: 1/-1; margin: 0;">Select doctor and date first.</p>';
    document.getElementById('ref_selected_time').value = '';
}

function fetchRefSlots() {
    const docId = document.getElementById('ref_doctor_id').value;
    const date = document.getElementById('ref_date').value;
    const container = document.getElementById('ref-slot-container');

    if (!docId || !date) return;

    container.innerHTML = '<span style="font-size: 0.8em; color: #666;">Loading available slots...</span>';

    fetch(`get_booked_slots.php?doctor_id=${docId}&date=${date}`)
        .then(res => res.json())
        .then(data => {
            if(data.error) throw new Error(data.error);
            renderRefSlots(data.booked_slots || [], date);
        })
        .catch(err => {
            container.innerHTML = `<span style="font-size: 0.8em; color: red;">Error: ${err.message}</span>`;
        });
}

function renderRefSlots(booked, dateStr) {
    const container = document.getElementById('ref-slot-container');
    container.innerHTML = '';
    const now = new Date();
    const todayStr = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
    const isToday = (dateStr === todayStr);

    for (let h = 9; h < 17; h++) {
        for (let m = 0; m < 60; m += 15) {
            const timeStr = `${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}`;
            const isBooked = booked.includes(timeStr);
            let isPast = false;
            if (isToday && (h < now.getHours() || (h === now.getHours() && m < now.getMinutes()))) {
                isPast = true;
            }
            
            const div = document.createElement('div');
            let cls = 'ref-slot';
            if(isBooked || isPast) {
                cls += ' booked';
                if (isPast) div.title = "Time passed";
                if (isBooked) div.title = "Already booked";
            }
            div.className = cls;
            div.textContent = timeStr;

            if(!isBooked && !isPast) {
                div.onclick = function() {
                    document.querySelectorAll('.ref-slot.selected').forEach(e => e.classList.remove('selected'));
                    div.classList.add('selected');
                    document.getElementById('ref_selected_time').value = timeStr;
                };
            }
            container.appendChild(div);
        }
    }
}
</script>

<?php if (!$is_embedded) include '../../includes/footer.php'; ?>
