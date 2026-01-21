<?php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_role(['patient']);

$page_title = "Patient Dashboard";
include '../includes/header.php';

$user_id = get_user_id();
$patient = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
$patient_id = $patient['id'];

// 1. Upcoming Visits (Scheduled, Future)
$upcoming_count = db_select_one("SELECT COUNT(*) as c FROM appointments 
                                 WHERE patient_id = $1 AND appointment_time > NOW() AND status = 'scheduled'", 
                                 [$patient_id])['c'];

// Get Next Appointment for Wait Time
// Get Next Appointment for Wait Time
require_once '../includes/queue_logic.php';
$next_appt = db_select_one("SELECT id, status, appointment_time FROM appointments 
                            WHERE patient_id = $1 
                            AND appointment_time >= CURRENT_DATE 
                            AND status IN ('scheduled', 'waiting', 'ready') 
                            ORDER BY appointment_time ASC LIMIT 1", [$patient_id]);
$wait_time_display = ''; // Legacy variable if needed elsewhere

// 2. Active Prescriptions (Total for now, as schema lacks status)
$rx_count = db_select_one("SELECT COUNT(*) as c FROM prescriptions WHERE patient_id = $1", [$patient_id])['c'];

// 3. Past Visits (Completed or Past)
$past_count = db_select_one("SELECT COUNT(*) as c FROM appointments 
                             WHERE patient_id = $1 AND (status = 'completed' OR (appointment_time < NOW() AND status != 'cancelled'))", 
                             [$patient_id])['c'];

// 4. Pending Bills (Count of unpaid invoices)
$pending_bills_count = db_select_one("SELECT COUNT(*) as c FROM billing WHERE patient_id = $1 AND status = 'pending'", [$patient_id])['c'];

// Prepare Top Bar Wait Time
$wait_banner = "";
if ($next_appt) {
    $mins = get_estimated_wait_time($next_appt['id']);
    
    // Calculate total delay (Time already passed + Future wait)
    $appt_time = strtotime($next_appt['appointment_time']);
    $now = time();
    $past_due_mins = 0;
    
    if ($now > $appt_time) {
        $past_due_mins = round(($now - $appt_time) / 60);
    }
    
    $total_delay_metric = $mins + $past_due_mins;
    $status_detail = "Waiting for Doctor";
    $status_icon = "fa-user-md";
    
    // Status Logic
    switch($next_appt['status']) {
        case 'scheduled':
            $status_detail = "Scheduled - Please Check-in at Reception";
            $status_icon = "fa-calendar-check";
            break;
        case 'waiting':
            $status_detail = "Checked In - Waiting for Nurse (Vitals Pending)";
            $status_icon = "fa-user-nurse";
            break;
        case 'ready':
            $status_detail = "Vitals Completed - Waiting for Doctor";
            $status_icon = "fa-stethoscope";
            break;
    }

    // Color Logic
    $bg_color = '#d1fae5'; // Green-100
    $text_color = '#065f46'; // Green-800
    $icon_color = '#059669'; // Green-600
    $msg = "On Time";
    
    // Using 10 mins as threshold since user complained about 11 mins
    if ($total_delay_metric > 10) {
        $bg_color = '#fef3c7'; // Yellow-100
        $text_color = '#92400e'; // Yellow-800
        $icon_color = '#d97706'; // Yellow-600
        $msg = "Delayed";
    }
    if ($total_delay_metric > 15) {
        $bg_color = '#fee2e2'; // Red-100
        $text_color = '#b91c1c'; // Red-800
        $icon_color = '#dc2626'; // Red-600
        $msg = "Heavy Delay";
    }

    $wait_banner = "
    <div style='background: $bg_color; border-left: 5px solid $icon_color; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px rgba(0,0,0,0.05);'>
        <div style='display: flex; align-items: center; gap: 15px;'>
            <div style='background: white; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: $icon_color; box-shadow: 0 2px 5px rgba(0,0,0,0.05);'>
                <i class='fas fa-hourglass-half'></i>
            </div>
            <div>
                <h4 style='margin: 0; color: $text_color; font-size: 1.1rem;'>Estimated Wait Time</h4>
                <p style='margin: 5px 0 0;'>
                    <span style='background: white; padding: 4px 12px; border-radius: 20px; color: {$text_color}; font-size: 0.9em; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: inline-block;'>
                        <i class='fas {$status_icon}' style='margin-right:5px;'></i> {$status_detail}
                    </span>
                </p>
            </div>
        </div>
        <div style='text-align: right;'>
            <div style='font-size: 2rem; font-weight: 800; color: $icon_color; line-height: 1;'>
                {$mins} <span style='font-size: 1rem; font-weight: 600;'>min</span>
            </div>
            <span style='background: white; padding: 2px 8px; border-radius: 10px; font-size: 0.75em; font-weight: 700; color: $icon_color; text-transform: uppercase; letter-spacing: 0.5px;'>$msg</span>
        </div>
    </div>
    ";
}
?>

<?php echo $wait_banner; ?>

<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        border-radius: 16px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 160px;
        position: relative;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border: none;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.05);
    }
    .stat-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 20px;
    }
    .stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    .stat-value {
        font-size: 36px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #1e293b;
    }
    .stat-label {
        font-size: 14px;
        color: #64748b;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .stat-badge {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    /* Card Variants */
    .card-purple { background-color: #EADCF8; }
    .card-purple .stat-icon { background-color: rgba(255,255,255,0.6); color: #7E22CE; }
    .card-purple .stat-badge { background-color: rgba(255,255,255,0.6); color: #6B21A8; }
    
    .card-blue { background-color: #D6E4FF; }
    .card-blue .stat-icon { background-color: rgba(255,255,255,0.6); color: #2563EB; }
    .card-blue .stat-badge { background-color: rgba(255,255,255,0.6); color: #1E40AF; }
    
    .card-yellow { background-color: #FEF9C3; }
    .card-yellow .stat-icon { background-color: rgba(255,255,255,0.6); color: #CA8A04; }
    .card-yellow .stat-badge { background-color: rgba(255,255,255,0.6); color: #854D0E; }
    
    .card-gray { background-color: #F1F5F9; }
    .card-gray .stat-icon { background-color: rgba(255,255,255,0.6); color: #475569; }
    .card-gray .stat-badge { background-color: rgba(255,255,255,0.6); color: #334155; }
</style>

<div class="dashboard-stats">
    <!-- 1. Upcoming Visits -->
    <a href="/modules/ehr/appointments.php" class="stat-card card-purple" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">📅</div>
            <div style="color: #6B21A8;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $upcoming_count; ?></div>
            <div class="stat-label">
                Upcoming Visits <span class="stat-badge">Next</span>
            </div>
            <div style="font-size: 12px; color: #6B21A8; margin-top: 4px;">Scheduled appointments</div>
        </div>
    </a>

    <!-- 2. Prescriptions -->
    <a href="/modules/ehr/prescriptions.php" class="stat-card card-blue" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">💊</div>
            <div style="color: #1E40AF;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $rx_count; ?></div>
            <div class="stat-label">
                Prescriptions <span class="stat-badge">Active</span>
            </div>
            <div style="font-size: 12px; color: #1E40AF; margin-top: 4px;">Active medications</div>
        </div>
    </a>

    <!-- 3. Past Visits -->
    <a href="/modules/ehr/appointments.php" class="stat-card card-yellow" style="text-decoration: none; color: inherit;">
        <div class="stat-card-header">
            <div class="stat-icon">📁</div>
            <div style="color: #854D0E;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $past_count; ?></div>
            <div class="stat-label">
                Past Visits <span class="stat-badge">Total</span>
            </div>
            <div style="font-size: 12px; color: #854D0E; margin-top: 4px;">Completed appointments</div>
        </div>
    </a>

    <!-- 4. Pending Bills -->
    <a href="/modules/billing/invoices.php" class="stat-card" style="text-decoration: none; color: inherit; background-color: #fee2e2;">
        <div class="stat-card-header">
            <div class="stat-icon" style="color: #dc2626; background: rgba(255,255,255,0.6);">💳</div>
            <div style="color: #991b1b;">...</div>
        </div>
        <div>
            <div class="stat-value"><?php echo $pending_bills_count; ?></div>
            <div class="stat-label">
                Pending Bills <span class="stat-badge" style="background: rgba(255,255,255,0.6); color: #7f1d1d;">Due</span>
            </div>
            <div style="font-size: 12px; color: #991b1b; margin-top: 4px;">Unpaid invoices</div>
        </div>
    </a>
    </div>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        My Health Overview
        <a href="/modules/ehr/edit_profile.php" class="btn-sm btn-primary" style="text-decoration: none;">
            <i class="fas fa-user-edit"></i> Edit Profile
        </a>
    </div>
    <div class="card-body">
        <p>Welcome to your health dashboard. Use the quick actions below to manage your care.</p>
    </div>
</div>

<!-- Message Doctor Floating Button -->
<div onclick="openDoctorChat()" style="position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background: #2563eb; color: white; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 24px; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4); cursor: pointer; z-index: 1000; transition: transform 0.2s;">
    <i class="fas fa-comment-medical"></i>
</div>

<!-- Chat Modal -->
<div id="chatModal" class="modal" style="display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(5px);">
    <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 0; border: none; width: 500px; max-width: 95%; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; height: 70vh;">
        
        <!-- Header -->
        <div style="padding: 15px 20px; background: #fff; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 40px; height: 40px; background: #e0f2fe; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #0284c7;">
                    <i class="fas fa-user-md"></i>
                </div>
                <div>
                    <h3 id="chatName" style="margin: 0; font-size: 1.1em;">Dr. Physician</h3>
                    <span style="font-size: 0.8em; color: #2ecc71;"><i class="fas fa-circle" style="font-size: 0.6em;"></i> Online</span>
                </div>
            </div>
            <span class="close" onclick="document.getElementById('chatModal').style.display='none'" style="font-size: 28px; cursor: pointer; color: #aaa;">&times;</span>
        </div>

        <!-- Body -->
        <div id="chatBody" style="flex-grow: 1; padding: 20px; overflow-y: auto; background: #f9f9f9; display: flex; flex-direction: column; gap: 15px;">
            <!-- Messages go here -->
        </div>

        <!-- Footer -->
        <div style="padding: 15px; background: #fff; border-top: 1px solid #eee;">
            <form id="chatForm" onsubmit="sendMessage(event)" style="display: flex; gap: 10px;">
                <input type="hidden" id="chatRecipientId" value="">
                <input type="text" id="chatInput" class="form-control" placeholder="Type a message..." style="border-radius: 25px; padding-left: 20px;" required>
                <button type="submit" class="btn btn-primary" style="border-radius: 50%; width: 45px; height: 45px; display: flex; justify-content: center; align-items: center;">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const currentUserId = <?php echo $user_id; ?>;
let activeDoctorId = null;

function openDoctorChat() {
    fetch('../modules/messaging/api.php?action=get_conversations')
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            document.getElementById('chatModal').style.display = 'block';
            
            if(data.data.length > 0) {
                const doc = data.data[0];
                setupChat(doc.user_id, doc.name);
            } else {
                <?php if($next_appt): 
                       $doc_id_q = db_select_one("SELECT doctor_id FROM appointments WHERE id = $1", [$next_appt['id']]);
                       if($doc_id_q):
                           $doc_user = db_select_one("SELECT user_id FROM staff WHERE id = $1", [$doc_id_q['doctor_id']]);
                           if($doc_user):
                ?>
                    setupChat(<?php echo $doc_user['user_id']; ?>, 'My Doctor');
                <?php else: ?>
                    alert("No doctor assigned or found on upcoming appointment.");
                <?php endif; endif; else: ?>
                    document.getElementById('chatBody').innerHTML = '<div style="text-align:center;color:#888;">No history found. Please book an appointment first.</div>';
                <?php endif; ?>
            }
        }
    });
}

function setupChat(userId, name) {
    activeDoctorId = userId;
    document.getElementById('chatRecipientId').value = userId;
    document.getElementById('chatName').innerText = name;
    loadThread(userId);
    
    if(window.chatInterval) clearInterval(window.chatInterval);
    window.chatInterval = setInterval(() => {
        if(document.getElementById('chatModal').style.display !== 'none') {
            loadThread(userId);
        }
    }, 3000);
}

function loadThread(userId) {
    fetch(`../modules/messaging/api.php?action=get_thread&user_id=${userId}`)
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            const body = document.getElementById('chatBody');
            const isScrolledToBottom = body.scrollHeight - body.scrollTop <= body.clientHeight + 100;
            
            let html = '';
            if(data.data.length === 0) html = '<div style="text-align:center;color:#ccc;margin-top:20px;">Start a conversation...</div>';
            
            data.data.forEach(msg => {
                const isMe = msg.sender_id == currentUserId;
                html += `
                <div style="display: flex; justify-content: ${isMe ? 'flex-end' : 'flex-start'};">
                    <div style="max-width: 70%; padding: 10px 15px; border-radius: 15px; font-size: 0.95em; line-height: 1.4; 
                        ${isMe ? 'background: #2563eb; color: white; border-bottom-right-radius: 2px;' : 'background: #fff; border: 1px solid #eee; color: #333; border-bottom-left-radius: 2px; box-shadow: 0 2px 5px rgba(0,0,0,0.02);'}">
                        ${msg.message_body}
                        <div style="font-size: 0.7em; margin-top: 5px; opacity: 0.7; text-align: right;">
                             ${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                        </div>
                    </div>
                </div>`;
            });
            
            body.innerHTML = html;
            if(isScrolledToBottom) body.scrollTop = body.scrollHeight;
        }
    });
}

function sendMessage(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if(!msg) return;
    
    const recipientId = document.getElementById('chatRecipientId').value;
    
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('recipient_id', recipientId);
    formData.append('message', msg);
    
    fetch('../modules/messaging/api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'success') {
            input.value = '';
            loadThread(recipientId);
        } else {
            alert('Error sending: ' + data.message);
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
