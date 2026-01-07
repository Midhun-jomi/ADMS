<?php
// modules/patient_management/nursing_station.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse', 'head_nurse']);

$page_title = "Nurse Dashboard"; // Renamed for clarity
include '../../includes/header.php';

// Get Nurse Allocation
$user_id = get_user_id();
$nurse = db_select_one("SELECT id, first_name, last_name FROM staff WHERE user_id = $1", [$user_id]);
$nurse_id = $nurse['id'] ?? 0;

$allocation = db_select_one("SELECT na.*, s_doc.first_name as doc_first, s_doc.last_name as doc_last, d.name as dept_name
                             FROM nurse_allocations na
                             LEFT JOIN staff s_doc ON na.doctor_id = s_doc.id
                             LEFT JOIN departments d ON na.department_id = d.id
                             WHERE na.nurse_id = $1", [$nurse_id]);

// Handle Vitals Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vitals'])) {
    $v_patient_id = $_POST['v_patient_id'];
    $v_appt_id = $_POST['v_appointment_id'] ?? null;
    $r_by = $_POST['recorded_by_id'] ?? $user_id; // recorded_by_id not actually in form, defaulting to $user_id
    
    // Save Metrics
    $metrics = [
        'heart_rate' => ['value' => $_POST['heart_rate'], 'unit' => 'bpm'],
        'glucose' => ['value' => $_POST['glucose'], 'unit' => 'mg/dL'],
        'weight' => ['value' => $_POST['weight'], 'unit' => 'kg'],
        'cholesterol' => ['value' => $_POST['cholesterol'], 'unit' => 'mg/dL'],
        'temperature' => ['value' => $_POST['temperature'], 'unit' => 'F'],
        'bp_systolic' => ['value' => $_POST['bp_systolic'], 'unit' => 'mmHg'],
        'bp_diastolic' => ['value' => $_POST['bp_diastolic'], 'unit' => 'mmHg']
    ];

    foreach ($metrics as $type => $data) {
        if (!empty($data['value'])) {
             db_insert('patient_health_metrics', [
                'patient_id' => $v_patient_id,
                'metric_type' => $type,
                'metric_value' => json_encode($data),
                'recorded_by' => $user_id
            ]);
        }
    }
    
    // Save Nurse Note / History
    if (!empty($_POST['nurse_notes']) && $v_appt_id) {
        $note = trim($_POST['nurse_notes']);
        if ($note) {
            $timestamp = date('h:i A');
            $auth_user = db_select_one("SELECT first_name, last_name FROM staff WHERE user_id = $1", [$user_id]);
            $nurse_name = $auth_user ? ($auth_user['first_name'].' '.$auth_user['last_name']) : 'Nurse';
            
            // Append to reason
            $appt = db_select_one("SELECT reason FROM appointments WHERE id = $1", [$v_appt_id]);
            if ($appt) {
                $new_reason = ($appt['reason'] ?? '') . "\n\n[Nurse Entry $timestamp]: " . $note;
                db_query("UPDATE appointments SET reason = $1 WHERE id = $2", [$new_reason, $v_appt_id]);
            }
        }
    }
    
    echo "<meta http-equiv='refresh' content='0'>";
}

// Fetch Admitted Patients
// Improved query to handle potential nulls safely
$sql = "SELECT a.id as admission_id, a.patient_id, a.admission_date, a.diagnosis, 
               p.id as patient_id_real, p.first_name, p.last_name, p.date_of_birth,
               r.room_number, r.room_type,
               (SELECT profile_image FROM users u WHERE u.id = p.user_id) as p_image
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        JOIN rooms r ON a.room_id = r.id
        WHERE a.status = 'admitted'
        ORDER BY r.room_number";

$admissions = db_select($sql);

// Fetch Outpatients (Appointments for Allocated Doctor)
$outpatients = [];
if (!empty($allocation['doctor_id'])) {
    $doc_id = $allocation['doctor_id'];
    // Postgres specific date check
    $sql_op = "SELECT a.*, p.first_name, p.last_name, p.date_of_birth, p.id as patient_id,
                      (SELECT profile_image FROM users u WHERE u.id = p.user_id) as p_image
               FROM appointments a
               JOIN patients p ON a.patient_id = p.id
               WHERE a.doctor_id = $1 
                 AND a.appointment_time::date = CURRENT_DATE
                 AND a.status != 'cancelled'
               ORDER BY a.appointment_time ASC";
    $outpatients = db_select($sql_op, [$doc_id]);
}
?>

<style>
    /* Premium Dashboard Styles specific to Nursing Station */
    .dashboard-grid-layout {
        display: grid;
        grid-template-columns: 2.5fr 1fr; /* Main list vs Sidebar info */
        gap: 30px;
        margin-top: 20px;
    }

    .allocation-banner {
        background: linear-gradient(135deg, #172a74 0%, #21a9af 100%);
        color: white;
        border-radius: 20px;
        padding: 30px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 20px rgba(33, 169, 175, 0.2);
    }
    .banner-content { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: center; }
    .banner-deco { position: absolute; right: -20px; bottom: -50px; font-size: 15rem; opacity: 0.1; transform: rotate(-15deg); }
    
    .glass-panel {
        background: white;
        border-radius: 20px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.03);
        padding: 25px;
        border: 1px solid #f0f0f0;
    }
    
    .patient-card-item {
        display: flex;
        align-items: center;
        background: #fff;
        border: 1px solid #eee;
        border-radius: 15px;
        padding: 15px;
        margin-bottom: 15px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .patient-card-item:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        border-color: #21a9af;
    }
    
    .p-avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; background: #eee; margin-right: 15px; }
    .p-info { flex: 1; }
    .p-name { font-weight: 700; color: #333; display: block; }
    .p-meta { font-size: 0.85em; color: #777; }
    .p-room { 
        background: #f3f4f6; padding: 5px 10px; border-radius: 8px; font-weight: 600; font-size: 0.9em; color: #555; 
        display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 60px; margin-right: 20px;
    }
    .p-actions { display: flex; gap: 10px; }
    
    /* Responsive */
    @media (max-width: 1100px) {
        .dashboard-grid-layout { grid-template-columns: 1fr; }
    }
</style>

<!-- Allocation Banner -->
<div class="allocation-banner">
    <i class="fas fa-user-nurse banner-deco"></i>
    <div class="banner-content">
        <div>
            <h2 style="margin: 0; font-size: 1.8rem;">Station Dashboard</h2>
            <p style="margin: 5px 0 0; opacity: 0.9;">Manage your patients and monitor vitals efficiently.</p>
        </div>
        <div style="text-align: right; background: rgba(255,255,255,0.15); padding: 10px 20px; border-radius: 12px; backdrop-filter: blur(5px);">
            <?php if ($allocation): ?>
                <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;">Current Assignment</div>
                <div style="font-size: 1.2rem; font-weight: 700;">
                    <?php echo htmlspecialchars($allocation['dept_name'] ?? 'General'); ?> 
                    <span style="opacity: 0.7;">&bull;</span> 
                    Dr. <?php echo htmlspecialchars($allocation['doc_last'] ?? 'Unassigned'); ?>
                </div>
            <?php else: ?>
                <div style="font-size: 1rem;"><i class="fas fa-exclamation-circle"></i> No Specific Allocation</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Layout -->
<div class="dashboard-grid-layout">
    <!-- Left Column: Patient List -->
    <div class="glass-panel">
        
        <!-- SECTION 1: TODAY'S OUTPATIENTS (If Allocated to Doctor) -->
        <?php if (!empty($allocation['doctor_id'])): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #333;"><i class="fas fa-user-clock" style="color:#ff9800; margin-right:10px;"></i> Today's Clinic (Dr. <?php echo htmlspecialchars($allocation['doc_last']); ?>)</h3>
                <span class="badge badge-warning"><?php echo count($outpatients); ?> Appointments</span>
            </div>

            <?php if (empty($outpatients)): ?>
                <div style="text-align: center; padding: 20px; color: #888; border-bottom: 1px solid #eee; margin-bottom: 20px;">
                    <p>No appointments scheduled for today.</p>
                </div>
            <?php else: ?>
                <div class="patient-list" style="margin-bottom: 40px;">
                    <?php foreach ($outpatients as $opt): 
                        $age = date_diff(date_create($opt['date_of_birth']), date_create('today'))->y;
                        $p_img = $opt['p_image'] ?: "https://ui-avatars.com/api/?name=" . urlencode($opt['first_name'] . ' ' . $opt['last_name']);
                        $time_formatted = date('h:i A', strtotime($opt['appointment_time']));
                    ?>
                    <div class="patient-card-item" style="border-left: 4px solid #ff9800;">
                        <div class="p-room" style="background: #fff3e0; color: #e65100; min-width: 80px;">
                            <span style="font-size: 1.1em;"><?php echo $time_formatted; ?></span>
                            <span style="font-size: 0.6em; text-transform: uppercase;">Time</span>
                        </div>
                        
                        <img src="<?php echo $p_img; ?>" class="p-avatar">
                        
                        <div class="p-info">
                            <span class="p-name"><?php echo htmlspecialchars($opt['first_name'] . ' ' . $opt['last_name']); ?></span>
                            <span class="p-meta">
                                <?php echo $age; ?> yrs &bull; Status: <?php echo ucfirst($opt['status']); ?>
                            </span>
                        </div>
                        
                        <div class="p-actions">
                             <button 
                                type="button"
                                class="btn btn-sm btn-light" 
                                style="color: #ff9800; border: 1px solid #ff9800;"
                                data-patient-id="<?php echo $opt['patient_id']; ?>"
                                data-patient-name="<?php echo htmlspecialchars($opt['first_name'] . ' ' . $opt['last_name']); ?>"
                                data-appt-id="<?php echo $opt['id']; ?>"
                                onclick="openVitalsModalFromElement(this)">
                                <i class="fas fa-heartbeat"></i> Take Vitals
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- SECTION 2: ADMITTED PATIENTS -->
        <hr style="margin: 30px 0; border: 0; border-top: 2px dashed #eee;">

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #333;">Active In-Patients</h3>
            <span class="badge badge-primary"><?php echo count($admissions); ?> Current</span>
        </div>

        <?php if (empty($admissions)): ?>
            <div style="text-align: center; padding: 40px; color: #888;">
                <i class="fas fa-bed" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3;"></i>
                <p>No patients currently admitted under your station.</p>
            </div>
        <?php else: ?>
            <div class="patient-list">
                <?php foreach ($admissions as $adm): 
                    $age = date_diff(date_create($adm['date_of_birth']), date_create('today'))->y;
                    $p_img = $adm['p_image'] ?: "https://ui-avatars.com/api/?name=" . urlencode($adm['first_name'] . ' ' . $adm['last_name']);
                ?>
                <div class="patient-card-item">
                    <div class="p-room">
                        <span style="font-size: 1.1em; color: #21a9af;"><?php echo htmlspecialchars($adm['room_number']); ?></span>
                        <span style="font-size: 0.6em; text-transform: uppercase;"><?php echo htmlspecialchars(substr($adm['room_type'], 0, 3)); ?></span>
                    </div>
                    
                    <img src="<?php echo $p_img; ?>" class="p-avatar">
                    
                    <div class="p-info">
                        <span class="p-name"><?php echo htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name']); ?></span>
                        <span class="p-meta">
                            <?php echo $age; ?> yrs &bull; Since <?php echo date('M d', strtotime($adm['admission_date'])); ?>
                        </span>
                        <div style="font-size: 0.8em; color: #666; margin-top: 4px;">
                            <i class="fas fa-notes-medical" style="color: #21a9af;"></i> <?php echo htmlspecialchars(substr($adm['diagnosis'], 0, 40)) . '...'; ?>
                        </div>
                    </div>
                    
                    <div class="p-actions">
                        <button 
                            type="button"
                            class="btn btn-sm btn-light" 
                            style="color: #21a9af; border: 1px solid #21a9af;"
                            data-patient-id="<?php echo $adm['patient_id']; ?>"
                            data-patient-name="<?php echo htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name']); ?>"
                            data-appt-id=""
                            onclick="openVitalsModalFromElement(this)">
                            <i class="fas fa-notes-medical"></i> Vitals
                        </button>
                        <a href="patient_care.php?patient_id=<?php echo $adm['patient_id']; ?>" class="btn btn-sm btn-primary" style="background: #21a9af; border: none;">
                            <i class="fas fa-caret-right"></i> Care
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Quick Stats/Tasks -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="glass-panel" style="background: #fff8e1; border: none;">
            <h4 style="margin: 0 0 15px 0; color: #d97706;"><i class="fas fa-exclamation-triangle"></i> Critical Alerts</h4>
            <!-- Mock Alerts -->
            <div style="font-size: 0.9em; margin-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 10px;">
                <strong>Room 101</strong>: High BP Report <span style="float: right; font-size: 0.8em; color: #888;">5m ago</span>
            </div>
            <div style="font-size: 0.9em;">
                <strong>Room 205</strong>: Medication Due <span style="float: right; font-size: 0.8em; color: #888;">10m ago</span>
            </div>
        </div>

        <div class="glass-panel">
            <h4 style="margin: 0 0 15px 0;">My Shift Info</h4>
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9em;">
                <span style="color: #666;">Start Time</span>
                <strong>08:00 AM</strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9em;">
                <span style="color: #666;">End Time</span>
                <strong>04:00 PM</strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.9em;">
                <span style="color: #666;">Ward Head</span>
                <strong>Sarah J.</strong>
            </div>
        </div>
    </div>
</div>

<!-- Vitals Modal -->
<div id="nurseVitalsModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(4px);">
    <div class="modal-content" style="background-color: #fefefe; margin: 10% auto; padding: 0; border: none; width: 500px; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div style="background: #21a9af; color: white; padding: 20px; border-top-left-radius: 20px; border-top-right-radius: 20px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.2rem;">Record Vitals & History</h3>
            <span onclick="closeVitalsModal()" style="font-size: 24px; cursor: pointer; opacity: 0.8;">&times;</span>
        </div>
        
        <div style="padding: 25px;">
            <p style="margin-top: 0; color: #666; font-size: 0.9em;">Recording for: <strong id="vitalsPatientName" style="color: #333;">-</strong></p>
            <form method="POST">
                <input type="hidden" name="add_vitals" value="1">
                <input type="hidden" name="v_patient_id" id="vitalsPatientId">
                <input type="hidden" name="v_appointment_id" id="vitalsAppointmentId">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">Temp (°F)</label>
                        <input type="number" step="0.1" name="temperature" class="form-control" placeholder="98.6" style="border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">Weight (kg)</label>
                        <input type="number" step="0.1" name="weight" class="form-control" placeholder="kg" style="border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">Heart Rate (BPM)</label>
                        <input type="number" name="heart_rate" class="form-control" placeholder="72" style="border-radius: 8px;">
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">BP Systolic</label>
                        <input type="number" name="bp_systolic" class="form-control" placeholder="120" style="border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">BP Diastolic</label>
                        <input type="number" name="bp_diastolic" class="form-control" placeholder="80" style="border-radius: 8px;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">Glucose</label>
                        <input type="number" name="glucose" class="form-control" placeholder="mg/dL" style="border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">Cholesterol</label>
                        <input type="number" name="cholesterol" class="form-control" placeholder="mg/dL" style="border-radius: 8px;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 0.8em; font-weight: 600; color: #555; margin-bottom: 5px;">Nurse Notes / Vital History / Chief Complaint</label>
                    <textarea name="nurse_notes" class="form-control" rows="3" placeholder="Enter patient history, complaints, or observations..." style="border-radius: 8px; width: 100%;"></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; background: #21a9af; border: none; padding: 12px; border-radius: 10px; font-weight: 600;">Save Record</button>
            </form>
        </div>
    </div>
</div>

<script>
    function openVitalsModalFromElement(btn) {
        var pid = btn.getAttribute('data-patient-id');
        var pname = btn.getAttribute('data-patient-name');
        var apptId = btn.getAttribute('data-appt-id');
        
        document.getElementById('vitalsPatientId').value = pid;
        document.getElementById('vitalsAppointmentId').value = apptId || '';
        document.getElementById('vitalsPatientName').textContent = pname;
        document.getElementById('nurseVitalsModal').style.display = 'block';
    }
    
    // Fallback for legacy calls if any
    function openVitalsModal(pid, pname, apptId) {
        document.getElementById('vitalsPatientId').value = pid;
        document.getElementById('vitalsAppointmentId').value = apptId || '';
        document.getElementById('vitalsPatientName').textContent = pname;
        document.getElementById('nurseVitalsModal').style.display = 'block';
    }
    function closeVitalsModal() {
        document.getElementById('nurseVitalsModal').style.display = 'none';
    }
    
    // Close on click outside
    window.onclick = function(event) {
        var modal = document.getElementById('nurseVitalsModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
