<?php
// modules/ehr/doctor_availability.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['doctor', 'admin', 'receptionist', 'nurse', 'head_nurse']);

$page_title = "Leave Management";
include '../../includes/header.php';

$role    = get_user_role();
$user_id = get_user_id();
$error   = '';
$success = '';

// Active tab: 'doctors' or 'staff'
// Doctors and non-admin staff always see their own tab
$active_tab = $_GET['tab'] ?? ($role === 'doctor' ? 'doctors' : ($role === 'admin' ? 'doctors' : 'staff'));

// Resolve staff ID for the logged-in doctor
$my_staff_id = null;
if ($role === 'doctor') {
    $s = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    $my_staff_id = $s['id'] ?? null;
}

// Resolve staff ID for non-doctor staff
$my_staff_record = null;
if (in_array($role, ['nurse', 'head_nurse', 'receptionist'])) {
    $my_staff_record = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // ── Doctor / Admin: Submit doctor leave request ───────────────────────────
    if ($action === 'save') {
        $target_date      = trim($_POST['available_date'] ?? '');
        $is_available     = isset($_POST['is_available']) ? (int)$_POST['is_available'] : 1;
        $unav_type        = trim($_POST['unavailability_type'] ?? 'leave');
        $reason           = trim($_POST['reason'] ?? '');
        $target_doctor_id = ($role === 'doctor') ? $my_staff_id : trim($_POST['doctor_id'] ?? '');
        $allowed_types    = ['leave','conference','training','personal','other'];

        if (!in_array($unav_type, $allowed_types)) $unav_type = 'leave';

        if ($target_doctor_id && $target_date) {
            try {
                $approval = ($role === 'admin') ? 'approved' : 'pending';
                db_query(
                    "INSERT INTO doctor_availability
                        (doctor_id, available_date, is_available, unavailability_type, reason, approval_status)
                     VALUES ($1, $2, $3, $4, $5, $6)
                     ON CONFLICT (doctor_id, available_date)
                     DO UPDATE SET
                        is_available        = EXCLUDED.is_available,
                        unavailability_type = EXCLUDED.unavailability_type,
                        reason              = EXCLUDED.reason,
                        approval_status     = EXCLUDED.approval_status,
                        admin_note          = NULL",
                    [$target_doctor_id, $target_date, $is_available ? 'true' : 'false', $unav_type, $reason, $approval]
                );
                $success = $role === 'admin'
                    ? "Availability saved for " . date('d M Y', strtotime($target_date)) . "."
                    : "Leave request submitted for " . date('d M Y', strtotime($target_date)) . ". Awaiting admin approval.";
            } catch (Exception $e) {
                $error = "Failed: " . $e->getMessage();
            }
        }

    // ── Admin: Approve / Reject doctor leave ─────────────────────────────────
    } elseif ($action === 'approve' || $action === 'reject') {
        if ($role !== 'admin') { $error = "Unauthorised."; }
        else {
            $entry_id   = trim($_POST['entry_id'] ?? '');
            $admin_note = trim($_POST['admin_note'] ?? '');
            $new_status = ($action === 'approve') ? 'approved' : 'rejected';
            if ($entry_id) {
                try {
                    db_query(
                        "UPDATE doctor_availability SET approval_status = $1, admin_note = $2 WHERE id = $3",
                        [$new_status, $admin_note, $entry_id]
                    );
                    $success = "Leave request " . ($action === 'approve' ? 'approved' : 'rejected') . ".";
                } catch (Exception $e) {
                    $error = "Failed: " . $e->getMessage();
                }
            }
        }

    // ── Delete doctor leave entry ─────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $del_id = trim($_POST['del_id'] ?? '');
        if ($del_id) {
            try {
                if ($role === 'doctor') {
                    db_query("DELETE FROM doctor_availability WHERE id = $1 AND doctor_id = $2", [$del_id, $my_staff_id]);
                } else {
                    db_delete('doctor_availability', ['id' => $del_id]);
                }
                $success = "Entry removed.";
            } catch (Exception $e) {
                $error = "Failed: " . $e->getMessage();
            }
        }

    // ── Staff leave: add (admin) or self-submit ───────────────────────────────
    } elseif ($action === 'add_staff_leave') {
        if ($role === 'admin') {
            $staff_id   = trim($_POST['staff_id'] ?? '');
            $leave_type = trim($_POST['leave_type'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date   = trim($_POST['end_date'] ?? '');
            $reason     = trim($_POST['reason'] ?? '');
            if ($staff_id && $leave_type && $start_date && $end_date) {
                try {
                    db_insert('leaves', [
                        'staff_id'    => $staff_id,
                        'leave_type'  => $leave_type,
                        'start_date'  => $start_date,
                        'end_date'    => $end_date,
                        'reason'      => $reason,
                        'status'      => 'approved',
                        'approved_by' => $user_id,
                    ]);
                    $success = "Staff leave recorded.";
                } catch (Exception $e) {
                    $error = "Failed: " . $e->getMessage();
                }
            }
        } elseif (in_array($role, ['nurse', 'head_nurse', 'receptionist'])) {
            $staff_id   = $my_staff_record['id'] ?? null;
            $leave_type = trim($_POST['leave_type'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $end_date   = trim($_POST['end_date'] ?? '');
            $reason     = trim($_POST['reason'] ?? '');
            if ($staff_id && $leave_type && $start_date && $end_date) {
                try {
                    db_insert('leaves', [
                        'staff_id'   => $staff_id,
                        'leave_type' => $leave_type,
                        'start_date' => $start_date,
                        'end_date'   => $end_date,
                        'reason'     => $reason,
                        'status'     => 'pending',
                    ]);
                    $success = "Leave request submitted. Awaiting admin approval.";
                } catch (Exception $e) {
                    $error = "Failed: " . $e->getMessage();
                }
            }
        }

    // ── Staff leave: approve / reject (admin) ─────────────────────────────────
    } elseif ($action === 'staff_approve' || $action === 'staff_reject') {
        if ($role !== 'admin') { $error = "Unauthorised."; }
        else {
            $leave_id   = trim($_POST['leave_id'] ?? '');
            $new_status = ($action === 'staff_approve') ? 'approved' : 'rejected';
            if ($leave_id) {
                try {
                    db_update('leaves',
                        ['status' => $new_status, 'approved_by' => $user_id],
                        ['id' => $leave_id]
                    );
                    $success = "Leave " . ($action === 'staff_approve' ? 'approved' : 'rejected') . ".";
                } catch (Exception $e) {
                    $error = "Failed: " . $e->getMessage();
                }
            }
        }

    // ── Staff leave: delete ───────────────────────────────────────────────────
    } elseif ($action === 'delete_staff_leave') {
        $leave_id = trim($_POST['leave_id'] ?? '');
        if ($leave_id && $role === 'admin') {
            try {
                db_delete('leaves', ['id' => $leave_id]);
                $success = "Leave record removed.";
            } catch (Exception $e) {
                $error = "Failed: " . $e->getMessage();
            }
        }
    }
}

// ── Doctor tab data ───────────────────────────────────────────────────────────
$view_month  = $_GET['month'] ?? date('Y-m');
$view_doctor = ($role === 'doctor') ? $my_staff_id : ($_GET['doctor_id'] ?? '');

$all_doctors = db_select(
    "SELECT s.id, s.first_name, s.last_name, s.specialization
     FROM staff s WHERE s.role = 'doctor' ORDER BY s.last_name, s.first_name"
);

$av_sql    = "SELECT da.*, s.first_name, s.last_name FROM doctor_availability da JOIN staff s ON da.doctor_id = s.id WHERE TO_CHAR(da.available_date,'YYYY-MM') = $1";
$av_params = [$view_month];
if ($view_doctor) {
    $av_params[] = $view_doctor;
    $av_sql .= " AND da.doctor_id = $" . count($av_params);
}
$av_sql .= " ORDER BY da.available_date ASC";
$av_entries = db_select($av_sql, $av_params);

$pending_requests = [];
if ($role === 'admin') {
    $pending_requests = db_select(
        "SELECT da.*, s.first_name, s.last_name FROM doctor_availability da
         JOIN staff s ON da.doctor_id = s.id
         WHERE da.approval_status = 'pending' AND da.is_available = false
         ORDER BY da.available_date ASC"
    );
}

$av_by_date = [];
foreach ($av_entries as $e) {
    $av_by_date[$e['available_date']][] = $e;
}

$month_start = date('Y-m-01', strtotime($view_month . '-01'));
$start_dow   = (int)date('N', strtotime($month_start));
$total_days  = (int)date('t', strtotime($view_month . '-01'));
$prev_month  = date('Y-m', strtotime($view_month . '-01 -1 month'));
$next_month  = date('Y-m', strtotime($view_month . '-01 +1 month'));

// ── Staff tab data ─────────────────────────────────────────────────────────────
$staff_role_filter = $_GET['staff_role'] ?? '';
$non_doctor_roles  = ['nurse','head_nurse','receptionist','pharmacist','lab_tech','radiologist'];

// Build staff list for dropdown
$all_non_doctor_staff = db_select(
    "SELECT id, first_name, last_name, role FROM staff WHERE role != 'doctor' ORDER BY role, last_name, first_name"
);

// Build leaves query
if (in_array($role, ['nurse', 'head_nurse', 'receptionist']) && $my_staff_record) {
    // Non-admin staff: see only their own leaves
    $staff_leaves = db_select(
        "SELECT l.*, s.first_name, s.last_name, s.role AS staff_role
         FROM leaves l JOIN staff s ON l.staff_id = s.id
         WHERE l.staff_id = $1 ORDER BY l.start_date DESC",
        [$my_staff_record['id']]
    );
} else {
    // Admin: see all non-doctor leaves, optionally filtered by role
    $sl_params = [];
    $sl_sql = "SELECT l.*, s.first_name, s.last_name, s.role AS staff_role
               FROM leaves l JOIN staff s ON l.staff_id = s.id
               WHERE s.role != 'doctor'";
    if ($staff_role_filter) {
        $sl_params[] = $staff_role_filter;
        $sl_sql .= " AND s.role = $" . count($sl_params);
    }
    $sl_sql .= " ORDER BY l.start_date DESC";
    $staff_leaves = db_select($sl_sql, $sl_params);
}

$staff_pending_count = count(array_filter($staff_leaves, fn($l) => $l['status'] === 'pending'));

// ── HR Stats ──────────────────────────────────────────────────────────────────
function is_avail($val) { return $val === 't' || $val === true || $val === 1; }

$hr_stats = [];
if ($role === 'admin') {
    $hr_stats['total_staff']    = count(db_select("SELECT id FROM staff"));
    $hr_stats['doc_on_leave']   = count(db_select(
        "SELECT id FROM doctor_availability WHERE is_available = false AND approval_status = 'approved' AND available_date = CURRENT_DATE"
    ));
    $hr_stats['doc_pending']    = count($pending_requests);
    $hr_stats['staff_pending']  = $staff_pending_count;
}
?>

<style>
.av-wrap { max-width: 1280px; margin: 0 auto; padding: 20px; }

/* Tabs */
.lm-tabs { display:flex; gap:4px; background:#f3f4f6; border-radius:12px; padding:4px; margin-bottom:24px; width:fit-content; }
.lm-tab  { padding:8px 22px; border-radius:9px; font-size:0.88em; font-weight:600; color:#6b7280; cursor:pointer; border:none; background:transparent; display:flex; align-items:center; gap:7px; transition:all 0.18s; text-decoration:none; }
.lm-tab:hover { color:#374151; }
.lm-tab.active { background:white; color:#6366f1; box-shadow:0 1px 6px rgba(0,0,0,0.1); }
.lm-tab .tab-badge { background:#ef4444; color:white; border-radius:99px; font-size:0.75em; padding:1px 6px; font-weight:700; }

/* Grid & Cards */
.av-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 24px; align-items: start; }
.av-card { background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 26px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }

/* Calendar */
.cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 4px; }
.cal-day-label { text-align:center; font-size:0.75em; font-weight:700; color:#6b7280; padding:6px 0; text-transform:uppercase; }
.cal-day { min-height: 64px; border-radius: 10px; padding: 6px 8px; border: 1px solid #f3f4f6; cursor: pointer; transition: 0.15s; font-size: 0.82em; position: relative; }
.cal-day:hover { border-color: #a5b4fc; background: #f5f3ff; }
.cal-day.today { border-color: #6366f1; background: #f0f4ff; }
.cal-day.approved-off  { background: #fee2e2; border-color: #fca5a5; }
.cal-day.pending-off   { background: #fef9c3; border-color: #fde047; }
.cal-day.available     { background: #d1fae5; border-color: #6ee7b7; }
.cal-day.empty { cursor: default; border-color: transparent; background: transparent; }
.cal-day .day-num { font-weight: 700; color: #374151; font-size: 0.9em; }
.av-tag { font-size: 0.7em; font-weight: 600; padding: 1px 6px; border-radius: 99px; display: inline-block; margin-top: 2px; }
.tag-available   { background: #bbf7d0; color: #065f46; }
.tag-approved    { background: #fecaca; color: #991b1b; }
.tag-pending     { background: #fef08a; color: #854d0e; }
.tag-rejected    { background: #e5e7eb; color: #6b7280; text-decoration: line-through; }

/* Form */
.form-group { margin-bottom: 13px; }
.form-group label { display:block; font-size:0.82em; font-weight:600; color:#374151; margin-bottom:4px; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px;
    font-size:0.9em; outline:none; transition: border 0.2s; box-sizing: border-box;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #6366f1; }
.btn-save    { background:#6366f1; color:white; border:none; border-radius:10px; padding:11px 26px; font-weight:700; cursor:pointer; width:100%; margin-top:4px; }
.btn-save:hover { background:#4f46e5; }
.btn-approve { background:#d1fae5; color:#065f46; border:none; border-radius:6px; padding:5px 12px; cursor:pointer; font-size:0.8em; font-weight:700; }
.btn-reject  { background:#fee2e2; color:#dc2626; border:none; border-radius:6px; padding:5px 12px; cursor:pointer; font-size:0.8em; font-weight:700; }
.btn-del     { background:#fee2e2; color:#dc2626; border:none; border-radius:6px; padding:4px 10px; cursor:pointer; font-size:0.8em; font-weight:600; }

/* Legend */
.legend { display:flex; gap:14px; font-size:0.8em; margin-bottom:14px; flex-wrap:wrap; }
.legend-dot { width:12px;height:12px;border-radius:3px;display:inline-block;margin-right:4px;vertical-align:middle; }
.list-row { display:flex; justify-content:space-between; align-items:flex-start; padding:10px 14px; border-radius:8px; background:#f9fafb; margin-bottom:6px; font-size:0.87em; gap:10px; }
.pending-banner { background:#fef9c3; border:1px solid #fde047; border-radius:12px; padding:16px 20px; margin-bottom:22px; }

/* Staff leaves table */
.sl-table { width:100%; border-collapse:collapse; font-size:0.88em; }
.sl-table th { background:#f9fafb; padding:10px 14px; text-align:left; font-size:0.78em; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; border-bottom:1px solid #e5e7eb; }
.sl-table td { padding:12px 14px; border-bottom:1px solid #f3f4f6; color:#374151; vertical-align:middle; }
.sl-table tr:hover td { background:#f9fafb; }
.sl-role-pill { display:inline-block; padding:2px 10px; border-radius:99px; font-size:0.78em; font-weight:600; background:#e0e7ff; color:#3730a3; }
.sl-role-pill.nurse      { background:#dbeafe; color:#1e40af; }
.sl-role-pill.head_nurse { background:#ede9fe; color:#5b21b6; }
.sl-role-pill.receptionist { background:#fce7f3; color:#9d174d; }
.sl-role-pill.pharmacist { background:#d1fae5; color:#065f46; }
.sl-role-pill.lab_tech   { background:#fef3c7; color:#92400e; }
.sl-role-pill.radiologist { background:#ffedd5; color:#c2410c; }
.sl-status { display:inline-block; padding:3px 10px; border-radius:99px; font-size:0.8em; font-weight:600; }
.sl-status.approved { background:#d1fae5; color:#065f46; }
.sl-status.pending  { background:#fef9c3; color:#854d0e; }
.sl-status.rejected { background:#fee2e2; color:#991b1b; }

@media (max-width:900px) { .av-grid { grid-template-columns:1fr; } }
</style>

<div class="av-wrap">

    <!-- Page Header -->
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
        <div style="width:46px;height:46px;background:linear-gradient(135deg,#0ea5e9,#6366f1);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.3em;flex-shrink:0;">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div>
            <h2 style="margin:0;font-size:1.4em;font-weight:800;color:#111827;">Leave Management</h2>
            <p style="margin:0;color:#6b7280;font-size:0.88em;">
                <?php echo $role === 'doctor' ? 'Request leave — requires admin approval before taking effect' : 'Manage and approve leave requests for all staff'; ?>
            </p>
        </div>
        <?php if ($role === 'admin'): ?>
        <a href="/modules/hr/payroll.php" style="margin-left:auto;text-decoration:none;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:7px 14px;font-size:0.84em;font-weight:600;color:#374151;display:flex;align-items:center;gap:6px;">
            <i class="fas fa-file-invoice-dollar" style="color:#16a34a;"></i> Payroll
        </a>
        <?php endif; ?>
    </div>

    <!-- Admin HR Stats -->
    <?php if ($role === 'admin'): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
        <div style="background:white;border-radius:14px;border:1px solid #e5e7eb;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <div style="width:44px;height:44px;background:rgba(99,102,241,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#6366f1;font-size:1.2em;flex-shrink:0;"><i class="fas fa-users"></i></div>
            <div>
                <div style="font-size:1.5em;font-weight:800;color:#111827;"><?php echo $hr_stats['total_staff']; ?></div>
                <div style="font-size:0.78em;color:#6b7280;font-weight:500;">Total Staff</div>
            </div>
        </div>
        <div style="background:white;border-radius:14px;border:1px solid #e5e7eb;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <div style="width:44px;height:44px;background:rgba(220,38,38,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#dc2626;font-size:1.2em;flex-shrink:0;"><i class="fas fa-user-md"></i></div>
            <div>
                <div style="font-size:1.5em;font-weight:800;color:#111827;"><?php echo $hr_stats['doc_on_leave']; ?></div>
                <div style="font-size:0.78em;color:#6b7280;font-weight:500;">Doctors on Leave Today</div>
            </div>
        </div>
        <div style="background:white;border-radius:14px;border:1px solid #e5e7eb;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <div style="width:44px;height:44px;background:rgba(234,179,8,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#ca8a04;font-size:1.2em;flex-shrink:0;"><i class="fas fa-envelope-open-text"></i></div>
            <div>
                <div style="font-size:1.5em;font-weight:800;color:#111827;"><?php echo $hr_stats['doc_pending'] + $hr_stats['staff_pending']; ?></div>
                <div style="font-size:0.78em;color:#6b7280;font-weight:500;">Pending Approvals</div>
            </div>
        </div>
        <div style="background:white;border-radius:14px;border:1px solid #e5e7eb;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <div style="width:44px;height:44px;background:rgba(46,204,113,0.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:1.2em;flex-shrink:0;"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div style="font-size:1.5em;font-weight:800;color:#111827;"><?php echo $hr_stats['staff_pending']; ?></div>
                <div style="font-size:0.78em;color:#6b7280;font-weight:500;">Staff Leave Pending</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs (admin sees both; doctor sees only Doctors; others see only Staff) -->
    <?php if ($role === 'admin'): ?>
    <div class="lm-tabs">
        <a href="?tab=doctors" class="lm-tab <?php echo $active_tab === 'doctors' ? 'active' : ''; ?>">
            <i class="fas fa-user-md"></i> Doctors
            <?php if ($hr_stats['doc_pending'] > 0): ?>
                <span class="tab-badge"><?php echo $hr_stats['doc_pending']; ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=staff" class="lm-tab <?php echo $active_tab === 'staff' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> Nurses &amp; Staff
            <?php if ($hr_stats['staff_pending'] > 0): ?>
                <span class="tab-badge"><?php echo $hr_stats['staff_pending']; ?></span>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#dc2626;font-size:0.9em;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#065f46;font-size:0.9em;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>


    <?php if ($active_tab === 'doctors' && in_array($role, ['doctor', 'admin', 'receptionist'])): ?>
    <!-- ══════════════════════ DOCTORS TAB ══════════════════════ -->

    <!-- Pending doctor approvals banner (admin) -->
    <?php if ($role === 'admin' && !empty($pending_requests)): ?>
    <div class="pending-banner">
        <div style="font-weight:700;font-size:1em;color:#854d0e;margin-bottom:14px;">
            <i class="fas fa-clock" style="margin-right:6px;"></i>
            Pending Doctor Leave Approvals (<?php echo count($pending_requests); ?>)
        </div>
        <?php foreach ($pending_requests as $p): ?>
        <div style="background:white;border-radius:10px;border:1px solid #fde047;padding:12px 16px;margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div>
                    <strong style="color:#111827;">Dr. <?php echo htmlspecialchars($p['first_name'].' '.$p['last_name']); ?></strong>
                    <span style="margin:0 8px;color:#d1d5db;">|</span>
                    <span style="color:#374151;"><?php echo date('d M Y', strtotime($p['available_date'])); ?></span>
                    <span style="margin:0 8px;color:#d1d5db;">|</span>
                    <span style="font-weight:600;color:#b45309;"><?php echo ucfirst($p['unavailability_type']); ?></span>
                    <?php if ($p['reason']): ?>
                        <div style="font-size:0.82em;color:#6b7280;margin-top:3px;"><?php echo htmlspecialchars($p['reason']); ?></div>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <form method="POST" style="display:flex;gap:6px;align-items:center;">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="entry_id" value="<?php echo $p['id']; ?>">
                        <input type="text" name="admin_note" placeholder="Note (optional)" style="padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.82em;width:160px;">
                        <button type="submit" class="btn-approve"><i class="fas fa-check"></i> Approve</button>
                    </form>
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="entry_id" value="<?php echo $p['id']; ?>">
                        <input type="text" name="admin_note" placeholder="Reason (optional)" style="padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.82em;width:160px;">
                        <button type="submit" class="btn-reject"><i class="fas fa-times"></i> Reject</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="av-grid">
        <!-- Calendar -->
        <div class="av-card">
            <div class="cal-header">
                <a href="?tab=doctors&month=<?php echo $prev_month; ?><?php echo $view_doctor ? '&doctor_id='.$view_doctor : ''; ?>"
                   style="text-decoration:none;background:#f3f4f6;border-radius:8px;padding:6px 12px;color:#374151;font-weight:600;">&lsaquo;</a>
                <span style="font-weight:800;font-size:1.1em;color:#111827;"><?php echo date('F Y', strtotime($view_month . '-01')); ?></span>
                <a href="?tab=doctors&month=<?php echo $next_month; ?><?php echo $view_doctor ? '&doctor_id='.$view_doctor : ''; ?>"
                   style="text-decoration:none;background:#f3f4f6;border-radius:8px;padding:6px 12px;color:#374151;font-weight:600;">&rsaquo;</a>
            </div>

            <?php if ($role !== 'doctor'): ?>
            <form method="GET" style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;">
                <input type="hidden" name="tab" value="doctors">
                <input type="hidden" name="month" value="<?php echo htmlspecialchars($view_month); ?>">
                <select name="doctor_id" onchange="this.form.submit()" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.88em;flex:1;">
                    <option value="">All Doctors</option>
                    <?php foreach ($all_doctors as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $view_doctor === $d['id'] ? 'selected' : ''; ?>>
                            Dr. <?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?>
                            <?php echo $d['specialization'] ? ' — ' . $d['specialization'] : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>

            <div class="legend">
                <span><span class="legend-dot" style="background:#bbf7d0;"></span>Available</span>
                <span><span class="legend-dot" style="background:#fee2e2;"></span>Approved Leave</span>
                <span><span class="legend-dot" style="background:#fef9c3;"></span>Pending Approval</span>
                <span><span class="legend-dot" style="background:#f3f4f6;border:1px solid #e5e7eb;"></span>Not set</span>
            </div>

            <div class="cal-grid">
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                    <div class="cal-day-label"><?php echo $d; ?></div>
                <?php endforeach; ?>

                <?php
                for ($i = 1; $i < $start_dow; $i++) echo '<div class="cal-day empty"></div>';
                for ($day = 1; $day <= $total_days; $day++):
                    $date_str = date('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                    $today    = $date_str === date('Y-m-d');
                    $entries  = $av_by_date[$date_str] ?? [];
                    $cell_class = 'cal-day';
                    if ($today) $cell_class .= ' today';
                    if (!empty($entries)) {
                        $unavail_entries = array_filter($entries, fn($e) => !is_avail($e['is_available']));
                        if ($unavail_entries) {
                            $has_approved = array_filter($unavail_entries, fn($e) => $e['approval_status'] === 'approved');
                            $cell_class  .= $has_approved ? ' approved-off' : ' pending-off';
                        } else {
                            $cell_class .= ' available';
                        }
                    }
                ?>
                <div class="<?php echo $cell_class; ?>" onclick="setDate('<?php echo $date_str; ?>')">
                    <div class="day-num"><?php echo $day; ?></div>
                    <?php foreach (array_slice($entries, 0, 2) as $e):
                        $avail = is_avail($e['is_available']);
                        $status = $e['approval_status'] ?? 'approved';
                        if ($avail) {
                            $tag_class = 'tag-available'; $label = '✓';
                        } elseif ($status === 'approved') {
                            $tag_class = 'tag-approved'; $label = ucfirst($e['unavailability_type']);
                        } elseif ($status === 'rejected') {
                            $tag_class = 'tag-rejected'; $label = 'Rejected';
                        } else {
                            $tag_class = 'tag-pending'; $label = 'Pending';
                        }
                    ?>
                        <span class="av-tag <?php echo $tag_class; ?>"><?php echo $label; ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Right: Form + List -->
        <div>
            <div class="av-card" style="margin-bottom:20px;">
                <div style="font-weight:700;font-size:1em;color:#374151;margin-bottom:16px;">
                    <i class="fas fa-pen" style="color:#6366f1;margin-right:6px;"></i>
                    <?php echo $role === 'doctor' ? 'Request Leave' : 'Mark Availability'; ?>
                </div>
                <?php if ($role === 'doctor'): ?>
                <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:9px 13px;margin-bottom:14px;font-size:0.83em;color:#854d0e;">
                    <i class="fas fa-info-circle"></i> Leave requests require admin approval before they take effect.
                </div>
                <?php endif; ?>
                <form method="POST" action="">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="save">
                    <?php if ($role !== 'doctor'): ?>
                    <div class="form-group">
                        <label>Doctor</label>
                        <select name="doctor_id" required>
                            <option value="">-- Select Doctor --</option>
                            <?php foreach ($all_doctors as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo $view_doctor === $d['id'] ? 'selected' : ''; ?>>
                                    Dr. <?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="available_date" id="form-date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="is_available" id="form-avail" onchange="toggleType(this.value)">
                            <option value="1">Available</option>
                            <option value="0">Unavailable / Leave</option>
                        </select>
                    </div>
                    <div class="form-group" id="type-group" style="display:none;">
                        <label>Leave Type</label>
                        <select name="unavailability_type">
                            <option value="leave">Leave</option>
                            <option value="conference">Conference</option>
                            <option value="training">Training</option>
                            <option value="personal">Personal</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="reason" rows="2" placeholder="Additional details..."></textarea>
                    </div>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-<?php echo $role === 'doctor' ? 'paper-plane' : 'save'; ?>" style="margin-right:6px;"></i>
                        <?php echo $role === 'doctor' ? 'Submit Request' : 'Save'; ?>
                    </button>
                </form>
            </div>

            <!-- Entries List -->
            <div class="av-card">
                <div style="font-weight:700;font-size:1em;color:#374151;margin-bottom:14px;">
                    <i class="fas fa-list" style="color:#6366f1;margin-right:6px;"></i>
                    <?php echo date('F Y', strtotime($view_month . '-01')); ?> Entries
                </div>
                <?php if (empty($av_entries)): ?>
                    <div style="text-align:center;padding:30px;color:#9ca3af;font-size:0.88em;">No entries this month.</div>
                <?php else: ?>
                    <?php foreach ($av_entries as $e):
                        $avail  = is_avail($e['is_available']);
                        $status = $e['approval_status'] ?? 'approved';
                        $status_label = $avail ? '' : match($status) {
                            'approved' => '<span style="color:#dc2626;font-weight:600;">Approved</span>',
                            'pending'  => '<span style="color:#b45309;font-weight:600;">⏳ Pending</span>',
                            'rejected' => '<span style="color:#6b7280;font-weight:600;text-decoration:line-through;">Rejected</span>',
                            default    => ''
                        };
                    ?>
                    <div class="list-row">
                        <div style="flex:1;">
                            <div>
                                <strong style="color:#111827;"><?php echo date('d M', strtotime($e['available_date'])); ?></strong>
                                <span style="margin:0 8px;color:#9ca3af;">·</span>
                                <span style="font-weight:600;color:<?php echo $avail ? '#065f46' : '#991b1b'; ?>;">
                                    <?php echo $avail ? 'Available' : ucfirst($e['unavailability_type']); ?>
                                </span>
                                <?php if ($status_label) echo ' <span style="margin-left:6px;">' . $status_label . '</span>'; ?>
                                <?php if ($role !== 'doctor'): ?>
                                    <span style="color:#6b7280;font-size:0.85em;margin-left:6px;">— Dr. <?php echo htmlspecialchars($e['first_name'] . ' ' . $e['last_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($e['reason']): ?>
                                <div style="font-size:0.8em;color:#6b7280;margin-top:2px;"><?php echo htmlspecialchars($e['reason']); ?></div>
                            <?php endif; ?>
                            <?php if ($e['admin_note']): ?>
                                <div style="font-size:0.8em;color:#6366f1;margin-top:2px;"><i class="fas fa-comment-alt"></i> <?php echo htmlspecialchars($e['admin_note']); ?></div>
                            <?php endif; ?>
                        </div>
                        <form method="POST" onsubmit="return confirm('Remove this entry?')">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="del_id" value="<?php echo $e['id']; ?>">
                            <button type="submit" class="btn-del">Remove</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php elseif ($active_tab === 'staff' || in_array($role, ['nurse', 'head_nurse', 'receptionist'])): ?>
    <!-- ══════════════════════ STAFF TAB ══════════════════════ -->

    <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

        <!-- Left: Leaves table -->
        <div class="av-card" style="padding:0;overflow:hidden;">
            <!-- Table header with role filter -->
            <div style="padding:18px 22px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div style="font-weight:700;color:#111827;font-size:1em;">
                    <i class="fas fa-users" style="color:#6366f1;margin-right:7px;"></i>
                    Staff Leave Requests
                    <?php if ($staff_pending_count > 0): ?>
                        <span style="background:#fef9c3;color:#854d0e;border-radius:99px;font-size:0.75em;padding:2px 9px;margin-left:6px;font-weight:700;"><?php echo $staff_pending_count; ?> pending</span>
                    <?php endif; ?>
                </div>
                <?php if ($role === 'admin'): ?>
                <form method="GET" style="display:flex;gap:8px;align-items:center;">
                    <input type="hidden" name="tab" value="staff">
                    <select name="staff_role" onchange="this.form.submit()" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85em;color:#374151;outline:none;">
                        <option value="">All Staff</option>
                        <option value="nurse"        <?php echo $staff_role_filter==='nurse'        ? 'selected' : ''; ?>>Nurse</option>
                        <option value="head_nurse"   <?php echo $staff_role_filter==='head_nurse'   ? 'selected' : ''; ?>>Head Nurse</option>
                        <option value="receptionist" <?php echo $staff_role_filter==='receptionist' ? 'selected' : ''; ?>>Receptionist</option>
                        <option value="pharmacist"   <?php echo $staff_role_filter==='pharmacist'   ? 'selected' : ''; ?>>Pharmacist</option>
                        <option value="lab_tech"     <?php echo $staff_role_filter==='lab_tech'     ? 'selected' : ''; ?>>Lab Tech</option>
                        <option value="radiologist"  <?php echo $staff_role_filter==='radiologist'  ? 'selected' : ''; ?>>Radiologist</option>
                    </select>
                </form>
                <?php endif; ?>
            </div>

            <?php if (empty($staff_leaves)): ?>
                <div style="text-align:center;padding:50px;color:#9ca3af;font-size:0.9em;">
                    <i class="fas fa-calendar fa-2x" style="margin-bottom:10px;display:block;color:#e5e7eb;"></i>
                    No leave requests found.
                </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="sl-table">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Role</th>
                        <th>Type</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <?php if ($role === 'admin'): ?><th style="text-align:center;">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staff_leaves as $l):
                    $days = max(1, (int)round((strtotime($l['end_date']) - strtotime($l['start_date'])) / 86400) + 1);
                    $sr   = $l['staff_role'] ?? '';
                ?>
                <tr>
                    <td>
                        <div style="font-weight:600;color:#111827;"><?php echo htmlspecialchars($l['first_name'] . ' ' . $l['last_name']); ?></div>
                        <?php if ($l['reason']): ?>
                            <div style="font-size:0.8em;color:#6b7280;margin-top:2px;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($l['reason']); ?>"><?php echo htmlspecialchars($l['reason']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="sl-role-pill <?php echo htmlspecialchars($sr); ?>"><?php echo ucfirst(str_replace('_', ' ', $sr)); ?></span></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($l['leave_type']); ?></td>
                    <td>
                        <div style="color:#374151;"><?php echo date('d M', strtotime($l['start_date'])); ?> — <?php echo date('d M Y', strtotime($l['end_date'])); ?></div>
                        <div style="font-size:0.8em;color:#9ca3af;"><?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?></div>
                    </td>
                    <td><span class="sl-status <?php echo $l['status']; ?>"><?php echo ucfirst($l['status']); ?></span></td>
                    <?php if ($role === 'admin'): ?>
                    <td style="text-align:center;white-space:nowrap;">
                        <?php if ($l['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="staff_approve">
                            <input type="hidden" name="leave_id" value="<?php echo $l['id']; ?>">
                            <button type="submit" class="btn-approve" style="margin-right:4px;"><i class="fas fa-check"></i></button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="staff_reject">
                            <input type="hidden" name="leave_id" value="<?php echo $l['id']; ?>">
                            <button type="submit" class="btn-reject"><i class="fas fa-times"></i></button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="delete_staff_leave">
                            <input type="hidden" name="leave_id" value="<?php echo $l['id']; ?>">
                            <button type="submit" class="btn-del">Del</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: Add leave form -->
        <div class="av-card">
            <div style="font-weight:700;font-size:1em;color:#374151;margin-bottom:16px;">
                <i class="fas fa-plus-circle" style="color:#6366f1;margin-right:6px;"></i>
                <?php echo $role === 'admin' ? 'Record Staff Leave' : 'Request Leave'; ?>
            </div>
            <?php if (in_array($role, ['nurse','head_nurse','receptionist'])): ?>
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:9px 13px;margin-bottom:14px;font-size:0.83em;color:#854d0e;">
                <i class="fas fa-info-circle"></i> Your request will be reviewed by an admin.
            </div>
            <?php endif; ?>
            <form method="POST" action="">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="add_staff_leave">
                <?php if ($role === 'admin'): ?>
                <div class="form-group">
                    <label>Staff Member</label>
                    <select name="staff_id" required>
                        <option value="">-- Select Staff --</option>
                        <?php
                        $prev_role = '';
                        foreach ($all_non_doctor_staff as $st):
                            if ($st['role'] !== $prev_role) {
                                echo '<optgroup label="' . htmlspecialchars(ucfirst(str_replace('_',' ',$st['role']))) . '">';
                                $prev_role = $st['role'];
                            }
                        ?>
                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Leave Type</label>
                    <select name="leave_type" required>
                        <option value="Annual">Annual Leave</option>
                        <option value="Sick">Sick Leave</option>
                        <option value="Casual">Casual Leave</option>
                        <option value="Maternity">Maternity Leave</option>
                        <option value="Paternity">Paternity Leave</option>
                        <option value="Unpaid">Unpaid Leave</option>
                        <option value="Emergency">Emergency Leave</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Reason (optional)</label>
                    <textarea name="reason" rows="2" placeholder="Brief reason..."></textarea>
                </div>
                <button type="submit" class="btn-save">
                    <i class="fas fa-<?php echo $role === 'admin' ? 'save' : 'paper-plane'; ?>" style="margin-right:6px;"></i>
                    <?php echo $role === 'admin' ? 'Save Record' : 'Submit Request'; ?>
                </button>
            </form>
        </div>

    </div>
    <?php endif; ?>

</div>

<script>
function setDate(d) {
    const el = document.getElementById('form-date');
    if (el) { el.value = d; el.scrollIntoView({behavior:'smooth', block:'center'}); }
}
function toggleType(val) {
    const g = document.getElementById('type-group');
    if (g) g.style.display = val === '0' ? 'block' : 'none';
}
</script>

<?php include '../../includes/footer.php'; ?>
