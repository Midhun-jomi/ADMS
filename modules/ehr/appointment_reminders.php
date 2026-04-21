<?php
// modules/ehr/appointment_reminders.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'receptionist']);

$user_id = get_user_id();
$error   = '';
$success = '';

// ---------------------------------------------------------------------------
// Ensure tables exist (graceful — won't fail if already present)
// ---------------------------------------------------------------------------
db_query("CREATE TABLE IF NOT EXISTS appointment_reminders (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    appointment_id UUID REFERENCES appointments(id) ON DELETE CASCADE,
    sent_to_email  VARCHAR(255),
    sent_at        TIMESTAMPTZ,
    status         VARCHAR(20) DEFAULT 'sent',
    method         VARCHAR(20) DEFAULT 'email',
    created_at     TIMESTAMPTZ DEFAULT now()
)", []);

db_query("CREATE TABLE IF NOT EXISTS reminder_settings (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    setting_key   VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at    TIMESTAMPTZ DEFAULT now()
)", []);

// ---------------------------------------------------------------------------
// Helper: load a reminder setting (with default)
// ---------------------------------------------------------------------------
function get_setting(string $key, string $default = ''): string {
    $row = db_select_one("SELECT setting_value FROM reminder_settings WHERE setting_key = $1", [$key]);
    return $row ? $row['setting_value'] : $default;
}

// ---------------------------------------------------------------------------
// Default template & lead time
// ---------------------------------------------------------------------------
$default_template = "Dear {patient_name},\n\nThis is a friendly reminder that you have an appointment with {doctor_name} on {appointment_time}.\n\nPlease arrive 10 minutes early.\n\nRegards,\n{hospital_name}";
$lead_time        = get_setting('reminder_lead_time', '24');
$email_template   = get_setting('reminder_template', $default_template);

// ---------------------------------------------------------------------------
// POST — Save Settings
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token. Please refresh and try again.";
    } else {
        $new_lead   = in_array($_POST['lead_time'] ?? '', ['1','24','48']) ? $_POST['lead_time'] : '24';
        $new_tmpl   = trim($_POST['email_template'] ?? '');
        if (empty($new_tmpl)) $new_tmpl = $default_template;

        $upsert = "INSERT INTO reminder_settings (setting_key, setting_value, updated_at)
                   VALUES ($1, $2, now())
                   ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()";
        db_query($upsert, ['reminder_lead_time', $new_lead]);
        db_query($upsert, ['reminder_template',  $new_tmpl]);

        $lead_time     = $new_lead;
        $email_template = $new_tmpl;
        $success = "Settings saved successfully.";
    }
}

// ---------------------------------------------------------------------------
// Helper: build + send reminder email via PHP mail()
// ---------------------------------------------------------------------------
function send_reminder_email(array $appt, string $to_email, string $template): array {
    $hospital_name   = 'ADMS Hospital';
    $patient_name    = htmlspecialchars($appt['pat_first'] . ' ' . $appt['pat_last']);
    $doctor_name     = 'Dr. ' . htmlspecialchars($appt['doc_first'] . ' ' . $appt['doc_last']);
    $appt_time_fmt   = date('D, d M Y \a\t h:i A', strtotime($appt['appointment_time']));

    $body_plain = str_replace(
        ['{patient_name}', '{doctor_name}', '{appointment_time}', '{hospital_name}'],
        [$patient_name,    $doctor_name,    $appt_time_fmt,       $hospital_name],
        $template
    );

    $body_html = '<!DOCTYPE html><html><body style="font-family:Inter,sans-serif;background:#f8fafc;margin:0;padding:20px;">'
        . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;">'
        . '<div style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:28px 32px;">'
        . '<h2 style="color:#fff;margin:0;font-size:1.3rem;">&#128197; Appointment Reminder</h2>'
        . '<p style="color:#bfdbfe;margin:4px 0 0;">' . htmlspecialchars($hospital_name) . '</p>'
        . '</div>'
        . '<div style="padding:28px 32px;">'
        . '<p style="color:#374151;line-height:1.7;">' . nl2br(htmlspecialchars($body_plain)) . '</p>'
        . '<div style="background:#eff6ff;border-left:4px solid #2563eb;border-radius:6px;padding:16px 20px;margin:20px 0;">'
        . '<p style="margin:0;color:#1e40af;font-weight:600;font-size:1.05rem;">&#128344; ' . $appt_time_fmt . '</p>'
        . '<p style="margin:4px 0 0;color:#3b82f6;">Appointment with ' . $doctor_name . '</p>'
        . '</div>'
        . '<p style="color:#9ca3af;font-size:0.85rem;margin-top:24px;">If you need to reschedule, please contact us as soon as possible.</p>'
        . '</div>'
        . '<div style="background:#f1f5f9;padding:16px 32px;text-align:center;">'
        . '<p style="margin:0;color:#94a3b8;font-size:0.8rem;">&copy; ' . date('Y') . ' ' . htmlspecialchars($hospital_name) . '. All rights reserved.</p>'
        . '</div>'
        . '</div></body></html>';

    $subject = "Appointment Reminder — " . $appt_time_fmt;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: ADMS Hospital <no-reply@admshospital.in>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $sent = @mail($to_email, $subject, $body_html, $headers);

    return [
        'sent'    => $sent,
        'status'  => $sent ? 'sent' : 'failed',
        'to'      => $to_email,
    ];
}

// ---------------------------------------------------------------------------
// POST — Send All Pending Reminders
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_all_pending'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token. Please refresh and try again.";
    } else {
        $interval = intval($lead_time);
        $pending = db_select(
            "SELECT a.id, a.appointment_time, a.reason,
                    p.first_name as pat_first, p.last_name as pat_last,
                    u.email as pat_email,
                    s.first_name as doc_first, s.last_name as doc_last
             FROM appointments a
             JOIN patients p ON a.patient_id = p.id
             JOIN users   u ON p.user_id     = u.id
             JOIN staff   s ON a.doctor_id   = s.id
             LEFT JOIN appointment_reminders ar ON ar.appointment_id = a.id
             WHERE a.status = 'scheduled'
               AND a.appointment_time BETWEEN NOW() AND NOW() + ($1 || ' hours')::INTERVAL
               AND ar.id IS NULL
             ORDER BY a.appointment_time ASC",
            [$interval]
        );

        $sent_count   = 0;
        $failed_count = 0;
        foreach ($pending as $appt) {
            $result = send_reminder_email($appt, $appt['pat_email'], $email_template);
            db_query(
                "INSERT INTO appointment_reminders (appointment_id, sent_to_email, sent_at, status, method)
                 VALUES ($1, $2, now(), $3, 'email')",
                [$appt['id'], $result['to'], $result['status']]
            );
            $result['sent'] ? $sent_count++ : $failed_count++;
        }

        if ($sent_count + $failed_count === 0) {
            $success = "No pending reminders found for the next {$interval} hours.";
        } else {
            $success = "Batch complete — {$sent_count} sent, {$failed_count} failed.";
        }
    }
}

// ---------------------------------------------------------------------------
// POST — Send Individual Reminder
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_single'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token. Please refresh and try again.";
    } else {
        $appt_id = trim($_POST['appointment_id'] ?? '');
        if (!preg_match('/^[a-f0-9\-]{36}$/', $appt_id)) {
            $error = "Invalid appointment ID.";
        } else {
            $appt = db_select_one(
                "SELECT a.id, a.appointment_time, a.reason,
                        p.first_name as pat_first, p.last_name as pat_last,
                        u.email as pat_email,
                        s.first_name as doc_first, s.last_name as doc_last
                 FROM appointments a
                 JOIN patients p ON a.patient_id = p.id
                 JOIN users   u ON p.user_id     = u.id
                 JOIN staff   s ON a.doctor_id   = s.id
                 WHERE a.id = $1",
                [$appt_id]
            );

            if (!$appt) {
                $error = "Appointment not found.";
            } else {
                $result = send_reminder_email($appt, $appt['pat_email'], $email_template);
                db_query(
                    "INSERT INTO appointment_reminders (appointment_id, sent_to_email, sent_at, status, method)
                     VALUES ($1, $2, now(), $3, 'email')",
                    [$appt_id, $result['to'], $result['status']]
                );
                $success = $result['sent']
                    ? "Reminder sent to " . htmlspecialchars($result['to']) . "."
                    : "Failed to send reminder to " . htmlspecialchars($result['to']) . ". Check server mail config.";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// POST — Manual Reminder
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_manual'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token. Please refresh and try again.";
    } else {
        $manual_email   = trim($_POST['manual_email'] ?? '');
        $manual_message = trim($_POST['manual_message'] ?? '');

        if (!filter_var($manual_email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (empty($manual_message)) {
            $error = "Message cannot be empty.";
        } else {
            $subject   = "Appointment Reminder — ADMS Hospital";
            $body_html = '<!DOCTYPE html><html><body style="font-family:Inter,sans-serif;background:#f8fafc;margin:0;padding:20px;">'
                . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;">'
                . '<div style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:28px 32px;">'
                . '<h2 style="color:#fff;margin:0;">&#128197; Appointment Reminder</h2>'
                . '<p style="color:#bfdbfe;margin:4px 0 0;">ADMS Hospital</p>'
                . '</div>'
                . '<div style="padding:28px 32px;">'
                . '<p style="color:#374151;line-height:1.7;">' . nl2br(htmlspecialchars($manual_message)) . '</p>'
                . '</div>'
                . '<div style="background:#f1f5f9;padding:16px 32px;text-align:center;">'
                . '<p style="margin:0;color:#94a3b8;font-size:0.8rem;">&copy; ' . date('Y') . ' ADMS Hospital. All rights reserved.</p>'
                . '</div>'
                . '</div></body></html>';

            $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: ADMS Hospital <no-reply@admshospital.in>\r\n";
            $sent = @mail($manual_email, $subject, $body_html, $headers);

            db_query(
                "INSERT INTO appointment_reminders (sent_to_email, sent_at, status, method)
                 VALUES ($1, now(), $2, 'email')",
                [$manual_email, $sent ? 'sent' : 'failed']
            );

            $success = $sent
                ? "Manual reminder sent to " . htmlspecialchars($manual_email) . "."
                : "Failed to send to " . htmlspecialchars($manual_email) . ". Check server mail config.";
        }
    }
}

// ---------------------------------------------------------------------------
// Data: Stats
// ---------------------------------------------------------------------------
$stats_sent_today = db_select_one(
    "SELECT COUNT(*) as cnt FROM appointment_reminders WHERE status='sent' AND sent_at::date = CURRENT_DATE",
    []
)['cnt'] ?? 0;

$stats_failed = db_select_one(
    "SELECT COUNT(*) as cnt FROM appointment_reminders WHERE status='failed'",
    []
)['cnt'] ?? 0;

$interval_val = intval($lead_time);
$stats_pending = db_select_one(
    "SELECT COUNT(*) as cnt
     FROM appointments a
     LEFT JOIN appointment_reminders ar ON ar.appointment_id = a.id
     WHERE a.status = 'scheduled'
       AND a.appointment_time BETWEEN NOW() AND NOW() + ($1 || ' hours')::INTERVAL
       AND ar.id IS NULL",
    [$interval_val]
)['cnt'] ?? 0;

// ---------------------------------------------------------------------------
// Data: Upcoming appointments needing attention
// ---------------------------------------------------------------------------
$upcoming = db_select(
    "SELECT a.id, a.appointment_time, a.reason,
            p.first_name as pat_first, p.last_name as pat_last,
            u.email as pat_email,
            s.first_name as doc_first, s.last_name as doc_last,
            ar.status as reminder_status,
            ar.sent_at as reminder_sent_at
     FROM appointments a
     JOIN patients p ON a.patient_id = p.id
     JOIN users   u ON p.user_id     = u.id
     JOIN staff   s ON a.doctor_id   = s.id
     LEFT JOIN LATERAL (
         SELECT status, sent_at FROM appointment_reminders
         WHERE appointment_id = a.id
         ORDER BY created_at DESC LIMIT 1
     ) ar ON TRUE
     WHERE a.status = 'scheduled'
       AND a.appointment_time BETWEEN NOW() AND NOW() + INTERVAL '48 hours'
     ORDER BY a.appointment_time ASC",
    []
);

// ---------------------------------------------------------------------------
// Data: Reminder log (last 100)
// ---------------------------------------------------------------------------
$reminder_log = db_select(
    "SELECT ar.*,
            p.first_name as pat_first, p.last_name as pat_last,
            a.appointment_time
     FROM appointment_reminders ar
     LEFT JOIN appointments a ON ar.appointment_id = a.id
     LEFT JOIN patients     p ON a.patient_id      = p.id
     ORDER BY ar.created_at DESC
     LIMIT 100",
    []
);

$page_title = "Appointment Reminders";
include '../../includes/header.php';
?>

<style>
    .reminder-stat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 18px;
        margin-bottom: 28px;
    }
    .reminder-stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 22px 24px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,.06);
        border: 1px solid #f3f4f6;
    }
    .reminder-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .reminder-stat-icon.blue   { background: #eff6ff; color: #2563eb; }
    .reminder-stat-icon.amber  { background: #fffbeb; color: #d97706; }
    .reminder-stat-icon.red    { background: #fef2f2; color: #dc2626; }
    .reminder-stat-info h3 { margin: 0; font-size: 1.75rem; font-weight: 700; color: #1e293b; }
    .reminder-stat-info p  { margin: 2px 0 0; font-size: 0.85rem; color: #64748b; }

    .section-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.06);
        border: 1px solid #f3f4f6;
        margin-bottom: 24px;
        overflow: hidden;
    }
    .section-card-header {
        padding: 18px 24px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
    }
    .section-card-header h5 {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        color: #1e293b;
    }
    .section-card-body { padding: 20px 24px; }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
    }
    .status-badge.sent     { background: #dcfce7; color: #15803d; }
    .status-badge.not-sent { background: #fef9c3; color: #a16207; }
    .status-badge.failed   { background: #fee2e2; color: #b91c1c; }

    .settings-grid {
        display: grid;
        grid-template-columns: 1fr 2fr;
        gap: 24px;
        align-items: start;
    }
    @media (max-width: 768px) {
        .settings-grid { grid-template-columns: 1fr; }
    }

    .placeholder-hint {
        background: #f1f5f9;
        border-radius: 8px;
        padding: 12px 14px;
        font-size: 0.82rem;
        color: #475569;
        line-height: 1.7;
    }
    .placeholder-hint code {
        background: #e2e8f0;
        border-radius: 4px;
        padding: 1px 5px;
        font-size: 0.8rem;
        color: #1e40af;
    }
</style>

<main style="padding: 28px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
        <div>
            <h2 style="margin:0;font-size:1.5rem;font-weight:700;color:#1e293b;">
                <i class="fas fa-bell" style="color:#2563eb;margin-right:10px;"></i>Appointment Reminders
            </h2>
            <p style="margin:4px 0 0;color:#64748b;font-size:0.9rem;">Send email reminders for upcoming appointments.</p>
        </div>
        <form method="POST" style="display:inline;">
            <?php echo csrf_input(); ?>
            <button type="submit" name="send_all_pending" class="btn btn-primary"
                    onclick="return confirm('Send reminders to all pending appointments in the next <?php echo htmlspecialchars($lead_time); ?> hours?')">
                <i class="fas fa-paper-plane"></i> Send All Pending
            </button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:18px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:18px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="reminder-stat-grid">
        <div class="reminder-stat-card">
            <div class="reminder-stat-icon blue"><i class="fas fa-check-circle"></i></div>
            <div class="reminder-stat-info">
                <h3><?php echo (int)$stats_sent_today; ?></h3>
                <p>Reminders Sent Today</p>
            </div>
        </div>
        <div class="reminder-stat-card">
            <div class="reminder-stat-icon amber"><i class="fas fa-clock"></i></div>
            <div class="reminder-stat-info">
                <h3><?php echo (int)$stats_pending; ?></h3>
                <p>Pending (next <?php echo htmlspecialchars($lead_time); ?>h)</p>
            </div>
        </div>
        <div class="reminder-stat-card">
            <div class="reminder-stat-icon red"><i class="fas fa-times-circle"></i></div>
            <div class="reminder-stat-info">
                <h3><?php echo (int)$stats_failed; ?></h3>
                <p>Failed (all time)</p>
            </div>
        </div>
    </div>

    <!-- Upcoming Appointments -->
    <div class="section-card">
        <div class="section-card-header">
            <h5><i class="fas fa-calendar-alt" style="color:#2563eb;margin-right:8px;"></i>Upcoming Appointments (Next 48 Hours)</h5>
            <span class="badge" style="background:#eff6ff;color:#2563eb;border-radius:20px;padding:4px 12px;font-size:0.82rem;">
                <?php echo count($upcoming); ?> appointments
            </span>
        </div>
        <div class="section-card-body" style="padding:0;">
            <?php if (empty($upcoming)): ?>
                <div style="padding:40px;text-align:center;color:#94a3b8;">
                    <i class="fas fa-calendar-check fa-2x" style="margin-bottom:12px;opacity:.5;"></i>
                    <p style="margin:0;">No upcoming appointments in the next 48 hours.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" id="tbl-upcoming">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Email</th>
                                <th>Doctor</th>
                                <th>Appointment Time</th>
                                <th>Reminder Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming as $appt): ?>
                                <?php
                                    $rs = $appt['reminder_status'] ?? null;
                                    if ($rs === 'sent') {
                                        $badge_class = 'sent';
                                        $badge_label = '<i class="fas fa-check"></i> Sent';
                                    } elseif ($rs === 'failed') {
                                        $badge_class = 'failed';
                                        $badge_label = '<i class="fas fa-times"></i> Failed';
                                    } else {
                                        $badge_class = 'not-sent';
                                        $badge_label = '<i class="fas fa-clock"></i> Not Sent';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;color:#1e293b;">
                                            <?php echo htmlspecialchars($appt['pat_first'] . ' ' . $appt['pat_last']); ?>
                                        </div>
                                    </td>
                                    <td style="color:#64748b;font-size:0.88rem;">
                                        <?php echo htmlspecialchars($appt['pat_email']); ?>
                                    </td>
                                    <td>Dr. <?php echo htmlspecialchars($appt['doc_first'] . ' ' . $appt['doc_last']); ?></td>
                                    <td>
                                        <div style="font-weight:500;">
                                            <?php echo date('d M Y', strtotime($appt['appointment_time'])); ?>
                                        </div>
                                        <div style="font-size:0.82rem;color:#64748b;">
                                            <?php echo date('h:i A', strtotime($appt['appointment_time'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $badge_class; ?>">
                                            <?php echo $badge_label; ?>
                                        </span>
                                        <?php if ($appt['reminder_sent_at']): ?>
                                            <div style="font-size:0.75rem;color:#94a3b8;margin-top:3px;">
                                                <?php echo date('d M, h:i A', strtotime($appt['reminder_sent_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appt['id']); ?>">
                                            <button type="submit" name="send_single" class="btn btn-primary"
                                                    style="padding:6px 14px;font-size:0.82rem;"
                                                    onclick="return confirm('Send reminder to <?php echo htmlspecialchars(addslashes($appt['pat_email'])); ?>?')">
                                                <i class="fas fa-paper-plane"></i>
                                                <?php echo $rs ? 'Resend' : 'Send'; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Manual Reminder + Settings (two-column layout) -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">

        <!-- Manual Reminder Form -->
        <div class="section-card" style="margin-bottom:0;">
            <div class="section-card-header">
                <h5><i class="fas fa-envelope" style="color:#7c3aed;margin-right:8px;"></i>Manual Reminder</h5>
            </div>
            <div class="section-card-body">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:0.88rem;font-weight:600;color:#374151;display:block;margin-bottom:6px;">
                            Recipient Email <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="email" name="manual_email" class="form-control"
                               placeholder="patient@example.com" required
                               style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.9rem;box-sizing:border-box;">
                    </div>
                    <div class="form-group" style="margin-bottom:18px;">
                        <label style="font-size:0.88rem;font-weight:600;color:#374151;display:block;margin-bottom:6px;">
                            Message <span style="color:#dc2626;">*</span>
                        </label>
                        <textarea name="manual_message" rows="5" class="form-control" required
                                  placeholder="Type your reminder message here..."
                                  style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.9rem;box-sizing:border-box;resize:vertical;"></textarea>
                    </div>
                    <button type="submit" name="send_manual" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-paper-plane"></i> Send Manual Reminder
                    </button>
                </form>
            </div>
        </div>

        <!-- Settings Panel -->
        <div class="section-card" style="margin-bottom:0;">
            <div class="section-card-header">
                <h5><i class="fas fa-cog" style="color:#64748b;margin-right:8px;"></i>Reminder Settings</h5>
            </div>
            <div class="section-card-body">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:0.88rem;font-weight:600;color:#374151;display:block;margin-bottom:6px;">
                            Send Reminder Before Appointment
                        </label>
                        <select name="lead_time" class="form-control"
                                style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.9rem;">
                            <?php foreach (['1' => '1 Hour', '24' => '24 Hours', '48' => '48 Hours'] as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php if ($lead_time == $val) echo 'selected'; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:8px;">
                        <label style="font-size:0.88rem;font-weight:600;color:#374151;display:block;margin-bottom:6px;">
                            Email Template
                        </label>
                        <textarea name="email_template" rows="7" class="form-control"
                                  style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.88rem;box-sizing:border-box;resize:vertical;font-family:monospace;"
                                  ><?php echo htmlspecialchars($email_template); ?></textarea>
                    </div>
                    <div class="placeholder-hint" style="margin-bottom:14px;">
                        Available placeholders:
                        <code>{patient_name}</code> <code>{doctor_name}</code>
                        <code>{appointment_time}</code> <code>{hospital_name}</code>
                    </div>
                    <button type="submit" name="save_settings" class="btn btn-primary" style="width:100%;">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Reminder Log -->
    <div class="section-card">
        <div class="section-card-header">
            <h5><i class="fas fa-history" style="color:#2563eb;margin-right:8px;"></i>Reminder Log</h5>
            <input type="text" id="filter-log" onkeyup="filterTable('filter-log','tbl-log')"
                   placeholder="Search log..."
                   style="padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:0.85rem;width:220px;">
        </div>
        <div class="section-card-body" style="padding:0;">
            <?php if (empty($reminder_log)): ?>
                <div style="padding:40px;text-align:center;color:#94a3b8;">
                    <i class="fas fa-inbox fa-2x" style="margin-bottom:12px;opacity:.5;"></i>
                    <p style="margin:0;">No reminders logged yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" id="tbl-log">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Appointment Time</th>
                                <th>Email Sent To</th>
                                <th>Sent At</th>
                                <th>Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reminder_log as $log): ?>
                                <tr>
                                    <td>
                                        <?php if ($log['pat_first']): ?>
                                            <?php echo htmlspecialchars($log['pat_first'] . ' ' . $log['pat_last']); ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-style:italic;">Manual</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['appointment_time']): ?>
                                            <?php echo date('d M Y, h:i A', strtotime($log['appointment_time'])); ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:#3b82f6;"><?php echo htmlspecialchars($log['sent_to_email']); ?></td>
                                    <td style="color:#64748b;font-size:0.88rem;">
                                        <?php echo $log['sent_at'] ? date('d M Y, h:i A', strtotime($log['sent_at'])) : '—'; ?>
                                    </td>
                                    <td>
                                        <?php $method = $log['method'] ?? 'email'; ?>
                                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.85rem;color:#64748b;">
                                            <i class="fas <?php echo $method === 'sms' ? 'fa-sms' : 'fa-envelope'; ?>"></i>
                                            <?php echo htmlspecialchars(ucfirst($method)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php $st = $log['status'] ?? 'unknown'; ?>
                                        <span class="status-badge <?php echo $st === 'sent' ? 'sent' : ($st === 'failed' ? 'failed' : 'not-sent'); ?>">
                                            <?php if ($st === 'sent'): ?>
                                                <i class="fas fa-check"></i> Sent
                                            <?php elseif ($st === 'failed'): ?>
                                                <i class="fas fa-times"></i> Failed
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(ucfirst($st)); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php include '../../includes/footer.php'; ?>
