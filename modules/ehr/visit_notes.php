<?php
// modules/ehr/visit_notes.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['doctor', 'patient']);

$role = get_user_role();
$page_title = "Consultation & Notes";
include '../../includes/header.php';

$appointment_id = $_GET['appointment_id'] ?? null;

if (!$appointment_id) {
    echo "<div class='alert alert-danger'>Appointment ID required.</div>";
    include '../../includes/footer.php';
    exit();
}

// Fetch appointment + patient details (Added medical_history)
// Fetch appointment + patient details (Added medical_history)
$appt = db_select_one("SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.gender, p.phone, u.email as p_email, p.address, p.id as patient_id, p.uhid, p.medical_history, u.profile_image 
                       FROM appointments a 
                       JOIN patients p ON a.patient_id = p.id 
                       JOIN users u ON p.user_id = u.id
                       WHERE a.id = $1", [$appointment_id]);

if (!$appt) {
    echo "<div class='alert alert-danger'>Appointment not found.</div>";
    include '../../includes/footer.php';
    exit();
}

$patient_id = $appt['patient_id'];

// Handle POST Requests (Doctor Only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    
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
            $success = "Visit completed successfully.";
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
            foreach ($test_types as $type) {
                $type = trim($type);
                if (!empty($type)) {
                    $sql = "INSERT INTO laboratory_tests (patient_id, doctor_id, test_type, status) VALUES ($1, $2, $3, 'ordered')";
                    db_query($sql, [$patient_id, $doc_pk, $type]);
                    $count++;
                }
            }
            if ($count > 0) {
                $success = "$count Lab Order(s) Sent Successfully.";
            }
        }
    }

    // 3. Handle Medication Prescription (AJAX)
    // We check for a special header or post val to know it's an AJAX call, or just standard POST
    if (isset($_POST['add_medication'])) {
        $med_name = $_POST['med_name'];
        $med_qty = $_POST['med_qty'];
        $med_freq = $_POST['med_frequency']; // New Field
        $med_timing = $_POST['med_timing'];
        $med_note = $_POST['med_note'];
        
        $doc_id = get_user_id();
        $doctor = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$doc_id]);
        $doc_pk = $doctor ? $doctor['id'] : null;

        if ($doc_pk) {
            // Check if prescription entry exists for this appointment
            $existing_rx = db_select_one("SELECT * FROM prescriptions WHERE appointment_id = $1", [$appointment_id]);
            
            $new_item = [
                'name' => $med_name, 
                'quantity' => $med_qty, 
                // formatted string: "1-0-1 | After Food. Note: ..."
                'dosage' => "$med_freq | $med_timing" . ($med_note ? ". Note: $med_note" : "") 
            ];

            if ($existing_rx) {
                // Append to existing
                $current_details = json_decode($existing_rx['medication_details'], true) ?: [];
                $current_details[] = $new_item;
                $new_json = json_encode($current_details);
                
                db_query("UPDATE prescriptions SET medication_details = $1, created_at = NOW() WHERE id = $2", [$new_json, $existing_rx['id']]);
            } else {
                // Create new
                $details_json = json_encode([$new_item]);
                db_insert('prescriptions', [
                    'patient_id' => $patient_id,
                    'doctor_id' => $doc_pk,
                    'appointment_id' => $appointment_id,
                    'medication_details' => $details_json
                ]);
            }
            
            // Return JSON for AJAX
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                echo json_encode(['status' => 'success', 'message' => 'Medication saved']);
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

// Fetch Vitals
$vitals = db_select("SELECT metric_type, metric_value, recorded_at FROM patient_health_metrics WHERE patient_id = $1 ORDER BY recorded_at DESC", [$patient_id]);
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
        background-color: #fefefe; margin: 10% auto; padding: 25px; border: 1px solid #888; width: 500px; border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: slideDown 0.3s ease-out;
    }
    
    /* ... inside script ... */
    
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
        // Optional: location.reload(); 
        // Better: just hide it to avoid jarring reload if they cancel
    }
    
    function submitMedication(e) {
        console.log("Submitting Medication...");
        e.preventDefault();

    @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    
    .lab-checkbox {
        display: block; padding: 10px; margin: 5px 0; border: 1px solid #ddd; border-radius: 5px; cursor: pointer; transition: 0.2s;
    }
    .lab-checkbox:hover { background: #f0f0f0; }
    .lab-checkbox input { margin-right: 10px; transform: scale(1.2); }
    .lab-checkbox.active { background-color: #e3f2fd; border-color: #2196f3; color: #0d47a1; }
    
    .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
</style>

<div class="header-actions" style="margin-bottom: 20px;">
    <a href="appointments.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Schedule</a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
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
            <div class="section-title" style="margin-bottom: 15px;"><i class="fas fa-heartbeat" style="color: #e91e63;"></i> Latest Vitals</div>
            <?php if (empty($latest_vitals)): ?>
                <p style="color: #999; font-style: italic; text-align: center;">No recorded vitals.</p>
            <?php else: ?>
                <div class="vitals-grid">
                    <?php 
                        $metrics_map = ['heart_rate' => ['label' => 'HR', 'icon' => 'fa-heart'], 'temperature' => ['label' => 'Temp', 'icon' => 'fa-thermometer-half'], 'bp_systolic' => ['label' => 'BP Sys', 'icon' => 'fa-tint'], 'glucose' => ['label' => 'Glu', 'icon' => 'fa-cube']];
                        foreach ($metrics_map as $key => $meta): if (isset($latest_vitals[$key])):
                            $val = $latest_vitals[$key]['value']; $unit = $latest_vitals[$key]['unit'];
                    ?>
                    <div class="vital-box"><span class="vital-name"><?php echo $meta['label']; ?></span><div class="vital-val"><?php echo $val; ?></div><span class="vital-unit"><?php echo $unit; ?></span></div>
                    <?php endif; endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Content -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="main-panel">
            <div class="section-title">
                <span><i class="fas fa-user-md"></i> Clinical Notes & Consultation</span>
                <span class="badge <?php echo $appt['status'] == 'completed' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst($appt['status']); ?></span>
            </div>

            <!-- Read-Only Visit History -->
            <label style="font-weight: 600; color: #555;">Previous Notes / HPI:</label>
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px solid #e9ecef; margin-bottom: 20px; white-space: pre-wrap; font-family: 'Inter', sans-serif;">
                <?php echo $appt['reason'] ?: 'No notes recorded.'; ?>
            </div>

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

                <div class="form-row" style="display: flex; gap: 15px; align-items: center; justify-content: space-between; margin-top: 20px;">
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="save_note" value="1" class="btn btn-info"><i class="fas fa-save"></i> Save Progress</button>
                        <button type="submit" name="complete_visit" value="1" class="btn btn-success" onclick="return confirm('Mark visit as completed and generate bill?');"><i class="fas fa-check-circle"></i> Complete Visit</button>
                    </div>
                    
                    <div style="display: flex; gap: 5px;">
                        <!-- Lab Button Triggers Modal -->
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openLabModal()">
                            <i class="fas fa-flask"></i> Order Lab
                        </button>
                        
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openMedModal()"><i class="fas fa-pills"></i> Meds</button>
                    </div>
                </div>
            </form>
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
        <h4 style="margin-top: 0;">Prescribe Medication</h4>
        
        <form id="medFormAjax" onsubmit="submitMedication(event)">
            <input type="hidden" name="add_medication" value="1">
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                <div style="flex: 2;">
                    <label style="font-size: 0.9em;">Medication</label>
                    <select name="med_name" class="form-control" required style="width: 100%;">
                        <option value="">Select...</option>
                        <?php foreach ($inventory as $item): ?>
                            <option value="<?php echo htmlspecialchars($item['medication_name']); ?>">
                                <?php echo htmlspecialchars($item['medication_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Frequency Select -->
                <div style="flex: 1;">
                    <label style="font-size: 0.9em;">Freq</label>
                    <select name="med_frequency" class="form-control" required>
                        <option value="">--</option>
                        <option value="1-0-1">1-0-1</option>
                        <option value="1-1-1">1-1-1</option>
                        <option value="1-0-0">1-0-0</option>
                        <option value="0-0-1">0-0-1</option>
                        <option value="0-1-0">0-1-0</option>
                        <option value="SOS">SOS</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.9em;">Qty</label>
                    <input type="number" name="med_qty" class="form-control" placeholder="Qty" required>
                </div>
            </div>
            
            <label style="font-size: 0.9em;">Timing</label>
            <div style="display: flex; gap: 20px; margin-bottom: 10px;">
                <label><input type="radio" name="med_timing" value="Before Food" required> Before Food</label>
                <label><input type="radio" name="med_timing" value="After Food"> After Food</label>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="font-size: 0.9em;">Note / Inst</label>
                <textarea name="med_note" class="form-control" rows="2" placeholder="e.g. Take with warm water"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">Add to List (Auto-Save)</button>
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

<script>
    function openLabModal() {
        document.getElementById('labModal').style.display = 'block';
    }
    
    function closeLabModal() {
        document.getElementById('labModal').style.display = 'none';
        // Reset selection visual state
        document.querySelectorAll('.lab-checkbox input').forEach(input => {
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
    
    function submitMedication(e) {
        console.log("Submitting Medication...");
        e.preventDefault();
        const form = document.getElementById('medFormAjax');
        const formData = new FormData(form);
        
        // Append AJAX flag
        formData.append('add_medication', '1');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const container = document.getElementById('medListContainer');
                const noMsg = document.getElementById('noMedsMsg');
                if (noMsg) noMsg.style.display = 'none';
                
                const name = form.med_name.value;
                const qty = form.med_qty.value;
                const freq = form.med_frequency.value;
                const timing = form.querySelector('input[name="med_timing"]:checked').value;
                const note = form.med_note.value;
                
                const div = document.createElement('div');
                div.className = 'med-list-item';
                div.innerHTML = `<strong>${name}</strong><span style="color: #666; font-size: 0.85em;">${qty} | ${freq} | ${timing}</span>`;
                if(note) div.innerHTML += `<br><span style="font-size:0.85em; color:#888;">${note}</span>`;
                
                container.prepend(div);
                
                form.med_name.value = "";
                form.med_qty.value = "";
                form.med_note.value = "";
            } else {
                alert('Error saving medication');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Failed to save medication');
        });
    }


    // Close modal if clicked outside
    window.onclick = function(event) {
        const labModal = document.getElementById('labModal');
        const medModal = document.getElementById('medModal');
        if (event.target == labModal) closeLabModal();
        if (event.target == medModal) closeMedModal();
    }
</script>

<?php include '../../includes/footer.php'; ?>
