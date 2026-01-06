<?php
// modules/ehr/history.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();

$page_title = "Medical History";
include '../../includes/header.php';

$patient_id = null;

// If patient, get own ID. If doctor, get from query param
if ($role === 'patient') {
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    $patient_id = $patient['id'];
} elseif ($role === 'doctor' && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
}

if (!$patient_id) {
    echo "<div class='alert alert-danger'>Patient not specified.</div>";
    include '../../includes/footer.php';
    exit();
}

// Fetch patient details
$patient_info = db_select_one("SELECT * FROM patients WHERE id = $1", [$patient_id]);

// Handle form submission (Doctor only)
if ($_SERVER["REQUEST_METHOD"] == "POST" && $role === 'doctor') {
    $new_history = $_POST['medical_history'];
    // Append to existing history with timestamp
    $updated_history = $patient_info['medical_history'] . "\n\n[" . date('Y-m-d H:i') . "] " . $new_history;
    
    db_update('patients', ['medical_history' => $updated_history], ['id' => $patient_id]);
    // Refresh info
    $patient_info = db_select_one("SELECT * FROM patients WHERE id = $1", [$patient_id]);
    echo "<div class='alert alert-success'>Medical history updated.</div>";
}
?>

<div class="card">
    <div class="card-header">
        Medical Profile: <?php echo htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']); ?>
    </div>
    
    <div class="form-row" style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 1;">
            <strong>DOB:</strong> <?php echo htmlspecialchars($patient_info['date_of_birth']); ?>
        </div>
        <div style="flex: 1;">
            <strong>Gender:</strong> <?php echo htmlspecialchars($patient_info['gender']); ?>
        </div>
        <div style="flex: 1;">
            <strong>Blood Group:</strong> <?php echo htmlspecialchars($patient_info['blood_group'] ?? 'Unknown'); ?>
        </div>
    </div>

    <div class="form-group">
        <label><strong>Current Medical History & Notes:</strong></label>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; white-space: pre-wrap; border: 1px solid #dee2e6;">
            <?php echo htmlspecialchars($patient_info['medical_history'] ?: 'No history recorded.'); ?>
        </div>
    </div>

    <?php if ($role === 'doctor'): ?>
        <hr>
        <form method="POST" action="">
            <div class="form-group">
                <label for="medical_history">Add New Note / Diagnosis</label>
                <textarea id="medical_history" name="medical_history" class="form-control" rows="3" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Update History</button>
        </form>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
