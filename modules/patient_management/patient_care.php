<?php
// modules/patient_management/patient_care.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'nurse']);

$patient_id = $_GET['patient_id'] ?? null;
if (!$patient_id) die("Patient ID required.");

// Fetch Patient Info
$patient = db_select_one("SELECT * FROM patients WHERE id = $1", [$patient_id]);
if (!$patient) die("Patient not found.");

// Fetch Last Vitals
$last_vitals = db_select_one("SELECT * FROM nurse_records WHERE patient_id = $1 ORDER BY recorded_at DESC LIMIT 1", [$patient_id]);

// Fetch Tasks
// Ensure checklist tasks exist for this patient, otherwise create default set
$default_tasks = [
    'Take vitals',
    'Confirm allergies',
    'Collect lab sample',
    'Upload reports',
    'Patient prepared'
];

// Check existing
$existing_tasks = db_select("SELECT * FROM nurse_tasks WHERE patient_id = $1 ORDER BY id", [$patient_id]);
if (empty($existing_tasks)) {
    // Insert defaults
    foreach ($default_tasks as $t) {
        db_insert('nurse_tasks', [
            'patient_id' => $patient_id,
            'task_name' => $t,
            'completed' => 'f' // boolean false
        ]);
    }
    // reload
    $existing_tasks = db_select("SELECT * FROM nurse_tasks WHERE patient_id = $1 ORDER BY id", [$patient_id]);
}

$page_title = "Patient Care: " . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
include '../../includes/header.php';

$success_msg = "";

// Handle Vitals Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vitals'])) {
    $bp = $_POST['bp'];
    $pulse = $_POST['pulse'];
    $temp = $_POST['temp'];
    $resp = $_POST['resp'];
    $spo2 = $_POST['spo2'];
    
    // EWS Calculation Logic
    $ews = 0;
    // Simple EWS (Simplified for Demo)
    // RR
    if ($resp <= 8 || $resp >= 25) $ews += 3;
    elseif ($resp >= 21) $ews += 2;
    // SpO2
    if ($spo2 <= 91) $ews += 3;
    elseif ($spo2 <= 93) $ews += 2;
    elseif ($spo2 <= 95) $ews += 1;
    // Temp
    if ($temp <= 35 || $temp >= 39.1) $ews += 3;
    elseif ($temp >= 38.1) $ews += 1;
    // Pulse
    if ($pulse <= 40 || $pulse >= 131) $ews += 3;
    elseif ($pulse >= 111) $ews += 2;
    elseif ($pulse >= 101) $ews += 1; // Corrected logic (removed incorrect condition)
    // BP (Sys) - Assuming format 120/80
    $bps = explode('/', $bp);
    $sys = intval($bps[0] ?? 120);
    if ($sys <= 90 || $sys >= 220) $ews += 3;
    elseif ($sys <= 100) $ews += 2;
    elseif ($sys <= 110) $ews += 1;
    
    // Risk Level
    $risk = 'Stable';
    if ($ews >= 5) $risk = 'Critical'; // Red
    elseif ($ews >= 2) $risk = 'Watch'; // Yellow
    
    // Save to DB
    // We need nurse_id. Let's lookup staff id from user_id
    $u_id = $_SESSION['user_id'];
    $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$u_id]);
    $nurse_id = $staff['id'] ?? null;
    
    try {
        db_insert('nurse_records', [
            'patient_id' => $patient_id,
            'nurse_id' => $nurse_id,
            'blood_pressure' => $bp,
            'pulse' => $pulse,
            'temperature' => $temp,
            'respiration' => $resp,
            'spo2' => $spo2,
            'ews_score' => $ews,
            'risk_level' => $risk
        ]);
        
        // Timer Logic (Feature 3)
        // If "Patient prepared" task is checked or simple action trigger? 
        // Prompt says "When nurse clicks Save Vitals: Start a timer"
        // Update patient ready_at
        db_query("UPDATE patients SET ready_at = NOW() WHERE id = $1", [$patient_id]);
        
        $success_msg = "Vitals Saved. EWS Score: $ews ($risk). Timer Started.";
        // Refresh last vitals
        $last_vitals = ['blood_pressure'=>$bp, 'pulse'=>$pulse, 'temperature'=>$temp, 'respiration'=>$resp, 'spo2'=>$spo2, 'ews_score'=>$ews, 'risk_level'=>$risk];
        
    } catch (Exception $e) {
        $success_msg = "Error: " . $e->getMessage();
    }
}

// Handle Task Toggle (via simple GET or separate POST, let's use JS fetch actually, but for simplicity here standard POST or just JS helper)
// We will use a script in the footer to toggle without reload, or simple reload for now.
?>

<div class="main-content">
    <div class="row">
        <!-- Vitals Column -->
        <div class="col-md-6">
            <div class="card"> <!-- style="border-top: 5px solid color..." needed for alert? -->
                <div class="card-header">
                    <i class="fas fa-heartbeat"></i> Take Vitals
                    <?php if (isset($last_vitals['risk_level'])): 
                        $badge_col = $last_vitals['risk_level'] == 'Critical' ? 'danger' : ($last_vitals['risk_level'] == 'Watch' ? 'warning' : 'success');
                    ?>
                        <span class="badge badge-<?php echo $badge_col; ?>" style="float:right; font-size:1em;"><?php echo $last_vitals['risk_level']; ?> (EWS: <?php echo $last_vitals['ews_score']; ?>)</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($success_msg): ?> <div class="alert alert-info"><?php echo $success_msg; ?></div> <?php endif; ?>
                
                <form method="POST" class="styled-form touch-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label>BP (mmHg)</label>
                            <input type="text" name="bp" placeholder="120/80" value="<?php echo $last_vitals['blood_pressure'] ?? ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Pulse (bpm)</label>
                            <input type="number" name="pulse" placeholder="72" value="<?php echo $last_vitals['pulse'] ?? ''; ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Temp (°C)</label>
                            <input type="number" step="0.1" name="temp" placeholder="37.0" value="<?php echo $last_vitals['temperature'] ?? ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Resp (rpm)</label>
                            <input type="number" name="resp" placeholder="16" value="<?php echo $last_vitals['respiration'] ?? ''; ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>SpO₂ (%)</label>
                        <input type="number" name="spo2" placeholder="98" value="<?php echo $last_vitals['spo2'] ?? ''; ?>" required>
                    </div>
                    
                    <button type="submit" name="save_vitals" class="btn btn-primary btn-lg btn-block">
                        <i class="fas fa-save"></i> Save Vitals & Start Timer
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Task Board Column -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-tasks"></i> Nurse Task Board
                </div>
                <div class="task-list">
                    <?php foreach ($existing_tasks as $task): ?>
                    <div class="task-item <?php echo $task['completed'] == 't' ? 'completed' : ''; ?>" onclick="toggleTask(<?php echo $task['id']; ?>, this)">
                        <div class="task-icon">
                            <i class="fas <?php echo $task['completed'] == 't' ? 'fa-check-circle' : 'fa-circle'; ?>"></i>
                        </div>
                        <div class="task-name"><?php echo htmlspecialchars($task['task_name']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-muted text-center mt-3"><small>Tap to toggle. Auto-saved.</small></p>
            </div>
        </div>
    </div>
</div>

<style>
    .touch-form input { font-size: 1.2em; padding: 15px; }
    .btn-block { width: 100%; padding: 15px; font-size: 1.2em; }
    
    .task-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 10px;
    }
    .task-item {
        display: flex;
        align-items: center;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s;
        border: 2px solid transparent;
    }
    .task-item:active { transform: scale(0.98); }
    .task-item.completed {
        background: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }
    .task-icon { font-size: 1.5em; margin-right: 15px; width: 30px; text-align: center; }
    .task-name { font-size: 1.1em; font-weight: 500; }
</style>

<script>
function toggleTask(taskId, el) {
    // Optimistic UI update
    const isComplete = el.classList.contains('completed');
    // Toggle class
    el.classList.toggle('completed');
    const icon = el.querySelector('.task-icon i');
    icon.className = isComplete ? 'fas fa-circle' : 'fas fa-check-circle';
    
    // API Call
    fetch('toggle_task.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'task_id=' + taskId + '&status=' + (!isComplete)
    }).then(res => res.json()).then(data => {
        if(!data.success) {
            alert('Failed to save task.');
            // Revert
            el.classList.toggle('completed');
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
