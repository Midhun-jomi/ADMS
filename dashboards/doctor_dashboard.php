<?php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['doctor']);
$page_title = "Doctor Dashboard";
include '../includes/header.php';

$user_id = get_user_id();
// Get doctor ID
$staff = db_select_one("SELECT id, first_name, last_name, specialization FROM staff WHERE user_id = $1", [$user_id]);
$doctor_id = $staff['id'] ?? 0;
$doctor_name = $staff['first_name'] ?? 'Doctor';

// Date Range for today
// Date Range for selected date (or today)
$selected_date = $_GET['date'] ?? date('Y-m-d');
$today_start = date('Y-m-d 00:00:00', strtotime($selected_date));
$today_end = date('Y-m-d 23:59:59', strtotime($selected_date));

// Fetch Appointments
$todays_appts = db_select("SELECT a.*, p.first_name, p.last_name, p.id as patient_id, r.room_number,
                           (SELECT profile_image FROM users u WHERE u.id = p.user_id) as p_image
                           FROM appointments a 
                           JOIN patients p ON a.patient_id = p.id 
                           LEFT JOIN rooms r ON a.room_id = r.id 
                           WHERE a.doctor_id = $1 
                             AND a.appointment_time >= '$today_start' 
                             AND a.appointment_time <= '$today_end'
                             AND a.status = 'scheduled' 
                           ORDER BY a.appointment_time ASC", [$doctor_id]);

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
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Dashboard Specific Styles */
    .dashboard-layout {
        display: grid;
        grid-template-columns: 3fr 1fr;
        gap: 30px;
        margin-top: 20px;
    }

    /* Header */
    .dash-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 30px;
    }
    .dash-header h1 {
        font-size: 2rem;
        font-weight: 700;
        margin: 0;
        color: #1a1a1a;
    }
    .dash-header p {
        color: #666;
        margin: 5px 0 0 0;
    }
    .date-controls {
        display: flex;
        gap: 15px;
    }
    .control-pill {
        background: white;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 0.9em;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        border: 1px solid #eee;
        cursor: pointer;
    }
    .control-pill.active {
        background: #FF8F6B; /* Orange from image */
        color: white;
        border: none;
    }

    /* Grid Areas */
    /* Grid Areas */
    /* Stats Grid (3 Columns) */
    .stats-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }
    
    .vitals-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        align-content: start;
    }

    /* Cards */
    .glass-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.04);
        position: relative;
        overflow: hidden;
    }

    /* Heart Card */
    .heart-card {
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        min-height: 300px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .heart-model {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 150px;
        color: #dc2626;
        filter: drop-shadow(0 10px 15px rgba(220, 38, 38, 0.4));
        animation: heartbeat 1.5s infinite;
    }
    @keyframes heartbeat {
        0% { transform: translate(-50%, -50%) scale(1); }
        10% { transform: translate(-50%, -50%) scale(1.1); }
        20% { transform: translate(-50%, -50%) scale(1); }
        100% { transform: translate(-50%, -50%) scale(1); }
    }
    .heart-stats {
        position: relative;
        z-index: 2;
        background: rgba(255,255,255,0.7);
        backdrop-filter: blur(10px);
        padding: 10px 20px;
        border-radius: 15px;
        display: inline-block;
        max-width: 120px;
    }
    .heart-stats strong { font-size: 1.5em; display: block; }
    .heart-stats span { font-size: 0.8em; color: #555; }

    /* Vital Chips */
    .vital-chip {
        background: white;
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    }
    .vital-header { display: flex; justify-content: space-between; font-size: 0.8em; color: #888; margin-bottom: 10px; }
    .vital-value { font-size: 2em; font-weight: 700; color: #333; }
    .vital-unit { font-size: 0.5em; color: #888; margin-left: 5px; }

    /* Charts Row */
    .charts-row {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
    }
    
    /* Sidebar */
    .sidebar-col {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }
    .schedule-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.04);
        flex: 1;
    }
    .date-strip {
        display: flex;
        justify-content: space-between;
        margin-bottom: 20px;
        background: #f8f9fa;
        padding: 10px;
        border-radius: 12px;
    }
    .date-item {
        text-align: center;
        width: 30px;
        font-size: 0.8em;
        cursor: pointer;
        padding: 5px;
        border-radius: 8px;
    }
    .date-item.active {
        background: #FF8F6B;
        color: white;
    }
    .appt-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .appt-img {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: #eee;
        object-fit: cover;
    }
    .appt-info {
        flex: 1;
    }
    .appt-name { font-weight: 600; font-size: 0.9em; display: block; }
    .appt-role { font-size: 0.8em; color: #888; }
    
    .issue-card {
        background: #f9fafb;
        border-radius: 20px;
        padding: 25px;
    }
    .issue-tag {
        background: #e5e7eb;
        color: #555;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.8em;
        margin: 5px;
        display: inline-block;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .dashboard-layout { grid-template-columns: 1fr; }
        .top-row { grid-template-columns: 1fr; }
        .vitals-grid { grid-template-columns: repeat(2, 1fr); }
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

// Fetch Latest Metrics for the "Active" patient (First appointment patient or selected)
$active_patient = $todays_appts[0] ?? null;
$metrics_data = [
    'heart_rate' => 72, 
    'glucose' => 100, 
    'cholesterol' => 150, 
    'stress_level' => 'Normal'
];

if ($active_patient) {
    $latest_metrics = db_select("SELECT metric_type, metric_value FROM patient_health_metrics 
                                 WHERE patient_id = $1 
                                 ORDER BY recorded_at DESC", [$active_patient['patient_id']]);
    
    foreach ($latest_metrics as $lm) {
        $val = json_decode($lm['metric_value'], true);
        // Only take the first found (latest) for each type
        if (!isset($found_types[$lm['metric_type']])) {
            $metrics_data[$lm['metric_type']] = $val['value'] ?? $val;
            $found_types[$lm['metric_type']] = true;
        }
    }
}
?>

<div class="dash-header">
    <div>
        <h1>Good Morning, <?php echo htmlspecialchars($doctor_name); ?></h1>
        <p>You have <?php echo $appt_count; ?> appointment<?php echo $appt_count != 1 ? 's' : ''; ?> today</p>
    </div>
    <div class="date-controls">
        <button class="control-pill active" onclick="document.getElementById('vitalsModal').style.display='block'">
            <i class="fas fa-plus"></i> Add Vitals
        </button>
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
                    <?php foreach ($todays_appts as $appt): ?>
                        <option value="<?php echo $appt['patient_id']; ?>">
                            <?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?>
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
                <label>ECG Data (Comma sep. 50 values)</label>
                <input type="text" name="ecg_data" class="form-control" placeholder="10,40,20..." value="<?php echo implode(',', array_map(function() { return rand(20, 80); }, range(1, 50))); ?>">
                <small class="text-muted">Auto-generated mock data for demo</small>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">Save Records</button>
        </form>
    </div>
</div>

<div class="dashboard-layout">
    <!-- Main Column -->
    <div class="main-col">
        <!-- Stats Row (3 Boxes) -->
        <div class="stats-grid">
            <!-- 1. Heart Card -->
            <div class="glass-card heart-card">
                <div style="z-index: 2;">
                    <h3 style="margin: 0; color: #444;">Live Heart Rate</h3>
                    <p style="color: #666; font-size: 0.9em;">
                        <?php echo $active_patient ? htmlspecialchars($active_patient['first_name'] . ' ' . $active_patient['last_name']) : 'No Active Patient'; ?> 
                        (Current)
                    </p>
                </div>
                
                <i class="fas fa-heart heart-model"></i>
                
                <div style="z-index: 2; margin-top: auto; display: flex; justify-content: space-between; align-items: flex-end;">
                    <div class="heart-stats">
                        <small>Stress Level</small>
                        <strong><?php echo $metrics_data['stress_level']; ?></strong>
                    </div>
                    <div class="heart-stats" style="text-align: right;">
                        <small>Rate</small>
                        <strong><?php echo $metrics_data['heart_rate']; ?><small>bpm</small></strong>
                    </div>
                </div>
            </div>

            <!-- 2. ECG Recording -->
            <div class="glass-card">
                <div class="card-header" style="border: none; padding-bottom: 0;">
                    <span>ECG Recording</span>
                </div>
                <canvas id="ecgChart" height="150"></canvas>
            </div>
            
            <!-- 3. Total Visitors -->
            <div class="glass-card">
                <div class="card-header" style="border: none; padding-bottom: 0;">
                    <span>Total Visitors</span>
                </div>
                <div style="position: relative; height: 200px; display: flex; justify-content: center; align-items: center;">
                    <canvas id="visitorChart"></canvas>
                    <div style="position: absolute; text-align: center;">
                        <strong style="font-size: 1.5em; display: block; color: #333;"><?php echo number_format($v_total); ?></strong>
                        <span style="display: block; font-size: 0.8em; color: #888;"><?php echo date('F'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar Column -->
    <div class="sidebar-col">
        <div class="schedule-card">
            <div class="card-header" style="border: none; padding-bottom: 5px;">
                <span><i class="far fa-calendar-alt text-warning"></i> Schedule</span>
                <i class="fas fa-ellipsis-v text-muted"></i>
            </div>
            
            <div class="date-strip">
                <?php 
                // Generate 7 days centered on selected date
                for($i=-3; $i<=3; $i++): 
                    $d = strtotime("$selected_date $i days");
                    $day_num = date('d', $d);
                    $day_name = date('M', $d);
                    $full_date = date('Y-m-d', $d);
                    $is_active = ($full_date === $selected_date) ? 'active' : '';
                ?>
                <a href="?date=<?php echo $full_date; ?>" style="text-decoration: none; color: inherit;">
                    <div class="date-item <?php echo $is_active; ?>">
                        <div style="opacity: 0.6; font-size: 0.8em;"><?php echo $day_name; ?></div>
                        <strong><?php echo $day_num; ?></strong>
                    </div>
                </a>
                <?php endfor; ?>
            </div>

            <div class="schedule-list">
                <?php if (empty($todays_appts)): ?>
                    <p class="text-muted text-center py-4">No appointments today.</p>
                <?php else: ?>
                    <?php foreach ($todays_appts as $appt): ?>
                        <div class="appt-item">
                            <img src="<?php echo $appt['p_image'] ?: 'https://ui-avatars.com/api/?name='.urlencode($appt['first_name']); ?>" class="appt-img">
                            <div class="appt-info">
                                <span class="appt-name"><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></span>
                                <span class="appt-role"><?php echo date('h:i A', strtotime($appt['appointment_time'])); ?> - <?php echo htmlspecialchars($appt['reason']); ?></span>
                            </div>
                            <a href="/modules/ehr/visit_notes.php?appointment_id=<?php echo $appt['id']; ?>" class="btn-sm btn-light"><i class="fas fa-chevron-right"></i></a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="issue-card">
            <div class="card-header" style="border: none;">
                <span>Issue Found <i class="fas fa-info-circle text-danger"></i></span>
                <i class="fas fa-arrow-up text-muted" style="transform: rotate(45deg);"></i>
            </div>
            <div>
                <span class="issue-tag">Osteoporosis</span>
                <span class="issue-tag">Bisphosphonate drugs</span>
                <span class="issue-tag">Hypertension</span>
            </div>
        </div>
    </div>
</div>

<script>
    // ECG Chart
    const ctxECG = document.getElementById('ecgChart').getContext('2d');
    new Chart(ctxECG, {
        type: 'line',
        data: {
            labels: Array.from({length: 50}, (_, i) => i),
            datasets: [{
                label: 'ECG',
                data: Array.from({length: 50}, () => Math.random() * 100),
                borderColor: '#FF8F6B',
                borderWidth: 2,
                tension: 0.4,
                pointRadius: 0
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { x: { display: false }, y: { display: false } }
        }
    });

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
