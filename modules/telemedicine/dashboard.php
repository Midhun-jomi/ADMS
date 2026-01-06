<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

$allowed_roles = ['admin', 'doctor', 'patient'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Telemedicine Console";
require_once '../../includes/header.php';

$success_msg = '';

// Doctor creates a session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    $appt_id = $_POST['appointment_id'];
    $link = "https://meet.jit.si/" . uniqid('hospital_telemed_');
    
    try {
        db_insert('telemedicine_sessions', [
            'appointment_id' => $appt_id,
            'meeting_link' => $link,
            'platform' => 'Jitsi Meet'
        ]);
        $success_msg = "Session created! Link: " . $link;
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Fetch Appointments suitable for telemed (e.g. status scheduled)
// For simplicity, fetching all scheduled appointments for current user context
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'doctor') {
    $doctor_id = db_select("SELECT id FROM staff WHERE user_id = '$user_id'")[0]['id'];
    $appointments = db_select("
        SELECT a.*, p.first_name, p.last_name, t.meeting_link 
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        LEFT JOIN telemedicine_sessions t ON a.id = t.appointment_id
        WHERE a.doctor_id = '$doctor_id' AND a.status = 'scheduled'
        ORDER BY a.appointment_time ASC
    ");
} elseif ($role === 'patient') {
    $patient_id = db_select("SELECT id FROM patients WHERE user_id = '$user_id'")[0]['id'];
    $appointments = db_select("
        SELECT a.*, s.first_name, s.last_name, t.meeting_link 
        FROM appointments a 
        JOIN staff s ON a.doctor_id = s.id 
        LEFT JOIN telemedicine_sessions t ON a.id = t.appointment_id
        WHERE a.patient_id = '$patient_id' AND a.status = 'scheduled'
        ORDER BY a.appointment_time ASC
    ");
} else {
    // Admin sees all
    $appointments = db_select("
        SELECT a.*, p.first_name as p_name, s.first_name as d_name, t.meeting_link 
        FROM appointments a 
        JOIN patients p ON a.patient_id = p.id 
        JOIN staff s ON a.doctor_id = s.id
        LEFT JOIN telemedicine_sessions t ON a.id = t.appointment_id
        WHERE a.status = 'scheduled'
        ORDER BY a.appointment_time ASC
    ");
}
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-video"></i> Telemedicine Console</h1>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Upcoming Virtual Consultations</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>With</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $appt): ?>
                    <tr>
                        <td><?php echo date('M d, H:i', strtotime($appt['appointment_time'])); ?></td>
                        <td>
                            <?php 
                                if ($role === 'doctor') echo $appt['first_name'] . ' ' . $appt['last_name'];
                                elseif ($role === 'patient') echo 'Dr. ' . $appt['first_name'] . ' ' . $appt['last_name'];
                                else echo $appt['p_name'] . ' (Pt) with Dr. ' . $appt['d_name'];
                            ?>
                        </td>
                        <td>
                            <?php if ($appt['meeting_link']): ?>
                                <span class="badge badge-success">Ready</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Pending Link</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($appt['meeting_link']): ?>
                                <a href="<?php echo $appt['meeting_link']; ?>" target="_blank" class="btn-primary">
                                    <i class="fas fa-video"></i> Join Call
                                </a>
                            <?php elseif ($role === 'doctor' || $role === 'admin'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['id']; ?>">
                                    <button type="submit" name="create_session" class="btn-secondary">Generate Link</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted">Wait for Doctor</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
