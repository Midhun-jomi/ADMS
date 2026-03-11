<?php
// modules/ehr/history.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Patient Medical History";
include '../../includes/header.php';

$patient_id = null;

// Determine Context
if ($role === 'patient') {
    $p = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    $patient_id = $p['id'] ?? null;
} elseif (in_array($role, ['doctor', 'admin', 'nurse', 'head_nurse']) && isset($_GET['patient_id'])) {
    // Verify patient exists and UUID format (prevents injection and type errors)
    $patient_id = $_GET['patient_id'];
    if (!preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/', $patient_id)) {
        $patient_id = null;
    } else {
        $patient_check = db_select_one("SELECT id FROM patients WHERE id = $1", [$patient_id]);
        if (!$patient_check) {
            $patient_id = null;
        }
    }
}

if (!$patient_id) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Patient context not found. Please select a patient.</div></div>";
    include '../../includes/footer.php';
    exit();
}

// Fetch Patient Details
$patient = db_select_one("
    SELECT p.*, u.email 
    FROM patients p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.id = $1
", [$patient_id]);

if (!$patient) {
    die("Patient not found.");
}

// Fetch Related Data
$appointments = db_select("
    SELECT a.*, s.first_name as doc_first, s.last_name as doc_last
    FROM appointments a
    LEFT JOIN staff s ON a.doctor_id = s.id
    WHERE a.patient_id = $1 
    ORDER BY appointment_time DESC
", [$patient_id]);

$prescriptions = db_select("
    SELECT pr.*, s.first_name as doc_first, s.last_name as doc_last
    FROM prescriptions pr
    LEFT JOIN staff s ON pr.doctor_id = s.id
    WHERE pr.patient_id = $1
    ORDER BY created_at DESC
", [$patient_id]);

// Use new admissions table
$admissions = db_select("
    SELECT adm.*, r.room_number, r.ward
    FROM admissions adm
    LEFT JOIN rooms r ON adm.room_id = r.id
    WHERE adm.patient_id = $1
    ORDER BY adm.admission_date DESC
", [$patient_id]);

// Fetch Lab Results
$lab_results = db_select("
    SELECT l.*, s.first_name as doc_first, s.last_name as doc_last 
    FROM laboratory_tests l 
    LEFT JOIN staff s ON l.doctor_id = s.id 
    WHERE l.patient_id = $1 
    ORDER BY l.created_at DESC
", [$patient_id]);

// Calculate Vitals (Mock or from visits if table exists, using a placeholder for now or parsing medical_history if structured)
// For now, we will display the raw text history prominent, but adding tabs for structured data.
?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --surface-color: rgba(255, 255, 255, 0.9);
        --bg-color: #f4f6fc;
        --border-radius-lg: 24px;
        --border-radius-md: 16px;
        --text-primary: #1e293b;
        --text-secondary: #64748b;
    }
    
    body {
        margin: 0;
        padding-top: 60px; /* If header overlaps */
        background: var(--bg-color);
        font-family: 'Inter', -apple-system, sans-serif;
    }
    
    .patient-header {
        background: var(--primary-gradient);
        color: white;
        padding: 40px;
        border-radius: var(--border-radius-lg);
        margin-bottom: 40px;
        box-shadow: 0 20px 40px rgba(118, 75, 162, 0.15);
        position: relative;
        overflow: hidden;
    }
    .patient-header::before {
        content: '';
        position: absolute;
        top: -50%; left: -50%;
        width: 200%; height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
        pointer-events: none;
    }
    .profile-stat {
        background: rgba(255,255,255,0.15);
        padding: 8px 16px;
        border-radius: 30px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        display: inline-flex;
        align-items: center;
        margin-right: 12px;
        margin-top: 15px;
        font-size: 0.9em;
        font-weight: 500;
        letter-spacing: 0.3px;
        transition: 0.3s;
    }
    .profile-stat i { margin-right: 8px; color: rgba(255,255,255,0.9); }
    .profile-stat:hover { background: rgba(255,255,255,0.25); transform: translateY(-2px); }

    .nav-tabs {
        display: flex;
        flex-direction: row;
        flex-wrap: wrap;
        list-style: none !important;
        padding-left: 0 !important;
        border-bottom: none;
        gap: 10px;
        margin-bottom: 25px;
    }
    .nav-item {
        margin-bottom: 0;
    }
    .nav-tabs .nav-link {
        display: inline-block;
        border: none;
        border-radius: 30px;
        font-weight: 600;
        color: var(--text-secondary);
        padding: 12px 24px;
        background: transparent;
        transition: 0.3s;
        text-decoration: none !important;
    }
    .nav-tabs .nav-link:hover {
        background: rgba(0,0,0,0.03);
        color: var(--text-primary);
    }
    .nav-tabs .nav-link.active {
        background: var(--primary-gradient);
        color: white;
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    }
    .tab-content {
        background: var(--surface-color);
        padding: 35px;
        border: 1px solid rgba(255,255,255,0.8);
        border-radius: var(--border-radius-lg);
        box-shadow: 0 15px 35px rgba(0,0,0,0.03);
        backdrop-filter: blur(20px);
    }
    
    /* Timeline Redesign */
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    .timeline::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, #dbeafe, #f1f5f9);
    }
    .timeline-item {
        position: relative;
        margin-bottom: 40px;
        background: white;
        padding: 25px;
        border-radius: var(--border-radius-md);
        box-shadow: 0 5px 20px rgba(0,0,0,0.03);
        border: 1px solid #f1f5f9;
        transition: 0.3s;
    }
    .timeline-item:hover {
        transform: translateX(5px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.06);
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -35px;
        top: 30px;
        width: 12px;
        height: 12px;
        background: #3b82f6;
        border-radius: 50%;
        box-shadow: 0 0 0 4px #eff6ff, 0 0 0 8px white;
    }
    .visit-date {
        color: #3b82f6;
        font-weight: 700;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .visit-doctor {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 1.25em;
        margin-bottom: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .visit-badge {
        font-size: 0.6em;
        padding: 5px 10px;
        border-radius: 20px;
        background: #e0e7ff;
        color: #4338ca;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .visit-section {
        background: #f8fafc;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 15px;
        border: 1px solid #e2e8f0;
    }
    .visit-section h5 {
        font-size: 0.85em;
        text-transform: uppercase;
        color: var(--text-secondary);
        font-weight: 700;
        letter-spacing: 0.5px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .visit-section p {
        margin: 0;
        color: #334155;
        font-size: 0.95em;
        line-height: 1.6;
        white-space: pre-wrap;
    }
    
    .vitals-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 10px;
    }
    .vital-box {
        background: white;
        padding: 10px 15px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        text-align: center;
    }
    .vital-box small {
        color: var(--text-secondary);
        font-size: 0.75em;
        font-weight: 600;
        display: block;
        margin-bottom: 2px;
        text-transform: uppercase;
    }
    .vital-box strong {
        color: var(--text-primary);
        font-size: 1.1em;
        font-family: monospace;
    }
    
    /* Global specific UI tweaks */
    .table-premium {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-premium th {
        background: #f1f5f9;
        color: var(--text-secondary);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8em;
        letter-spacing: 0.5px;
        padding: 15px;
        border-bottom: 2px solid #e2e8f0;
    }
    .table-premium td {
        padding: 18px 15px;
        background: white;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-weight: 500;
    }
    .table-premium tr:hover td { background: #f8fafc; }
</style>

<div class="patient-header">
    <div class="d-flex align-items-center">
        <div style="margin-right: 30px;">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($patient['first_name'].' '.$patient['last_name']); ?>&background=ffffff&color=667eea&size=100" style="border-radius: 50%; border: 4px solid rgba(255,255,255,0.3); box-shadow: 0 10px 20px rgba(0,0,0,0.1);">
        </div>
        <div>
            <h1 style="margin: 0; font-weight: 800; font-size: 2.5em; letter-spacing: -0.5px;"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
            <div style="opacity: 0.95;">
                <span class="profile-stat"><i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($patient['gender']); ?></span>
                <span class="profile-stat"><i class="fas fa-birthday-cake"></i> <?php echo htmlspecialchars($patient['date_of_birth']); ?> &bull; Age: <?php echo date_diff(date_create($patient['date_of_birth']), date_create('today'))->y; ?></span>
                <span class="profile-stat"><i class="fas fa-tint"></i> <?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></span>
                <span class="profile-stat"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($patient['phone'] ?? 'N/A'); ?></span>
                <span class="profile-stat"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($patient['email'] ?? 'N/A'); ?></span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left Column: Quick Vitals / Summary -->
    <div class="col-md-3">
        <div class="tab-content" style="padding: 25px; margin-bottom: 30px;">
            <h4 style="font-size: 1.1em; font-weight: 800; color: var(--text-primary); margin-bottom: 20px; text-transform: uppercase;"><i class="fas fa-heartbeat" style="color: #ef4444; margin-right: 8px;"></i> Quick Summary</h4>
            
            <div style="margin-bottom: 20px;">
                <p style="margin: 0; font-size: 0.8em; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Overall Status</p>
                <div style="background: #ecfdf5; color: #059669; padding: 10px 15px; border-radius: 10px; font-weight: 700; margin-top: 5px; border: 1px solid #a7f3d0; display: inline-block;">
                    Stable
                </div>
            </div>
            
            <div style="background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px dashed #cbd5e1;">
                <p style="margin: 0; font-size: 0.8em; color: var(--text-secondary); text-transform: uppercase; font-weight: 600; margin-bottom: 8px;">Chronic Conditions</p>
                <p style="margin: 0; font-size: 0.9em; color: #334155; font-weight: 500;">
                    <?php echo htmlspecialchars($patient['medical_history'] ?: 'None recorded on file.'); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Right Column: Full Detail Tabs -->
    <div class="col-md-9">
        <ul class="nav nav-tabs" id="historyTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="viz-tab" data-toggle="tab" href="#viz" role="tab"><i class="fas fa-stream"></i> Visit Timeline</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="rx-tab" data-toggle="tab" href="#rx" role="tab"><i class="fas fa-pills"></i> Prescriptions</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="lab-tab" data-toggle="tab" href="#lab" role="tab"><i class="fas fa-flask"></i> Lab Reports</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="adm-tab" data-toggle="tab" href="#adm" role="tab"><i class="fas fa-bed"></i> Admissions</a>
            </li>
        </ul>
        
        <div class="tab-content" id="historyTabsContent">
            <!-- Visits / Timeline -->
            <div class="tab-pane fade show active" id="viz" role="tabpanel">
                <h3 style="font-weight: 800; color: var(--text-primary); margin-bottom: 30px;">Complete Visit History</h3>
                <div class="timeline">
                    <?php if (empty($appointments)): ?>
                        <div style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="far fa-calendar-times" style="font-size: 4em; margin-bottom: 15px; opacity: 0.5;"></i>
                            <h4>No visits recorded yet.</h4>
                        </div>
                    <?php else: ?>
                        <?php foreach ($appointments as $ppt): ?>
                            <div class="timeline-item">
                                <div class="visit-date"><i class="far fa-clock"></i> <?php echo date('l, M d, Y - h:i A', strtotime($ppt['appointment_time'])); ?></div>
                                <div class="visit-doctor">
                                    <span>Consultation with Dr. <?php echo htmlspecialchars($ppt['doc_first'] . ' ' . $ppt['doc_last']); ?></span>
                                    <span class="visit-badge status-<?php echo strtolower($ppt['status']); ?>"><?php echo htmlspecialchars($ppt['status']); ?></span>
                                </div>
                                
                                <?php 
                                // Fetch vitals specifically for this appointment
                                $vitals = db_select("SELECT metric_type, metric_value FROM patient_health_metrics WHERE appointment_id = $1", [$ppt['id']]);
                                if (!empty($vitals)): 
                                ?>
                                <div class="visit-section">
                                    <h5><i class="fas fa-heartbeat"></i> Vitals Recorded</h5>
                                    <div class="vitals-grid">
                                        <?php foreach($vitals as $v): 
                                            // Handling JSON value like {"value": "120/80", "unit": "mmHg"}
                                            $v_data = json_decode($v['metric_value'], true);
                                            $val = $v_data['value'] ?? $v['metric_value'];
                                            $unit = $v_data['unit'] ?? '';
                                        ?>
                                        <div class="vital-box">
                                            <small><?php echo ucwords(str_replace('_', ' ', $v['metric_type'])); ?></small>
                                            <strong><?php echo htmlspecialchars($val); ?> <span style="font-size: 0.7em; color: #94a3b8;"><?php echo htmlspecialchars($unit); ?></span></strong>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($ppt['reason'])): ?>
                                <div class="visit-section">
                                    <h5><i class="fas fa-stethoscope"></i> Clinical Notes & Reason</h5>
                                    <p><?php echo htmlspecialchars($ppt['reason']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <div style="display: flex; gap: 10px; margin-top: 20px;">
                                    <a href="#rx" onclick="document.getElementById('rx-tab').click();" class="btn btn-sm btn-outline-primary" style="font-weight: 600; border-radius: 8px;"><i class="fas fa-file-prescription"></i> Check Prescriptions</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Prescriptions -->
            <div class="tab-pane fade" id="rx" role="tabpanel">
                <h3 style="font-weight: 800; color: var(--text-primary); margin-bottom: 30px;">Prescriptions</h3>
                <div class="table-responsive" style="border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
                    <table class="table-premium">
                        <thead>
                            <tr>
                                <th>Date Issued</th>
                                <th>Prescribing Doctor</th>
                                <th>Medications</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prescriptions as $rx): ?>
                            <tr>
                                <td><i class="far fa-calendar-alt text-primary"></i> <?php echo date('M d, Y', strtotime($rx['created_at'])); ?></td>
                                <td>Dr. <?php echo htmlspecialchars($rx['doc_last']); ?></td>
                                <td>
                                    <?php 
                                        $med_data = $rx['medication_details'] ?? '';
                                        $meds = json_decode($med_data, true);
                                        if (is_array($meds)) {
                                            foreach ($meds as $m) echo "<span class='badge badge-primary' style='background: #e0e7ff; color: #4338ca; padding: 6px 10px; border-radius: 6px; margin-right: 5px; margin-bottom: 5px; display: inline-block;'><i class='fas fa-capsules'></i> " . htmlspecialchars($m['name'] ?? 'Unknown') . "</span>";
                                        } elseif (!empty($med_data)) {
                                            echo htmlspecialchars(substr($med_data, 0, 50)) . (strlen($med_data) > 50 ? '...' : '');
                                        } else {
                                            echo "<span class='text-muted'>No data</span>";
                                        }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($prescriptions)): ?><tr><td colspan="3" style="text-align:center; padding: 30px;"><i class="fas fa-prescription-bottle-alt text-muted fa-2x"></i> <br><br>No prescriptions found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Lab Results -->
            <div class="tab-pane fade" id="lab" role="tabpanel">
                <h3 style="font-weight: 800; color: var(--text-primary); margin-bottom: 30px;">Laboratory Reports</h3>
                <div class="table-responsive" style="border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
                    <table class="table-premium">
                        <thead>
                            <tr>
                                <th>Report Date</th>
                                <th>Test Type</th>
                                <th>Ordered By</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lab_results as $lab): ?>
                            <tr>
                                <td><i class="far fa-calendar-check text-primary"></i> <?php echo date('M d, Y', strtotime($lab['created_at'])); ?></td>
                                <td style="font-weight: 700; color: #3b82f6;"><?php echo htmlspecialchars($lab['test_type']); ?></td>
                                <td>Dr. <?php echo htmlspecialchars($lab['doc_last']); ?></td>
                                <td>
                                    <?php if ($lab['status'] === 'completed'): ?>
                                        <span style="background: #ecfdf5; color: #059669; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8em; text-transform: uppercase;"><i class="fas fa-check-circle"></i> Completed</span>
                                    <?php else: ?>
                                        <span style="background: #fffbeb; color: #d97706; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8em; text-transform: uppercase;"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($lab['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../../modules/lab/results.php?id=<?php echo $lab['id']; ?>" class="btn btn-sm btn-primary" style="font-weight: 600; border-radius: 8px;">View Detailed Report</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($lab_results)): ?><tr><td colspan="5" style="text-align:center; padding: 30px;"><i class="fas fa-microscope text-muted fa-2x"></i> <br><br>No lab results found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Admissions -->
            <div class="tab-pane fade" id="adm" role="tabpanel">
                <h3 style="font-weight: 800; color: var(--text-primary); margin-bottom: 30px;">Inpatient Admissions</h3>
                 <div class="table-responsive" style="border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
                    <table class="table-premium">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Location (Ward)</th>
                                <th>Diagnosis</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admissions as $adm): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700;"><i class="fas fa-sign-in-alt text-success"></i> <?php echo date('M d, Y', strtotime($adm['admission_date'])); ?></div>
                                    <div style="font-size: 0.9em; color: var(--text-secondary); margin-top: 4px;"><i class="fas fa-sign-out-alt text-danger"></i> <?php echo $adm['discharge_date'] ? date('M d, Y', strtotime($adm['discharge_date'])) : 'Present'; ?></div>
                                </td>
                                <td><span style="font-family: monospace; font-size: 1.1em; background: #f1f5f9; padding: 4px 8px; border-radius: 6px;">Room <?php echo htmlspecialchars($adm['room_number']); ?></span><br><small class="text-muted"><?php echo htmlspecialchars($adm['ward']); ?></small></td>
                                <td style="font-weight: 500; font-style: italic;"><?php echo htmlspecialchars($adm['diagnosis']); ?></td>
                                <td>
                                    <?php if ($adm['status'] === 'admitted'): ?>
                                        <span style="background: #eff6ff; color: #2563eb; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8em; text-transform: uppercase;"><i class="fas fa-procedures"></i> Admitted</span>
                                    <?php else: ?>
                                        <span style="background: #f8fafc; color: #64748b; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8em; text-transform: uppercase;"><i class="fas fa-home"></i> Discharged</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($admissions)): ?><tr><td colspan="4" style="text-align:center; padding: 30px;"><i class="fas fa-hospital-user text-muted fa-2x"></i> <br><br>No admissions found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // Tab persist via hash and activation
    $(document).ready(function(){
        if(window.location.hash) {
            $('a[href="'+window.location.hash+'"]').tab('show');
        }
        $('a[data-toggle="tab"]').on('click', function(e) {
            e.preventDefault();
            $(this).tab('show');
            history.pushState(null, null, $(this).attr('href'));
        });
    });
</script>

<?php include '../../includes/footer.php'; ?>
