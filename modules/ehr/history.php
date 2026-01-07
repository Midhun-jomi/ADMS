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
    $patient_id = $p['id'];
} elseif (($role === 'doctor' || $role === 'admin' || $role === 'nurse') && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
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
    .patient-header {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 10px 25px rgba(0, 242, 254, 0.2);
    }
    .profile-stat {
        background: rgba(255,255,255,0.2);
        padding: 10px 15px;
        border-radius: 10px;
        backdrop-filter: blur(5px);
        display: inline-block;
        margin-right: 15px;
        margin-top: 10px;
        font-size: 0.9em;
    }
    .nav-tabs .nav-link {
        border-radius: 10px 10px 0 0;
        font-weight: 600;
        color: #555;
    }
    .nav-tabs .nav-link.active {
        background-color: #fff;
        border-bottom-color: #fff;
        color: #007bff;
    }
    .tab-content {
        background: white;
        padding: 25px;
        border: 1px solid #dee2e6;
        border-top: none;
        border-radius: 0 0 15px 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }
    .timeline {
        border-left: 3px solid #e9ecef;
        padding-left: 20px;
        margin-left: 10px;
    }
    .timeline-item {
        margin-bottom: 25px;
        position: relative;
    }
    .timeline-item::before {
        content: '';
        position: absolute;
        left: -28px;
        top: 5px;
        width: 14px;
        height: 14px;
        background: #007bff;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 0 0 2px #e9ecef;
    }
</style>

<div class="patient-header">
    <div class="d-flex align-items-center">
        <div style="font-size: 4em; margin-right: 25px;">
            <i class="fas fa-user-circle"></i>
        </div>
        <div>
            <h1 style="margin: 0; font-weight: 800;"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
            <div style="opacity: 0.9;">
                <span class="profile-stat"><i class="fas fa-venus-mars"></i> <?php echo htmlspecialchars($patient['gender']); ?></span>
                <span class="profile-stat"><i class="fas fa-birthday-cake"></i> <?php echo htmlspecialchars($patient['date_of_birth']); ?> (Age: <?php echo date_diff(date_create($patient['date_of_birth']), date_create('today'))->y; ?>)</span>
                <span class="profile-stat"><i class="fas fa-tint"></i> <?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A'); ?></span>
                <span class="profile-stat"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?></span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="card mb-4">
            <div class="card-header">
                <strong>Quick Vitals</strong>
            </div>
            <div class="card-body">
                <!-- Mock Data for Visuals -->
                <p class="mb-1 text-muted">Blood Pressure</p>
                <h4>120/80 <small class="text-success">Normal</small></h4>
                <hr>
                <p class="mb-1 text-muted">Heart Rate</p>
                <h4>72 <small class="text-muted">bpm</small></h4>
                <hr>
                <p class="mb-1 text-muted">Weight</p>
                <h4>- <small class="text-muted">kg</small></h4>
            </div>
        </div>
        
        <?php if ($role === 'doctor' || $role === 'admin'): ?>
        <div class="card">
            <div class="card-header bg-primary text-white">
                Actions
            </div>
            <div class="list-group list-group-flush">
                <a href="../prescriptions/create.php?patient_id=<?php echo $patient['id']; ?>" class="list-group-item list-group-item-action"><i class="fas fa-prescription"></i> Write Prescription</a>
                <a href="../appointments/book_appointment.php?patient_id=<?php echo $patient['id']; ?>" class="list-group-item list-group-item-action"><i class="fas fa-calendar-plus"></i> Book Follow-up</a>
                <a href="../patient_management/admit_patient.php?patient_id=<?php echo $patient['id']; ?>" class="list-group-item list-group-item-action"><i class="fas fa-procedures"></i> Admit Patient</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-9">
        <ul class="nav nav-tabs" id="historyTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="viz-tab" data-toggle="tab" href="#viz" role="tab">Timeline & Visits</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="notes-tab" data-toggle="tab" href="#notes" role="tab">Clinical Notes</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="rx-tab" data-toggle="tab" href="#rx" role="tab">Prescriptions</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="adm-tab" data-toggle="tab" href="#adm" role="tab">Admissions</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="lab-tab" data-toggle="tab" href="#lab" role="tab">Lab Results</a>
            </li>
        </ul>
        
        <div class="tab-content" id="historyTabsContent">
            <!-- Visits / Timeline -->
            <div class="tab-pane fade show active" id="viz" role="tabpanel">
                <h4 class="mb-4">Visit Timeline</h4>
                <div class="timeline">
                    <?php if (empty($appointments)): ?>
                        <p class="text-muted">No appointments recorded.</p>
                    <?php else: ?>
                        <?php foreach ($appointments as $ppt): ?>
                            <div class="timeline-item">
                                <span class="d-block text-muted small"><?php echo date('M d, Y h:i A', strtotime($ppt['appointment_time'])); ?></span>
                                <strong class="d-block" style="font-size: 1.1em;"><?php echo ucfirst($ppt['status']); ?> Appointment</strong>
                                <span class="text-primary">Dr. <?php echo htmlspecialchars($ppt['doc_first'] . ' ' . $ppt['doc_last']); ?></span>
                                <p class="mt-2 text-muted"><?php echo htmlspecialchars($ppt['reason_for_visit'] ?? 'Routine checkup'); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Notes (Editable by Doctor) -->
            <div class="tab-pane fade" id="notes" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4>Clinical Notes</h4>
                    <?php if ($role !== 'patient'): ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="alert('Feature coming soon: Detailed Note Editor')">Edit History</button>
                    <?php endif; ?>
                </div>
                <div style="white-space: pre-wrap; font-family: 'Courier New', monospace; background: #f8f9fa; padding: 20px; border-radius: 5px;">
                    <?php echo htmlspecialchars($patient['medical_history'] ?: 'No detailed history recorded.'); ?>
                </div>
                
                <?php if ($role === 'doctor'): ?>
                <hr>
                <form method="POST" action="">
                    <label><strong>Quick Add Note:</strong></label>
                    <textarea name="medical_history" class="form-control" rows="3" placeholder="Append new note..."></textarea>
                    <button type="submit" class="btn btn-primary mt-2 btn-sm">Save Note</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Prescriptions -->
            <div class="tab-pane fade" id="rx" role="tabpanel">
                <h4>Prescription History</h4>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Doctor</th>
                                <th>Medications</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prescriptions as $rx): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($rx['created_at'])); ?></td>
                                <td>Dr. <?php echo htmlspecialchars($rx['doc_last']); ?></td>
                                <td>
                                    <?php 
                                        // Assumption: medications is JSON or Text
                                        // If JSON
                                        $meds = json_decode($rx['medications'], true);
                                        if (is_array($meds)) {
                                            foreach ($meds as $m) echo "<span class='badge badge-info mr-1'>" . htmlspecialchars($m['name']) . "</span>";
                                        } else {
                                            echo htmlspecialchars(substr($rx['medications'], 0, 50)) . '...';
                                        }
                                    ?>
                                </td>
                                <td>Active</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($prescriptions)): ?><tr><td colspan="4">No prescriptions found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Admissions -->
            <div class="tab-pane fade" id="adm" role="tabpanel">
                <h4>Inpatient History</h4>
                 <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Admitted</th>
                                <th>Discharged</th>
                                <th>Room/Ward</th>
                                <th>Diagnosis</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admissions as $adm): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($adm['admission_date'])); ?></td>
                                <td><?php echo $adm['discharge_date'] ? date('M d, Y', strtotime($adm['discharge_date'])) : '-'; ?></td>
                                <td><?php echo htmlspecialchars($adm['room_number'] . ' (' . $adm['ward'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($adm['diagnosis']); ?></td>
                                <td>
                                    <?php if ($adm['status'] === 'admitted'): ?>
                                        <span class="badge badge-warning">Admitted</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Discharged</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($admissions)): ?><tr><td colspan="5">No admissions found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Lab Results -->
            <div class="tab-pane fade" id="lab" role="tabpanel">
                <h4>Laboratory Results</h4>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Test Type</th>
                                <th>Ordered By</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lab_results as $lab): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($lab['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($lab['test_type']); ?></td>
                                <td>Dr. <?php echo htmlspecialchars($lab['doc_last']); ?></td>
                                <td>
                                    <?php if ($lab['status'] === 'completed'): ?>
                                        <span class="badge badge-success">Completed</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><?php echo ucfirst($lab['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../../modules/lab/results.php?id=<?php echo $lab['id']; ?>" class="btn btn-sm btn-primary">View Results</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($lab_results)): ?><tr><td colspan="5">No lab results found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Auto-select tab based on hash
    document.addEventListener("DOMContentLoaded", function() {
        if (window.location.hash) {
            var triggerEl = document.querySelector('a[href="' + window.location.hash + '"]');
            if (triggerEl) {
                // Bootstrap 4 Tab show
                $(triggerEl).tab('show');
            }
        }
    });
</script>

<?php include '../../includes/footer.php'; ?>
