<?php
// modules/pharmacy/dispense.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['pharmacist', 'admin', 'doctor']); // Doctors can view/prescribe

$role = get_user_role();
$page_title = "Pharmacy Dispensing";
include '../../includes/header.php';

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

// Handle Prescription (Doctor)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
    $patient_id = $_POST['patient_id'];
    $meds = [
        ['name' => $_POST['med_name'], 'quantity' => (int)$_POST['med_qty'], 'dosage' => $_POST['med_dosage']]
    ];

    $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [get_user_id()]);
    $data = [
        'patient_id' => $patient_id,
        'doctor_id' => $staff['id'],
        'medication_details' => json_encode($meds)
    ];

    $appointment_id = $_POST['appointment_id'] ?? null;
    if ($appointment_id) {
        $data['appointment_id'] = $appointment_id;
    }

    db_insert('prescriptions', $data);
    $success = "Prescription created.";
    } // end CSRF check
}

// Fetch Prescriptions
$prescriptions = db_select("SELECT pr.*, p.first_name, p.last_name 
                            FROM prescriptions pr 
                            JOIN patients p ON pr.patient_id = p.id 
                            ORDER BY pr.created_at DESC");

?>

<div class="card">
    <div class="card-header">Active Prescriptions</div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($role === 'doctor'): 
        // Fetch patients for dropdown
        $patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name");
        // Fetch medications for dropdown
        $medications = db_select("SELECT medication_name, quantity FROM pharmacy_inventory ORDER BY medication_name");
        $pre_patient_id = $_GET['patient_id'] ?? '';
        
        // Fetch latest triage result for this patient
        $triage_info = null;
        if ($pre_patient_id) {
            $triage_info = db_select_one("SELECT * FROM triage_analysis WHERE patient_id = $1 ORDER BY id DESC LIMIT 1", [$pre_patient_id]);
        }
    ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
            <?php if ($triage_info): ?>
                <div class="alert alert-info" style="border-left: 5px solid #17a2b8;">
                    <h6 style="color: #0c5460; font-weight: bold;"><i class="fas fa-user-md"></i> AI Triage Insights</h6>
                    <div style="font-size: 0.95em; line-height: 1.5;">
                        <?php echo nl2br(htmlspecialchars($triage_info['ai_findings'])); ?>
                    </div>
                    <div style="margin-top: 5px; font-weight: bold; color: #555;">
                        Severity Score: <?php echo $triage_info['severity_score']; ?>/10
                    </div>
                    <small class="text-muted">Analysis Date: <?php echo date('M d, Y H:i', strtotime($triage_info['created_at'] ?? 'now')); ?></small>
                </div>
            <?php elseif ($pre_patient_id): ?>
                <div class="alert alert-secondary">No AI triage data found for this patient.</div>
            <?php endif; ?>

            <h5>Prescribe Medication</h5>
            <form method="POST" action="" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <?php echo csrf_input(); ?>
                <div style="flex: 1; min-width: 200px;">
                    <label>Patient</label>
                    <select name="patient_id" class="form-control" required onchange="window.location.search = '?patient_id=' + this.value">
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($p['id'] == $pre_patient_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label>Medication Name</label>
                    <select name="med_name" class="form-control" required>
                        <option value="">-- Select Med --</option>
                        <?php foreach ($medications as $m): ?>
                            <option value="<?php echo htmlspecialchars($m['medication_name']); ?>">
                                <?php echo htmlspecialchars($m['medication_name']); ?> (Stock: <?php echo $m['quantity']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 100px;">
                    <label>Quantity</label>
                    <input type="number" name="med_qty" class="form-control" required>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label>Dosage</label>
                    <input type="text" name="med_dosage" class="form-control" placeholder="e.g. 1-0-1" required>
                </div>
                <!-- Hidden Appointment ID if available -->
                <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($_GET['appointment_id'] ?? ''); ?>">
                
                <button type="submit" class="btn btn-primary">Prescribe</button>
            </form>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 14px;">
        <input type="text" id="filter-dispense" onkeyup="filterTable('filter-dispense','tbl-dispense')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
    </div>
    <table id="tbl-dispense" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Date</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Patient</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Medication Details</th>
                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($prescriptions as $rx): ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($rx['created_at'])); ?></td>
                    <td style="padding: 10px;"><?php echo htmlspecialchars($rx['first_name'] . ' ' . $rx['last_name']); ?></td>
                    <td style="padding: 10px; word-break: break-word; max-width: 400px;">
                        <?php 
                            $meds = json_decode($rx['medication_details'], true);
                            if (is_array($meds)) {
                                foreach ($meds as $m) {
                                    echo htmlspecialchars($m['name'] . ' (' . $m['quantity'] . ') - ' . $m['dosage']) . "<br>";
                                }
                            }
                        ?>
                    </td>
                    <td style="padding: 10px; white-space: nowrap; width: 1%; text-align: center;">
                        <?php if ($role !== 'doctor'): ?>
                            <form method="POST" action="process_dispense.php" onsubmit="return confirm('Dispense medications and generate bill?');">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="prescription_id" value="<?php echo $rx['id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background: #28a745; color: white;">Dispense</button>
                            </form>
                        <?php else: ?>
                            <span style="color: #6c757d; font-size: 0.9em;">Pending Dispense</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
