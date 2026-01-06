<?php
// dashboards/receptionist_dashboard.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['receptionist']);

$page_title = "Receptionist Dashboard";
include '../includes/header.php';

// Stats
$today = date('Y-m-d');
$todays_appts = db_select_one("SELECT COUNT(*) as c FROM appointments WHERE DATE(appointment_time) = '$today'")['c'];
$pending_invoices = db_select_one("SELECT COUNT(*) as c FROM billing WHERE status = 'pending'")['c'];
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <h3><?php echo $todays_appts; ?></h3>
        <p>Today's Appointments</p>
    </div>
    <div class="stat-card">
        <h3><?php echo $pending_invoices; ?></h3>
        <p>Pending Invoices</p>
    </div>
</div>

<div class="card">
    <div class="card-header">Quick Actions</div>
    <div class="card-body">
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="/modules/ehr/patient_register.php" class="btn btn-primary">Register New Patient</a>
            <a href="/modules/ehr/appointments.php" class="btn btn-primary">Manage Appointments</a>
            <a href="/modules/billing/invoices.php" class="btn btn-primary">Billing & Invoices</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Today's Schedule</div>
    <?php
    $schedule = db_select("SELECT a.*, p.first_name, p.last_name, s.first_name as doc_first, s.last_name as doc_last 
                           FROM appointments a 
                           JOIN patients p ON a.patient_id = p.id 
                           LEFT JOIN staff s ON a.doctor_id = s.id
                           WHERE DATE(a.appointment_time) = '$today' 
                           ORDER BY a.appointment_time ASC");
    ?>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background-color: #f8f9fa; text-align: left;">
                <th style="padding: 10px;">Time</th>
                <th style="padding: 10px;">Patient</th>
                <th style="padding: 10px;">Doctor</th>
                <th style="padding: 10px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($schedule)): ?>
                <tr><td colspan="4" style="padding: 10px;">No appointments for today.</td></tr>
            <?php else: ?>
                <?php foreach ($schedule as $appt): ?>
                    <tr style="border-bottom: 1px solid #dee2e6;">
                        <td style="padding: 10px;"><?php echo date('H:i', strtotime($appt['appointment_time'])); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></td>
                        <td style="padding: 10px;"><?php echo htmlspecialchars($appt['doc_first'] . ' ' . $appt['doc_last']); ?></td>
                        <td style="padding: 10px;"><?php echo ucfirst($appt['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
