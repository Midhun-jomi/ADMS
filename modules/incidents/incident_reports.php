<?php
// modules/incidents/incident_reports.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role    = get_user_role();
$user_id = get_user_id();

$allowed_roles = ['admin', 'doctor', 'nurse', 'head_nurse', 'pharmacist', 'lab_tech', 'radiologist', 'receptionist'];
if (!in_array($role, $allowed_roles)) {
    http_response_code(403);
    exit('Access denied.');
}

$page_title = "Incident Reports";
include '../../includes/header.php';

$error   = '';
$success = '';

// ── POST HANDLERS ──────────────────────────────────────────────────────────────

// Report New Incident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $incident_type      = trim($_POST['incident_type']      ?? '');
        $incident_datetime  = trim($_POST['incident_datetime']  ?? '');
        $location           = trim($_POST['location']           ?? '');
        $patient_id         = trim($_POST['patient_id']         ?? '');
        $description        = trim($_POST['description']        ?? '');
        $immediate_action   = trim($_POST['immediate_action']   ?? '');
        $severity           = trim($_POST['severity']           ?? '');
        $witness_names      = trim($_POST['witness_names']      ?? '');

        $valid_types = ['Medication Error','Patient Fall','Needle Stick Injury','Equipment Failure','Near Miss','Patient Complaint','Security Breach','Other'];
        $valid_sev   = ['Minor','Moderate','Severe','Critical'];

        if (empty($incident_type) || empty($incident_datetime) || empty($location) || empty($description) || empty($severity)) {
            $error = "Incident type, date/time, location, description, and severity are required.";
        } elseif (!in_array($incident_type, $valid_types)) {
            $error = "Invalid incident type selected.";
        } elseif (!in_array($severity, $valid_sev)) {
            $error = "Invalid severity level selected.";
        } else {
            $patient_id_val = !empty($patient_id) ? $patient_id : null;
            try {
                $sql = "INSERT INTO incident_reports
                            (incident_type, incident_datetime, location, patient_id, description,
                             immediate_action, severity, witness_names, status, reported_by)
                        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'open', $9)";
                db_query($sql, [
                    $incident_type,
                    $incident_datetime,
                    $location,
                    $patient_id_val,
                    $description,
                    $immediate_action,
                    $severity,
                    $witness_names,
                    $user_id,
                ]);
                $success = "Incident report submitted successfully.";
            } catch (Exception $e) {
                $error = "Failed to submit incident report: " . $e->getMessage();
            }
        }
    }
}

// Update Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $report_id  = trim($_POST['report_id'] ?? '');
        $new_status = trim($_POST['new_status'] ?? '');
        $valid_statuses = ['open', 'under_investigation', 'resolved'];

        if (empty($report_id) || !in_array($new_status, $valid_statuses)) {
            $error = "Invalid status update request.";
        } else {
            // Non-admin can only update their own reports
            if ($role !== 'admin') {
                $check = db_select_one("SELECT id FROM incident_reports WHERE id = $1 AND reported_by = $2", [$report_id, $user_id]);
                if (!$check) {
                    $error = "You can only update your own reports.";
                }
            }

            if (!$error) {
                try {
                    db_query("UPDATE incident_reports SET status = $1 WHERE id = $2", [$new_status, $report_id]);
                    $success = "Report status updated successfully.";
                } catch (Exception $e) {
                    $error = "Failed to update status: " . $e->getMessage();
                }
            }
        }
    }
}

// Add Follow-up Notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'followup') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $report_id   = trim($_POST['report_id']    ?? '');
        $follow_up   = trim($_POST['follow_up_notes'] ?? '');

        if (empty($report_id) || empty($follow_up)) {
            $error = "Report ID and follow-up notes are required.";
        } else {
            if ($role !== 'admin') {
                $check = db_select_one("SELECT id FROM incident_reports WHERE id = $1 AND reported_by = $2", [$report_id, $user_id]);
                if (!$check) {
                    $error = "You can only add notes to your own reports.";
                }
            }

            if (!$error) {
                try {
                    db_query("UPDATE incident_reports SET follow_up_notes = $1 WHERE id = $2", [$follow_up, $report_id]);
                    $success = "Follow-up notes saved.";
                } catch (Exception $e) {
                    $error = "Failed to save follow-up notes: " . $e->getMessage();
                }
            }
        }
    }
}

// ── FETCH REPORTS ─────────────────────────────────────────────────────────────
if ($role === 'admin') {
    $reports = db_select(
        "SELECT ir.*,
                p.first_name AS p_first, p.last_name AS p_last,
                u.email AS reporter_name
         FROM incident_reports ir
         LEFT JOIN patients p ON ir.patient_id = p.id
         LEFT JOIN users    u ON ir.reported_by = u.id
         ORDER BY ir.created_at DESC"
    );
} else {
    $reports = db_select(
        "SELECT ir.*,
                p.first_name AS p_first, p.last_name AS p_last,
                u.email AS reporter_name
         FROM incident_reports ir
         LEFT JOIN patients p ON ir.patient_id = p.id
         LEFT JOIN users    u ON ir.reported_by = u.id
         WHERE ir.reported_by = \$1
         ORDER BY ir.created_at DESC",
        [$user_id]
    );
}

// Stats
$stat_total       = 0;
$stat_open        = 0;
$stat_investigating = 0;
$stat_resolved    = 0;
foreach ($reports as $r) {
    $stat_total++;
    if ($r['status'] === 'open')                $stat_open++;
    elseif ($r['status'] === 'under_investigation') $stat_investigating++;
    elseif ($r['status'] === 'resolved')        $stat_resolved++;
}

// Patients for create modal
$all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name");
?>

<style>
.inc-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border: 1px solid #f0f0f0;
    flex: 1;
    min-width: 160px;
}
.inc-stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.inc-stat-info h4 { margin: 0; font-size: 1.75rem; font-weight: 700; color: #111827; }
.inc-stat-info p  { margin: 0; font-size: 0.82rem; color: #6b7280; font-weight: 500; }

/* Severity badges */
.sev-critical { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; }
.sev-severe   { background: #ffedd5; color: #9a3412; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; }
.sev-moderate { background: #fef9c3; color: #854d0e; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; }
.sev-minor    { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 700; }

/* Status badges */
.status-open        { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
.status-investigating { background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
.status-resolved    { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }

/* Modal overlay */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1050;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 16px;
    width: 100%;
    max-width: 700px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 1;
    border-radius: 16px 16px 0 0;
}
.modal-header h5 { margin: 0; font-weight: 700; color: #1f2937; font-size: 1.1rem; }
.modal-body   { padding: 24px; }
.modal-footer { padding: 16px 24px; border-top: 1px solid #f3f4f6; display: flex; justify-content: flex-end; gap: 10px; }
.btn-close-modal { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #6b7280; line-height: 1; }
.btn-close-modal:hover { color: #111; }

.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 600px) { .form-grid-2 { grid-template-columns: 1fr; } }

.detail-label { font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; display: block; margin-bottom: 3px; }
.detail-val   { font-size: 0.94rem; color: #111827; margin: 0 0 14px; }
.detail-block { background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; font-size: 0.9rem; line-height: 1.6; white-space: pre-wrap; }
</style>

<div style="max-width: 1280px; margin: 0 auto; padding: 20px;">

    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="margin: 0; font-weight: 700; color: #1f2937;">
                <i class="fas fa-exclamation-triangle" style="color: #dc2626; margin-right: 10px;"></i>Incident Reports
            </h2>
            <p style="margin: 4px 0 0; color: #6b7280; font-size: 0.9rem;">
                <?php echo $role === 'admin' ? 'All incident and adverse event reports' : 'Your submitted incident reports'; ?>
            </p>
        </div>
        <button class="btn btn-primary" onclick="openModal('modal-create')"
            style="border-radius: 10px; padding: 10px 20px; font-weight: 600;">
            <i class="fas fa-plus"></i> Report New Incident
        </button>
    </div>

    <!-- Stats -->
    <div style="display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap;">
        <div class="inc-stat-card">
            <div class="inc-stat-icon" style="background: #ede9fe; color: #6d28d9;">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="inc-stat-info">
                <h4><?php echo $stat_total; ?></h4>
                <p>Total Reports</p>
            </div>
        </div>
        <div class="inc-stat-card">
            <div class="inc-stat-icon" style="background: #fee2e2; color: #dc2626;">
                <i class="fas fa-circle"></i>
            </div>
            <div class="inc-stat-info">
                <h4><?php echo $stat_open; ?></h4>
                <p>Open</p>
            </div>
        </div>
        <div class="inc-stat-card">
            <div class="inc-stat-icon" style="background: #fef3c7; color: #d97706;">
                <i class="fas fa-search"></i>
            </div>
            <div class="inc-stat-info">
                <h4><?php echo $stat_investigating; ?></h4>
                <p>Under Investigation</p>
            </div>
        </div>
        <div class="inc-stat-card">
            <div class="inc-stat-icon" style="background: #d1fae5; color: #059669;">
                <i class="fas fa-check-double"></i>
            </div>
            <div class="inc-stat-info">
                <h4><?php echo $stat_resolved; ?></h4>
                <p>Resolved</p>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="alert alert-danger" style="border-radius: 10px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="border-radius: 10px; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Search -->
    <div style="margin-bottom: 16px; display: flex; justify-content: flex-end;">
        <input type="text" id="filter-incidents" onkeyup="filterTable('filter-incidents','tbl-incidents')"
            placeholder="Search reports..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 240px; outline: none;">
    </div>

    <!-- Table -->
    <div class="card" style="border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-radius: 14px; overflow: hidden;">
        <div class="table-responsive">
            <table id="tbl-incidents" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; text-align: left;">
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase; white-space: nowrap;">Report #</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Type</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase; white-space: nowrap;">Date &amp; Time</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Location</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Patient</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase; white-space: nowrap;">Reported By</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Severity</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Status</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="9" style="padding: 40px; text-align: center; color: #9ca3af;">
                            <i class="fas fa-clipboard-list" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                            No incident reports found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $r): ?>
                    <?php
                        $sev_badge = match($r['severity'] ?? '') {
                            'Critical' => '<span class="sev-critical"><i class="fas fa-circle" style="margin-right:4px;font-size:0.65rem;"></i>Critical</span>',
                            'Severe'   => '<span class="sev-severe"><i class="fas fa-circle" style="margin-right:4px;font-size:0.65rem;"></i>Severe</span>',
                            'Moderate' => '<span class="sev-moderate"><i class="fas fa-circle" style="margin-right:4px;font-size:0.65rem;"></i>Moderate</span>',
                            'Minor'    => '<span class="sev-minor"><i class="fas fa-circle" style="margin-right:4px;font-size:0.65rem;"></i>Minor</span>',
                            default    => htmlspecialchars($r['severity'] ?? ''),
                        };
                        $status_badge = match($r['status'] ?? 'open') {
                            'under_investigation' => '<span class="status-investigating"><i class="fas fa-search" style="margin-right:4px;"></i>Under Investigation</span>',
                            'resolved'            => '<span class="status-resolved"><i class="fas fa-check" style="margin-right:4px;"></i>Resolved</span>',
                            default               => '<span class="status-open"><i class="fas fa-circle" style="margin-right:4px;font-size:0.65rem;"></i>Open</span>',
                        };
                        $inc_date     = $r['incident_datetime'] ? date('d M Y H:i', strtotime($r['incident_datetime'])) : '—';
                        $patient_name = ($r['p_first'] ?? '') ? htmlspecialchars($r['p_first'] . ' ' . $r['p_last']) : '<span style="color:#9ca3af;">—</span>';
                        $report_num   = 'INC-' . str_pad($r['report_number'] ?? '', 4, '0', STR_PAD_LEFT);

                        // Build next status options for dropdown
                        $next_statuses = [];
                        if (($r['status'] ?? 'open') !== 'under_investigation' && ($r['status'] ?? 'open') !== 'resolved') {
                            $next_statuses['under_investigation'] = 'Under Investigation';
                        }
                        if (($r['status'] ?? 'open') !== 'resolved') {
                            $next_statuses['resolved'] = 'Resolved';
                        }
                        if (($r['status'] ?? 'open') !== 'open') {
                            $next_statuses['open'] = 'Re-open';
                        }

                        // JSON-encoded data for view modal
                        $modal_data = htmlspecialchars(json_encode([
                            'id'               => $r['id'],
                            'report_number'    => $report_num,
                            'incident_type'    => $r['incident_type'] ?? '',
                            'incident_datetime'=> $inc_date,
                            'location'         => $r['location'] ?? '',
                            'patient'          => ($r['p_first'] ?? '') ? ($r['p_first'] . ' ' . $r['p_last']) : '',
                            'description'      => $r['description'] ?? '',
                            'immediate_action' => $r['immediate_action'] ?? '',
                            'severity'         => $r['severity'] ?? '',
                            'witness_names'    => $r['witness_names'] ?? '',
                            'status'           => $r['status'] ?? '',
                            'follow_up_notes'  => $r['follow_up_notes'] ?? '',
                            'reporter'         => $r['reporter_name'] ?? '',
                            'created_at'       => $r['created_at'] ? date('d M Y H:i', strtotime($r['created_at'])) : '',
                        ]), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr style="border-bottom: 1px solid #f3f4f6;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                        <td style="padding: 13px 16px;">
                            <span style="font-family: monospace; background: #f3f4f6; padding: 2px 7px; border-radius: 5px; font-size: 0.82rem; font-weight: 600;">
                                <?php echo htmlspecialchars($report_num); ?>
                            </span>
                        </td>
                        <td style="padding: 13px 16px; font-weight: 500;"><?php echo htmlspecialchars($r['incident_type'] ?? ''); ?></td>
                        <td style="padding: 13px 16px; white-space: nowrap; font-size: 0.88rem;"><?php echo $inc_date; ?></td>
                        <td style="padding: 13px 16px;"><?php echo htmlspecialchars($r['location'] ?? '—'); ?></td>
                        <td style="padding: 13px 16px;"><?php echo $patient_name; ?></td>
                        <td style="padding: 13px 16px; font-size: 0.88rem;"><?php echo htmlspecialchars($r['reporter_name'] ?? '—'); ?></td>
                        <td style="padding: 13px 16px;"><?php echo $sev_badge; ?></td>
                        <td style="padding: 13px 16px;"><?php echo $status_badge; ?></td>
                        <td style="padding: 13px 16px;">
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <!-- View Full Report -->
                                <button class="btn btn-sm"
                                    style="background: #ede9fe; color: #6d28d9; border-radius: 7px; font-size: 0.8rem;"
                                    onclick="viewIncidentReport(<?php echo $modal_data; ?>)"
                                    title="View full report">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                <!-- Update Status -->
                                <?php if (!empty($next_statuses)): ?>
                                <div style="position: relative; display: inline-block;">
                                    <button class="btn btn-sm"
                                        style="background: #fef3c7; color: #92400e; border-radius: 7px; font-size: 0.8rem;"
                                        onclick="toggleStatusMenu('menu-<?php echo htmlspecialchars($r['id']); ?>')"
                                        title="Update status">
                                        <i class="fas fa-exchange-alt"></i> Status
                                    </button>
                                    <div id="menu-<?php echo htmlspecialchars($r['id']); ?>"
                                        style="display:none; position:absolute; right:0; top:calc(100% + 4px); background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.12); z-index:200; min-width:190px; overflow:hidden;">
                                        <?php foreach ($next_statuses as $sv => $sl): ?>
                                        <form method="POST" action="">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="action"     value="update_status">
                                            <input type="hidden" name="report_id"  value="<?php echo htmlspecialchars($r['id']); ?>">
                                            <input type="hidden" name="new_status" value="<?php echo htmlspecialchars($sv); ?>">
                                            <button type="submit" style="display:block; width:100%; padding:10px 16px; background:none; border:none; text-align:left; cursor:pointer; font-size:0.85rem; color:#374151;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='none'">
                                                <i class="fas fa-arrow-right" style="margin-right:7px; color:#6b7280;"></i><?php echo htmlspecialchars($sl); ?>
                                            </button>
                                        </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Follow-up Notes -->
                                <button class="btn btn-sm"
                                    style="background: #e0f2fe; color: #075985; border-radius: 7px; font-size: 0.8rem;"
                                    onclick="openFollowup('<?php echo htmlspecialchars($r['id']); ?>','<?php echo htmlspecialchars(addslashes($r['follow_up_notes'] ?? '')); ?>')"
                                    title="Add follow-up notes">
                                    <i class="fas fa-notes-medical"></i> Notes
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── MODAL: Report New Incident ─────────────────────────────────────────── -->
<div id="modal-create" class="modal-overlay" onclick="if(event.target===this) closeModal('modal-create')">
    <div class="modal-box" style="max-width: 780px;">
        <div class="modal-header">
            <h5><i class="fas fa-plus-circle" style="color:#dc2626; margin-right:8px;"></i>Report New Incident</h5>
            <button class="btn-close-modal" onclick="closeModal('modal-create')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create">

                <div class="form-grid-2" style="margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Incident Type <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="incident_type" class="form-control" required style="border-radius: 8px;">
                            <option value="">-- Select Type --</option>
                            <?php foreach (['Medication Error','Patient Fall','Needle Stick Injury','Equipment Failure','Near Miss','Patient Complaint','Security Breach','Other'] as $it): ?>
                                <option value="<?php echo htmlspecialchars($it); ?>"><?php echo htmlspecialchars($it); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Severity <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="severity" class="form-control" required style="border-radius: 8px;">
                            <option value="">-- Select Severity --</option>
                            <option value="Minor">Minor</option>
                            <option value="Moderate">Moderate</option>
                            <option value="Severe">Severe</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2" style="margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Date &amp; Time of Incident <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="datetime-local" name="incident_datetime" class="form-control" required
                            style="border-radius: 8px;" max="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Location (Ward/Room) <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="text" name="location" class="form-control" required
                            placeholder="e.g. Ward 3 / Room 14" style="border-radius: 8px;">
                    </div>
                </div>

                <div class="form-grid-2" style="margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Involved Patient (optional)
                        </label>
                        <select name="patient_id" class="form-control" style="border-radius: 8px;">
                            <option value="">-- None / Not Applicable --</option>
                            <?php foreach ($all_patients as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['id']); ?>">
                                    <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Witness Name(s)
                        </label>
                        <input type="text" name="witness_names" class="form-control"
                            placeholder="Names of witnesses, comma-separated" style="border-radius: 8px;">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                        Description of Incident <span style="color:#dc2626;">*</span>
                    </label>
                    <textarea name="description" class="form-control" rows="4" required
                        placeholder="Provide a detailed description of what happened..."
                        style="border-radius: 8px; resize: vertical;"></textarea>
                </div>

                <div class="form-group">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                        Immediate Action Taken
                    </label>
                    <textarea name="immediate_action" class="form-control" rows="3"
                        placeholder="Describe any immediate steps taken in response to the incident..."
                        style="border-radius: 8px; resize: vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" style="background:#f3f4f6; color:#374151; border-radius: 8px;" onclick="closeModal('modal-create')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="border-radius: 8px; background: #dc2626; border-color: #dc2626;">
                    <i class="fas fa-paper-plane"></i> Submit Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── MODAL: View Full Report ─────────────────────────────────────────────── -->
<div id="modal-view" class="modal-overlay" onclick="if(event.target===this) closeModal('modal-view')">
    <div class="modal-box" style="max-width: 750px;">
        <div class="modal-header">
            <h5><i class="fas fa-clipboard-list" style="color:#dc2626; margin-right:8px;"></i>Incident Report Details</h5>
            <button class="btn-close-modal" onclick="closeModal('modal-view')">&times;</button>
        </div>
        <div class="modal-body" id="view-inc-body">
            <!-- Populated by JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" style="background:#f3f4f6; color:#374151; border-radius: 8px;" onclick="closeModal('modal-view')">
                Close
            </button>
        </div>
    </div>
</div>

<!-- ── MODAL: Follow-up Notes ──────────────────────────────────────────────── -->
<div id="modal-followup" class="modal-overlay" onclick="if(event.target===this) closeModal('modal-followup')">
    <div class="modal-box" style="max-width: 520px;">
        <div class="modal-header">
            <h5><i class="fas fa-notes-medical" style="color:#075985; margin-right:8px;"></i>Follow-up Notes</h5>
            <button class="btn-close-modal" onclick="closeModal('modal-followup')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action"    value="followup">
                <input type="hidden" name="report_id" id="followup-report-id" value="">
                <div class="form-group">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                        Notes / Investigation Findings
                    </label>
                    <textarea name="follow_up_notes" id="followup-notes-textarea" class="form-control" rows="6"
                        placeholder="Enter follow-up notes, investigation findings, corrective actions..."
                        style="border-radius: 8px; resize: vertical;" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" style="background:#f3f4f6; color:#374151; border-radius: 8px;" onclick="closeModal('modal-followup')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="border-radius: 8px;">
                    <i class="fas fa-save"></i> Save Notes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close status dropdown menus when clicking elsewhere
document.addEventListener('click', function(e) {
    if (!e.target.closest('[id^="menu-"]') && !e.target.closest('.btn')) {
        document.querySelectorAll('[id^="menu-"]').forEach(function(m) { m.style.display = 'none'; });
    }
});
function toggleStatusMenu(id) {
    var m = document.getElementById(id);
    var visible = m.style.display === 'block';
    document.querySelectorAll('[id^="menu-"]').forEach(function(x) { x.style.display = 'none'; });
    m.style.display = visible ? 'none' : 'block';
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

function viewIncidentReport(data) {
    var sevColors = {
        'Critical': '#991b1b', 'Severe': '#9a3412', 'Moderate': '#854d0e', 'Minor': '#065f46'
    };
    var sevBg = {
        'Critical': '#fee2e2', 'Severe': '#ffedd5', 'Moderate': '#fef9c3', 'Minor': '#d1fae5'
    };
    var statusMap = {
        'open': '<span class="status-open"><i class="fas fa-circle" style="margin-right:4px;font-size:0.65rem;"></i>Open</span>',
        'under_investigation': '<span class="status-investigating"><i class="fas fa-search" style="margin-right:4px;"></i>Under Investigation</span>',
        'resolved': '<span class="status-resolved"><i class="fas fa-check" style="margin-right:4px;"></i>Resolved</span>',
    };

    var sevStyle = 'background:' + (sevBg[data.severity]||'#f3f4f6') + ';color:' + (sevColors[data.severity]||'#374151') + ';padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:700;';

    var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">'
        + '<div><span class="detail-label">Report #</span><p class="detail-val" style="font-family:monospace;background:#f3f4f6;padding:3px 8px;border-radius:5px;display:inline-block;">' + escHtml(data.report_number) + '</p></div>'
        + '<div><span class="detail-label">Status</span><p class="detail-val">' + (statusMap[data.status] || escHtml(data.status)) + '</p></div>'
        + '<div><span class="detail-label">Incident Type</span><p class="detail-val" style="font-weight:600;">' + escHtml(data.incident_type) + '</p></div>'
        + '<div><span class="detail-label">Severity</span><p class="detail-val"><span style="' + sevStyle + '">' + escHtml(data.severity) + '</span></p></div>'
        + '<div><span class="detail-label">Date &amp; Time</span><p class="detail-val">' + escHtml(data.incident_datetime) + '</p></div>'
        + '<div><span class="detail-label">Location</span><p class="detail-val">' + escHtml(data.location) + '</p></div>'
        + '<div><span class="detail-label">Patient Involved</span><p class="detail-val">' + (data.patient ? escHtml(data.patient) : '—') + '</p></div>'
        + '<div><span class="detail-label">Reported By</span><p class="detail-val">' + escHtml(data.reporter) + '</p></div>'
        + '<div><span class="detail-label">Witness(es)</span><p class="detail-val">' + (data.witness_names ? escHtml(data.witness_names) : '—') + '</p></div>'
        + '<div><span class="detail-label">Filed On</span><p class="detail-val">' + escHtml(data.created_at) + '</p></div>'
        + '</div>'
        + '<div style="margin-bottom:14px;"><span class="detail-label" style="margin-bottom:6px;">Description</span>'
        + '<div class="detail-block">' + escHtml(data.description) + '</div></div>';

    if (data.immediate_action) {
        html += '<div style="margin-bottom:14px;"><span class="detail-label" style="margin-bottom:6px;">Immediate Action Taken</span>'
            + '<div class="detail-block">' + escHtml(data.immediate_action) + '</div></div>';
    }
    if (data.follow_up_notes) {
        html += '<div><span class="detail-label" style="margin-bottom:6px;">Follow-up Notes</span>'
            + '<div class="detail-block" style="border-color:#bfdbfe;background:#eff6ff;">' + escHtml(data.follow_up_notes) + '</div></div>';
    }

    document.getElementById('view-inc-body').innerHTML = html;
    openModal('modal-view');
}

function openFollowup(reportId, existingNotes) {
    document.getElementById('followup-report-id').value = reportId;
    document.getElementById('followup-notes-textarea').value = existingNotes;
    openModal('modal-followup');
}
</script>

<?php include '../../includes/footer.php'; ?>
