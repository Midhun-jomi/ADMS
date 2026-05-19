<?php
// includes/stats_widgets.php

// Ensure DB connection and Session
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = $_SESSION['role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? 0;

// Initialize Default Stats (Admin/Default)
$stats = [
    'card1' => ['label' => 'Total Patients', 'value' => 0, 'icon' => 'fa-user-injured', 'color' => 'card-purple', 'trend' => '+2.1%', 'sub' => 'Total registered patients', 'link' => BASE_URL . '/modules/ehr/patients.php'],
    'card2' => ['label' => 'Appointments', 'value' => 0, 'icon' => 'fa-calendar-alt', 'color' => 'card-blue', 'trend' => '-1.5%', 'sub' => 'Total scheduled appointments', 'link' => BASE_URL . '/modules/ehr/appointments.php'],
    'card3' => ['label' => 'Bed Room', 'value' => 0, 'icon' => 'fa-bed', 'color' => 'card-yellow', 'trend' => '+2.1%', 'sub' => 'Occupied beds', 'link' => BASE_URL . '/modules/patient_management/nursing_station.php'],
    'card4' => ['label' => 'Total Invoice', 'value' => '$0', 'icon' => 'fa-file-invoice-dollar', 'color' => 'card-light', 'trend' => '+2.1%', 'sub' => 'Total revenue generated', 'link' => BASE_URL . '/modules/ehr/invoices.php']
];

try {
    if ($role === 'admin') {
        // ADMIN STATS
        $stats['card1']['value'] = db_select_one("SELECT COUNT(*) as c FROM patients")['c'] ?? 0;
        $stats['card2']['value'] = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE status = 'scheduled'")['c'] ?? 0;
        $stats['card3']['value'] = db_select_one("SELECT COUNT(*) as c FROM rooms WHERE status = 'occupied'")['c'] ?? 0;
        $revenue = db_select_one("SELECT SUM(total_amount) as s FROM billing WHERE status = 'paid'")['s'] ?? 0;
        $stats['card4']['value'] = '₹' . number_format($revenue, 0);

    } elseif ($role === 'doctor') {
        // DOCTOR STATS
        // Get correct doctor_id from staff table
        $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
        $doctor_id = $staff['id'] ?? 0;

        // 1. My Appointments (Scheduled)
        $my_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = $1 AND status = 'scheduled'", [$doctor_id])['c'] ?? 0;
        $stats['card1'] = ['label' => 'My Appointments', 'value' => $my_appts, 'icon' => 'fa-calendar-check', 'color' => 'card-purple', 'trend' => 'Active', 'sub' => 'Scheduled for you', 'link' => BASE_URL . '/modules/ehr/appointments.php'];

        // 2. My Patients (Distinct patients seen)
        $my_patients = db_select_one("SELECT COUNT(DISTINCT patient_id) as c FROM appointments WHERE doctor_id = $1", [$doctor_id])['c'] ?? 0;
        $stats['card2'] = ['label' => 'My Patients', 'value' => $my_patients, 'icon' => 'fa-user-md', 'color' => 'card-blue', 'trend' => 'Total', 'sub' => 'Unique patients seen', 'link' => BASE_URL . '/modules/ehr/patients.php'];

        // 3. Today's Appointments
        // Use PHP ranges to ensure "Today" is consistent with other views
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');
        $today_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = $1 AND appointment_time >= $2 AND appointment_time <= $3", [$doctor_id, $today_start, $today_end])['c'] ?? 0;
        $stats['card3'] = ['label' => 'Today\'s Schedule', 'value' => $today_appts, 'icon' => 'fa-clock', 'color' => 'card-yellow', 'trend' => 'Today', 'sub' => 'Appointments today', 'link' => BASE_URL . '/dashboards/doctor_dashboard.php'];

        // 4. Consultations
        $consultations = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = $1 AND status = 'completed'", [$doctor_id])['c'] ?? 0;
        $stats['card4'] = ['label' => 'Consultations', 'value' => $consultations, 'icon' => 'fa-stethoscope', 'color' => 'card-light', 'trend' => 'Done', 'sub' => 'Completed visits', 'link' => BASE_URL . '/modules/ehr/appointments.php?status=completed'];

    } elseif ($role === 'patient') {
        // PATIENT STATS
        // 1. My Upcoming Appointments
        $my_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE patient_id = $1 AND status = 'scheduled'", [$user_id])['c'] ?? 0;
        $stats['card1'] = ['label' => 'Upcoming Visits', 'value' => $my_appts, 'icon' => 'fa-calendar-day', 'color' => 'card-purple', 'trend' => 'Next', 'sub' => 'Scheduled appointments'];

        // 2. My Prescriptions (Mock count for now as we don't have a prescriptions table linked easily, or use 0)
        $stats['card2'] = ['label' => 'Prescriptions', 'value' => 0, 'icon' => 'fa-pills', 'color' => 'card-blue', 'trend' => 'Active', 'sub' => 'Active medications'];

        // 3. My Medical Records/History (Current Month)
        $month_start = date('Y-m-01 00:00:00');
        $month_end = date('Y-m-t 23:59:59');
        $history = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE patient_id = $1 AND status = 'completed' AND appointment_time >= $2 AND appointment_time <= $3", [$user_id, $month_start, $month_end])['c'] ?? 0;
        $stats['card3'] = ['label' => 'Past Visits', 'value' => $history, 'icon' => 'fa-history', 'color' => 'card-yellow', 'trend' => 'This Month', 'sub' => 'Completed this month'];

        // 4. Pending Bills
        $pending_bills = db_select_one("SELECT COUNT(*) as c FROM billing WHERE patient_id = $1 AND status = 'unpaid'", [$user_id])['c'] ?? 0;
        $stats['card4'] = ['label' => 'Pending Bills', 'value' => $pending_bills, 'icon' => 'fa-file-invoice', 'color' => 'card-light', 'trend' => 'Due', 'sub' => 'Unpaid invoices'];

    } elseif ($role === 'nurse') {
        // NURSE STATS
        // 1. Beds Occupied
        $beds = db_select_one("SELECT COUNT(*) as c FROM rooms WHERE status = 'occupied'")['c'] ?? 0;
        $stats['card1'] = ['label' => 'Beds Occupied', 'value' => $beds, 'icon' => 'fa-bed', 'color' => 'card-purple', 'trend' => 'Now', 'sub' => 'Current in-patients', 'link' => BASE_URL . '/modules/patient_management/in_patients.php'];

        // 2. Available Beds
        $avail_beds = db_select_one("SELECT COUNT(*) as c FROM rooms WHERE status = 'available'")['c'] ?? 0;
        $stats['card2'] = ['label' => 'Available Beds', 'value' => $avail_beds, 'icon' => 'fa-check-circle', 'color' => 'card-blue', 'trend' => 'Open', 'sub' => 'Ready for admission', 'link' => BASE_URL . '/modules/patient_management/manage_beds.php'];

        // 3. Today's Admissions
        $admissions_today = db_select_one("SELECT COUNT(*) as c FROM admissions WHERE admission_date::date = CURRENT_DATE")['c'] ?? 0;
        $stats['card3'] = ['label' => 'Admitted Today', 'value' => $admissions_today, 'icon' => 'fa-user-plus', 'color' => 'card-yellow', 'trend' => 'New', 'sub' => 'Patients admitted today', 'link' => BASE_URL . '/modules/patient_management/nursing_station.php'];

        // 4. Discharges Today
        $discharges_today = db_select_one("SELECT COUNT(*) as c FROM discharge_summaries WHERE created_at::date = CURRENT_DATE")['c'] ?? 0;
        $stats['card4'] = ['label' => 'Discharged Today', 'value' => $discharges_today, 'icon' => 'fa-walking', 'color' => 'card-light', 'trend' => 'Out', 'sub' => 'Patients discharged today', 'link' => BASE_URL . '/modules/patient_management/nursing_station.php'];

    } elseif ($role === 'lab_tech') {
        // LAB TECH STATS - Emerald/Amber Theme
        $pending = db_select_one("SELECT COUNT(*) as c FROM laboratory_tests WHERE status = 'ordered'")['c'] ?? 0;
        $stats['card1'] = ['label' => 'Pending Orders', 'value' => $pending, 'icon' => 'fa-flask', 'color' => 'card-emerald', 'trend' => 'Ordered', 'sub' => 'Tests awaiting processing', 'link' => BASE_URL . '/modules/lab/orders.php'];

        $completed = db_select_one("SELECT COUNT(*) as c FROM laboratory_tests WHERE status = 'completed'")['c'] ?? 0;
        $stats['card2'] = ['label' => 'Completed Reports', 'value' => $completed, 'icon' => 'fa-file-medical', 'color' => 'card-blue', 'trend' => 'Done', 'sub' => 'Reports generated', 'link' => BASE_URL . '/modules/lab/results.php'];

        $today = db_select_one("SELECT COUNT(*) as c FROM laboratory_tests WHERE created_at::date = CURRENT_DATE")['c'] ?? 0;
        $stats['card3'] = ['label' => 'Today\'s Requests', 'value' => $today, 'icon' => 'fa-clock', 'color' => 'card-amber', 'trend' => 'New', 'sub' => 'Requested today', 'link' => BASE_URL . '/modules/lab/orders.php'];

        $stats['card4'] = ['label' => 'High Priority', 'value' => 0, 'icon' => 'fa-exclamation-circle', 'color' => 'card-rose', 'trend' => 'Urgent', 'sub' => 'Needs immediate attention', 'link' => BASE_URL . '/modules/lab/orders.php'];

    } elseif ($role === 'radiologist') {
        // RADIOLOGIST STATS - Cyan/Indigo Theme
        $pending = db_select_one("SELECT COUNT(*) as c FROM radiology_reports WHERE status = 'ordered'")['c'] ?? 0;
        $stats['card1'] = ['label' => 'Pending Scans', 'value' => $pending, 'icon' => 'fa-x-ray', 'color' => 'card-cyan', 'trend' => 'Pending', 'sub' => 'Scans awaiting report', 'link' => BASE_URL . '/modules/radiology/orders.php'];

        $completed = db_select_one("SELECT COUNT(*) as c FROM radiology_reports WHERE status = 'completed'")['c'] ?? 0;
        $stats['card2'] = ['label' => 'Reports Completed', 'value' => $completed, 'icon' => 'fa-clipboard-check', 'color' => 'card-indigo', 'trend' => 'Finalized', 'sub' => 'Reports generated', 'link' => BASE_URL . '/modules/radiology/upload.php'];

        $today = db_select_one("SELECT COUNT(*) as c FROM radiology_reports WHERE created_at::date = CURRENT_DATE")['c'] ?? 0;
        $stats['card3'] = ['label' => 'Today\'s Workload', 'value' => $today, 'icon' => 'fa-tasks', 'color' => 'card-teal', 'trend' => 'Daily', 'sub' => 'Total cases today', 'link' => BASE_URL . '/modules/radiology/orders.php'];

        $stats['card4'] = ['label' => 'Critical Values', 'value' => 0, 'icon' => 'fa-biohazard', 'color' => 'card-light', 'trend' => 'Alert', 'sub' => 'Findings to notify', 'link' => BASE_URL . '/modules/radiology/orders.php'];
    }

} catch (Exception $e) {
    // Fallback is already set in initialization
}
?>

<div class="dashboard-grid stats-widget-container">
    <?php foreach ($stats as $key => $card): ?>
        <a href="<?php echo $card['link'] ?? '#'; ?>" class="stat-card-link" style="text-decoration: none; color: inherit; display: block;">
            <div class="card stat-card <?php echo $card['color']; ?>" style="transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;">
                <div style="display: flex; justify-content: space-between;">
                    <div class="stat-icon" style="background: rgba(255,255,255,0.5); color: #333;">
                        <i class="fas <?php echo $card['icon']; ?>"></i>
                    </div>
                    <div class="icon-btn-sm" style="display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.3); border-radius: 50%; width: 24px; height: 24px;">
                        <i class="fas fa-chevron-right" style="font-size: 0.8em; opacity: 0.7;"></i>
                    </div>
                </div>
                <div>
                    <div class="stat-value"><?php echo $card['value']; ?></div>
                    <div class="stat-label">
                        <?php echo $card['label']; ?> 
                        <span class="stat-trend <?php echo strpos($card['trend'], '-') !== false ? 'trend-down' : 'trend-up'; ?>">
                            <?php echo $card['trend']; ?>
                        </span>
                    </div>
                    <div class="stat-subtext"><?php echo $card['sub']; ?></div>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<style>
.stat-card-link:hover .stat-card {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
}
</style>
