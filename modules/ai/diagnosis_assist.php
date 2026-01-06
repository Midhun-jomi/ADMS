<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Doctors, Nurses
$allowed_roles = ['doctor', 'nurse', 'admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "AI Diagnosis Assistant";
require_once '../../includes/header.php';

$symptoms = "";
$prediction = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $symptoms = strtolower(trim($_POST['symptoms']));
    
    // Rule-based "AI" Logic
    $rules = [
        'fever,cough,fatigue' => ['diagnosis' => 'Viral Flu', 'confidence' => 85, 'urgency' => 'Normal'],
        'chest pain,shortness of breath' => ['diagnosis' => 'Possible Cardiac Event', 'confidence' => 92, 'urgency' => 'Critical'],
        'headache,nausea,sensitivity to light' => ['diagnosis' => 'Migraine', 'confidence' => 78, 'urgency' => 'Low'],
        'abdominal pain,fever' => ['diagnosis' => 'Appendicitis Check Required', 'confidence' => 65, 'urgency' => 'High'],
        'sore throat,fever' => ['diagnosis' => 'Strep Throat', 'confidence' => 80, 'urgency' => 'Normal']
    ];
    
    // Fuzzy matching
    foreach ($rules as $key => $result) {
        $key_parts = explode(',', $key);
        $matches = 0;
        foreach ($key_parts as $part) {
            if (strpos($symptoms, $part) !== false) $matches++;
        }
        
        if ($matches >= count($key_parts) / 2) {
            $prediction = $result;
            break; 
        }
    }
    
    if (empty($prediction) && !empty($symptoms)) {
        $prediction = ['diagnosis' => 'Inconclusive - Clinical Review Needed', 'confidence' => 10, 'urgency' => 'Normal'];
    }
}
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-robot"></i> AI Diagnostic Support</h1>
        <p class="text-muted">Experimental Feature: Symptom-based triage suggestions.</p>
    </div>

    <div class="card">
        <form method="POST">
            <div class="form-group">
                <label>Enter Patient Symptoms (comma separated)</label>
                <textarea name="symptoms" class="form-control" rows="3" placeholder="e.g. fever, cough, chest pain..."><?php echo htmlspecialchars($symptoms); ?></textarea>
            </div>
            <button type="submit" class="btn-primary">Analyze Symptoms</button>
        </form>
    </div>

    <?php if (!empty($prediction)): ?>
    <div class="card mt-3" style="border-left: 5px solid <?php echo $prediction['urgency'] === 'Critical' ? '#e74c3c' : ($prediction['urgency'] === 'High' ? '#f39c12' : '#2ecc71'); ?>;">
        <h3>Analysis Result</h3>
        <div class="ai-result">
            <div class="res-item">
                <strong>Suggested Condition:</strong>
                <h2><?php echo $prediction['diagnosis']; ?></h2>
            </div>
            <div class="res-item">
                <strong>Confidence Score:</strong>
                <div class="progress-bar">
                    <div class="fill" style="width: <?php echo $prediction['confidence']; ?>%; background: var(--primary-color);"></div>
                </div>
                <span><?php echo $prediction['confidence']; ?>%</span>
            </div>
            <div class="res-item">
                <strong>Triage Urgency:</strong>
                <span class="badge badge-<?php echo strtolower($prediction['urgency'] === 'Normal' ? 'info' : ($prediction['urgency'] === 'Critical' ? 'danger' : 'warning')); ?>">
                    <?php echo $prediction['urgency']; ?>
                </span>
            </div>
        </div>
        <p class="text-small text-muted mt-2">
            <i>Disclaimer: AI suggestions are for assistance only and do not replace professional medical advice.</i>
        </p>
    </div>
    <?php endif; ?>
</div>

<style>
    .ai-result { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-top: 1rem; }
    @media(max-width: 700px) { .ai-result { grid-template-columns: 1fr; } }
    .mt-3 { margin-top: 1.5rem; }
    .text-small { font-size: 0.85rem; }
</style>

<?php require_once '../../includes/footer.php'; ?>
