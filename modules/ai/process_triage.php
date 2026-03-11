<?php
// modules/ai/process_triage.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient', 'admin']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF check
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Invalid request. Please refresh and try again.");
    }

    $patient_id = $_POST['patient_id'];

    // Patients can only submit for themselves
    if (get_user_role() === 'patient') {
        $own = db_select_one("SELECT id FROM patients WHERE user_id = $1", [get_user_id()]);
        if (!$own || $own['id'] !== $patient_id) {
            die("Unauthorized.");
        }
    }
    
    // Construct JSON payload from form data
    $symptoms_data = [
        'chief_complaint' => $_POST['chief_complaint'],
        'duration' => $_POST['duration'],
        'severity' => $_POST['severity'],
        'associated_symptoms' => $_POST['associated_symptoms'] ?? [],
        'notes' => $_POST['notes']
    ];
    $symptoms_json = json_encode($symptoms_data);

    // --- IMPROVED AI ANALYSIS (Keyword Logic) ---
    $text = strtolower($symptoms_data['chief_complaint'] . " " . $symptoms_data['notes'] . " " . implode(" ", $symptoms_data['associated_symptoms']));
    $severity_input = (int)$_POST['severity'];
    
    // Keyword Dictionary
    $keywords = [
        'critical' => ['chest pain', 'breath', 'unconscious', 'stroke', 'heart attack', 'bleeding', 'seizure'],
        'high' => ['fever', 'fracture', 'burn', 'vomiting', 'pain', 'migraine'],
        'moderate' => ['cough', 'cold', 'rash', 'stomach', 'nausea'],
        'low' => ['itch', 'fatigue', 'mild', 'checkup']
    ];
    
    $predicted_urgency = 'Low';
    $predicted_condition = 'General Checkup';
    $action_rec = 'Schedule a routine appointment.';
    $base_score = $severity_input;

    // Detect Urgency
    foreach ($keywords['critical'] as $k) {
        if (strpos($text, $k) !== false) {
            $predicted_urgency = 'Critical';
            $predicted_condition = 'Potential Emergency (e.g., Cardiac or Respiratory Issue)';
            $action_rec = 'Go to the Emergency Room (ER) immediately.';
            $base_score += 5; // boost severity
            break; 
        }
    }
    if ($predicted_urgency === 'Low') {
        foreach ($keywords['high'] as $k) {
            if (strpos($text, $k) !== false) {
                $predicted_urgency = 'High';
                $predicted_condition = 'Acute Illness (e.g., Infection, Injury)';
                $action_rec = 'See a doctor within 24 hours.';
                $base_score += 2;
                break;
            }
        }
    }
    
    // Final score cap
    $severity_score = min(10, $base_score);

    $ai_findings = "Based on your symptoms, the AI assesses this as **$predicted_urgency** priority.\n" . 
                   "Possible Condition: **$predicted_condition**.\n" . 
                   "**Recommendation**: $action_rec";
    // --------------------------------------------

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
        header("Location: " . BASE_URL . "/dashboards/patient_dashboard.php?triage_success=1");
        exit();
    } catch (Exception $e) {
        die("Error saving triage data: " . $e->getMessage());
    }
} else {
    header("Location: triage_form.php");
    exit();
}
?>
