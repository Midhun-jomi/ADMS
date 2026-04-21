<?php
// modules/referrals/referrals.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor']);

$page_title = "Patient Referrals";
include '../../includes/header.php';

$role    = get_user_role();
$user_id = get_user_id();
$error   = '';
$success = '';

// ── Resolve current staff ID (for doctor context) ─────────────────────────────
$current_staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
$current_staff_id = $current_staff['id'] ?? null;

// ── Handle: Create Referral ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $patient_id       = trim($_POST['patient_id'] ?? '');
        $from_doctor_id   = trim($_POST['from_doctor_id'] ?? '');
        $to_doctor_id     = trim($_POST['to_doctor_id'] ?? '') ?: null;
        $referral_type    = trim($_POST['referral_type'] ?? '');
        $priority         = trim($_POST['priority'] ?? '');
        $reason           = trim($_POST['reason'] ?? '');
        $notes            = trim($_POST['notes'] ?? '');
        $external_hospital = trim($_POST['external_hospital'] ?? '');

        $allowed_types     = ['Internal', 'External'];
        $allowed_priorities = ['Routine', 'Urgent', 'Emergency'];

        if (empty($patient_id) || empty($from_doctor_id) || !in_array($referral_type, $allowed_types) || !in_array($priority, $allowed_priorities) || empty($reason)) {
            $error = "Please fill all required fields correctly.";
        } else {
            try {
                db_insert('referrals', [
                    'patient_id'        => $patient_id,
                    'from_doctor_id'    => $from_doctor_id,
                    'to_doctor_id'      => $to_doctor_id,
                    'referral_type'     => $referral_type,
                    'priority'          => $priority,
                    'reason'            => $reason,
                    'notes'             => $notes,
                    'external_hospital' => $referral_type === 'External' ? $external_hospital : null,
                    'status'            => 'pending',
                    'created_by'        => $user_id,
                ]);
                $success = "Referral created successfully.";
            } catch (Exception $e) {
                $error = "Failed to create referral: " . $e->getMessage();
            }
        }
    }
}

// ── Handle: Update Status ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $ref_id     = trim($_POST['ref_id'] ?? '');
        $new_status = trim($_POST['status'] ?? '');
        $allowed_statuses = ['pending', 'accepted', 'completed', 'cancelled'];

        if (empty($ref_id) || !in_array($new_status, $allowed_statuses)) {
            $error = "Invalid status update parameters.";
        } else {
            try {
                db_update('referrals', ['status' => $new_status], ['id' => $ref_id]);
                $success = "Referral status updated to " . ucfirst($new_status) . ".";
            } catch (Exception $e) {
                $error = "Failed to update referral: " . $e->getMessage();
            }
        }
    }
}

// ── Handle: Delete (admin only) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if ($role !== 'admin') {
        $error = "Unauthorised action.";
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $ref_id = trim($_POST['ref_id'] ?? '');
        if (!empty($ref_id)) {
            try {
                db_query("DELETE FROM referrals WHERE id = $1", [$ref_id]);
                $success = "Referral deleted.";
            } catch (Exception $e) {
                $error = "Failed to delete referral: " . $e->getMessage();
            }
        }
    }
}

// ── Data: Stats ───────────────────────────────────────────────────────────────
$stat_total     = db_select_one("SELECT COUNT(*) AS c FROM referrals", []);
$stat_pending   = db_select_one("SELECT COUNT(*) AS c FROM referrals WHERE status = 'pending'", []);
$stat_accepted  = db_select_one("SELECT COUNT(*) AS c FROM referrals WHERE status = 'accepted'", []);
$stat_completed = db_select_one("SELECT COUNT(*) AS c FROM referrals WHERE status = 'completed'", []);

$cnt_total     = (int)($stat_total['c']     ?? 0);
$cnt_pending   = (int)($stat_pending['c']   ?? 0);
$cnt_accepted  = (int)($stat_accepted['c']  ?? 0);
$cnt_completed = (int)($stat_completed['c'] ?? 0);

// ── Data: Patients list ───────────────────────────────────────────────────────
$patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name ASC, first_name ASC");

// ── Data: Doctors list ────────────────────────────────────────────────────────
$doctors = db_select("SELECT id, first_name, last_name, specialization FROM staff WHERE role = 'doctor' ORDER BY last_name ASC");

// ── Data: Referrals (with filter) ─────────────────────────────────────────────
$filter_status = trim($_GET['status'] ?? '');
$allowed_filter_statuses = ['pending', 'accepted', 'completed', 'cancelled'];

$sql_referrals  = "SELECT r.*,
                          p.first_name  AS pat_first,  p.last_name  AS pat_last,
                          fd.first_name AS from_first,  fd.last_name AS from_last,
                          td.first_name AS to_first,   td.last_name AS to_last
                   FROM referrals r
                   JOIN patients p  ON r.patient_id     = p.id
                   JOIN staff    fd ON r.from_doctor_id = fd.id
                   LEFT JOIN staff td ON r.to_doctor_id = td.id";
$params_refs   = [];
if (in_array($filter_status, $allowed_filter_statuses)) {
    $params_refs[]    = $filter_status;
    $sql_referrals   .= " WHERE r.status = $1";
}
$sql_referrals .= " ORDER BY r.created_at DESC";
$referrals = db_select($sql_referrals, $params_refs);

// ── Helpers: badges ───────────────────────────────────────────────────────────
function priority_badge(string $priority): string {
    $map = [
        'Routine'   => ['bg' => '#6c757d', 'label' => 'Routine'],
        'Urgent'    => ['bg' => '#fd7e14', 'label' => 'Urgent'],
        'Emergency' => ['bg' => '#dc3545', 'label' => 'Emergency'],
    ];
    $cfg = $map[$priority] ?? ['bg' => '#6c757d', 'label' => ucfirst($priority)];
    $bg  = htmlspecialchars($cfg['bg']);
    $lbl = htmlspecialchars($cfg['label']);
    return "<span class=\"badge\" style=\"background:{$bg};color:#fff;padding:4px 10px;border-radius:20px;font-size:0.78em;font-weight:600;\">{$lbl}</span>";
}

function ref_status_badge(string $status): string {
    $map = [
        'pending'   => ['bg' => '#ffc107', 'color' => '#212529', 'label' => 'Pending'],
        'accepted'  => ['bg' => '#0d6efd', 'color' => '#fff',     'label' => 'Accepted'],
        'completed' => ['bg' => '#28a745', 'color' => '#fff',     'label' => 'Completed'],
        'cancelled' => ['bg' => '#dc3545', 'color' => '#fff',     'label' => 'Cancelled'],
    ];
    $cfg   = $map[$status] ?? ['bg' => '#6c757d', 'color' => '#fff', 'label' => ucfirst($status)];
    $bg    = htmlspecialchars($cfg['bg']);
    $color = htmlspecialchars($cfg['color']);
    $lbl   = htmlspecialchars($cfg['label']);
    return "<span class=\"badge\" style=\"background:{$bg};color:{$color};padding:4px 10px;border-radius:20px;font-size:0.78em;font-weight:600;\">{$lbl}</span>";
}
?>

<style>
    /* ── Referrals Page Styles ── */
    .ref-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
        gap: 18px;
        margin-bottom: 28px;
    }
    .ref-stat-card {
        background: #fff;
        border-radius: 14px;
        padding: 20px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        border: 1px solid #f0f0f0;
    }
    .ref-stat-icon {
        width: 50px; height: 50px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .ref-stat-info h4 { margin: 0; font-size: 1.7rem; font-weight: 700; color: #111827; line-height: 1; }
    .ref-stat-info p  { margin: 4px 0 0; font-size: 0.82rem; color: #6b7280; font-weight: 500; }

    /* ── Filter toolbar ── */
    .ref-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .ref-filter-btn {
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
    .ref-filter-btn:hover  { background: #f3f4f6; }
    .ref-filter-btn.active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }

    /* ── Modal ── */
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
        max-width: 580px;
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
    .form-group .form-control:focus { outline: none; border-color: #3b82f6; }
    .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    /* ── Table ── */
    .ref-table { width: 100%; border-collapse: collapse; }
    .ref-table thead tr { background: #f8f9fa; }
    .ref-table th {
        padding: 11px 12px;
        text-align: left;
        font-size: 0.8em;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 2px solid #e5e7eb;
    }
    .ref-table td {
        padding: 12px 12px;
        border-bottom: 1px solid #f3f4f6;
        font-size: 0.88em;
        color: #374151;
        vertical-align: middle;
    }
    .ref-table tbody tr:hover { background: #fafafa; }
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
    .ref-id {
        font-family: monospace;
        background: #f0f4ff;
        color: #1d4ed8;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.9em;
        font-weight: 700;
    }
    .type-badge {
        display: inline-block;
        padding: 2px 9px;
        border-radius: 12px;
        font-size: 0.78em;
        font-weight: 600;
    }
    /* External hospital row highlight */
    .external-row { background: #fefce8; }
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
    <div class="ref-stats-grid">
        <div class="ref-stat-card">
            <div class="ref-stat-icon" style="background:#eff6ff;">
                <i class="fas fa-exchange-alt" style="color:#1d4ed8;"></i>
            </div>
            <div class="ref-stat-info">
                <h4><?php echo $cnt_total; ?></h4>
                <p>Total Referrals</p>
            </div>
        </div>
        <div class="ref-stat-card">
            <div class="ref-stat-icon" style="background:#fffbeb;">
                <i class="fas fa-hourglass-half" style="color:#d97706;"></i>
            </div>
            <div class="ref-stat-info">
                <h4><?php echo $cnt_pending; ?></h4>
                <p>Pending</p>
            </div>
        </div>
        <div class="ref-stat-card">
            <div class="ref-stat-icon" style="background:#eff6ff;">
                <i class="fas fa-thumbs-up" style="color:#0d6efd;"></i>
            </div>
            <div class="ref-stat-info">
                <h4><?php echo $cnt_accepted; ?></h4>
                <p>Accepted</p>
            </div>
        </div>
        <div class="ref-stat-card">
            <div class="ref-stat-icon" style="background:#f0fdf4;">
                <i class="fas fa-check-double" style="color:#28a745;"></i>
            </div>
            <div class="ref-stat-info">
                <h4><?php echo $cnt_completed; ?></h4>
                <p>Completed</p>
            </div>
        </div>
    </div>

    <!-- ── Toolbar: Filter + Create Referral ── -->
    <div class="card" style="padding: 20px 24px;">
        <div class="ref-toolbar">
            <a href="?status="          class="ref-filter-btn <?php echo $filter_status === '' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> All
            </a>
            <a href="?status=pending"   class="ref-filter-btn <?php echo $filter_status === 'pending'   ? 'active' : ''; ?>"
               style="<?php echo $filter_status === 'pending'   ? '' : 'border-color:#ffc107;color:#856404;'; ?>">
                <i class="fas fa-hourglass-half"></i> Pending
            </a>
            <a href="?status=accepted"  class="ref-filter-btn <?php echo $filter_status === 'accepted'  ? 'active' : ''; ?>"
               style="<?php echo $filter_status === 'accepted'  ? '' : 'border-color:#0d6efd;color:#0d6efd;'; ?>">
                <i class="fas fa-thumbs-up"></i> Accepted
            </a>
            <a href="?status=completed" class="ref-filter-btn <?php echo $filter_status === 'completed' ? 'active' : ''; ?>"
               style="<?php echo $filter_status === 'completed' ? '' : 'border-color:#28a745;color:#28a745;'; ?>">
                <i class="fas fa-check-double"></i> Completed
            </a>
            <a href="?status=cancelled" class="ref-filter-btn <?php echo $filter_status === 'cancelled' ? 'active' : ''; ?>"
               style="<?php echo $filter_status === 'cancelled' ? '' : 'border-color:#dc3545;color:#dc3545;'; ?>">
                <i class="fas fa-times-circle"></i> Cancelled
            </a>
            <div style="margin-left: auto;">
                <button onclick="openModal('modal-create')"
                        class="btn btn-primary"
                        style="background:#1d4ed8;border:none;color:#fff;padding:9px 18px;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.9em;">
                    <i class="fas fa-plus"></i> Create Referral
                </button>
            </div>
        </div>

        <!-- ── Referrals Table ── -->
        <div class="table-responsive">
            <table class="ref-table" id="tbl-referrals">
                <thead>
                    <tr>
                        <th>Referral ID</th>
                        <th>Patient</th>
                        <th>From Doctor</th>
                        <th>To Doctor / Hospital</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Reason</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($referrals)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:30px;color:#9ca3af;">
                            <i class="fas fa-exchange-alt" style="font-size:2em;opacity:0.3;margin-bottom:8px;display:block;"></i>
                            No referrals found<?php echo $filter_status ? ' for the selected status' : ''; ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($referrals as $r): ?>
                    <tr class="<?php echo $r['referral_type'] === 'External' ? 'external-row' : ''; ?>">
                        <td>
                            <span class="ref-id">REF-<?php echo str_pad($r['id'] ?? '', 6, '0', STR_PAD_LEFT); ?></span>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($r['pat_first'] . ' ' . $r['pat_last']); ?></strong>
                        </td>
                        <td>Dr. <?php echo htmlspecialchars($r['from_first'] . ' ' . $r['from_last']); ?></td>
                        <td>
                            <?php if ($r['referral_type'] === 'External'): ?>
                                <span style="color:#6b7280;font-size:0.85em;"><i class="fas fa-hospital"></i></span>
                                <?php echo htmlspecialchars($r['external_hospital'] ?: 'External Hospital'); ?>
                            <?php elseif ($r['to_first']): ?>
                                Dr. <?php echo htmlspecialchars($r['to_first'] . ' ' . $r['to_last']); ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;">Not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['referral_type'] === 'External'): ?>
                                <span class="type-badge" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;">
                                    <i class="fas fa-external-link-alt"></i> External
                                </span>
                            <?php else: ?>
                                <span class="type-badge" style="background:#e0e7ff;color:#3730a3;border:1px solid #a5b4fc;">
                                    <i class="fas fa-hospital-user"></i> Internal
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo priority_badge($r['priority']); ?></td>
                        <td style="max-width:180px;word-break:break-word;">
                            <?php echo htmlspecialchars(mb_strimwidth($r['reason'], 0, 60, '...')); ?>
                        </td>
                        <td style="white-space:nowrap;font-size:0.83em;color:#6b7280;">
                            <?php echo date('d M Y', strtotime($r['created_at'])); ?>
                        </td>
                        <td><?php echo ref_status_badge($r['status']); ?></td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <!-- View -->
                                <button class="btn-xs" style="background:#e0e7ff;color:#3730a3;"
                                    onclick="openViewModal(
                                        '<?php echo htmlspecialchars($r['id'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['pat_first']  . ' ' . $r['pat_last'],  ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['from_first'] . ' ' . $r['from_last'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['referral_type'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['priority'],    ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['reason'],      ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars(addslashes($r['notes'] ?? ''), ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['external_hospital'] ?? '', ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars(($r['to_first'] ?? '') . ' ' . ($r['to_last'] ?? ''), ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['status'],     ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['created_at'], ENT_QUOTES); ?>'
                                    )">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <!-- Update Status -->
                                <button class="btn-xs" style="background:#fef3c7;color:#92400e;"
                                    onclick="openStatusModal(
                                        '<?php echo htmlspecialchars($r['id'],     ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($r['status'], ENT_QUOTES); ?>'
                                    )">
                                    <i class="fas fa-sync-alt"></i> Status
                                </button>
                                <!-- Delete (admin only) -->
                                <?php if ($role === 'admin'): ?>
                                <form method="POST" action="" style="display:inline;"
                                      onsubmit="return confirm('Permanently delete this referral?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="ref_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                                    <button type="submit" class="btn-xs" style="background:#fecaca;color:#991b1b;">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                                <?php endif; ?>
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
     MODAL 1: Create Referral
════════════════════════════════════════════════════════════════════════ -->
<div id="modal-create" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="create-ref-title">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('modal-create')" aria-label="Close">&times;</button>
        <h3 class="modal-title" id="create-ref-title">
            <i class="fas fa-exchange-alt" style="color:#1d4ed8;"></i> Create Patient Referral
        </h3>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="create">

            <div class="form-group" style="position: relative;">
                <label>Patient <span style="color:#dc3545;">*</span></label>
                <input type="text" id="patient-search" autocomplete="off" class="form-control"
                    placeholder="Type patient name..." required
                    style="width:100%;"
                    oninput="filterPatients(this.value)">
                <input type="hidden" name="patient_id" id="patient_id_hidden" required>
                <ul id="patient-suggestions" style="display:none; position:absolute; z-index:9999; background:#fff; border:1px solid #d1d5db; border-radius:8px; margin:0; padding:4px 0; width:100%; max-height:200px; overflow-y:auto; box-shadow:0 4px 16px rgba(0,0,0,0.1); list-style:none;"></ul>
            </div>

            <script>
            const patientData = <?php echo json_encode(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['first_name'] . ' ' . $p['last_name']], $patients)); ?>;

            function filterPatients(query) {
                const list = document.getElementById('patient-suggestions');
                const hidden = document.getElementById('patient_id_hidden');
                hidden.value = '';
                if (!query.trim()) { list.style.display = 'none'; return; }

                const matches = patientData.filter(p => p.name.toLowerCase().includes(query.toLowerCase()));
                list.innerHTML = '';
                if (!matches.length) {
                    list.innerHTML = '<li style="padding:8px 14px; color:#9ca3af; font-size:0.88em;">No patients found</li>';
                } else {
                    matches.forEach(p => {
                        const li = document.createElement('li');
                        li.textContent = p.name;
                        li.style.cssText = 'padding:8px 14px; cursor:pointer; font-size:0.9em; color:#111827;';
                        li.onmouseenter = () => li.style.background = '#f3f4f6';
                        li.onmouseleave = () => li.style.background = '';
                        li.onclick = () => {
                            document.getElementById('patient-search').value = p.name;
                            hidden.value = p.id;
                            list.style.display = 'none';
                        };
                        list.appendChild(li);
                    });
                }
                list.style.display = 'block';
            }

            document.addEventListener('click', e => {
                if (!e.target.closest('#patient-search') && !e.target.closest('#patient-suggestions')) {
                    document.getElementById('patient-suggestions').style.display = 'none';
                }
            });
            </script>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Referring Doctor <span style="color:#dc3545;">*</span></label>
                    <select name="from_doctor_id" class="form-control" required>
                        <option value="">-- Select Doctor --</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo htmlspecialchars($doc['id']); ?>"
                                <?php echo ($current_staff_id && $doc['id'] === $current_staff_id) ? 'selected' : ''; ?>>
                                Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Referral Type <span style="color:#dc3545;">*</span></label>
                    <select name="referral_type" id="ref-type-select" class="form-control" required onchange="toggleExternalFields(this.value)">
                        <option value="">-- Select Type --</option>
                        <option value="Internal">Internal</option>
                        <option value="External">External</option>
                    </select>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Priority <span style="color:#dc3545;">*</span></label>
                    <select name="priority" class="form-control" required>
                        <option value="">-- Select Priority --</option>
                        <option value="Routine">Routine</option>
                        <option value="Urgent">Urgent</option>
                        <option value="Emergency">Emergency</option>
                    </select>
                </div>
                <div class="form-group" id="to-doctor-group" style="position:relative;">
                    <label>Refer To Doctor <span style="color:#dc3545;">*</span></label>
                    <input type="text" id="doctor-search" autocomplete="off" class="form-control"
                        placeholder="Type doctor name..." required
                        style="width:100%;"
                        oninput="filterDoctors(this.value)">
                    <input type="hidden" name="to_doctor_id" id="to_doctor_id_hidden" required>
                    <ul id="doctor-suggestions" style="display:none; position:absolute; z-index:9999; background:#fff; border:1px solid #d1d5db; border-radius:8px; margin:0; padding:4px 0; width:100%; max-height:200px; overflow-y:auto; box-shadow:0 4px 16px rgba(0,0,0,0.1); list-style:none;"></ul>
                </div>

                <script>
                const doctorData = <?php echo json_encode(array_map(fn($d) => [
                    'id'   => $d['id'],
                    'name' => 'Dr. ' . $d['first_name'] . ' ' . $d['last_name'] . ($d['specialization'] ? ' — ' . $d['specialization'] : '')
                ], $doctors)); ?>;

                function filterDoctors(query) {
                    const list = document.getElementById('doctor-suggestions');
                    const hidden = document.getElementById('to_doctor_id_hidden');
                    hidden.value = '';
                    if (!query.trim()) { list.style.display = 'none'; return; }

                    const matches = doctorData.filter(d => d.name.toLowerCase().includes(query.toLowerCase()));
                    list.innerHTML = '';
                    if (!matches.length) {
                        list.innerHTML = '<li style="padding:8px 14px; color:#9ca3af; font-size:0.88em;">No doctors found</li>';
                    } else {
                        matches.forEach(d => {
                            const li = document.createElement('li');
                            li.textContent = d.name;
                            li.style.cssText = 'padding:8px 14px; cursor:pointer; font-size:0.9em; color:#111827;';
                            li.onmouseenter = () => li.style.background = '#f3f4f6';
                            li.onmouseleave = () => li.style.background = '';
                            li.onclick = () => {
                                document.getElementById('doctor-search').value = d.name;
                                hidden.value = d.id;
                                list.style.display = 'none';
                            };
                            list.appendChild(li);
                        });
                    }
                    list.style.display = 'block';
                }

                document.addEventListener('click', e => {
                    if (!e.target.closest('#doctor-search') && !e.target.closest('#doctor-suggestions')) {
                        document.getElementById('doctor-suggestions').style.display = 'none';
                    }
                });
                </script>
            </div>

            <div class="form-group" id="ext-hospital-group" style="display:none;">
                <label>External Hospital Name</label>
                <input type="text" name="external_hospital" class="form-control" placeholder="Name of external hospital or clinic">
            </div>

            <div class="form-group">
                <label>Specialty / Reason for Referral <span style="color:#dc3545;">*</span></label>
                <input type="text" name="reason" class="form-control"
                       placeholder="e.g. Cardiology consult — chest pain not responding to treatment" required>
            </div>

            <div class="form-group">
                <label>Notes / Relevant Diagnosis</label>
                <textarea name="notes" class="form-control" rows="3"
                          placeholder="Attach diagnosis, current medications, patient history summary..."></textarea>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-create')"
                        style="padding:9px 20px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:600;cursor:pointer;font-size:0.9em;color:#374151;">
                    Cancel
                </button>
                <button type="submit"
                        style="padding:9px 22px;border-radius:8px;border:none;background:#1d4ed8;color:#fff;font-weight:700;cursor:pointer;font-size:0.9em;">
                    <i class="fas fa-paper-plane"></i> Submit Referral
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL 2: Update Status
════════════════════════════════════════════════════════════════════════ -->
<div id="modal-status" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="status-modal-title">
    <div class="modal-box" style="max-width:420px;">
        <button class="modal-close" onclick="closeModal('modal-status')" aria-label="Close">&times;</button>
        <h3 class="modal-title" id="status-modal-title">
            <i class="fas fa-sync-alt" style="color:#f59e0b;"></i> Update Referral Status
        </h3>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="ref_id" id="status-ref-id">

            <div class="form-group">
                <label>New Status <span style="color:#dc3545;">*</span></label>
                <select name="status" id="status-select" class="form-control" required>
                    <option value="pending">Pending</option>
                    <option value="accepted">Accepted</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button type="button" onclick="closeModal('modal-status')"
                        style="padding:9px 20px;border-radius:8px;border:1.5px solid #d1d5db;background:#fff;font-weight:600;cursor:pointer;font-size:0.9em;color:#374151;">
                    Cancel
                </button>
                <button type="submit"
                        style="padding:9px 22px;border-radius:8px;border:none;background:#f59e0b;color:#fff;font-weight:700;cursor:pointer;font-size:0.9em;">
                    <i class="fas fa-save"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL 3: View Referral Details
════════════════════════════════════════════════════════════════════════ -->
<div id="modal-view" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="view-ref-title">
    <div class="modal-box" style="max-width:520px;">
        <button class="modal-close" onclick="closeModal('modal-view')" aria-label="Close">&times;</button>
        <h3 class="modal-title" id="view-ref-title">
            <i class="fas fa-file-medical-alt" style="color:#1d4ed8;"></i>
            Referral Details — <span id="view-ref-id" style="font-family:monospace;"></span>
        </h3>
        <div id="view-ref-body" style="font-size:0.9em;line-height:1.8;"></div>
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

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});

// ── Toggle external hospital field ────────────────────────────────────────────
function toggleExternalFields(type) {
    var extGroup  = document.getElementById('ext-hospital-group');
    var docGroup  = document.getElementById('to-doctor-group');
    if (type === 'External') {
        extGroup.style.display = 'block';
        docGroup.style.display = 'none';
    } else {
        extGroup.style.display = 'none';
        docGroup.style.display = 'block';
    }
}

// ── Open Status Modal ─────────────────────────────────────────────────────────
function openStatusModal(refId, currentStatus) {
    document.getElementById('status-ref-id').value    = refId;
    document.getElementById('status-select').value    = currentStatus;
    openModal('modal-status');
}

// ── Open View Modal ───────────────────────────────────────────────────────────
function openViewModal(refId, patient, fromDoctor, type, priority, reason, notes, extHospital, toDoctor, status, createdAt) {
    document.getElementById('view-ref-id').textContent = 'REF-' + refId.substring(0, 8).toUpperCase();

    var priorityColors = { Routine:'#6c757d', Urgent:'#fd7e14', Emergency:'#dc3545' };
    var statusColors   = { pending:'#ffc107', accepted:'#0d6efd', completed:'#28a745', cancelled:'#dc3545' };
    var statusTextCol  = { pending:'#212529', accepted:'#fff',    completed:'#fff',    cancelled:'#fff' };

    var pColor = priorityColors[priority] || '#6c757d';
    var sColor = statusColors[status]     || '#6c757d';
    var sTColor = statusTextCol[status]   || '#fff';

    var html = '<table style="width:100%;border-collapse:collapse;">';
    function row(label, value) {
        html += '<tr><td style="padding:6px 10px 6px 0;color:#6b7280;font-weight:600;width:38%;vertical-align:top;">' + label + '</td>'
              + '<td style="padding:6px 0;color:#111827;">' + value + '</td></tr>';
    }

    row('Patient',      '<strong>' + escHtml(patient) + '</strong>');
    row('From Doctor',  'Dr. ' + escHtml(fromDoctor));
    row('Referral Type', type === 'External'
        ? '<span style="background:#fff3cd;color:#856404;padding:3px 10px;border-radius:12px;font-size:0.82em;font-weight:600;border:1px solid #ffc107;">&#8599; External</span>'
        : '<span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:12px;font-size:0.82em;font-weight:600;border:1px solid #a5b4fc;">&#8635; Internal</span>');

    if (type === 'External') {
        row('External Hospital', extHospital ? escHtml(extHospital) : '<span style="color:#9ca3af;">Not specified</span>');
    } else {
        row('To Doctor', toDoctor && toDoctor.trim() ? 'Dr. ' + escHtml(toDoctor) : '<span style="color:#9ca3af;">Not assigned</span>');
    }

    row('Priority', '<span style="background:' + escHtml(pColor) + ';color:#fff;padding:3px 10px;border-radius:20px;font-size:0.82em;font-weight:600;">' + escHtml(priority) + '</span>');
    row('Status',   '<span style="background:' + escHtml(sColor) + ';color:' + sTColor + ';padding:3px 10px;border-radius:20px;font-size:0.82em;font-weight:600;">' + escHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span>');
    row('Reason',   escHtml(reason));
    row('Notes',    notes ? escHtml(notes) : '<span style="color:#9ca3af;">None</span>');
    row('Created',  escHtml(createdAt));
    html += '</table>';

    document.getElementById('view-ref-body').innerHTML = html;
    openModal('modal-view');
}

function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
}
</script>

<?php include '../../includes/footer.php'; ?>
