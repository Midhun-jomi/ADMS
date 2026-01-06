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
    $patient_id = $_POST['patient_id'];
    $medication_details = $_POST['medication_details']; // JSON string or array
    
    // Simplified: We assume medication_details is a JSON string of [{name, qty, dosage}]
    // For this demo, we'll just take a text input and wrap it
    $meds = [
        ['name' => $_POST['med_name'], 'quantity' => $_POST['med_qty'], 'dosage' => $_POST['med_dosage']]
    ];
    
    $data = [
        'patient_id' => $patient_id,
        'doctor_id' => get_user_id(), // This should be staff ID, but for simplicity using user_id or need lookup
        // Fix: Lookup staff ID
        'medication_details' => json_encode($meds)
    ];
    
    // Need appointment_id? Schema says yes. Let's make it optional or nullable in schema? 
    // Schema: appointment_id UUID REFERENCES appointments(id) ON DELETE CASCADE
    // It's a foreign key. We need an appointment. 
    // For now, let's assume we are coming from an appointment context or pass it in hidden field.
    // If standalone prescription, we might need to adjust schema or create a dummy appointment.
    // Let's assume passed in URL or POST.
    
    $appointment_id = $_POST['appointment_id'] ?? null;
    
    // Get staff ID
    $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [get_user_id()]);
    $data['doctor_id'] = $staff['id'];
    
    // Optional Appointment ID
    if ($appointment_id) {
        $data['appointment_id'] = $appointment_id;
    }
    
    db_insert('prescriptions', $data);
    $success = "Prescription created.";
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
    ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
            <h5>Prescribe Medication</h5>
            <form method="POST" action="" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 200px;">
                    <label>Patient</label>
                    <select name="patient_id" class="form-control" required>
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
                <input type="hidden" name="appointment_id" value="<?php echo $_GET['appointment_id'] ?? ''; ?>">
                
                <button type="submit" class="btn btn-primary">Prescribe</button>
            </form>
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse;">
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
                    <td style="padding: 10px;">
                        <?php 
                            $meds = json_decode($rx['medication_details'], true);
                            foreach ($meds as $m) {
                                echo htmlspecialchars($m['name'] . ' (' . $m['quantity'] . ') - ' . $m['dosage']) . "<br>";
                            }
                        ?>
                    </td>
                    <td style="padding: 10px;">
                    <td style="padding: 10px;">
                        <?php if ($role !== 'doctor'): ?>
                            <form method="POST" action="process_dispense.php" onsubmit="return confirm('Dispense medications and generate bill?');">
                                <input type="hidden" name="prescription_id" value="<?php echo $rx['id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background: #28a745; color: white;">Dispense</button>
                            </form>
                        <?php else: ?>
                            <span style="color: #6c757d; font-size: 0.9em;">Pending Dispense</span>
                        <?php endif; ?>
                    </td>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
