<?php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['doctor']);
$page_title = "Doctor Dashboard";
include '../includes/header.php';

$user_id = get_user_id();
// Get doctor ID and Room
$staff = db_select_one("SELECT s.id, s.first_name, s.last_name, s.specialization, r.room_number, r.location 
                        FROM staff s 
                        LEFT JOIN rooms r ON s.primary_room_id = r.id 
                        WHERE s.user_id = $1", [$user_id]);
$doctor_id = $staff['id'] ?? 0;
$doctor_name = $staff['first_name'] ?? 'Doctor';
$doctor_room = $staff['room_number'] ?? 'Not Assigned';
$doctor_location = $staff['location'] ?? 'General Outpatient';

// Date Range for today
// Date Range for selected date (or today)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$today_start = date('Y-m-d 00:00:00', strtotime($selected_date));
$today_end = date('Y-m-d 23:59:59', strtotime($selected_date));

// Handle Consultation Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/queue_logic.php';
    require_once '../includes/fcm_service.php';

    if (isset($_POST['start_consult']) && !empty($_POST['appt_id'])) {
        // 1. Start Current Consult
        update_appointment_status($_POST['appt_id'], 'consulting');
        
        // 2. Automate: Notify NEXT patient
        // Find next eligible appointment (Waiting or Ready) that is NOT the one we just started
        $next_appt = db_select_one("SELECT a.id, p.first_name, u.fcm_token 
                                    FROM appointments a
                                    JOIN patients p ON a.patient_id = p.id
                                    JOIN users u ON p.user_id = u.id
                                    WHERE a.doctor_id = $1 
                                    AND a.status IN ('waiting', 'ready')
                                    AND a.appointment_time >= CURRENT_DATE
                                    AND a.id != $2
                                    ORDER BY a.appointment_time ASC 
                                    LIMIT 1", [$doctor_id, $_POST['appt_id']]);
                                    
        if ($next_appt && !empty($next_appt['fcm_token'])) {
            $msg = "Hello {$next_appt['first_name']}, the doctor has started the previous consultation. You are next! Please be ready.";
            FCMService::send($next_appt['fcm_token'], "Queue Update: You are Next", $msg);
            // Optional: Feedback to doctor
            echo "<script>setTimeout(() => alert('Next patient ({$next_appt['first_name']}) has been auto-notified!'), 500);</script>";
        }

    } elseif (isset($_POST['end_consult']) && !empty($_POST['appt_id'])) {
        update_appointment_status($_POST['appt_id'], 'completed');
    } elseif (isset($_POST['notify_patient']) && !empty($_POST['appt_id'])) {
        require_once '../includes/fcm_service.php';
        // Get Patient User ID and Details
        $pat_details = db_select_one("SELECT p.first_name, p.last_name, p.user_id, u.fcm_token 
                                      FROM appointments a 
                                      JOIN patients p ON a.patient_id = p.id 
                                      JOIN users u ON p.user_id = u.id 
                                      WHERE a.id = $1", [$_POST['appt_id']]);
        
        if ($pat_details && !empty($pat_details['fcm_token'])) {
            $msg = "Hello {$pat_details['first_name']}, you are next in line. Please proceed to Room $doctor_room.";
            $res = FCMService::send($pat_details['fcm_token'], "Appointment Alert", $msg);
            if($res['status'] == 'success' || $res['status'] == 'simulated') {
                echo "<div class='alert alert-success' style='position:fixed; top:20px; right:20px; z-index:9999;'>Notification sent!</div>";
            } else {
                echo "<div class='alert alert-danger' style='position:fixed; top:20px; right:20px; z-index:9999;'>Failed to send: {$res['message']}</div>";
            }
        } else {
             echo "<div class='alert alert-warning' style='position:fixed; top:20px; right:20px; z-index:9999;'>Patient has no registered device for notifications.</div>";
        }
    }
}

// Fetch Appointments
$todays_appts = db_select("SELECT a.*, p.first_name, p.last_name, p.id as patient_id, r.room_number,
                           (SELECT profile_image FROM users u WHERE u.id = p.user_id) as p_image
                           FROM appointments a 
                           JOIN patients p ON a.patient_id = p.id 
                           LEFT JOIN rooms r ON a.room_id = r.id 
                           WHERE a.doctor_id = $1 
                             AND a.appointment_time >= '$today_start' 
                             AND a.appointment_time <= '$today_end'
                             AND a.status IN ('scheduled', 'waiting', 'ready', 'consulting') 
                           ORDER BY 
                             CASE WHEN a.status = 'consulting' THEN 0 
                                  WHEN a.status = 'ready' THEN 1 
                                  WHEN a.status = 'waiting' THEN 2 
                                  ELSE 3 END,
                             a.appointment_time ASC", [$doctor_id]);

$appt_count = count($todays_appts);

// Fetch Visitor Stats for Current Month
$visitor_stats = db_select_one("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN gender ILIKE 'Male' THEN 1 ELSE 0 END) as male,
    SUM(CASE WHEN gender ILIKE 'Female' THEN 1 ELSE 0 END) as female,
    SUM(CASE WHEN date_of_birth > CURRENT_DATE - INTERVAL '13 years' THEN 1 ELSE 0 END) as child
    FROM patients 
    WHERE id IN (
        SELECT DISTINCT patient_id 
        FROM appointments 
        WHERE doctor_id = $1 
        AND appointment_time >= date_trunc('month', CURRENT_DATE)
    )", [$doctor_id]);

// Defaults if null
$v_total = $visitor_stats['total'] ?? 0;
$v_male = $visitor_stats['male'] ?? 0;
$v_female = $visitor_stats['female'] ?? 0;
$v_child = $visitor_stats['child'] ?? 0;

// Fetch all patients for Vitals dropdown, ordered by first_name
$all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY first_name ASC");
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Premium Dashboard Specific Styles */
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --orange-gradient: linear-gradient(135deg, #FF8F6B 0%, #FF6B6B 100%);
        --blue-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        --surface-color: rgba(255, 255, 255, 0.95);
        --text-primary: #1a202c;
        --text-secondary: #718096;
        --border-radius-lg: 24px;
        --border-radius-md: 16px;
    }

    body {
        background-color: #f7fafc;
        font-family: 'Inter', sans-serif;
    }

    .dashboard-layout {
        display: grid;
        grid-template-columns: 2.2fr 1fr;
        gap: 30px;
        margin-top: 20px;
        animation: fadeIn 0.5s ease;
        align-items: start;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Header */
    .dash-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .dash-header h1 {
        font-size: 2.2rem;
        font-weight: 800;
        margin: 0;
        color: var(--text-primary);
        letter-spacing: -0.5px;
    }
    .dash-header p {
        color: var(--text-secondary);
        margin: 8px 0 0 0;
        font-size: 1.05rem;
    }
    .date-controls {
        display: flex;
        gap: 15px;
    }
    .control-pill {
        background: var(--surface-color);
        padding: 10px 20px;
        border-radius: 30px;
        font-size: 0.95em;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.04);
        border: 1px solid rgba(255,255,255,0.8);
        backdrop-filter: blur(10px);
        color: var(--text-primary);
        cursor: default;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        margin-bottom: 30px;
    }
    
    /* Cards */
    .glass-card {
        background: var(--surface-color);
        border-radius: var(--border-radius-lg);
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.03);
        border: 1px solid rgba(255,255,255,0.8);
        backdrop-filter: blur(20px);
        position: relative;
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .glass-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.06);
    }

    /* Heart Card */
    .heart-card {
        background: var(--primary-gradient);
        color: white;
        min-height: 160px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        border: none;
    }
    .heart-card h3 { color: white !important; font-weight: 700; margin-bottom: 5px; }
    .heart-card p { color: rgba(255,255,255,0.8) !important; font-weight: 500; font-size: 0.9em; margin-top: 0; }
    
    .heart-model {
        position: absolute;
        top: 40%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 130px;
        color: rgba(255, 75, 75, 0.4);
        filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.2));
        animation: heartbeat 1.5s infinite ease-in-out;
    }
    @keyframes heartbeat {
        0% { transform: translate(-50%, -50%) scale(1); }
        15% { transform: translate(-50%, -50%) scale(1.15); }
        30% { transform: translate(-50%, -50%) scale(1); }
        45% { transform: translate(-50%, -50%) scale(1.15); }
        60% { transform: translate(-50%, -50%) scale(1); }
        100% { transform: translate(-50%, -50%) scale(1); }
    }
    
    .heart-stats-container {
        z-index: 2;
        margin-top: auto;
        display: flex;
        justify-content: space-between;
        gap: 15px;
    }
    .heart-stats {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(12px);
        padding: 15px 20px;
        border-radius: var(--border-radius-md);
        flex: 1;
        border: 1px solid rgba(255,255,255,0.3);
    }
    .heart-stats strong { font-size: 1.6em; display: block; font-weight: 800; line-height: 1.1; }
    .heart-stats small { font-size: 0.8em; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

    .schedule-card {
        padding: 25px;
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 500px;
    }
    .schedule-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .schedule-header h3 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-primary);
    }
    .schedule-list {
        overflow-y: auto;
        flex-grow: 1;
        padding-right: 5px;
    }
    .schedule-list::-webkit-scrollbar { width: 6px; }
    .schedule-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    
    .appt-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        border-radius: var(--border-radius-md);
        background: #f8fafc;
        margin-bottom: 12px;
        transition: all 0.2s;
        border: 1px solid transparent;
    }
    .appt-item:hover {
        background: white;
        border-color: #e2e8f0;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    }
    .appt-item.consulting {
        background: #eff6ff;
        border-color: #bfdbfe;
    }
    
    .appt-img {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .appt-name { font-weight: 700; font-size: 1rem; color: var(--text-primary); display: block; }
    .appt-role { font-size: 0.85em; color: var(--text-secondary); display: flex; align-items: center; gap: 5px; margin-top: 4px; }
    
    /* Donut Card */
    .visitor-card {
        display: flex;
        flex-direction: column;
        min-height: 160px;
    }
    .visitor-card h3 {
        width: 100%;
        margin: 0 0 15px 0;
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        text-align: left;
    }

    /* Buttons */
    .btn-action {
        padding: 8px 16px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        border: none;
        cursor: pointer;
        transition: transform 0.1s, box-shadow 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-action:hover { transform: translateY(-2px); }
    .btn-action:active { transform: translateY(0); }
    
    .btn-start { background: var(--orange-gradient); color: white; box-shadow: 0 4px 10px rgba(255, 143, 107, 0.3); }
    .btn-notify { background: var(--blue-gradient); color: white; box-shadow: 0 4px 10px rgba(79, 172, 254, 0.3); }
    .btn-end { background: linear-gradient(135deg, #f87171 0%, #ef4444 100%); color: white; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3); }
    
    .btn-icon-only {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: #f1f5f9;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
    }
    .btn-icon-only:hover {
        background: white;
        color: var(--text-primary);
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid { grid-template-columns: 1fr; }
        .schedule-card { height: 400px; }
    }
</style>

<!-- Add Vitals Module -->
<?php
// Handle Vitals Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vitals'])) {
    $v_patient_id = $_POST['v_patient_id'];
    $metrics = [
        'heart_rate' => ['value' => $_POST['heart_rate'], 'unit' => 'bpm'],
        'glucose' => ['value' => $_POST['glucose'], 'unit' => 'mg/dL'],
        'cholesterol' => ['value' => $_POST['cholesterol'], 'unit' => 'mg/dL'],
        'stress_level' => ['value' => $_POST['stress_level'], 'unit' => ''],
        'ecg' => ['data' => explode(',', $_POST['ecg_data'])] // Mock data input
    ];

    foreach ($metrics as $type => $data) {
        if (!empty($data['value']) || !empty($data['data'])) {
            db_insert('patient_health_metrics', [
                'patient_id' => $v_patient_id,
                'metric_type' => $type,
                'metric_value' => json_encode($data),
                'recorded_by' => $user_id
            ]);
        }
    }
    // Refresh to show new data
    echo "<meta http-equiv='refresh' content='0'>";
}

// Fetch Latest Metrics for the "Active" patient (Most recently recorded vitals)
$latest_metric_entry = db_select_one("SELECT patient_id, recorded_at FROM patient_health_metrics ORDER BY recorded_at DESC LIMIT 1");

$active_patient = null;
if ($latest_metric_entry) {
    // Fetch patient details for the latest metric
    $active_patient = db_select_one("SELECT id as patient_id, first_name, last_name FROM patients WHERE id = $1", [$latest_metric_entry['patient_id']]);
}

$metrics_data = [
    'heart_rate' => 0, 
    'glucose' => 0, 
    'cholesterol' => 0, 
    'stress_level' => 'N/A'
];

if ($active_patient) {
    $latest_metrics = db_select("SELECT metric_type, metric_value FROM patient_health_metrics 
                                 WHERE patient_id = $1 
                                 ORDER BY recorded_at DESC", [$active_patient['patient_id']]);
    
    $found_types = [];
    foreach ($latest_metrics as $lm) {
        $val_json = json_decode($lm['metric_value'], true);
        $type = $lm['metric_type'];
        if (!isset($found_types[$type])) {
            $metrics_data[$type] = $val_json['value'] ?? 0;
            $found_types[$type] = true;
        }
    }
}
?>

<div class="dash-header">
    <div>
        <h1>Good Morning, Dr. <?php echo htmlspecialchars($doctor_name); ?></h1>
        <p>
            <i class="fas fa-hospital-user"></i> <?php echo htmlspecialchars($doctor_location); ?> 
            <span style="margin: 0 10px; opacity: 0.5;">|</span>
            <i class="fas fa-door-open"></i> Room <?php echo htmlspecialchars($doctor_room); ?>
        </p>
    </div>
    <div class="date-controls">
        
        <div class="control-pill"><i class="far fa-calendar"></i> <?php echo date('d M Y'); ?></div>
    </div>
</div>

<!-- Vitals Modal -->
<div id="vitalsModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div class="modal-content" style="background-color: #fefefe; margin: 10% auto; padding: 25px; border: 1px solid #888; width: 500px; border-radius: 15px;">
        <span class="close" onclick="document.getElementById('vitalsModal').style.display='none'" style="float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
        <h2 style="margin-top: 0;">Record Vitals</h2>
        <form method="POST">
            <input type="hidden" name="add_vitals" value="1">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label>Select Patient</label>
                <select name="v_patient_id" class="form-control" required>
                    <option value="">-- Select Patient --</option>
                    <?php foreach ($all_patients as $patient): ?>
                        <option value="<?php echo $patient['id']; ?>">
                            <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row" style="display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>Heart Rate (bpm)</label>
                    <input type="number" name="heart_rate" class="form-control" placeholder="72">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Stress Level</label>
                    <select name="stress_level" class="form-control">
                        <option value="Low">Low</option>
                        <option value="Normal">Normal</option>
                        <option value="High">High</option>
                    </select>
                </div>
            </div>

            <div class="form-row" style="display: flex; gap: 15px; margin-top: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>Glucose (mg/dL)</label>
                    <input type="number" name="glucose" class="form-control" placeholder="100">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Cholesterol (mg/dL)</label>
                    <input type="number" name="cholesterol" class="form-control" placeholder="150">
                </div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label>Observations / Notes</label>
                <textarea name="nurse_notes" class="form-control" rows="2" placeholder="Enter clinical observations..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">Save Clinical Records</button>
        </form>
    </div>
</div>

<div class="dashboard-layout">
    <!-- Main Column -->
    <div class="main-col">
        <!-- Stats Row -->
        <div class="stats-grid">

                  <!-- 1. Heart Rate Card -->
            <div class="glass-card visitor-card" style="padding: 25px; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <h3 style="margin: 0 0 4px 0; font-size: 1.05em; font-weight: 700; color: var(--text-primary);">Quote Of The Day
                    </h3>
                    <p style="margin: 0 0 16px 0; font-size: 0.8em; color: var(--text-secondary);">
                    </p>
                </div>

                <!-- Animated Heart -->
                <div style="display: flex; justify-content: center; align-items: center; flex: 1; padding: 0; overflow: visible;">
                    <div class="hr-heart-wrap">
                        <svg class="hr-heart" viewBox="0 0 100 90" xmlns="http://www.w3.org/2000/svg">
                            <path d="M50 85 C50 85 5 55 5 28 C5 14 16 5 28 5 C37 5 45 11 50 19 C55 11 63 5 72 5 C84 5 95 14 95 28 C95 55 50 85 50 85Z" fill="#ff1900"/>
                        </svg>
                    </div>
                </div>

                <!-- Quote -->
                <?php
                $quotes = [
                    "The good physician treats the disease; the great physician treats the patient.",
                    "Wherever the art of medicine is loved, there is also a love of humanity.",
                    "The best medicine is to teach people how not to need it.",
                    "To cure sometimes, to relieve often, to comfort always.",
                    "Medicine is not only a science; it is also an art.",
                    "A doctor's mission is to heal, to comfort, and to care.",
                    "The art of medicine consists of amusing the patient while nature cures the disease.",
                ];
                $q = $quotes[date('N') % count($quotes)];
                ?>
                <div style="margin-top: 10px; border-top: 1px solid rgba(0,0,0,0.07); padding-top: 12px;">
                    <p style="margin: 0; font-size: 0.78em; color: var(--text-secondary); font-style: italic; line-height: 1.5; text-align: center;">
                        &ldquo;<?php echo htmlspecialchars($q); ?>&rdquo;
                    </p>
                </div>
            </div>

            <!-- 2. Queue Overview Card -->
            <div class="glass-card heart-card">
                <?php
                $waiting_now = 0;
                foreach ($todays_appts as $a) {
                    if (in_array($a['status'], ['waiting', 'ready'])) $waiting_now++;
                }
                ?>
                <div style="z-index: 2;">
                    <h3>Queue Overview</h3>
                    <p>Current snapshot of your clinic</p>
                </div>
                
                <i class="fas fa-users" style="position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%); font-size: 130px; color: rgba(255, 255, 255, 0.1); filter: drop-shadow(0 10px 15px rgba(0,0,0,0.2)); pointer-events: none;"></i>
                
                <div class="heart-stats-container">
                    <div class="heart-stats">
                        <small style="letter-spacing: 1px;">WAITING NOW</small>
                        <strong style="font-size: 2em; margin-top: -2px;"><?php echo $waiting_now; ?></strong>
                    </div>
                    <div class="heart-stats" style="text-align: right;">
                        <small style="letter-spacing: 1px;">REMAINING</small>
                        <strong style="font-size: 2em; margin-top: -2px;"><?php echo $appt_count; ?></strong>
                    </div>
                </div>
            </div>

  
        </div>

        <style>
        .hr-heart-wrap {
            position: relative;
            width: 130px;
            height: 130px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: visible;
        }
        .hr-heart {
            width: 170px;
            height: 170px;
            animation: heartbeat 1.2s ease-in-out infinite;
            filter: drop-shadow(0 4px 12px rgba(192,57,43,0.35));
        }
        @keyframes heartbeat {
            0%   { transform: scale(1);    }
            14%  { transform: scale(1.18); }
            28%  { transform: scale(1);    }
            42%  { transform: scale(1.12); }
            56%  { transform: scale(1);    }
            100% { transform: scale(1);    }
        }
        .hr-ecg {
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 220px;
            height: 22px;
            opacity: 0.65;
        }
        .hr-ecg-line {
            stroke-dasharray: 260;
            stroke-dashoffset: 260;
            animation: ecg-draw 1.2s ease-in-out infinite;
        }
        @keyframes ecg-draw {
            0%   { stroke-dashoffset: 260; opacity: 0; }
            10%  { opacity: 1; }
            60%  { stroke-dashoffset: 0; }
            80%  { stroke-dashoffset: 0; opacity: 1; }
            100% { stroke-dashoffset: 0; opacity: 0; }
        }
        </style>
        
        <!-- Recent Activity Module -->
        <div style="margin-top: 10px; background: var(--surface-color); padding: 25px; border-radius: var(--border-radius-lg); box-shadow: 0 10px 30px rgba(0,0,0,0.03); border: 1px solid rgba(255,255,255,0.8); backdrop-filter: blur(20px);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; color: var(--text-primary);"><i class="fas fa-bolt" style="color: #fbbf24; margin-right: 8px;"></i> Quick Actions</h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                <a href="/modules/ehr/patients.php" style="text-decoration: none; background: #f8fafc; padding: 15px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; transition: 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='#f8fafc'; this.style.transform='translateY(0)';">
                    <i class="fas fa-users" style="font-size: 1.5rem; color: #64748b; margin-bottom: 10px; display: block;"></i>
                    <span style="color: #334155; font-weight: 600; font-size: 0.9em;">Patients</span>
                </a>
                <a href="/modules/lab/orders.php" style="text-decoration: none; background: #f8fafc; padding: 15px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; transition: 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='#f8fafc'; this.style.transform='translateY(0)';">
                    <i class="fas fa-flask" style="font-size: 1.5rem; color: #64748b; margin-bottom: 10px; display: block;"></i>
                    <span style="color: #334155; font-weight: 600; font-size: 0.9em;">Lab Orders</span>
                </a>
                <a href="/modules/radiology/orders.php" style="text-decoration: none; background: #f8fafc; padding: 15px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; transition: 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.transform='translateY(-2px)';" onmouseout="this.style.background='#f8fafc'; this.style.transform='translateY(0)';">
                    <i class="fas fa-x-ray" style="font-size: 1.5rem; color: #64748b; margin-bottom: 10px; display: block;"></i>
                    <span style="color: #334155; font-weight: 600; font-size: 0.9em;">Radiology</span>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Sidebar Column -->
    <div class="sidebar-col">
        <!-- Schedule Card -->
        <div class="glass-card schedule-card">
            <div class="schedule-header">
                <h3><i class="far fa-calendar-alt" style="color:#FF8F6B; margin-right:8px;"></i> Today's Schedule</h3>
                <button class="btn-icon-only"><i class="fas fa-ellipsis-h"></i></button>
            </div>
            
            <div class="date-strip" style="display: flex; justify-content: space-around; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid rgba(0,0,0,0.05);">
                <?php 
                for($i=-2; $i<=2; $i++): 
                    $d = strtotime("$selected_date $i days");
                    $day_num = date('d', $d);
                    $day_name = date('M', $d);
                    $full_date = date('Y-m-d', $d);
                    $is_active = ($full_date === $selected_date) ? 'active' : '';
                ?>
                <a href="?date=<?php echo $full_date; ?>" style="text-decoration: none; color: inherit; display: block;">
                    <div class="date-item <?php echo $is_active; ?>" style="text-align: center; border-radius: 10px; padding: 8px 5px; cursor: pointer; <?php echo $is_active ? 'background: #f8fafc; color: #1e293b;' : 'color: #94a3b8; transition: 0.2s;'; ?>" onmouseover="this.style.background='#f1f5f9'; this.style.color='#1e293b';" onmouseout="<?php echo $is_active ? '' : 'this.style.background=\'transparent\'; this.style.color=\'#94a3b8\';'; ?>">
                        <div style="font-size: 0.75em; font-weight: 600; text-transform: uppercase; margin-bottom: 3px;"><?php echo $day_name; ?></div>
                        <strong style="font-size: 1.3em; font-weight: 800;"><?php echo $day_num; ?></strong>
                    </div>
                </a>
                <?php endfor; ?>
            </div>

            <div class="schedule-list">
                <?php if (empty($todays_appts)): ?>
                    <div style="text-align: center; padding: 40px 0;">
                        <i class="far fa-calendar-times" style="font-size: 3rem; color: #e2e8f0; margin-bottom: 15px;"></i>
                        <p style="color: #64748b; font-size: 0.95em; margin: 0; font-weight: 500;">No appointments scheduled.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($todays_appts as $appt): 
                        $status = $appt['status'];
                        $timer_html = '';
                        $badge_color = 'secondary';
                        
                        if (($status == 'waiting' || $status == 'ready') && !empty($appt['checked_in_at'])) {
                            $start = strtotime($appt['checked_in_at']);
                            $diff = round((time() - $start) / 60);
                            $timer_color = '#10b981';
                            if ($diff >= 10 && $diff <= 20) $timer_color = '#f59e0b';
                            if ($diff > 20) $timer_color = '#ef4444';
                            $timer_html = "<span style='color: $timer_color; font-weight: 700; font-size: 0.95em; margin-right: 12px; display: inline-flex; align-items: center; gap: 4px;'><i class='fas fa-stopwatch'></i> {$diff}m</span>";
                        } elseif ($status == 'consulting') {
                            $timer_html = "<span style='color: #3b82f6; font-weight: 700; font-size: 0.95em; margin-right: 12px; display: inline-flex; align-items: center; gap: 4px;'><i class='fas fa-spinner fa-spin'></i> Active</span>";
                        }
                        
                        switch($status) {
                            case 'ready': $badge_color = 'success'; break;
                            case 'waiting': $badge_color = 'warning'; break;
                            case 'consulting': $badge_color = 'primary'; break;
                            case 'scheduled': $badge_color = 'info'; break;
                        }
                    ?>
                        <div class="appt-item <?php echo $status=='consulting' ? 'consulting' : ''; ?>" style="<?php echo $status=='consulting' ? 'background: #eff6ff; border-color: #bfdbfe;' : 'background: #f8fafc; border: 1px solid #e2e8f0;'; ?> padding: 15px; border-radius: var(--border-radius-md); margin-bottom: 12px; display: flex; align-items: center; gap: 15px;">
                            <div style="flex-shrink: 0;">
                                <img src="<?php echo $appt['p_image'] ?: 'https://ui-avatars.com/api/?name='.urlencode($appt['first_name']).'&background=e2e8f0&color=475569'; ?>" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
                            </div>
                            
                            <div style="flex-grow: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center;">
                                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
                                    <span style="font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%;">
                                        <?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?>
                                    </span>
                                </div>
                                <span style="font-size: 0.8em; color: var(--text-secondary); margin-top: 4px; display: flex; align-items: center; gap: 8px;">
                                    <i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($appt['appointment_time'])); ?>
                                    <span style="font-weight: 800; text-transform: uppercase; font-size: 0.85em;" class="text-<?php echo $badge_color; ?>"><?php echo $status; ?></span>
                                </span>
                            </div>
                            
                            <div style="flex-shrink: 0; text-align: right; display: flex; align-items: center;">
                                <?php echo $timer_html; ?>
                                
                                <?php if ($status == 'ready' || $status == 'waiting'): ?>
                                    <form method="POST" style="margin:0; display:inline-flex; gap: 6px;">
                                        <input type="hidden" name="appt_id" value="<?php echo $appt['id']; ?>">
                                        <?php if($status == 'waiting'): ?>
                                            <button type="submit" name="notify_patient" class="btn-action btn-notify" style="padding: 6px 10px; font-size: 0.8em;" title="Notify">
                                                <i class="fas fa-bell"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="submit" name="start_consult" class="btn-action btn-start" style="padding: 6px 12px; font-size: 0.85em;">
                                            <i class="fas fa-play"></i> Start
                                        </button>
                                    </form>
                                <?php elseif ($status == 'consulting'): ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="appt_id" value="<?php echo $appt['id']; ?>">
                                        <button type="submit" name="end_consult" class="btn-action btn-end" style="padding: 6px 12px; font-size: 0.85em;">
                                            <i class="fas fa-stop"></i> End
                                        </button>
                                    </form>
                                <?php elseif ($status == 'scheduled' || $status == 'completed'): ?>
                                    <a href="/modules/ehr/visit_notes.php?appointment_id=<?php echo $appt['id']; ?>" class="btn-action" style="padding: 6px 12px; font-size: 0.85em; background: #e2e8f0; color: #475569;">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Messaging System Removed
// Only Charts Remain




    // Donut Chart
    const ctxVisitor = document.getElementById('visitorChart').getContext('2d');
    new Chart(ctxVisitor, {
        type: 'doughnut',
        data: {
            labels: ['Male', 'Female', 'Child'],
            datasets: [{
                data: [<?php echo $v_male; ?>, <?php echo $v_female; ?>, <?php echo $v_child; ?>],
                backgroundColor: ['#3b82f6', '#FF8F6B', '#10b981'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            cutout: '70%',
            plugins: { legend: { display: false } }
        }
    });

    // Mini Sparklines
    const sparkOptions = {
        type: 'bar',
        options: { 
            responsive: true, 
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { display: false }, y: { display: false } }
        }
    };

</script>

<?php include '../includes/footer.php'; ?>
