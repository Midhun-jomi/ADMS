<?php
// modules/patient_management/patient_portal.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['patient']);

$user_id = get_user_id();

// ---------------------------------------------------------------------------
// Resolve patient record
// ---------------------------------------------------------------------------
$patient = db_select_one(
    "SELECT p.*, u.email, p.phone as user_phone
     FROM patients p
     JOIN users u ON p.user_id = u.id
     WHERE p.user_id = $1",
    [$user_id]
);

if (!$patient) {
    $page_title = "My Health Portal";
    include '../../includes/header.php';
    echo '<main style="padding:40px;"><div class="alert alert-danger">'
        . '<i class="fas fa-exclamation-circle"></i> Patient record not found. Please contact administration.</div></main>';
    include '../../includes/footer.php';
    exit();
}

$patient_id = $patient['id'];

// ---------------------------------------------------------------------------
// Health Summary Data
// ---------------------------------------------------------------------------
$last_visit = db_select_one(
    "SELECT appointment_time FROM appointments
     WHERE patient_id = $1 AND status IN ('completed','cancelled')
     ORDER BY appointment_time DESC LIMIT 1",
    [$patient_id]
);

$active_meds_count = db_select_one(
    "SELECT COUNT(*) as cnt FROM prescriptions WHERE patient_id = $1",
    [$patient_id]
);
if (false) {
    $active_meds_count = db_select_one(
        "SELECT COUNT(*) as cnt FROM prescriptions WHERE patient_id = $1",
        [$patient_id]
    );
}

$upcoming_appts_count = db_select_one(
    "SELECT COUNT(*) as cnt FROM appointments
     WHERE patient_id = $1 AND status = 'scheduled' AND appointment_time > NOW()",
    [$patient_id]
);

// ---------------------------------------------------------------------------
// Tab Data Queries
// ---------------------------------------------------------------------------

// My Appointments — upcoming + past
$upcoming_appointments = db_select(
    "SELECT a.id, a.appointment_time, a.status, a.reason,
            s.first_name as doc_first, s.last_name as doc_last, s.specialization
     FROM appointments a
     JOIN staff s ON a.doctor_id = s.id
     WHERE a.patient_id = $1 AND a.appointment_time >= NOW() AND a.status = 'scheduled'
     ORDER BY a.appointment_time ASC",
    [$patient_id]
);

$past_appointments = db_select(
    "SELECT a.id, a.appointment_time, a.status, a.reason,
            s.first_name as doc_first, s.last_name as doc_last, s.specialization
     FROM appointments a
     JOIN staff s ON a.doctor_id = s.id
     WHERE a.patient_id = $1 AND (a.appointment_time < NOW() OR a.status != 'scheduled')
     ORDER BY a.appointment_time DESC
     LIMIT 20",
    [$patient_id]
);

// My Prescriptions
$prescriptions = db_select(
    "SELECT pr.id, pr.created_at, pr.notes,
            s.first_name as doc_first, s.last_name as doc_last
     FROM prescriptions pr
     LEFT JOIN staff s ON pr.doctor_id = s.id
     WHERE pr.patient_id = $1
     ORDER BY pr.created_at DESC",
    [$patient_id]
);

// Prescription items — parsed from medication_details JSON
$prescription_items = [];
foreach ($prescriptions as $rx) {
    $meds = json_decode($rx['medication_details'] ?? '[]', true) ?: [];
    foreach ($meds as $med) {
        $prescription_items[$rx['id']][] = [
            'prescription_id' => $rx['id'],
            'medication_name' => $med['name']      ?? '',
            'dosage'          => $med['dosage']     ?? '',
            'frequency'       => $med['frequency']  ?? '',
            'duration'        => $med['duration']   ?? '',
            'instructions'    => $med['instructions'] ?? '',
        ];
    }
}

// My Lab Results
$lab_results = db_select(
    "SELECT id, result_value, '' AS reference_range, 'completed' AS status,
            notes, test_date AS created_at, test_name, test_date AS ordered_at
     FROM patient_lab_results
     WHERE patient_id = $1
     ORDER BY test_date DESC
     LIMIT 50",
    [$patient_id]
);

// My Bills
$bills = db_select(
    "SELECT id, total_amount, status, created_at, service_description, payment_method
     FROM billing
     WHERE patient_id = $1
     ORDER BY created_at DESC",
    [$patient_id]
);

// My Consent Forms
$consent_forms = db_select(
    "SELECT id, consent_type AS form_type, procedure_name AS description, status, created_at, signed_at
     FROM consent_forms
     WHERE patient_id = $1
     ORDER BY created_at DESC",
    [$patient_id]
);

// My Referrals
$referrals = db_select(
    "SELECT r.id, r.created_at AS referral_date, r.reason, r.status, r.notes,
            s.first_name as ref_by_first, s.last_name as ref_by_last,
            r.referral_type AS referred_to_specialty
     FROM referrals r
     LEFT JOIN staff s ON r.from_doctor_id = s.id
     WHERE r.patient_id = $1
     ORDER BY r.created_at DESC",
    [$patient_id]
);

$page_title = "My Health Portal";
include '../../includes/header.php';
?>

<style>
    /* ===== Health Summary ===== */
    .health-summary {
        background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
        border-radius: 16px;
        padding: 28px 32px;
        color: #fff;
        margin-bottom: 28px;
        display: flex;
        align-items: center;
        gap: 28px;
        flex-wrap: wrap;
    }
    .health-avatar {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: rgba(255,255,255,.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        flex-shrink: 0;
        border: 3px solid rgba(255,255,255,.35);
    }
    .health-info h2 { margin: 0 0 4px; font-size: 1.5rem; font-weight: 700; }
    .health-info p  { margin: 0; font-size: 0.88rem; opacity: .85; }
    .health-stats {
        margin-left: auto;
        display: flex;
        gap: 24px;
        flex-wrap: wrap;
    }
    .health-stat-item {
        text-align: center;
        background: rgba(255,255,255,.15);
        border-radius: 10px;
        padding: 14px 20px;
        min-width: 100px;
        border: 1px solid rgba(255,255,255,.2);
    }
    .health-stat-item .val { font-size: 1.7rem; font-weight: 700; line-height: 1; }
    .health-stat-item .lbl { font-size: 0.75rem; opacity: .85; margin-top: 4px; }
    .blood-group-badge {
        display: inline-block;
        background: #ef4444;
        color: #fff;
        font-size: 1.1rem;
        font-weight: 700;
        padding: 6px 16px;
        border-radius: 20px;
        margin-top: 6px;
    }

    /* ===== Tabs ===== */
    .portal-tabs {
        display: flex;
        gap: 4px;
        background: #f1f5f9;
        border-radius: 12px;
        padding: 5px;
        margin-bottom: 24px;
        overflow-x: auto;
        flex-wrap: nowrap;
    }
    .portal-tab-btn {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border: none;
        border-radius: 8px;
        background: transparent;
        color: #64748b;
        font-size: 0.88rem;
        font-weight: 500;
        cursor: pointer;
        white-space: nowrap;
        transition: all .2s;
    }
    .portal-tab-btn:hover { background: #e2e8f0; color: #1e293b; }
    .portal-tab-btn.active {
        background: #fff;
        color: #2563eb;
        font-weight: 600;
        box-shadow: 0 1px 4px rgba(0,0,0,.1);
    }
    .portal-tab-btn .badge-count {
        background: #2563eb;
        color: #fff;
        border-radius: 20px;
        padding: 1px 7px;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.5;
    }
    .portal-tab-btn.active .badge-count { background: #1d4ed8; }

    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    /* ===== Cards ===== */
    .portal-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.06);
        border: 1px solid #f3f4f6;
        overflow: hidden;
        margin-bottom: 20px;
    }
    .portal-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .portal-card-header h6 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 600;
        color: #1e293b;
    }
    .portal-card-body { padding: 20px; }

    /* ===== Profile Grid ===== */
    .profile-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
    }
    .profile-field label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .05em;
        margin-bottom: 4px;
    }
    .profile-field .val {
        font-size: 0.95rem;
        color: #1e293b;
        font-weight: 500;
    }

    /* ===== Appointment Item ===== */
    .appt-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        border: 1px solid #f1f5f9;
        border-radius: 10px;
        margin-bottom: 10px;
        background: #fafafa;
    }
    .appt-time-block {
        text-align: center;
        min-width: 60px;
        background: #eff6ff;
        border-radius: 8px;
        padding: 8px;
    }
    .appt-time-block .day  { font-size: 1.4rem; font-weight: 700; color: #2563eb; line-height: 1; }
    .appt-time-block .mon  { font-size: 0.72rem; font-weight: 600; color: #3b82f6; text-transform: uppercase; }
    .appt-time-block .time { font-size: 0.72rem; color: #64748b; margin-top: 2px; }
    .appt-info { flex: 1; }
    .appt-info .doc  { font-weight: 600; color: #1e293b; font-size: 0.92rem; }
    .appt-info .spec { font-size: 0.8rem; color: #64748b; }
    .appt-info .rsn  { font-size: 0.82rem; color: #475569; margin-top: 3px; }

    /* ===== Status Badges ===== */
    .sbadge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.76rem;
        font-weight: 600;
    }
    .sbadge.scheduled  { background:#dbeafe;color:#1d4ed8; }
    .sbadge.completed  { background:#dcfce7;color:#15803d; }
    .sbadge.cancelled  { background:#fee2e2;color:#b91c1c; }
    .sbadge.normal     { background:#dcfce7;color:#15803d; }
    .sbadge.abnormal   { background:#fee2e2;color:#b91c1c; }
    .sbadge.pending    { background:#fef9c3;color:#a16207; }
    .sbadge.paid       { background:#dcfce7;color:#15803d; }
    .sbadge.overdue    { background:#fee2e2;color:#b91c1c; }
    .sbadge.signed     { background:#dcfce7;color:#15803d; }
    .sbadge.active     { background:#dbeafe;color:#1d4ed8; }
    .sbadge.void       { background:#f3f4f6;color:#64748b; }

    /* ===== Prescription Card ===== */
    .rx-card {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 14px;
        overflow: hidden;
    }
    .rx-header {
        background: #f8fafc;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #e2e8f0;
    }
    .rx-items { padding: 14px 16px; }
    .rx-item-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr;
        gap: 8px;
        padding: 8px 0;
        border-bottom: 1px dashed #f1f5f9;
        font-size: 0.86rem;
        align-items: center;
    }
    .rx-item-row:last-child { border-bottom: none; }
    .rx-item-row .med-name { font-weight: 600; color: #1e293b; }
    .rx-item-row .meta     { color: #64748b; }

    /* ===== Lab Result Row ===== */
    .lab-result-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr 1fr;
        gap: 8px;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        font-size: 0.88rem;
    }
    .lab-result-grid:last-child { border-bottom: none; }

    /* ===== Bill Row ===== */
    .bill-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        border: 1px solid #f1f5f9;
        border-radius: 10px;
        margin-bottom: 10px;
        background: #fafafa;
    }
    .bill-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .bill-icon.paid    { background:#dcfce7;color:#16a34a; }
    .bill-icon.pending { background:#fef9c3;color:#d97706; }
    .bill-icon.overdue { background:#fee2e2;color:#dc2626; }

    /* ===== Consent Form Row ===== */
    .consent-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 16px;
        border: 1px solid #f1f5f9;
        border-radius: 10px;
        margin-bottom: 10px;
        background: #fafafa;
    }

    /* ===== Referral Row ===== */
    .referral-row {
        padding: 14px 16px;
        border: 1px solid #f1f5f9;
        border-radius: 10px;
        margin-bottom: 10px;
        background: #fafafa;
    }

    /* ===== Empty State ===== */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #94a3b8;
    }
    .empty-state i { font-size: 2.5rem; opacity: .4; margin-bottom: 12px; display: block; }
    .empty-state p { margin: 0; font-size: 0.9rem; }

    @media (max-width: 768px) {
        .health-summary { flex-direction: column; }
        .health-stats   { margin-left: 0; }
        .rx-item-row    { grid-template-columns: 1fr 1fr; }
        .lab-result-grid { grid-template-columns: 1fr 1fr; }
        .portal-tab-btn { padding: 9px 12px; font-size: 0.82rem; }
    }
</style>

<main style="padding: 28px;">

    <!-- Health Summary Card -->
    <div class="health-summary">
        <div class="health-avatar">
            <i class="fas fa-user"></i>
        </div>
        <div class="health-info">
            <h2><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h2>
            <p><i class="fas fa-envelope" style="opacity:.7;margin-right:5px;"></i><?php echo htmlspecialchars($patient['email'] ?? '—'); ?></p>
            <?php if (!empty($patient['blood_group'])): ?>
                <span class="blood-group-badge">
                    <i class="fas fa-tint" style="margin-right:4px;"></i><?php echo htmlspecialchars($patient['blood_group']); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="health-stats">
            <div class="health-stat-item">
                <div class="val"><?php echo (int)($upcoming_appts_count['cnt'] ?? 0); ?></div>
                <div class="lbl">Upcoming Appts</div>
            </div>
            <div class="health-stat-item">
                <div class="val"><?php echo (int)($active_meds_count['cnt'] ?? 0); ?></div>
                <div class="lbl">Active Medications</div>
            </div>
            <div class="health-stat-item">
                <div class="val">
                    <?php echo $last_visit ? date('d M', strtotime($last_visit['appointment_time'])) : '—'; ?>
                </div>
                <div class="lbl">Last Visit</div>
            </div>
            <div class="health-stat-item">
                <div class="val"><?php echo !empty($patient['blood_group']) ? htmlspecialchars($patient['blood_group']) : '—'; ?></div>
                <div class="lbl">Blood Group</div>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <div class="portal-tabs" id="portalTabs">
        <button class="portal-tab-btn active" data-tab="profile">
            <i class="fas fa-id-card"></i> My Profile
        </button>
        <button class="portal-tab-btn" data-tab="appointments">
            <i class="fas fa-calendar-alt"></i> My Appointments
            <?php if (count($upcoming_appointments) > 0): ?>
                <span class="badge-count"><?php echo count($upcoming_appointments); ?></span>
            <?php endif; ?>
        </button>
        <button class="portal-tab-btn" data-tab="prescriptions">
            <i class="fas fa-pills"></i> Prescriptions
            <?php if (count($prescriptions) > 0): ?>
                <span class="badge-count"><?php echo count($prescriptions); ?></span>
            <?php endif; ?>
        </button>
        <button class="portal-tab-btn" data-tab="lab">
            <i class="fas fa-flask"></i> Lab Results
            <?php if (count($lab_results) > 0): ?>
                <span class="badge-count"><?php echo count($lab_results); ?></span>
            <?php endif; ?>
        </button>
        <button class="portal-tab-btn" data-tab="bills">
            <i class="fas fa-file-invoice-dollar"></i> My Bills
            <?php if (count($bills) > 0): ?>
                <span class="badge-count"><?php echo count($bills); ?></span>
            <?php endif; ?>
        </button>
        <button class="portal-tab-btn" data-tab="consent">
            <i class="fas fa-file-signature"></i> Consent Forms
            <?php
                $pending_consents = array_filter($consent_forms, fn($c) => ($c['status'] ?? '') === 'pending');
                if (count($pending_consents) > 0):
            ?>
                <span class="badge-count" style="background:#ef4444;"><?php echo count($pending_consents); ?></span>
            <?php endif; ?>
        </button>
        <button class="portal-tab-btn" data-tab="referrals">
            <i class="fas fa-exchange-alt"></i> Referrals
            <?php if (count($referrals) > 0): ?>
                <span class="badge-count"><?php echo count($referrals); ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 1: MY PROFILE                                                  -->
    <!-- ================================================================= -->
    <div class="tab-pane active" id="tab-profile">
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-id-card" style="color:#2563eb;margin-right:8px;"></i>Personal Information</h6>
                <a href="<?php echo BASE_URL; ?>/modules/ehr/edit_profile.php" class="btn btn-primary" style="padding:7px 16px;font-size:0.85rem;">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
            </div>
            <div class="portal-card-body">
                <div class="profile-grid">
                    <div class="profile-field">
                        <label>Full Name</label>
                        <div class="val"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Date of Birth</label>
                        <div class="val">
                            <?php echo $patient['date_of_birth']
                                ? date('d M Y', strtotime($patient['date_of_birth']))
                                : '<span style="color:#94a3b8;">Not set</span>'; ?>
                        </div>
                    </div>
                    <div class="profile-field">
                        <label>Gender</label>
                        <div class="val"><?php echo htmlspecialchars(ucfirst($patient['gender'] ?? '—')); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Blood Group</label>
                        <div class="val">
                            <?php if (!empty($patient['blood_group'])): ?>
                                <span style="color:#ef4444;font-weight:700;">
                                    <i class="fas fa-tint" style="margin-right:4px;"></i><?php echo htmlspecialchars($patient['blood_group']); ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">Not set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="profile-field">
                        <label>Email Address</label>
                        <div class="val"><?php echo htmlspecialchars($patient['email'] ?? '—'); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Phone Number</label>
                        <div class="val"><?php echo htmlspecialchars($patient['contact_number'] ?? $patient['user_phone'] ?? '—'); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Emergency Contact</label>
                        <div class="val"><?php echo htmlspecialchars($patient['emergency_contact'] ?? '—'); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Allergies</label>
                        <div class="val">
                            <?php echo !empty($patient['allergies'])
                                ? htmlspecialchars($patient['allergies'])
                                : '<span style="color:#15803d;">None recorded</span>'; ?>
                        </div>
                    </div>
                    <div class="profile-field" style="grid-column: 1 / -1;">
                        <label>Address</label>
                        <div class="val"><?php echo htmlspecialchars($patient['address'] ?? '—'); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 2: MY APPOINTMENTS                                             -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-appointments">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
            <h5 style="margin:0;font-size:1rem;font-weight:600;color:#1e293b;">
                <i class="fas fa-calendar-alt" style="color:#2563eb;margin-right:8px;"></i>My Appointments
            </h5>
            <a href="<?php echo BASE_URL; ?>/modules/ehr/book_appointment.php" class="btn btn-primary" style="padding:8px 18px;font-size:0.85rem;">
                <i class="fas fa-plus"></i> Book New Appointment
            </a>
        </div>

        <!-- Upcoming -->
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-clock" style="color:#2563eb;margin-right:8px;"></i>Upcoming Appointments</h6>
                <span style="font-size:0.82rem;color:#64748b;"><?php echo count($upcoming_appointments); ?> scheduled</span>
            </div>
            <div class="portal-card-body">
                <?php if (empty($upcoming_appointments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-check"></i>
                        <p>No upcoming appointments scheduled.</p>
                        <a href="<?php echo BASE_URL; ?>/modules/ehr/book_appointment.php"
                           style="display:inline-block;margin-top:12px;padding:8px 20px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:0.88rem;">
                            <i class="fas fa-plus"></i> Book an Appointment
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_appointments as $appt): ?>
                        <div class="appt-item">
                            <div class="appt-time-block">
                                <div class="day"><?php echo date('d', strtotime($appt['appointment_time'])); ?></div>
                                <div class="mon"><?php echo date('M', strtotime($appt['appointment_time'])); ?></div>
                                <div class="time"><?php echo date('h:i A', strtotime($appt['appointment_time'])); ?></div>
                            </div>
                            <div class="appt-info">
                                <div class="doc">Dr. <?php echo htmlspecialchars($appt['doc_first'] . ' ' . $appt['doc_last']); ?></div>
                                <div class="spec"><?php echo htmlspecialchars($appt['specialization'] ?? ''); ?></div>
                                <?php if (!empty($appt['reason'])): ?>
                                    <div class="rsn"><i class="fas fa-notes-medical" style="margin-right:4px;"></i><?php echo htmlspecialchars($appt['reason']); ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="sbadge scheduled"><i class="fas fa-circle" style="font-size:.5rem;"></i> Scheduled</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Past -->
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-history" style="color:#64748b;margin-right:8px;"></i>Past Appointments</h6>
                <span style="font-size:0.82rem;color:#64748b;">Last 20 records</span>
            </div>
            <div class="portal-card-body">
                <?php if (empty($past_appointments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar"></i>
                        <p>No past appointments found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($past_appointments as $appt): ?>
                        <div class="appt-item">
                            <div class="appt-time-block" style="background:#f1f5f9;">
                                <div class="day" style="color:#64748b;"><?php echo date('d', strtotime($appt['appointment_time'])); ?></div>
                                <div class="mon" style="color:#94a3b8;"><?php echo date('M Y', strtotime($appt['appointment_time'])); ?></div>
                            </div>
                            <div class="appt-info">
                                <div class="doc">Dr. <?php echo htmlspecialchars($appt['doc_first'] . ' ' . $appt['doc_last']); ?></div>
                                <div class="spec"><?php echo htmlspecialchars($appt['specialization'] ?? ''); ?></div>
                                <?php if (!empty($appt['reason'])): ?>
                                    <div class="rsn"><?php echo htmlspecialchars($appt['reason']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php $st = $appt['status'] ?? 'completed'; ?>
                            <span class="sbadge <?php echo htmlspecialchars($st); ?>">
                                <?php echo htmlspecialchars(ucfirst($st)); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 3: MY PRESCRIPTIONS                                            -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-prescriptions">
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-pills" style="color:#7c3aed;margin-right:8px;"></i>My Prescriptions</h6>
                <span style="font-size:0.82rem;color:#64748b;"><?php echo count($prescriptions); ?> records</span>
            </div>
            <div class="portal-card-body">
                <?php if (empty($prescriptions)): ?>
                    <div class="empty-state">
                        <i class="fas fa-prescription-bottle-alt"></i>
                        <p>No prescriptions found in your records.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($prescriptions as $rx): ?>
                        <div class="rx-card">
                            <div class="rx-header">
                                <div>
                                    <span style="font-weight:600;color:#1e293b;">
                                        Dr. <?php echo htmlspecialchars($rx['doc_first'] . ' ' . $rx['doc_last']); ?>
                                    </span>
                                    <span style="color:#64748b;font-size:0.82rem;margin-left:10px;">
                                        <i class="fas fa-calendar" style="margin-right:3px;"></i>
                                        <?php echo date('d M Y', strtotime($rx['created_at'])); ?>
                                    </span>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/modules/ehr/prescriptions.php"
                                   style="font-size:0.82rem;color:#2563eb;text-decoration:none;">
                                    <i class="fas fa-eye"></i> View Full
                                </a>
                            </div>
                            <div class="rx-items">
                                <?php if (!empty($prescription_items[$rx['id']])): ?>
                                    <div class="rx-item-row" style="font-size:0.75rem;color:#94a3b8;font-weight:600;padding-bottom:4px;">
                                        <div>MEDICATION</div><div>DOSAGE</div><div>FREQUENCY</div><div>DURATION</div>
                                    </div>
                                    <?php foreach ($prescription_items[$rx['id']] as $item): ?>
                                        <div class="rx-item-row">
                                            <div class="med-name">
                                                <i class="fas fa-capsules" style="color:#7c3aed;margin-right:5px;font-size:.85rem;"></i>
                                                <?php echo htmlspecialchars($item['medication_name'] ?? '—'); ?>
                                            </div>
                                            <div class="meta"><?php echo htmlspecialchars($item['dosage'] ?? '—'); ?></div>
                                            <div class="meta"><?php echo htmlspecialchars($item['frequency'] ?? '—'); ?></div>
                                            <div class="meta"><?php echo htmlspecialchars($item['duration'] ?? '—'); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="color:#64748b;font-size:0.88rem;padding:6px 0;">
                                        <?php if (!empty($rx['notes'])): ?>
                                            <i class="fas fa-sticky-note" style="margin-right:5px;color:#f59e0b;"></i>
                                            <?php echo htmlspecialchars($rx['notes']); ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;font-style:italic;">No medication details available. <a href="<?php echo BASE_URL; ?>/modules/ehr/prescriptions.php" style="color:#2563eb;">View prescription</a></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 4: MY LAB RESULTS                                              -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-lab">
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-flask" style="color:#059669;margin-right:8px;"></i>My Lab Results</h6>
                <span style="font-size:0.82rem;color:#64748b;"><?php echo count($lab_results); ?> recent results</span>
            </div>
            <div class="portal-card-body">
                <?php if (empty($lab_results)): ?>
                    <div class="empty-state">
                        <i class="fas fa-vial"></i>
                        <p>No lab results found in your records.</p>
                    </div>
                <?php else: ?>
                    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;padding:8px 0;font-size:0.75rem;font-weight:600;color:#94a3b8;text-transform:uppercase;">
                        <div>Test Name</div>
                        <div>Result</div>
                        <div>Reference Range</div>
                        <div>Status</div>
                    </div>
                    <?php foreach ($lab_results as $lr): ?>
                        <?php $lr_status = strtolower($lr['status'] ?? 'normal'); ?>
                        <div class="lab-result-grid">
                            <div>
                                <div style="font-weight:600;color:#1e293b;font-size:0.9rem;">
                                    <?php echo htmlspecialchars($lr['test_name'] ?? '—'); ?>
                                </div>
                                <div style="font-size:0.78rem;color:#94a3b8;">
                                    <?php echo $lr['created_at'] ? date('d M Y', strtotime($lr['created_at'])) : ''; ?>
                                </div>
                            </div>
                            <div style="font-weight:600;color:#1e293b;">
                                <?php echo htmlspecialchars($lr['result_value'] ?? '—'); ?>
                            </div>
                            <div style="color:#64748b;font-size:0.85rem;">
                                <?php echo htmlspecialchars($lr['reference_range'] ?? '—'); ?>
                            </div>
                            <div>
                                <span class="sbadge <?php echo htmlspecialchars($lr_status); ?>">
                                    <?php if ($lr_status === 'normal'): ?>
                                        <i class="fas fa-check"></i> Normal
                                    <?php elseif ($lr_status === 'abnormal'): ?>
                                        <i class="fas fa-exclamation-triangle"></i> Abnormal
                                    <?php else: ?>
                                        <?php echo htmlspecialchars(ucfirst($lr_status)); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 5: MY BILLS                                                    -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-bills">
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-file-invoice-dollar" style="color:#d97706;margin-right:8px;"></i>My Bills &amp; Invoices</h6>
                <a href="<?php echo BASE_URL; ?>/modules/billing/invoices.php" style="font-size:0.82rem;color:#2563eb;text-decoration:none;">
                    <i class="fas fa-external-link-alt"></i> View All in Billing
                </a>
            </div>
            <div class="portal-card-body">
                <?php if (empty($bills)): ?>
                    <div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <p>No invoices found in your account.</p>
                    </div>
                <?php else: ?>
                    <?php
                    $total_due = 0;
                    foreach ($bills as $bill) {
                        if (in_array($bill['status'] ?? '', ['pending', 'overdue'])) {
                            $total_due += floatval($bill['total_amount'] ?? 0);
                        }
                    }
                    if ($total_due > 0):
                    ?>
                        <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                            <i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1.2rem;"></i>
                            <div>
                                <strong style="color:#92400e;">Outstanding Balance: ₹<?php echo number_format($total_due, 2); ?></strong>
                                <p style="margin:2px 0 0;font-size:0.82rem;color:#b45309;">Please settle your pending bills at the billing counter or online.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($bills as $bill): ?>
                        <?php $bs = $bill['status'] ?? 'pending'; ?>
                        <div class="bill-row">
                            <div class="bill-icon <?php echo htmlspecialchars($bs); ?>">
                                <i class="fas <?php echo $bs === 'paid' ? 'fa-check' : ($bs === 'overdue' ? 'fa-exclamation' : 'fa-clock'); ?>"></i>
                            </div>
                            <div style="flex:1;">
                                <div style="font-weight:600;color:#1e293b;font-size:0.92rem;">
                                    <?php echo htmlspecialchars($bill['service_description'] ?? 'Medical Service'); ?>
                                </div>
                                <div style="font-size:0.8rem;color:#94a3b8;margin-top:2px;">
                                    <i class="fas fa-calendar" style="margin-right:4px;"></i>
                                    <?php echo date('d M Y', strtotime($bill['created_at'])); ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:1.1rem;font-weight:700;color:#1e293b;">
                                    ₹<?php echo number_format(floatval($bill['total_amount'] ?? 0), 2); ?>
                                </div>
                                <span class="sbadge <?php echo htmlspecialchars($bs); ?>" style="margin-top:4px;">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $bs))); ?>
                                </span>
                            </div>
                            <?php if ($bs !== 'paid'): ?>
                                <div style="margin-left:8px;">
                                    <a href="<?php echo BASE_URL; ?>/modules/billing/invoices.php?id=<?php echo htmlspecialchars($bill['id']); ?>"
                                       class="btn btn-primary" style="padding:7px 14px;font-size:0.82rem;white-space:nowrap;">
                                        <i class="fas fa-credit-card"></i> Pay
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 6: CONSENT FORMS                                               -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-consent">
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-file-signature" style="color:#7c3aed;margin-right:8px;"></i>Consent Forms</h6>
                <span style="font-size:0.82rem;color:#64748b;">
                    <?php echo count($pending_consents); ?> requiring action
                </span>
            </div>
            <div class="portal-card-body">
                <?php if (empty($consent_forms)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-signature"></i>
                        <p>No consent forms on record.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($consent_forms as $form): ?>
                        <?php $cs = $form['status'] ?? 'pending'; ?>
                        <div class="consent-row">
                            <div style="width:44px;height:44px;border-radius:10px;background:<?php echo $cs === 'signed' ? '#dcfce7' : '#fef9c3'; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas <?php echo $cs === 'signed' ? 'fa-check-circle' : 'fa-file-signature'; ?>"
                                   style="color:<?php echo $cs === 'signed' ? '#16a34a' : '#d97706'; ?>;font-size:1.1rem;"></i>
                            </div>
                            <div style="flex:1;">
                                <div style="font-weight:600;color:#1e293b;font-size:0.92rem;">
                                    <?php echo htmlspecialchars($form['form_type'] ?? 'Consent Form'); ?>
                                </div>
                                <?php if (!empty($form['description'])): ?>
                                    <div style="font-size:0.82rem;color:#64748b;margin-top:2px;">
                                        <?php echo htmlspecialchars($form['description']); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="font-size:0.78rem;color:#94a3b8;margin-top:3px;">
                                    <i class="fas fa-calendar" style="margin-right:3px;"></i>
                                    <?php echo date('d M Y', strtotime($form['created_at'])); ?>
                                    <?php if ($form['signed_at']): ?>
                                        &nbsp;&bull;&nbsp;Signed: <?php echo date('d M Y', strtotime($form['signed_at'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="sbadge <?php echo $cs === 'signed' ? 'signed' : 'pending'; ?>">
                                    <?php echo $cs === 'signed' ? '<i class="fas fa-check"></i> Signed' : '<i class="fas fa-clock"></i> Pending'; ?>
                                </span>
                                <a href="<?php echo BASE_URL; ?>/modules/ehr/consent_forms.php?id=<?php echo htmlspecialchars($form['id']); ?>"
                                   class="btn <?php echo $cs === 'pending' ? 'btn-primary' : 'btn'; ?>"
                                   style="padding:6px 14px;font-size:0.82rem;<?php echo $cs !== 'pending' ? 'background:#f1f5f9;color:#475569;border:1px solid #e5e7eb;' : ''; ?>">
                                    <i class="fas <?php echo $cs === 'pending' ? 'fa-pen' : 'fa-eye'; ?>"></i>
                                    <?php echo $cs === 'pending' ? 'View &amp; Acknowledge' : 'View'; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- TAB 7: REFERRALS                                                   -->
    <!-- ================================================================= -->
    <div class="tab-pane" id="tab-referrals">
        <div class="portal-card">
            <div class="portal-card-header">
                <h6><i class="fas fa-exchange-alt" style="color:#0891b2;margin-right:8px;"></i>My Referrals</h6>
                <span style="font-size:0.82rem;color:#64748b;"><?php echo count($referrals); ?> referrals</span>
            </div>
            <div class="portal-card-body">
                <?php if (empty($referrals)): ?>
                    <div class="empty-state">
                        <i class="fas fa-exchange-alt"></i>
                        <p>No referrals found in your records.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($referrals as $ref): ?>
                        <?php $rs = strtolower($ref['status'] ?? 'active'); ?>
                        <div class="referral-row">
                            <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <div>
                                    <div style="font-weight:600;color:#1e293b;font-size:0.95rem;">
                                        <i class="fas fa-hospital-alt" style="color:#0891b2;margin-right:6px;"></i>
                                        <?php echo htmlspecialchars($ref['referred_to_specialty'] ?? 'Specialist Referral'); ?>
                                    </div>
                                    <div style="font-size:0.82rem;color:#64748b;margin-top:4px;">
                                        Referred by: Dr. <?php echo htmlspecialchars($ref['ref_by_first'] . ' ' . $ref['ref_by_last']); ?>
                                        &nbsp;&bull;&nbsp;
                                        <i class="fas fa-calendar" style="margin-right:3px;"></i>
                                        <?php echo $ref['referral_date'] ? date('d M Y', strtotime($ref['referral_date'])) : '—'; ?>
                                    </div>
                                    <?php if (!empty($ref['reason'])): ?>
                                        <div style="font-size:0.85rem;color:#475569;margin-top:6px;background:#f8fafc;padding:8px 12px;border-radius:6px;border-left:3px solid #0891b2;">
                                            <i class="fas fa-notes-medical" style="margin-right:5px;color:#0891b2;"></i>
                                            <?php echo htmlspecialchars($ref['reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($ref['notes'])): ?>
                                        <div style="font-size:0.82rem;color:#64748b;margin-top:6px;">
                                            <i class="fas fa-sticky-note" style="margin-right:4px;color:#f59e0b;"></i>
                                            <?php echo htmlspecialchars($ref['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <span class="sbadge <?php echo in_array($rs, ['active','pending','completed','void']) ? $rs : 'active'; ?>">
                                    <?php echo htmlspecialchars(ucfirst($rs)); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</main>

<script>
(function () {
    // Map tab button data-tab → pane id
    const tabs = document.querySelectorAll('#portalTabs .portal-tab-btn');
    const panes = {
        'profile':       document.getElementById('tab-profile'),
        'appointments':  document.getElementById('tab-appointments'),
        'prescriptions': document.getElementById('tab-prescriptions'),
        'lab':           document.getElementById('tab-lab'),
        'bills':         document.getElementById('tab-bills'),
        'consent':       document.getElementById('tab-consent'),
        'referrals':     document.getElementById('tab-referrals'),
    };

    function activateTab(key) {
        tabs.forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.tab === key);
        });
        Object.keys(panes).forEach(function (k) {
            if (panes[k]) panes[k].classList.toggle('active', k === key);
        });
        // Update hash without scroll-jump
        try {
            history.replaceState(null, '', '#' + key);
        } catch (e) {}
    }

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateTab(btn.dataset.tab);
        });
    });

    // Restore tab from URL hash on load
    var hash = window.location.hash.replace('#', '');
    if (hash && panes[hash]) {
        activateTab(hash);
    }
})();
</script>

<?php include '../../includes/footer.php'; ?>
