<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
require_once '../../includes/header.php';

$room_name = $_GET['room'] ?? '';
$error = '';

if (empty($room_name)) {
    $error = "Invalid meeting room.";
}

// Get User Details for Jitsi Display Name
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$display_name = 'User';
$email = $_SESSION['email'];

if ($role === 'doctor') {
    $d = db_select_one("SELECT first_name, last_name FROM staff WHERE user_id = $1", [$user_id]);
    if ($d) $display_name = "Dr. " . $d['first_name'] . " " . $d['last_name'];
} elseif ($role === 'patient') {
    $p = db_select_one("SELECT first_name, last_name FROM patients WHERE user_id = $1", [$user_id]);
    if ($p) $display_name = $p['first_name'] . " " . $p['last_name'];
} else {
    $display_name = "Admin (" . explode('@', $email)[0] . ")";
}
?>

<div class="main-content">
    <div style="margin-bottom: 20px;">
        <a href="<?php echo BASE_URL; ?>/modules/telemedicine/dashboard.php" class="btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Console
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php else: ?>
        <div class="card" style="padding: 0; overflow: hidden; height: 80vh;">
            <div id="meet" style="width: 100%; height: 100%;"></div>
        </div>
        
        <div class="alert alert-info" style="margin-top: 15px;">
            <i class="fas fa-info-circle"></i> 
            <strong>Note:</strong> If you see "Waiting for moderator", please click the 
            <strong>"I am the host"</strong> button in the video window and log in with your Google/GitHub account to start the meeting.
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
        configOverwrite: { 
            startWithAudioMuted: true,
            startWithVideoMuted: true
        },
        interfaceConfigOverwrite: { 
            SHOW_JITSI_WATERMARK: false 
        }
    };
    const api = new JitsiMeetExternalAPI(domain, options);
</script>

<?php require_once '../../includes/footer.php'; ?>
