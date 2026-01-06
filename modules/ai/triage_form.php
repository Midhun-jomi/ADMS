<?php
// modules/ai/triage_form.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient', 'admin']);

$page_title = "AI Symptom Triage";
include '../../includes/header.php';

$role = get_user_role();
$patient_id = null;

if ($role === 'patient') {
    $user_id = $_SESSION['user_id'];
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    $patient_id = $patient['id'];
}

// For Admin: Fetch all patients
$patients = [];
if ($role === 'admin') {
    $patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name");
}
?>

<div class="card">
    <div class="card-header">
        <h3>Symptom Checker</h3>
        <p>Answer the following questions to get an AI-powered preliminary assessment.</p>
    </div>

    <form method="POST" action="process_triage.php">
        <?php if ($role === 'patient'): ?>
            <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
        <?php else: ?>
            <div class="form-group">
                <label>Select Patient (Admin Mode)</label>
                <select name="patient_id" class="form-control" required>
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($patients as $p): ?>
                        <option value="<?php echo $p['id']; ?>">
                            <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label for="chief_complaint">What is your main symptom?</label>
            <input type="text" id="chief_complaint" name="chief_complaint" placeholder="e.g., Headache, Fever, Chest Pain" required>
        </div>

        <div class="form-group">
            <label for="duration">How long have you had this symptom?</label>
            <select id="duration" name="duration" class="form-control">
                <option value="Less than 24 hours">Less than 24 hours</option>
                <option value="1-3 days">1-3 days</option>
                <option value="1 week">1 week</option>
                <option value="More than 1 week">More than 1 week</option>
            </select>
        </div>

        <div class="form-group">
            <label for="severity">Rate the severity (1-10)</label>
            <input type="range" id="severity" name="severity" min="1" max="10" value="5" oninput="this.nextElementSibling.value = this.value">
            <output>5</output>
        </div>

        <div class="form-group">
            <label>Do you have any of these associated symptoms?</label>
            <div>
                <input type="checkbox" id="fever" name="associated_symptoms[]" value="Fever"> <label for="fever">Fever</label><br>
                <input type="checkbox" id="nausea" name="associated_symptoms[]" value="Nausea"> <label for="nausea">Nausea/Vomiting</label><br>
                <input type="checkbox" id="dizziness" name="associated_symptoms[]" value="Dizziness"> <label for="dizziness">Dizziness</label><br>
                <input type="checkbox" id="shortness_breath" name="associated_symptoms[]" value="Shortness of Breath"> <label for="shortness_breath">Shortness of Breath</label>
            </div>
        </div>

        <div class="form-group">
            <label for="notes">Any additional details?</label>
            <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Analyze Symptoms</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
