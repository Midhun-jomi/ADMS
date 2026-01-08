<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
require_once '../../includes/header.php';

$room_name = $_GET['room'] ?? '';
$error = '';

// Clean room name
$room_name = str_replace('https://meet.jit.si/', '', $room_name);
$room_name = trim($room_name, '/');

if (empty($room_name)) {
    $error = "Invalid meeting room.";
}

// User Details
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$display_name = 'User';
$email = $_SESSION['email'];

if ($role === 'doctor') {
    $d = db_select_one("SELECT id, first_name, last_name FROM staff WHERE user_id = $1", [$user_id]);
    if ($d) {
        $display_name = "Dr. " . $d['first_name'] . " " . $d['last_name'];
        $staff_id = $d['id'];
    }
} elseif ($role === 'patient') {
    $p = db_select_one("SELECT first_name, last_name FROM patients WHERE user_id = $1", [$user_id]);
    if ($p) $display_name = $p['first_name'] . " " . $p['last_name'];
} else {
    $display_name = "Admin (" . explode('@', $email)[0] . ")";
}

// Fetch Appointment Context for Clinical Tools
$appt = db_select_one("
    SELECT a.* 
    FROM appointments a
    JOIN telemedicine_sessions t ON a.id = t.appointment_id
    WHERE t.meeting_link = $1
", [$room_name]);
$appointment_id = $appt['id'] ?? null;
$patient_id = $appt['patient_id'] ?? null;

$success_msg = '';

// --- BACKEND LOGIC REMOVED ---
// Usage: Clinical actions are now performed via the embedded Consultation Notes below.
?>

<div class="main-content" style="padding: 0; display: flex; flex-direction: column; height: calc(100vh - 80px); overflow: hidden;">
    
    <!-- TOP: VIDEO AREA (60% Height) -->
    <div class="video-section" style="flex: 0 0 60%; position: relative; background: #000; border-bottom: 2px solid #ddd;">
        <?php if ($error): ?>
            <div class="alert alert-danger m-4"><?php echo $error; ?></div>
        <?php else: ?>
            <div id="meet" style="width: 100%; height: 100%;"></div>
        <?php endif; ?>
    </div>

    <!-- BOTTOM: EMBEDDED NOTES (40% Height) -->
    <?php if ($appointment_id): ?>
    <div class="notes-section" style="flex: 1; position: relative; background: #fff;">
        <iframe 
            src="<?php echo BASE_URL; ?>/modules/ehr/visit_notes.php?appointment_id=<?php echo $appointment_id; ?>&embedded=1" 
            style="width: 100%; height: 100%; border: none;"
            title="Consultation Notes">
        </iframe>
    </div>
    <?php endif; ?>

</div>

<script src='https://meet.jit.si/external_api.js'></script>
<script>
    const domain = 'meet.jit.si';
    const options = {
        roomName: '<?php echo htmlspecialchars($room_name); ?>',
        width: '100%',
        height: '100%',
        parentNode: document.querySelector('#meet'),
        userInfo: {
            email: '<?php echo $email; ?>',
            displayName: '<?php echo addslashes($display_name); ?>'
        },
        configOverwrite: { startWithAudioMuted: true, startWithVideoMuted: true },
        interfaceConfigOverwrite: { SHOW_JITSI_WATERMARK: false }
    };
    const api = new JitsiMeetExternalAPI(domain, options);
</script>

<?php require_once '../../includes/footer.php'; ?>
