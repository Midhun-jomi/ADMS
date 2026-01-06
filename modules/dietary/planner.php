<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Doctor, Nurse, Admin
$allowed_roles = ['admin', 'doctor', 'nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Dietary Planning";
require_once '../../includes/header.php';

$success_msg = '';

// Add Diet Plan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_diet'])) {
    try {
        db_insert('diet_plans', [
            'patient_id' => $_POST['patient_id'],
            'plan_name' => $_POST['plan_name'],
            'instructions' => $_POST['instructions'],
            'start_date' => date('Y-m-d'),
            'status' => 'active'
        ]);
        $success_msg = "Diet plan assigned!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$patients = db_select("SELECT * FROM patients");
$plans = db_select("
    SELECT d.*, p.first_name, p.last_name 
    FROM diet_plans d 
    JOIN patients p ON d.patient_id = p.id 
    ORDER BY d.created_at DESC
");
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-utensils"></i> Patient Dietary Plans</h1>
        <button class="btn-primary" onclick="showModal('dietModal')">
            <i class="fas fa-plus"></i> Assign Plan
        </button>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Diet Plan</th>
                        <th>Instructions</th>
                        <th>Start Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($p['plan_name']); ?></span></td>
                        <td><?php echo htmlspecialchars($p['instructions']); ?></td>
                        <td><?php echo $p['start_date']; ?></td>
                        <td><?php echo ucfirst($p['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="dietModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('dietModal').style.display='none'">&times;</span>
        <h3>Assign Diet Plan</h3>
        <form method="POST">
            <div class="form-group">
                <label>Patient</label>
                <select name="patient_id" required>
                    <?php foreach ($patients as $pt): ?>
                        <option value="<?php echo $pt['id']; ?>"><?php echo $pt['first_name'] . ' ' . $pt['last_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Plan Type</label>
                <select name="plan_name">
                    <option value="General Healthy">General Healthy</option>
                    <option value="Diabetic">Diabetic (Low Sugar)</option>
                    <option value="Renal">Renal (Low Sodium/Protein)</option>
                    <option value="Liquid">Liquid Only</option>
                    <option value="Soft">Soft Diet</option>
                </select>
            </div>
            <div class="form-group">
                <label>Special Instructions</label>
                <textarea name="instructions" required placeholder="e.g. No allergies, small portions..."></textarea>
            </div>
            <button type="submit" name="assign_diet" class="btn-primary">Assign Plan</button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
