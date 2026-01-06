<?php
// modules/patient_management/admit_patient.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse']);

$error = '';
$success = '';

// Handle Admission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = $_POST['patient_id'];
    $room_id = $_POST['room_id'];
    $diagnosis = $_POST['diagnosis'];

    if ($patient_id && $room_id) {
        try {
            // Start Transaction
            db_query("BEGIN");

            // Create Admission Record
            $sql = "INSERT INTO admissions (patient_id, room_id, diagnosis) VALUES ($1, $2, $3)";
            db_query($sql, [$patient_id, $room_id, $diagnosis]);

            // Update Room Status
            db_query("UPDATE rooms SET status = 'occupied' WHERE id = $1", [$room_id]);

            db_query("COMMIT");
            $success = "Patient admitted successfully.";
        } catch (Exception $e) {
            db_query("ROLLBACK");
            $error = "Error admitting patient: " . $e->getMessage();
        }
    } else {
        $error = "Please select both patient and room.";
    }
}

$page_title = "Admit Patient";
include '../../includes/header.php';

// Fetch Patients
$patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name");

// Fetch Available Rooms
$rooms = db_select("SELECT id, room_number, room_type FROM rooms WHERE status = 'available' ORDER BY room_number");
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div class="card-header">Admit New Patient</div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
            <p><a href="manage_beds.php">Back to Bed Management</a></p>
        </div>
    <?php else: ?>

    <form method="POST" action="">
        <div class="form-group">
            <label>Select Patient</label>
            <select name="patient_id" class="form-control" required>
                <option value="">-- Choose Patient --</option>
                <?php foreach ($patients as $p): ?>
                    <option value="<?php echo $p['id']; ?>">
                        <?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Select Room</label>
            <select name="room_id" class="form-control" required>
                <option value="">-- Choose Available Room --</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?php echo $r['id']; ?>">
                        <?php echo htmlspecialchars($r['room_number'] . ' (' . $r['room_type'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Initial Diagnosis / Reason for Admission</label>
            <textarea name="diagnosis" class="form-control" rows="4" required></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Admit Patient</button>
        <a href="manage_beds.php" class="btn" style="background: #ccc; margin-left: 10px;">Cancel</a>
    </form>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
