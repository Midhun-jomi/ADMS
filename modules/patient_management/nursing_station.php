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
    $v_appt_id = !empty($_POST['v_appointment_id']) ? $_POST['v_appointment_id'] : null;
    $r_by = $_POST['recorded_by_id'] ?? $user_id;
    
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
                'appointment_id' => $v_appt_id,
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
            $appt = db_select_one("SELECT reason, doctor_id FROM appointments WHERE id = $1", [$v_appt_id]);
            if ($appt) {
                $new_reason = ($appt['reason'] ?? '') . "\n\n[Nurse Entry $timestamp]: " . $note;
                db_query("UPDATE appointments SET reason = $1 WHERE id = $2", [$new_reason, $v_appt_id]);

                // Notify Doctor that Vitals/History is ready
                if ($appt['doctor_id']) {
                    $doc_user = db_select_one("SELECT user_id FROM staff WHERE id = $1", [$appt['doctor_id']]);
                    if ($doc_user) {
                        db_insert('notifications', [
                            'user_id' => $doc_user['user_id'],
                            'title' => 'Triage Completed',
                            'message' => "Nurse has updated vitals and clinical findings for a patient. Ready for consultation.",
                            'is_read' => 0
                        ]);
                    }
                }
            }
        }
    }
    
    echo "<meta http-equiv='refresh' content='0'>";
}

// Fetch Real-time Critical Alerts (Last 6 hours)
$critical_alerts = db_select("SELECT p.first_name, p.last_name, m.metric_type, m.metric_value, m.recorded_at, r.room_number 
                              FROM patient_health_metrics m
                              JOIN patients p ON m.patient_id = p.id
                              LEFT JOIN admissions a ON a.patient_id = p.id AND a.status = 'admitted'
                              LEFT JOIN rooms r ON a.room_id = r.id
                              WHERE m.recorded_at > NOW() - INTERVAL '6 hours'
                              ORDER BY m.recorded_at DESC");

$active_alerts = [];
foreach ($critical_alerts as $alert) {
    $val_data = json_decode($alert['metric_value'], true);
    $val = floatval($val_data['value'] ?? 0);
    $is_critical = false;
    $msg = '';
    
    if ($alert['metric_type'] == 'bp_systolic' && ($val > 160 || $val < 90)) { $is_critical = true; $msg = "Abnormal Blood Pressure ($val)"; }
    if ($alert['metric_type'] == 'glucose' && ($val > 250 || $val < 60)) { $is_critical = true; $msg = "Critical Glucose Level ($val)"; }
    if ($alert['metric_type'] == 'heart_rate' && ($val > 120 || $val < 50)) { $is_critical = true; $msg = "Arrhythmia Alert ($val BPM)"; }
    if ($alert['metric_type'] == 'temperature' && $val > 102) { $is_critical = true; $msg = "High Fever ($val F)"; }

    if ($is_critical) {
        $active_alerts[] = [
            'patient' => $alert['first_name'].' '.$alert['last_name'],
            'room' => $alert['room_number'] ?? 'OPD',
            'msg' => $msg,
            'time' => date('h:i A', strtotime($alert['recorded_at']))
        ];
    }
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

<!-- Quick Entry Bar -->
<div class="glass-panel" style="margin-bottom: 30px; border: 2px solid #21a9af; background: #f0fdfa;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
        <div style="flex: 1; min-width: 300px;">
            <h4 style="margin: 0 0 5px 0; color: #115e59;"><i class="fas fa-search-plus"></i> Quick Vitals Entry</h4>
            <p style="margin: 0; font-size: 0.85em; color: #134e4a;">Search by Patient Name or UHID to record new clinical data.</p>
        </div>
        <div style="display: flex; gap: 10px; flex: 1; min-width: 300px;">
            <input type="text" id="quickPatientSearch" class="form-control" placeholder="Search Name or UHID..." style="border-radius: 10px;" oninput="debouncedSearch()" onkeyup="if(event.key === 'Enter') searchAndRecordVitals()">
            <button class="btn btn-primary" style="background: #21a9af; border: none; white-space: nowrap; border-radius: 10px;" onclick="searchAndRecordVitals()">
                <i class="fas fa-search"></i> Search
            </button>
        </div>
    </div>
    <div id="search_results" style="margin-top: 15px; display: none; background: white; border-radius: 10px; padding: 10px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); max-height: 250px; overflow-y: auto;">
        <!-- AJAX results will appear here -->
    </div>
</div>

<script>
let searchTimeout = null;
function debouncedSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchAndRecordVitals, 500);
}

async function searchAndRecordVitals() {
    const query = document.getElementById('quickPatientSearch').value.trim();
    const resDiv = document.getElementById('search_results');
    
    if (query.length < 2) {
        resDiv.style.display = 'none';
        return;
    }
    
    resDiv.innerHTML = '<div style="text-align: center; padding: 15px; color: #666;"><i class="fas fa-spinner fa-spin"></i> Searching database...</div>';
    resDiv.style.display = 'block';

    try {
        // Try absolute root first, then relative
        let response = await fetch('/api/search_patients.php?q=' + encodeURIComponent(query));
        if (!response.ok) {
            response = await fetch('../../api/search_patients.php?q=' + encodeURIComponent(query));
        }
        if (!response.ok) throw new Error('API unreachable');
        const data = await response.json();
        
        if (data.length === 0) {
            resDiv.innerHTML = '<p style="padding: 10px; color: #888;">No patients found.</p>';
        } else {
            let html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">';
            data.forEach(p => {
                html += `
                    <div style="padding: 10px; border: 1px solid #eee; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong style="display: block;">${p.first_name} ${p.last_name}</strong>
                            <small class="text-muted">UHID: P-${String(p.uhid || 0).padStart(4, '0')}</small>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" onclick="openVitalsModal('${p.id}', '${p.first_name} ${p.last_name}', '')">
                            Select
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            resDiv.innerHTML = html;
        }
    } catch (e) {
        resDiv.innerHTML = '<p style="color: red;">Search failed. API not found.</p>';
    }
}
</script>

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
                                class="btn btn-sm btn-primary" 
                                style="background: #ff9800; border: none; padding: 8px 15px; border-radius: 8px; font-weight: 600;"
                                data-patient-id="<?php echo $opt['patient_id']; ?>"
                                data-patient-name="<?php echo htmlspecialchars($opt['first_name'] . ' ' . $opt['last_name']); ?>"
                                data-appt-id="<?php echo $opt['id']; ?>"
                                onclick="openVitalsModalFromElement(this)">
                                <i class="fas fa-heartbeat"></i> RECORD VITALS
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
                            class="btn btn-sm btn-primary" 
                            style="background: #21a9af; border: none; padding: 8px 15px; border-radius: 8px; font-weight: 600;"
                            data-patient-id="<?php echo $adm['patient_id']; ?>"
                            data-patient-name="<?php echo htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name']); ?>"
                            data-appt-id=""
                            onclick="openVitalsModalFromElement(this)">
                            <i class="fas fa-plus-circle"></i> RECORD VITALS
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Quick Stats/Tasks -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="glass-panel" style="background: <?php echo empty($active_alerts) ? '#f0fff4' : '#fff8e1'; ?>; border: none;">
            <h4 style="margin: 0 0 15px 0; color: <?php echo empty($active_alerts) ? '#2f855a' : '#d97706'; ?>;">
                <i class="fas <?php echo empty($active_alerts) ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i> 
                <?php echo empty($active_alerts) ? 'System Stable' : 'Critical Alerts'; ?>
            </h4>
            
            <?php if (empty($active_alerts)): ?>
                <p style="font-size: 0.85em; color: #555; margin: 0;">No critical vital deviations detected in the last 6 hours.</p>
            <?php else: ?>
                <?php foreach(array_slice($active_alerts, 0, 3) as $alert): ?>
                    <div style="font-size: 0.85em; margin-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 10px;">
                        <strong style="color: #c53030;"><?php echo htmlspecialchars($alert['room']); ?></strong>: <?php echo htmlspecialchars($alert['msg']); ?> 
                        <div style="font-size: 0.8em; color: #888;"><?php echo htmlspecialchars($alert['patient']); ?> &bull; <?php echo $alert['time']; ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="glass-panel">
            <h4 style="margin: 0 0 15px 0;">Station Status</h4>
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9em;">
                <span style="color: #666;">Current Time</span>
                <strong><?php echo date('h:i A'); ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.9em;">
                <span style="color: #666;">Active Patients</span>
                <strong><?php echo count($admissions) + count($outpatients); ?></strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.9em;">
                <span style="color: #666;">Staff On Duty</span>
                <strong><?php echo htmlspecialchars($nurse['first_name'] . ' ' . $nurse['last_name']); ?></strong>
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
