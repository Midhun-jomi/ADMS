<?php
// modules/ai/process_triage.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient', 'admin']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $patient_id = $_POST['patient_id'];
    
    // Construct JSON payload from form data
    $symptoms_data = [
        'chief_complaint' => $_POST['chief_complaint'],
        'duration' => $_POST['duration'],
        'severity' => $_POST['severity'],
        'associated_symptoms' => $_POST['associated_symptoms'] ?? [],
        'notes' => $_POST['notes']
    ];
    $symptoms_json = json_encode($symptoms_data);

    // --- MOCK AI ANALYSIS ---
    // In a real app, this would call an OpenAI/Gemini API
    $mock_conditions = ['Common Cold', 'Flu', 'Migraine', 'Gastritis', 'Anxiety'];
    $predicted_condition = $mock_conditions[array_rand($mock_conditions)];
    
    $severity_score = (int)$_POST['severity'];
    if (in_array('Shortness of Breath', $symptoms_data['associated_symptoms'])) {
        $severity_score += 2;
    }
    
    $ai_findings = "Based on the reported symptoms (" . $symptoms_data['chief_complaint'] . "), " .
                   "the AI suggests a potential case of **$predicted_condition**.\n" .
                   "Recommended Action: " . ($severity_score > 7 ? "Visit ER immediately." : "Schedule an appointment.");
    // ------------------------

    // Save to database
    $data = [
        'patient_id' => $patient_id,
        'symptoms_json' => $symptoms_json,
        'ai_findings' => $ai_findings,
        'severity_score' => $severity_score,
        'status' => 'pending_review'
    ];

    try {
        db_insert('triage_analysis', $data);
        // Redirect to results page (or dashboard with success message)
        header("Location: /dashboards/patient_dashboard.php?triage_success=1");
        exit();
    } catch (Exception $e) {
        die("Error saving triage data: " . $e->getMessage());
    }
} else {
    header("Location: triage_form.php");
    exit();
}
?>
