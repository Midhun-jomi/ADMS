<?php
// modules/patient_management/discharge.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor']);

$admission_id = $_GET['admission_id'] ?? null;
if (!$admission_id) {
    die("Invalid Admission ID");
}

// Fetch Admission Details
$sql = "SELECT a.*, p.first_name, p.last_name, r.room_number 
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id
        JOIN rooms r ON a.room_id = r.id
        WHERE a.id = $1";
$admission = db_select_one($sql, [$admission_id]);

if (!$admission) {
    die("Admission not found.");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $summary = $_POST['summary'];
    $instructions = $_POST['instructions'];
    
    try {
        db_query("BEGIN");
        
        // 1. Create Discharge Summary
        $sql = "INSERT INTO discharge_summaries (admission_id, patient_id, summary_text, follow_up_instructions, generated_by) 
                VALUES ($1, $2, $3, $4, $5)";
        db_query($sql, [$admission_id, $admission['patient_id'], $summary, $instructions, $_SESSION['user_id']]);
        
        // 2. Update Admission Status
        db_query("UPDATE admissions SET status = 'discharged', discharge_date = CURRENT_TIMESTAMP WHERE id = $1", [$admission_id]);
        
        // 3. Free up the Room
        db_query("UPDATE rooms SET status = 'available' WHERE id = $1", [$admission['room_id']]);
        
        db_query("COMMIT");
        $success = "Patient discharged successfully.";
    } catch (Exception $e) {
        db_query("ROLLBACK");
        $error = "Error: " . $e->getMessage();
    }
}

$page_title = "Discharge Patient";
include '../../includes/header.php';
?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        Discharge Summary: <?php echo htmlspecialchars($admission['first_name'] . ' ' . $admission['last_name']); ?>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
            <p><a href="nursing_station.php">Back to Nursing Station</a></p>
        </div>
    <?php else: ?>
    
    <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px;">
        <p><strong>Room:</strong> <?php echo htmlspecialchars($admission['room_number']); ?></p>
        <p><strong>Admitted On:</strong> <?php echo date('M d, Y H:i', strtotime($admission['admission_date'])); ?></p>
        <p><strong>Initial Diagnosis:</strong> <?php echo htmlspecialchars($admission['diagnosis']); ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label>Clinical Summary & Treatment Given</label>
            <textarea name="summary" class="form-control" rows="6" required placeholder="Detailed summary of hospital stay..."></textarea>
        </div>

        <div class="form-group">
            <label>Follow-up Instructions & Medications</label>
            <textarea name="instructions" class="form-control" rows="4" required placeholder="Medications to take at home, next visit date..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary" style="background-color: var(--danger-color);">
            Confirm Discharge & Release Room
        </button>
        <a href="nursing_station.php" class="btn" style="background: #ccc; margin-left: 10px;">Cancel</a>
    </form>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
