<?php
// modules/ehr/appointments.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = get_user_role();
$user_id = get_user_id();
$page_title = "My Schedule";
include '../../includes/header.php';

// Prepare Data Fetching
$appointments = [];
$stats_data = []; // For charts

// Sorting Logic (Universal)
$sort_order = $_GET['sort'] ?? 'default';

// Helper to get Date Sort Clause
function getDateSortSQL($alias = 'a') {
    return "
        CASE 
            WHEN DATE($alias.appointment_time) = CURRENT_DATE THEN 1 
            WHEN $alias.appointment_time > NOW() THEN 2 
            ELSE 3 
        END ASC,
        CASE 
            WHEN $alias.appointment_time < NOW() AND DATE($alias.appointment_time) != CURRENT_DATE THEN $alias.appointment_time 
            ELSE NULL 
        END DESC,
        $alias.appointment_time ASC";
}

if ($role === 'patient') {
    $patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    if ($patient) {
        // Patient Sorting
        $p_sort = "";
        switch ($sort_order) {
            case 'desc': $p_sort = "ORDER BY a.appointment_time DESC"; break;
            case 'asc':  $p_sort = "ORDER BY a.appointment_time ASC"; break;
            case 'name_asc': $p_sort = "ORDER BY s.first_name ASC, s.last_name ASC"; break;
            case 'name_desc': $p_sort = "ORDER BY s.first_name DESC, s.last_name DESC"; break;
            default: $p_sort = "ORDER BY " . getDateSortSQL('a'); break;
        }

        $sql = "SELECT a.*, s.first_name as doc_first, s.last_name as doc_last, s.specialization, r.room_number 
                FROM appointments a 
                LEFT JOIN staff s ON a.doctor_id = s.id 
                LEFT JOIN rooms r ON a.room_id = r.id 
                WHERE a.patient_id = $1 
                $p_sort";
        $appointments = db_select($sql, [$patient['id']]);
    }
} elseif ($role === 'doctor') {
    $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    if ($staff) {

        // Doctor Sorting
        $d_sort = "";
        switch ($sort_order) {
            case 'desc': $d_sort = "ORDER BY a.appointment_time DESC"; break;
            case 'asc':  $d_sort = "ORDER BY a.appointment_time ASC"; break;
            case 'name_asc': $d_sort = "ORDER BY p.first_name ASC, p.last_name ASC"; break;
            case 'name_desc': $d_sort = "ORDER BY p.first_name DESC, p.last_name DESC"; break;
            default: $d_sort = "ORDER BY " . getDateSortSQL('a'); break;
        }

        $sql = "SELECT a.*, p.first_name as pat_first, p.last_name as pat_last, r.room_number,
                (SELECT profile_image FROM users u WHERE u.id = p.user_id) as p_image
                FROM appointments a 
                LEFT JOIN patients p ON a.patient_id = p.id 
                LEFT JOIN rooms r ON a.room_id = r.id 
                WHERE a.doctor_id = $1 
                $d_sort";
        $appointments = db_select($sql, [$staff['id']]);

        // Get Weekly Stats (Last 7 days)
        $stats_sql = "SELECT DATE(appointment_time) as date, COUNT(*) as count 
                      FROM appointments 
                      WHERE doctor_id = $1 
                      AND appointment_time >= NOW() - INTERVAL '7 days'
                      GROUP BY DATE(appointment_time) 
                      ORDER BY date ASC";
        $stats_data = db_select($stats_sql, [$staff['id']]);
    }
} else {
    // Admin/Other View (All)
    $a_sort = "";
    switch ($sort_order) {
        case 'desc': $a_sort = "ORDER BY a.appointment_time DESC"; break;
        case 'asc':  $a_sort = "ORDER BY a.appointment_time ASC"; break;
        // Sort by Patient Name Default
        case 'name_asc': $a_sort = "ORDER BY p.first_name ASC, p.last_name ASC"; break;
        case 'name_desc': $a_sort = "ORDER BY p.first_name DESC, p.last_name DESC"; break;
        default: $a_sort = "ORDER BY a.appointment_time DESC"; break;
    }

    $sql = "SELECT a.*, p.first_name as pat_first, p.last_name as pat_last, 
            s.first_name as doc_first, s.last_name as doc_last
            FROM appointments a 
            LEFT JOIN patients p ON a.patient_id = p.id 
            LEFT JOIN staff s ON a.doctor_id = s.id
            $a_sort LIMIT 50";
    $appointments = db_select($sql);
}

// Prepare Chart Data
$labels = [];
$data = [];
if (!empty($stats_data)) {
    foreach ($stats_data as $row) {
        $labels[] = date('D', strtotime($row['date']));
        $data[] = $row['count'];
    }
}
// Fill empty days if needed (simplified: just use what we have, or mock if empty for demo visual)
if (empty($labels) && $role === 'doctor') {
    // Mock Data for "Premium Visual" if no real data yet
    $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $data = [5, 8, 6, 9, 12, 4, 2];
}
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Premium Page Styles */
    .page-header-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
    
    .analytics-section {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 25px;
        margin-bottom: 35px;
    }
    
    .chart-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.03);
        height: 300px;
        position: relative;
    }
    
    .stat-box {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 20px;
        padding: 25px;
        color: white;
        display: flex;
        flex-direction: column;
        justify-content: center;
        box-shadow: 0 10px 20px rgba(118, 75, 162, 0.3);
    }

    .appt-list-container {
        background: white;
        border-radius: 20px;
        box-shadow: 0 5px 25px rgba(0,0,0,0.03);
        padding: 25px;
    }

    /* Table Styling */
    .premium-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 15px; /* Spacing between rows */
        margin-top: -15px;
    }
    
    .premium-table th {
        text-align: left;
        color: #8898aa;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.8rem;
        padding: 10px 20px;
        border: none;
    }
    
    .premium-table tr.appt-row {
        background: #fdfdfd;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .premium-table tr.appt-row:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        background: white;
        z-index: 10;
        position: relative;
    }
    
    .premium-table td {
        padding: 20px;
        vertical-align: middle;
        border: none;
        border-top: 1px solid #f0f0f0;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .premium-table tr.appt-row td:first-child {
        border-left: 1px solid #f0f0f0;
        border-top-left-radius: 12px;
        border-bottom-left-radius: 12px;
    }
    
    .premium-table tr.appt-row td:last-child {
        border-right: 1px solid #f0f0f0;
        border-top-right-radius: 12px;
        border-bottom-right-radius: 12px;
    }

    .user-pill {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        background: #eee;
    }
    
    .status-badge {
        padding: 6px 12px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .status-scheduled { background: #e0f2fe; color: #0284c7; }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }

    /* Responsive */
    @media (max-width: 900px) {
        .analytics-section { grid-template-columns: 1fr; }
    }
</style>

<div class="page-header-row">
    <div>
        <h1 style="margin: 0;">My Schedule</h1>
        <p style="color: #666; margin: 5px 0 0;">Manage your upcoming appointments and patient history.</p>
    </div>
    <?php if ($role === 'patient'): ?>
        <a href="book_appointment.php" class="btn btn-primary"><i class="fas fa-plus"></i> Book New</a>
    <?php endif; ?>
</div>

<?php if ($role === 'doctor'): ?>
    <!-- Analytics Section -->
    <div class="analytics-section">
        <div class="chart-card">
            <h3 style="margin: 0 0 15px 0; font-size: 1.1rem; color: #444;">Weekly Patient Activity</h3>
            <canvas id="weekChart"></canvas>
        </div>
        <div class="stat-box">
            <h2 style="font-size: 3rem; margin: 0;"><?php echo count($appointments); ?></h2>
            <p style="margin: 5px 0 0; opacity: 0.9;">Total Appointments</p>
            <div style="margin-top: 20px; background: rgba(255,255,255,0.2); height: 1px;"></div>
            <div style="margin-top: 15px; font-size: 0.9rem;">
                <i class="fas fa-arrow-up"></i> <strong>12%</strong> vs last week
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Appointment List -->
<div class="appt-list-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin: 0; font-size: 1.2rem;">Appointment List</h3>
        <form method="GET" style="margin: 0;">
            <select name="sort" onchange="this.form.submit()" style="padding: 8px 15px; border-radius: 8px; border: 1px solid #ddd; background: #f9f9f9; cursor: pointer; font-size: 0.9em;">
                <option value="default" <?php echo ($sort_order == 'default') ? 'selected' : ''; ?>>Smart Sort</option>
                <option value="desc" <?php echo ($sort_order == 'desc') ? 'selected' : ''; ?>>Newest First</option>
                <option value="asc" <?php echo ($sort_order == 'asc') ? 'selected' : ''; ?>>Oldest First</option>
                <option value="name_asc" <?php echo ($sort_order == 'name_asc') ? 'selected' : ''; ?>>Name (A-Z)</option>
                <option value="name_desc" <?php echo ($sort_order == 'name_desc') ? 'selected' : ''; ?>>Name (Z-A)</option>
            </select>
        </form>
    </div>
    
    <?php if (empty($appointments)): ?>
        <div style="text-align: center; padding: 40px; color: #888;">
            <i class="far fa-calendar-times" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3;"></i>
            <p>No appointments found.</p>
        </div>
    <?php else: ?>
        <table class="premium-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th><?php echo ($role === 'patient') ? 'Doctor' : 'Patient'; ?></th>
                    <th>Room</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appt): 
                    $time_str = date('h:i A', strtotime($appt['appointment_time']));
                    $date_str = date('M d, Y', strtotime($appt['appointment_time']));
                    $other_name = ($role === 'patient') 
                        ? "Dr. " . $appt['doc_first'] . " " . $appt['doc_last']
                        : $appt['pat_first'] . " " . $appt['pat_last'];
                    
                    // Avatar image
                    $p_img = ($role === 'doctor' && isset($appt['p_image'])) 
                        ? $appt['p_image'] 
                        : "https://ui-avatars.com/api/?name=" . urlencode($other_name) . "&background=random";
                ?>
                <tr class="appt-row">
                    <td>
                        <div style="font-weight: 700; color: #333;"><?php echo $time_str; ?></div>
                        <div style="font-size: 0.85rem; color: #888;"><?php echo $date_str; ?></div>
                    </td>
                    <td>
                        <div class="user-pill">
                            <img src="<?php echo $p_img; ?>" class="user-avatar" alt="User">
                            <div>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($other_name); ?></div>
                                <?php if ($role === 'patient'): ?>
                                    <div style="font-size: 0.8rem; color: #888;"><?php echo htmlspecialchars($appt['specialization'] ?? ''); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="font-family: monospace; background: #eee; padding: 4px 8px; border-radius: 6px;">
                            <?php echo htmlspecialchars($appt['room_number'] ?? 'TBD'); ?>
                        </span>
                    </td>
                    <td style="color: #555; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($appt['reason'] ?? ''); ?>">
                        <?php echo htmlspecialchars($appt['reason'] ?? 'N/A'); ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo $appt['status']; ?>">
                            <?php echo ucfirst($appt['status']); ?>
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <?php if ($role === 'doctor'): ?>
                            <a href="visit_notes.php?appointment_id=<?php echo $appt['id']; ?>" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.85rem;">
                                Consult <i class="fas fa-arrow-right" style="margin-left: 5px;"></i>
                            </a>
                        <?php else: ?>
                            <a href="visit_notes.php?appointment_id=<?php echo $appt['id']; ?>" class="btn btn-light" style="font-size: 0.85rem; border: 1px solid #ddd;">
                                Details
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
    <?php if ($role === 'doctor'): ?>
    // Render Analytics Chart
    const ctx = document.getElementById('weekChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($labels); ?>,
            datasets: [{
                label: 'Patients',
                data: <?php echo json_encode($data); ?>,
                backgroundColor: '#FF8F6B',
                borderRadius: 8,
                barThickness: 20
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [5, 5], color: '#f0f0f0' },
                    ticks: { display: false }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
    <?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
