<?php
// modules/emergency/dashboard.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse', 'head_nurse']);

$page_title = "Emergency Dashboard";
include '../../includes/header.php';

$role     = get_user_role();
$user_id  = get_user_id();
$error    = '';
$success  = '';

// ── Handle: Register New Emergency Case ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $patient_name        = trim($_POST['patient_name'] ?? '');
        $age                 = (int)($_POST['age'] ?? 0);
        $gender              = trim($_POST['gender'] ?? '');
        $chief_complaint     = trim($_POST['chief_complaint'] ?? '');
        $triage_level        = trim($_POST['triage_level'] ?? '');
        $assigned_doctor_id  = trim($_POST['assigned_doctor_id'] ?? '') ?: null;
        $room_id             = trim($_POST['room_id'] ?? '') ?: null;
        $notes               = trim($_POST['notes'] ?? '');

        $allowed_triage = ['red', 'orange', 'yellow', 'green'];
        if (empty($patient_name) || $age <= 0 || empty($chief_complaint) || !in_array($triage_level, $allowed_triage)) {
            $error = "Please fill all required fields with valid values.";
        } else {
            try {
                db_insert('emergency_cases', [
                    'patient_name'       => $patient_name,
                    'age'                => $age,
                    'gender'             => $gender,
                    'chief_complaint'    => $chief_complaint,
                    'triage_level'       => $triage_level,
                    'assigned_doctor_id' => $assigned_doctor_id,
                    'room_id'            => $room_id,
                    'notes'              => $notes,
                    'status'             => 'active',
                    'created_by'         => $user_id,
                ]);
                $success = "Emergency case registered successfully.";
            } catch (Exception $e) {
                $error = "Failed to register case: " . $e->getMessage();
            }
        }
    }
}

// ── Handle: Update Case Status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $case_id       = trim($_POST['case_id'] ?? '');
        $new_triage    = trim($_POST['triage_level'] ?? '');
        $new_status    = trim($_POST['status'] ?? '');
        $update_notes  = trim($_POST['notes'] ?? '');

        $allowed_statuses = ['active', 'under_treatment', 'observation', 'discharged'];
        $allowed_triage   = ['red', 'orange', 'yellow', 'green'];

        if (empty($case_id) || !in_array($new_triage, $allowed_triage) || !in_array($new_status, $allowed_statuses)) {
            $error = "Invalid update parameters.";
        } else {
            try {
                db_update('emergency_cases',
                    ['triage_level' => $new_triage, 'status' => $new_status, 'notes' => $update_notes, 'updated_at' => 'NOW()'],
                    ['id' => $case_id]
                );
                $success = "Case updated successfully.";
            } catch (Exception $e) {
                $error = "Failed to update case: " . $e->getMessage();
            }
        }
    }
}

// ── Handle: Discharge Case ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'discharge') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $case_id = trim($_POST['case_id'] ?? '');
        if (!empty($case_id)) {
            try {
                db_update('emergency_cases',
                    ['status' => 'discharged', 'updated_at' => 'NOW()'],
                    ['id' => $case_id]
                );
                $success = "Patient discharged successfully.";
            } catch (Exception $e) {
                $error = "Failed to discharge patient: " . $e->getMessage();
            }
        }
    }
}

// ── Data: Stats for today ─────────────────────────────────────────────────────
$today_start = date('Y-m-d') . ' 00:00:00';

$stat_total      = db_select_one("SELECT COUNT(*) AS c FROM emergency_cases WHERE created_at >= $1", [$today_start]);
$stat_critical   = db_select_one("SELECT COUNT(*) AS c FROM emergency_cases WHERE triage_level = 'red'   AND created_at >= $1", [$today_start]);
$stat_serious    = db_select_one("SELECT COUNT(*) AS c FROM emergency_cases WHERE triage_level = 'orange' AND created_at >= $1", [$today_start]);
$stat_stable     = db_select_one("SELECT COUNT(*) AS c FROM emergency_cases WHERE triage_level IN ('yellow','green') AND created_at >= $1", [$today_start]);
$stat_discharged = db_select_one("SELECT COUNT(*) AS c FROM emergency_cases WHERE status = 'discharged' AND created_at >= $1", [$today_start]);

$cnt_total      = (int)($stat_total['c'] ?? 0);
$cnt_critical   = (int)($stat_critical['c'] ?? 0);
$cnt_serious    = (int)($stat_serious['c'] ?? 0);
$cnt_stable     = (int)($stat_stable['c'] ?? 0);
$cnt_discharged = (int)($stat_discharged['c'] ?? 0);

// ── Data: Doctors list ────────────────────────────────────────────────────────
$doctors = db_select("SELECT id, first_name, last_name, specialization FROM staff WHERE role = 'doctor' ORDER BY last_name ASC");

// ── Data: Rooms list ──────────────────────────────────────────────────────────
$rooms = db_select("SELECT id, room_number, room_type FROM rooms ORDER BY room_number ASC");

// ── Data: Active cases (with filter) ─────────────────────────────────────────
$filter_triage = trim($_GET['triage'] ?? '');
$allowed_filter = ['red', 'orange', 'yellow', 'green'];

$sql_cases  = "SELECT ec.*, s.first_name AS doc_first, s.last_name AS doc_last,
                       r.room_number
               FROM emergency_cases ec
               LEFT JOIN staff s ON ec.assigned_doctor_id = s.id
               LEFT JOIN rooms r ON ec.room_id = r.id
               WHERE ec.status != 'discharged'";
$params_cases = [];
if (in_array($filter_triage, $allowed_filter)) {
    $params_cases[] = $filter_triage;
    $sql_cases .= " AND ec.triage_level = $1";
}
$sql_cases .= " ORDER BY CASE ec.triage_level WHEN 'red' THEN 1 WHEN 'orange' THEN 2 WHEN 'yellow' THEN 3 ELSE 4 END, ec.created_at ASC";
$active_cases = db_select($sql_cases, $params_cases);

// ── Helper: triage badge ──────────────────────────────────────────────────────
function triage_badge(string $level): string {
    $map = [
        'red'    => ['bg' => '#dc3545', 'label' => 'Critical'],
        'orange' => ['bg' => '#fd7e14', 'label' => 'Serious'],
        'yellow' => ['bg' => '#ffc107', 'label' => 'Moderate', 'color' => '#212529'],
        'green'  => ['bg' => '#28a745', 'label' => 'Minor'],
    ];
    $cfg   = $map[$level] ?? ['bg' => '#6c757d', 'label' => ucfirst($level)];
    $color = $cfg['color'] ?? '#fff';
    $bg    = htmlspecialchars($cfg['bg']);
    $lbl   = htmlspecialchars($cfg['label']);
    return "<span class=\"badge\" style=\"background:{$bg};color:{$color};padding:4px 10px;border-radius:20px;font-size:0.78em;font-weight:600;\">{$lbl}</span>";
}

// ── Helper: status badge ──────────────────────────────────────────────────────
function status_badge(string $status): string {
    $map = [
        'active'          => ['bg' => '#17a2b8', 'label' => 'Active'],
        'under_treatment' => ['bg' => '#6f42c1', 'label' => 'Under Treatment'],
        'observation'     => ['bg' => '#fd7e14', 'label' => 'Observation'],
        'discharged'      => ['bg' => '#28a745', 'label' => 'Discharged'],
    ];
    $cfg   = $map[$status] ?? ['bg' => '#6c757d', 'label' => ucfirst($status)];
    $bg    = htmlspecialchars($cfg['bg']);
    $lbl   = htmlspecialchars($cfg['label']);
    return "<span class=\"badge\" style=\"background:{$bg};color:#fff;padding:4px 10px;border-radius:20px;font-size:0.78em;font-weight:600;\">{$lbl}</span>";
}

// ── Helper: human-readable time since ────────────────────────────────────────
function time_since(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)         return $diff . 's ago';
    if ($diff < 3600)       return floor($diff / 60) . 'm ago';
    if ($diff < 86400)      return floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm ago';
    return floor($diff / 86400) . 'd ago';
}
?>

<style>
    /* ── Emergency Dashboard Styles ── */
    .emg-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
        gap: 18px;
        margin-bottom: 28px;
    }
    .emg-stat-card {
        background: #fff;
        border-radius: 14px;
        padding: 20px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        border: 1px solid #f0f0f0;
    }
    .emg-stat-icon {
        width: 50px; height: 50px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .emg-stat-info h4  { margin: 0; font-size: 1.7rem; font-weight: 700; color: #111827; line-height: 1; }
    .emg-stat-info p   { margin: 4px 0 0; font-size: 0.82rem; color: #6b7280; font-weight: 500; }
    .emg-toolbar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .emg-filter-btn {
        padding: 6px 16px;
        border-radius: 20px;
        border: 1.5px solid #dee2e6;
        background: #fff;
        font-size: 0.84em;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        color: #374151;
        transition: all 0.15s;
    }
    .emg-filter-btn:hover  { background: #f3f4f6; }
    .emg-filter-btn.active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
    .emg-filter-btn.red    { border-color: #dc3545; color: #dc3545; }
    .emg-filter-btn.red.active    { background: #dc3545; color: #fff; }
    .emg-filter-btn.orange { border-color: #fd7e14; color: #fd7e14; }
    .emg-filter-btn.orange.active { background: #fd7e14; color: #fff; }
    .emg-filter-btn.yellow { border-color: #ffc107; color: #856404; }
    .emg-filter-btn.yellow.active { background: #ffc107; color: #212529; border-color: #ffc107; }
    .emg-filter-btn.green  { border-color: #28a745; color: #28a745; }
    .emg-filter-btn.green.active  { background: #28a745; color: #fff; }

    /* ── Modal Styles ── */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1050;
        align-items: center;
        justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
        background: #fff;
        border-radius: 16px;
        padding: 28px 30px;
        width: 100%;
        max-width: 560px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        position: relative;
    }
    .modal-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #111827;
        margin: 0 0 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .modal-close {
        position: absolute;
        top: 16px; right: 18px;
        background: none; border: none;
        font-size: 1.3rem; color: #9ca3af;
        cursor: pointer;
        line-height: 1;
    }
    .modal-close:hover { color: #374151; }
    .form-group { margin-bottom: 16px; }
    .form-group label {
        display: block;
        font-size: 0.84em;
        font-weight: 600;
        color: #374151;
        margin-bottom: 5px;
    }
    .form-group .form-control {
        width: 100%;
        padding: 9px 12px;
        border: 1.5px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.9em;
        box-sizing: border-box;
        transition: border-color 0.15s;
        background: #fff;
    }
    .form-group .form-control:focus {
        outline: none;
        border-color: #3b82f6;
    }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    /* ── Table ── */
    .emg-table { width: 100%; border-collapse: collapse; }
    .emg-table thead tr { background: #f8f9fa; }
    .emg-table th {
        padding: 11px 12px;
        text-align: left;
        font-size: 0.8em;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #e5e7eb;
    }
    .emg-table td {
        padding: 12px 12px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 0.88em;
        color: #374151;
        vertical-align: middle;
    }
    .emg-table tbody tr:hover { background: #fafafa; }
    .btn-xs {
        padding: 4px 10px;
        font-size: 0.78em;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .case-number {
        font-family: monospace;
        background: #f0f4ff;
        color: #1d4ed8;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.9em;
        font-weight: 700;
    }
</style>

<div style="max-width: 1300px; margin: 0 auto;">

    <!-- ── Alerts ── -->
    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- ── Stats Cards ── -->
    <div class="emg-stats-grid">
        <div class="emg-stat-card">
            <div class="emg-stat-icon" style="background:#eff6ff;">
                <i class="fas fa-ambulance" style="color:#1d4ed8;"></i>
            </div>
            <div class="emg-stat-info">
                <h4><?php echo $cnt_total; ?></h4>
                <p>Total Cases Today</p>
            </div>
        </div>
        <div class="emg-stat-card">
            <div class="emg-stat-icon" style="background:#fef2f2;">
                <i class="fas fa-heartbeat" style="color:#dc3545;"></i>
            </div>
            <div class="emg-stat-info">
                <h4><?php echo $cnt_critical; ?></h4>
                <p>Critical (Red)</p>
            </div>
        </div>
        <div class="emg-stat-card">
            <div class="emg-stat-icon" style="background:#fff7ed;">
                <i class="fas fa-exclamation-triangle" style="color:#fd7e14;"></i>
            </div>
            <div class="emg-stat-info">
                <h4><?php echo $cnt_serious; ?></h4>
                <p>Serious (Orange)</p>
            </div>
        </div>
        <div class="emg-stat-card">
            <div class="emg-stat-icon" style="background:#f0fdf4;">
                <i class="fas fa-user-check" style="color:#28a745;"></i>
            </div>
            <div class="emg-stat-info">
                <h4><?php echo $cnt_stable; ?></h4>
                <p>Stable (Yellow/Green)</p>
            </div>
        </div>
        <div class="emg-stat-card">
            <div class="emg-stat-icon" style="background:#f0fdf4;">
                <i class="fas fa-door-open" style="color:#6b7280;"></i>
            </div>
            <div class="emg-stat-info">
                <h4><?php echo $cnt_discharged; ?></h4>
                <p>Discharged Today</p>
            </div>
        </div>
    </div>

    <!-- ── Toolbar: Filter + Register button ── -->
    <div class="card" style="padding: 20px 24px;">
        <div class="emg-toolbar">
            <a href="?triage=" class="emg-filter-btn <?php echo $filter_triage === '' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All Active
            </a>
            <a href="?triage=red"    class="emg-filter-btn red    <?php echo $filter_triage === 'red'    ? 'active' : ''; ?>">
                <i class="fas fa-circle"></i> Critical
            </a>
            <a href="?triage=orange" class="emg-filter-btn orange <?php echo $filter_triage === 'orange' ? 'active' : ''; ?>">
                <i class="fas fa-circle"></i> Serious
            </a>
            <a href="?triage=yellow" class="emg-filter-btn yellow <?php echo $filter_triage === 'yellow' ? 'active' : ''; ?>">
                <i class="fas fa-circle"></i> Moderate
            </a>
            <a href="?triage=green"  class="emg-filter-btn green  <?php echo $filter_triage === 'green'  ? 'active' : ''; ?>">
                <i class="fas fa-circle"></i> Minor
            </a>
            <div style="margin-left: auto;">
                <button onclick="openModal('modal-triage')"
                        class="btn btn-primary"
                        style="background:#dc3545;border:none;color:#fff;padding:9px 18px;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.9em;">
                    <i class="fas fa-plus"></i> Register Emergency Case
                </button>
            </div>
        </div>

        <!-- ── Active Cases Table ── -->
        <div class="table-responsive">
            <table class="emg-table" id="tbl-emergency">
                <thead>
                    <tr>
                        <th>Case #</th>
                        <th>Patient</th>
                        <th>Age</th>
                        <th>Chief Complaint</th>
                        <th>Triage</th>
                        <th>Doctor</th>
                        <th>Room</th>
                        <th>Time Since</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($active_cases)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:30px;color:#9ca3af;">
                            <i class="fas fa-check-circle" style="font-size:2em;opacity:0.4;margin-bottom:8px;display:block;"></i>
                            No active emergency cases<?php echo $filter_triage ? ' for selected triage level' : ''; ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($active_cases as $c): ?>
                    <tr>
                        <td>
                            <span class="case-number">EC-<?php echo str_pad($c['case_number'] ?? '0', 4, '0', STR_PAD_LEFT); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($c['patient_name']); ?></strong></td>
                        <td><?php echo (int)$c['age']; ?></td>
                        <td style="max-width:200px;word-break:break-word;"><?php echo htmlspecialchars($c['chief_complaint']); ?></td>
                        <td><?php echo triage_badge($c['triage_level']); ?></td>
                        <td>
                            <?php if ($c['doc_first']): ?>
                                Dr. <?php echo htmlspecialchars($c['doc_first'] . ' ' . $c['doc_last']); ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $c['room_number'] ? htmlspecialchars($c['room_number']) : '<span style="color:#9ca3af;">–</span>'; ?></td>
                        <td>
                            <span style="font-size:0.85em;color:#6b7280;">
                                <i class="far fa-clock"></i>
                                <?php echo time_since($c['created_at']); ?>
                            </span>
                        </td>
                        <td><?php echo status_badge($c['status']); ?></td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <!-- View details -->
                                <button
                                    class="btn-xs"
                                    style="background:#e0e7ff;color:#3730a3;"
                                    onclick="openViewModal(
                                        '<?php echo htmlspecialchars($c['case_number'] ?? '', ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['patient_name'], ENT_QUOTES); ?>',
                                        <?php echo (int)$c['age']; ?>,
                                        '<?php echo htmlspecialchars($c['gender'] ?? '', ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['chief_complaint'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['triage_level'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars(($c['doc_first'] ?? '') . ' ' . ($c['doc_last'] ?? ''), ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['room_number'] ?? '', ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['notes'] ?? '', ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['status'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['created_at'], ENT_QUOTES); ?>'
                                    )">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <!-- Update status -->
                                <button
                                    class="btn-xs"
                                    style="background:#fef3c7;color:#92400e;"
                                    onclick="openUpdateModal(
                                        '<?php echo htmlspecialchars($c['id'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['triage_level'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($c['status'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars(addslashes($c['notes'] ?? ''), ENT_QUOTES); ?>'
                                    )">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                <!-- Discharge -->
                                <form method="POST" action="" style="display:inline;"
                                      onsubmit="return confirm('Discharge this patient?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action"  value="discharge">
                                    <input type="hidden" name="case_id" value="<?php echo htmlspecialchars($c['id']); ?>">
                                    <button type="submit" class="btn-xs" style="background:#d1fae5;color:#065f46;">
                                        <i class="fas fa-sign-out-alt"></i> Discharge
                                    </button>
                                </form>
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

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL 1: Register New Emergency Case (Triage Form)
════════════════════════════════════════════════════════════════════════ -->
<div id="modal-triage" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="triage-modal-title">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('modal-triage')" aria-label="Close">&times;</button>
        <h3 class="modal-title" id="triage-modal-title">
            <i class="fas fa-ambulance" style="color:#dc3545;"></i> Register Emergency Case
        </h3>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="create">

            <div class="form-row-2">
                <div class="form-group">
                    <label>Patient Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="patient_name" class="form-control" placeholder="Full name" required>
                </div>
                <div class="form-group">
                    <label>Age <span style="color:#dc3545;">*</span></label>
                    <input type="number" name="age" class="form-control" placeholder="Years" min="0" max="150" required>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender" class="form-control">
                        <option value="">-- Select --</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Triage Level <span style="color:#dc3545;">*</span></label>
                    <select name="triage_level" class="form-control" required>
                        <option value="">-- Select Triage --</option>
                        <option value="red">&#x1F534; Red — Critical</option>
                        <option value="orange">&#x1F7E0; Orange — Serious</option>
                        <option value="yellow">&#x1F7E1; Yellow — Moderate</option>
                        <option value="green">&#x1F7E2; Green — Minor</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Chief Complaint <span style="color:#dc3545;">*</span></label>
                <input type="text" name="chief_complaint" class="form-control" placeholder="e.g. Chest pain, difficulty breathing..." required>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Assign Doctor</label>
                    <select name="assigned_doctor_id" class="form-control">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo htmlspecialchars($doc['id']); ?>">
                                Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                                <?php if ($doc['specialization']): ?>
                                    (<?php echo htmlspecialchars($doc['specialization']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assign Bed / Room</label>
                    <select name="room_id" class="form-control">
                        <option value="">-- None --</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo htmlspecialchars($room['id']); ?>">
                                <?php echo htmlspecialchars($room['room_number'] . ' (' . ($room['room_type'] ?? 'Room') . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes, allergies, medications..."></textarea>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-triage')"
                        style="padding:9px 20px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:600;cursor:pointer;font-size:0.9em;color:#374151;">
                    Cancel
                </button>
                <button type="submit"
                        style="padding:9px 22px;border-radius:8px;border:none;background:#dc3545;color:#fff;font-weight:700;cursor:pointer;font-size:0.9em;">
                    <i class="fas fa-plus"></i> Register Case
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL 2: Update Case Status
════════════════════════════════════════════════════════════════════════ -->
<div id="modal-update" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="update-modal-title">
    <div class="modal-box" style="max-width:480px;">
        <button class="modal-close" onclick="closeModal('modal-update')" aria-label="Close">&times;</button>
        <h3 class="modal-title" id="update-modal-title">
            <i class="fas fa-edit" style="color:#f59e0b;"></i> Update Case
        </h3>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action"  value="update">
            <input type="hidden" name="case_id" id="update-case-id">

            <div class="form-group">
                <label>Triage Level</label>
                <select name="triage_level" id="update-triage" class="form-control" required>
                    <option value="red">&#x1F534; Red — Critical</option>
                    <option value="orange">&#x1F7E0; Orange — Serious</option>
                    <option value="yellow">&#x1F7E1; Yellow — Moderate</option>
                    <option value="green">&#x1F7E2; Green — Minor</option>
                </select>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status" id="update-status" class="form-control" required>
                    <option value="active">Active</option>
                    <option value="under_treatment">Under Treatment</option>
                    <option value="observation">Observation</option>
                    <option value="discharged">Discharged</option>
                </select>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" id="update-notes" class="form-control" rows="3" placeholder="Update notes..."></textarea>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-update')"
                        style="padding:9px 20px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:600;cursor:pointer;font-size:0.9em;color:#374151;">
                    Cancel
                </button>
                <button type="submit"
                        style="padding:9px 22px;border-radius:8px;border:none;background:#f59e0b;color:#fff;font-weight:700;cursor:pointer;font-size:0.9em;">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL 3: View Case Details
════════════════════════════════════════════════════════════════════════ -->
<div id="modal-view" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="view-modal-title">
    <div class="modal-box" style="max-width:500px;">
        <button class="modal-close" onclick="closeModal('modal-view')" aria-label="Close">&times;</button>
        <h3 class="modal-title" id="view-modal-title">
            <i class="fas fa-file-medical" style="color:#1d4ed8;"></i>
            Case Details — <span id="view-case-num" style="font-family:monospace;"></span>
        </h3>
        <div id="view-body" style="font-size:0.9em;line-height:1.8;">
            <!-- Populated by JS -->
        </div>
        <div style="text-align:right;margin-top:18px;">
            <button onclick="closeModal('modal-view')"
                    style="padding:9px 22px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:600;cursor:pointer;font-size:0.9em;color:#374151;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
// ── Modal helpers ──────────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});

// ── Open Update Modal ─────────────────────────────────────────────────────────
function openUpdateModal(caseId, triage, status, notes) {
    document.getElementById('update-case-id').value  = caseId;
    document.getElementById('update-triage').value   = triage;
    document.getElementById('update-status').value   = status;
    document.getElementById('update-notes').value    = notes;
    openModal('modal-update');
}

// ── Open View Modal ───────────────────────────────────────────────────────────
function openViewModal(caseNum, name, age, gender, complaint, triage, doctor, room, notes, status, createdAt) {
    document.getElementById('view-case-num').textContent = 'EC-' + String(caseNum).padStart(4, '0');

    var triageLabels = { red:'Critical', orange:'Serious', yellow:'Moderate', green:'Minor' };
    var triageColors = { red:'#dc3545', orange:'#fd7e14', yellow:'#856404', green:'#28a745' };
    var statusLabels = { active:'Active', under_treatment:'Under Treatment', observation:'Observation', discharged:'Discharged' };
    var statusColors = { active:'#17a2b8', under_treatment:'#6f42c1', observation:'#fd7e14', discharged:'#28a745' };

    var tLbl   = triageLabels[triage]  || triage;
    var tColor = triageColors[triage]  || '#6c757d';
    var sLbl   = statusLabels[status]  || status;
    var sColor = statusColors[status]  || '#6c757d';

    var html = '<table style="width:100%;border-collapse:collapse;">';
    function row(label, value) {
        html += '<tr><td style="padding:6px 10px 6px 0;color:#6b7280;font-weight:600;width:38%;vertical-align:top;">' + label + '</td>'
              + '<td style="padding:6px 0;color:#111827;">' + value + '</td></tr>';
    }
    row('Patient Name',    '<strong>' + escHtml(name) + '</strong>');
    row('Age / Gender',    age + ' yrs' + (gender ? ' / ' + escHtml(gender) : ''));
    row('Chief Complaint', escHtml(complaint));
    row('Triage Level',    '<span style="background:' + tColor + ';color:' + (triage==='yellow'?'#212529':'#fff') + ';padding:3px 10px;border-radius:20px;font-size:0.82em;font-weight:600;">' + escHtml(tLbl) + '</span>');
    row('Assigned Doctor', doctor && doctor.trim() ? 'Dr. ' + escHtml(doctor) : '<span style="color:#9ca3af;">Unassigned</span>');
    row('Room / Bed',      room  ? escHtml(room) : '<span style="color:#9ca3af;">Not assigned</span>');
    row('Status',          '<span style="background:' + sColor + ';color:#fff;padding:3px 10px;border-radius:20px;font-size:0.82em;font-weight:600;">' + escHtml(sLbl) + '</span>');
    row('Admitted At',     escHtml(createdAt));
    row('Notes',           notes ? escHtml(notes) : '<span style="color:#9ca3af;">None</span>');
    html += '</table>';

    document.getElementById('view-body').innerHTML = html;
    openModal('modal-view');
}

function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
}
</script>

<?php include '../../includes/footer.php'; ?>
