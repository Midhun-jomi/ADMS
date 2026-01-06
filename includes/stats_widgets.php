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
    'card1' => ['label' => 'Total Patients', 'value' => 0, 'icon' => 'fa-user-injured', 'color' => 'card-purple', 'trend' => '+2.1%', 'sub' => 'Total registered patients'],
    'card2' => ['label' => 'Appointments', 'value' => 0, 'icon' => 'fa-calendar-alt', 'color' => 'card-blue', 'trend' => '-1.5%', 'sub' => 'Total scheduled appointments'],
    'card3' => ['label' => 'Bed Room', 'value' => 0, 'icon' => 'fa-bed', 'color' => 'card-yellow', 'trend' => '+2.1%', 'sub' => 'Occupied beds'],
    'card4' => ['label' => 'Total Invoice', 'value' => '$0', 'icon' => 'fa-file-invoice-dollar', 'color' => 'card-light', 'trend' => '+2.1%', 'sub' => 'Total revenue generated']
];

try {
    if ($role === 'admin') {
        // ADMIN STATS
        $stats['card1']['value'] = db_select_one("SELECT COUNT(*) as c FROM patients")['c'] ?? 0;
        $stats['card2']['value'] = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE status = 'scheduled'")['c'] ?? 0;
        $stats['card3']['value'] = db_select_one("SELECT COUNT(*) as c FROM rooms WHERE status = 'occupied'")['c'] ?? 0;
        $revenue = db_select_one("SELECT SUM(total_amount) as s FROM billing WHERE status = 'paid'")['s'] ?? 0;
        $stats['card4']['value'] = '$' . number_format($revenue, 0);

    } elseif ($role === 'doctor') {
        // DOCTOR STATS
        // Get correct doctor_id from staff table
        $staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
        $doctor_id = $staff['id'] ?? 0;

        // 1. My Appointments (Scheduled)
        $my_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = $1 AND status = 'scheduled'", [$doctor_id])['c'] ?? 0;
        $stats['card1'] = ['label' => 'My Appointments', 'value' => $my_appts, 'icon' => 'fa-calendar-check', 'color' => 'card-purple', 'trend' => 'Active', 'sub' => 'Scheduled for you'];

        // 2. My Patients (Distinct patients seen)
        $my_patients = db_select_one("SELECT COUNT(DISTINCT patient_id) as c FROM appointments WHERE doctor_id = $1", [$doctor_id])['c'] ?? 0;
        $stats['card2'] = ['label' => 'My Patients', 'value' => $my_patients, 'icon' => 'fa-user-md', 'color' => 'card-blue', 'trend' => 'Total', 'sub' => 'Unique patients seen'];

        // 3. Today's Appointments
        // Use PHP ranges to ensure "Today" is consistent with other views
        $today_start = date('Y-m-d 00:00:00');
        $today_end = date('Y-m-d 23:59:59');
        $today_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = $1 AND appointment_time >= $2 AND appointment_time <= $3", [$doctor_id, $today_start, $today_end])['c'] ?? 0;
        $stats['card3'] = ['label' => 'Today\'s Schedule', 'value' => $today_appts, 'icon' => 'fa-clock', 'color' => 'card-yellow', 'trend' => 'Today', 'sub' => 'Appointments today'];

        // 4. Consultations
        $consultations = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE doctor_id = $1 AND status = 'completed'", [$doctor_id])['c'] ?? 0;
        $stats['card4'] = ['label' => 'Consultations', 'value' => $consultations, 'icon' => 'fa-stethoscope', 'color' => 'card-light', 'trend' => 'Done', 'sub' => 'Completed visits'];

    } elseif ($role === 'patient') {
        // PATIENT STATS
        // 1. My Upcoming Appointments
        $my_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE patient_id = $1 AND status = 'scheduled'", [$user_id])['c'] ?? 0;
        $stats['card1'] = ['label' => 'Upcoming Visits', 'value' => $my_appts, 'icon' => 'fa-calendar-day', 'color' => 'card-purple', 'trend' => 'Next', 'sub' => 'Scheduled appointments'];

        // 2. My Prescriptions (Mock count for now as we don't have a prescriptions table linked easily, or use 0)
        $stats['card2'] = ['label' => 'Prescriptions', 'value' => 0, 'icon' => 'fa-pills', 'color' => 'card-blue', 'trend' => 'Active', 'sub' => 'Active medications'];

        // 3. My Medical Records/History
        $history = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE patient_id = $1 AND status = 'completed'", [$user_id])['c'] ?? 0;
        $stats['card3'] = ['label' => 'Past Visits', 'value' => $history, 'icon' => 'fa-history', 'color' => 'card-yellow', 'trend' => 'Total', 'sub' => 'Completed appointments'];

        // 4. Pending Bills
        $pending_bills = db_select_one("SELECT COUNT(*) as c FROM billing WHERE patient_id = $1 AND status = 'unpaid'", [$user_id])['c'] ?? 0;
        $stats['card4'] = ['label' => 'Pending Bills', 'value' => $pending_bills, 'icon' => 'fa-file-invoice', 'color' => 'card-light', 'trend' => 'Due', 'sub' => 'Unpaid invoices'];

    } elseif ($role === 'nurse') {
        // NURSE STATS
        // 1. Beds Occupied
        $beds = db_select_one("SELECT COUNT(*) as c FROM rooms WHERE status = 'occupied'")['c'] ?? 0;
        $stats['card1'] = ['label' => 'Beds Occupied', 'value' => $beds, 'icon' => 'fa-bed', 'color' => 'card-purple', 'trend' => 'Now', 'sub' => 'Current in-patients'];

        // 2. Available Beds
        $avail_beds = db_select_one("SELECT COUNT(*) as c FROM rooms WHERE status = 'available'")['c'] ?? 0;
        $stats['card2'] = ['label' => 'Available Beds', 'value' => $avail_beds, 'icon' => 'fa-check-circle', 'color' => 'card-blue', 'trend' => 'Open', 'sub' => 'Ready for admission'];

        // 3. Today's Admissions
        $admissions_today = db_select_one("SELECT COUNT(*) as c FROM admissions WHERE admission_date::date = CURRENT_DATE")['c'] ?? 0;
        $stats['card3'] = ['label' => 'Admitted Today', 'value' => $admissions_today, 'icon' => 'fa-user-plus', 'color' => 'card-yellow', 'trend' => 'New', 'sub' => 'Patients admitted today'];

        // 4. Discharges Today (Mock or real)
        // We don't have a discharge_date in admissions easily queryable without join or check status.
        // Let's check discharge_summaries created today
        $discharges_today = db_select_one("SELECT COUNT(*) as c FROM discharge_summaries WHERE created_at::date = CURRENT_DATE")['c'] ?? 0;
        $stats['card4'] = ['label' => 'Discharged Today', 'value' => $discharges_today, 'icon' => 'fa-walking', 'color' => 'card-light', 'trend' => 'Out', 'sub' => 'Patients discharged today'];
    }

} catch (Exception $e) {
    // Fallback is already set in initialization
}
?>

<div class="dashboard-grid stats-widget-container">
    <?php foreach ($stats as $key => $card): ?>
        <div class="card stat-card <?php echo $card['color']; ?>">
            <div style="display: flex; justify-content: space-between;">
                <div class="stat-icon" style="background: rgba(255,255,255,0.5); color: #333;">
                    <i class="fas <?php echo $card['icon']; ?>"></i>
                </div>
                <button class="icon-btn-sm"><i class="fas fa-ellipsis-h"></i></button>
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
    <?php endforeach; ?>
</div>
