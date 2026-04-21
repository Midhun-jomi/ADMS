<?php
// modules/consent/consent_forms.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role     = get_user_role();
$user_id  = get_user_id();

// Patients see only their own; all staff roles allowed
$allowed_staff = ['admin', 'doctor', 'nurse', 'head_nurse', 'receptionist'];
if ($role !== 'patient' && !in_array($role, $allowed_staff)) {
    http_response_code(403);
    exit('Access denied.');
}

$page_title = "Consent Forms";
include '../../includes/header.php';

$error   = '';
$success = '';

// ── POST HANDLERS ──────────────────────────────────────────────────────────────

// Create new consent form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } elseif (!in_array($role, $allowed_staff)) {
        $error = "You do not have permission to create consent forms.";
    } else {
        $patient_id    = trim($_POST['patient_id']    ?? '');
        $consent_type  = trim($_POST['consent_type']  ?? '');
        $procedure_name = trim($_POST['procedure_name'] ?? '');
        $doctor_id     = trim($_POST['doctor_id']     ?? '');
        $consent_text  = trim($_POST['consent_text']  ?? '');
        $witness_name  = trim($_POST['witness_name']  ?? '');

        $valid_types = ['Surgery', 'Procedure', 'Anesthesia', 'Blood Transfusion', 'HIV Test', 'General Treatment', 'Research', 'Photography'];

        if (empty($patient_id) || empty($consent_type) || empty($procedure_name) || empty($consent_text)) {
            $error = "Patient, consent type, procedure name, and consent text are required.";
        } elseif (!in_array($consent_type, $valid_types)) {
            $error = "Invalid consent type selected.";
        } else {
            $doctor_id_val = !empty($doctor_id) ? $doctor_id : null;
            try {
                $sql = "INSERT INTO consent_forms
                            (patient_id, consent_type, procedure_name, doctor_id, consent_text, witness_name, status, created_by)
                        VALUES ($1, $2, $3, $4, $5, $6, 'pending', $7)";
                db_query($sql, [$patient_id, $consent_type, $procedure_name, $doctor_id_val, $consent_text, $witness_name, $user_id]);
                $success = "Consent form created successfully.";
            } catch (Exception $e) {
                $error = "Failed to create consent form: " . $e->getMessage();
            }
        }
    }
}

// Mark as Signed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } elseif (!in_array($role, $allowed_staff)) {
        $error = "Permission denied.";
    } else {
        $form_id = trim($_POST['form_id'] ?? '');
        if (!empty($form_id)) {
            try {
                db_query(
                    "UPDATE consent_forms SET status = 'signed', signed_at = NOW() WHERE id = $1",
                    [$form_id]
                );
                $success = "Consent form marked as signed.";
            } catch (Exception $e) {
                $error = "Failed to update status: " . $e->getMessage();
            }
        }
    }
}

// Revoke
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } elseif (!in_array($role, ['admin', 'doctor'])) {
        $error = "Only admins and doctors can revoke consent forms.";
    } else {
        $form_id = trim($_POST['form_id'] ?? '');
        if (!empty($form_id)) {
            try {
                db_query(
                    "UPDATE consent_forms SET status = 'revoked' WHERE id = $1",
                    [$form_id]
                );
                $success = "Consent form revoked.";
            } catch (Exception $e) {
                $error = "Failed to revoke: " . $e->getMessage();
            }
        }
    }
}

// ── FILTERS ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['filter_status'] ?? '';
$filter_type   = $_GET['filter_type']   ?? '';

$valid_statuses = ['pending', 'signed', 'revoked'];
$valid_filter_types = ['Surgery', 'Procedure', 'Anesthesia', 'Blood Transfusion', 'HIV Test', 'General Treatment', 'Research', 'Photography'];

if (!in_array($filter_status, $valid_statuses)) $filter_status = '';
if (!in_array($filter_type, $valid_filter_types)) $filter_type = '';

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$params     = [];
$conditions = [];
$idx        = 1;

if ($role === 'patient') {
    $patient_row = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    if ($patient_row) {
        $conditions[] = "cf.patient_id = \$$idx";
        $params[]     = $patient_row['id'];
        $idx++;
    } else {
        $patient_row = null;
    }
}

if ($filter_status !== '') {
    $conditions[] = "cf.status = \$$idx";
    $params[]     = $filter_status;
    $idx++;
}
if ($filter_type !== '') {
    $conditions[] = "cf.consent_type = \$$idx";
    $params[]     = $filter_type;
    $idx++;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$forms_sql = "SELECT cf.*,
                     p.first_name AS p_first, p.last_name AS p_last,
                     s.first_name AS d_first, s.last_name AS d_last,
                     u.email AS created_by_name
              FROM consent_forms cf
              LEFT JOIN patients p  ON cf.patient_id = p.id
              LEFT JOIN staff s     ON cf.doctor_id  = s.id
              LEFT JOIN users u     ON cf.created_by = u.id
              $where
              ORDER BY cf.created_at DESC";

$forms = db_select($forms_sql, $params);

// Stats
$stat_total   = 0;
$stat_signed  = 0;
$stat_pending = 0;
$stat_revoked = 0;
foreach ($forms as $f) {
    $stat_total++;
    if ($f['status'] === 'signed')  $stat_signed++;
    if ($f['status'] === 'pending') $stat_pending++;
    if ($f['status'] === 'revoked') $stat_revoked++;
}

// Fetch patients + doctors for create modal (staff only)
$all_patients = [];
$all_doctors  = [];
if (in_array($role, $allowed_staff)) {
    $all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name");
    $all_doctors  = db_select("SELECT id, first_name, last_name FROM staff WHERE role = 'doctor' ORDER BY last_name, first_name");
}
?>

<style>
.consent-stat-card {
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
.consent-stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.consent-stat-info h4 { margin: 0; font-size: 1.75rem; font-weight: 700; color: #111827; }
.consent-stat-info p  { margin: 0; font-size: 0.82rem; color: #6b7280; font-weight: 500; }

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
.modal-body { padding: 24px; }
.modal-footer { padding: 16px 24px; border-top: 1px solid #f3f4f6; display: flex; justify-content: flex-end; gap: 10px; }
.btn-close-modal { background: none; border: none; font-size: 1.4rem; cursor: pointer; color: #6b7280; line-height: 1; }
.btn-close-modal:hover { color: #111; }

.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 600px) { .form-grid-2 { grid-template-columns: 1fr; } }

.badge-status-pending  { background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
.badge-status-signed   { background: #d1fae5; color: #065f46; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
.badge-status-revoked  { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }

.consent-text-preview {
    background: #f8f9fa;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 14px;
    font-size: 0.9rem;
    line-height: 1.6;
    white-space: pre-wrap;
    max-height: 300px;
    overflow-y: auto;
}

@media print {
    .sidebar, .topbar, .btn, .modal-overlay, .filter-bar, .action-bar { display: none !important; }
    .print-area { display: block !important; }
}
</style>

<div style="max-width: 1280px; margin: 0 auto; padding: 20px;">

    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h2 style="margin: 0; font-weight: 700; color: #1f2937;">
                <i class="fas fa-file-signature" style="color: #4f46e5; margin-right: 10px;"></i>Consent Forms
            </h2>
            <p style="margin: 4px 0 0; color: #6b7280; font-size: 0.9rem;">Manage patient consent documentation</p>
        </div>
        <?php if (in_array($role, $allowed_staff)): ?>
        <button class="btn btn-primary" onclick="openModal('modal-create')" style="border-radius: 10px; padding: 10px 20px; font-weight: 600;">
            <i class="fas fa-plus"></i> New Consent Form
        </button>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div style="display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap;">
        <div class="consent-stat-card">
            <div class="consent-stat-icon" style="background: #ede9fe; color: #6d28d9;">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="consent-stat-info">
                <h4><?php echo $stat_total; ?></h4>
                <p>Total Forms</p>
            </div>
        </div>
        <div class="consent-stat-card">
            <div class="consent-stat-icon" style="background: #d1fae5; color: #059669;">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="consent-stat-info">
                <h4><?php echo $stat_signed; ?></h4>
                <p>Signed</p>
            </div>
        </div>
        <div class="consent-stat-card">
            <div class="consent-stat-icon" style="background: #fef3c7; color: #d97706;">
                <i class="fas fa-clock"></i>
            </div>
            <div class="consent-stat-info">
                <h4><?php echo $stat_pending; ?></h4>
                <p>Pending Signature</p>
            </div>
        </div>
        <div class="consent-stat-card">
            <div class="consent-stat-icon" style="background: #fee2e2; color: #dc2626;">
                <i class="fas fa-ban"></i>
            </div>
            <div class="consent-stat-info">
                <h4><?php echo $stat_revoked; ?></h4>
                <p>Revoked</p>
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

    <!-- Filters -->
    <div class="filter-bar" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: flex-end;">
        <form method="GET" action="" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
            <div>
                <label style="display: block; font-size: 0.82rem; font-weight: 600; color: #374151; margin-bottom: 4px;">Status</label>
                <select name="filter_status" class="form-control" style="border-radius: 8px; height: 38px; min-width: 150px;">
                    <option value="">All Statuses</option>
                    <option value="pending"  <?php echo $filter_status === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                    <option value="signed"   <?php echo $filter_status === 'signed'   ? 'selected' : ''; ?>>Signed</option>
                    <option value="revoked"  <?php echo $filter_status === 'revoked'  ? 'selected' : ''; ?>>Revoked</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 0.82rem; font-weight: 600; color: #374151; margin-bottom: 4px;">Consent Type</label>
                <select name="filter_type" class="form-control" style="border-radius: 8px; height: 38px; min-width: 180px;">
                    <option value="">All Types</option>
                    <?php foreach (['Surgery','Procedure','Anesthesia','Blood Transfusion','HIV Test','General Treatment','Research','Photography'] as $ct): ?>
                        <option value="<?php echo htmlspecialchars($ct); ?>" <?php echo $filter_type === $ct ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ct); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="height: 38px; border-radius: 8px; padding: 0 16px;">
                <i class="fas fa-filter"></i> Filter
            </button>
            <?php if ($filter_status || $filter_type): ?>
                <a href="consent_forms.php" class="btn" style="height: 38px; border-radius: 8px; padding: 0 14px; background:#f3f4f6; color:#374151; display:flex; align-items:center;">
                    <i class="fas fa-times" style="margin-right: 6px;"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <div style="margin-left: auto;">
            <input type="text" id="filter-forms" onkeyup="filterTable('filter-forms','tbl-consent-forms')"
                placeholder="Search table..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 220px; outline: none;">
        </div>
    </div>

    <!-- Table -->
    <div class="card" style="border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-radius: 14px; overflow: hidden;">
        <div class="table-responsive">
            <table id="tbl-consent-forms" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8f9fa; text-align: left;">
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase; white-space: nowrap;">Form ID</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Patient</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Consent Type</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Procedure</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Doctor</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase; white-space: nowrap;">Created</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Status</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Witness</th>
                        <th style="padding: 13px 16px; border-bottom: 2px solid #e5e7eb; font-size: 0.82rem; color: #6b7280; text-transform: uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($forms)): ?>
                    <tr>
                        <td colspan="9" style="padding: 40px; text-align: center; color: #9ca3af;">
                            <i class="fas fa-file-alt" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                            No consent forms found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($forms as $f): ?>
                    <?php
                        $short_id    = strtoupper(substr($f['id'], 0, 8));
                        $patient_name = htmlspecialchars(($f['p_first'] ?? '') . ' ' . ($f['p_last'] ?? ''));
                        $doctor_name  = $f['d_first'] ? htmlspecialchars($f['d_first'] . ' ' . $f['d_last']) : '<span style="color:#9ca3af;">—</span>';
                        $status_badge = match($f['status'] ?? 'pending') {
                            'signed'  => '<span class="badge-status-signed"><i class="fas fa-check" style="margin-right:4px;"></i>Signed</span>',
                            'revoked' => '<span class="badge-status-revoked"><i class="fas fa-ban" style="margin-right:4px;"></i>Revoked</span>',
                            default   => '<span class="badge-status-pending"><i class="fas fa-clock" style="margin-right:4px;"></i>Pending Signature</span>',
                        };
                        $created_at  = $f['created_at'] ? date('d M Y', strtotime($f['created_at'])) : '—';
                        $signed_at   = $f['signed_at']  ? date('d M Y H:i', strtotime($f['signed_at'])) : null;
                        // JSON-encode form data for view modal
                        $modal_data  = htmlspecialchars(json_encode([
                            'id'             => $f['id'],
                            'short_id'       => $short_id,
                            'patient'        => ($f['p_first'] ?? '') . ' ' . ($f['p_last'] ?? ''),
                            'consent_type'   => $f['consent_type'] ?? '',
                            'procedure_name' => $f['procedure_name'] ?? '',
                            'doctor'         => ($f['d_first'] ?? '') . ' ' . ($f['d_last'] ?? ''),
                            'consent_text'   => $f['consent_text'] ?? '',
                            'witness_name'   => $f['witness_name'] ?? '',
                            'status'         => $f['status'] ?? '',
                            'created_at'     => $created_at,
                            'signed_at'      => $signed_at ?? '',
                        ]), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr style="border-bottom: 1px solid #f3f4f6;" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                        <td style="padding: 13px 16px;">
                            <span style="font-family: monospace; background: #f3f4f6; padding: 2px 7px; border-radius: 5px; font-size: 0.82rem;">
                                <?php echo $short_id; ?>
                            </span>
                        </td>
                        <td style="padding: 13px 16px; font-weight: 500;"><?php echo $patient_name; ?></td>
                        <td style="padding: 13px 16px;"><?php echo htmlspecialchars($f['consent_type'] ?? ''); ?></td>
                        <td style="padding: 13px 16px;"><?php echo htmlspecialchars($f['procedure_name'] ?? ''); ?></td>
                        <td style="padding: 13px 16px;"><?php echo $doctor_name; ?></td>
                        <td style="padding: 13px 16px; white-space: nowrap;"><?php echo $created_at; ?></td>
                        <td style="padding: 13px 16px;"><?php echo $status_badge; ?></td>
                        <td style="padding: 13px 16px;"><?php echo htmlspecialchars($f['witness_name'] ?? '—'); ?></td>
                        <td style="padding: 13px 16px;">
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <!-- View -->
                                <button class="btn btn-sm"
                                    style="background: #ede9fe; color: #6d28d9; border-radius: 7px; font-size: 0.8rem;"
                                    onclick="viewConsentForm(<?php echo $modal_data; ?>)"
                                    title="View full consent form">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                <?php if ($role !== 'patient' && ($f['status'] ?? '') === 'pending'): ?>
                                <!-- Mark Signed -->
                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Mark this consent form as signed?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action"  value="sign">
                                    <input type="hidden" name="form_id" value="<?php echo htmlspecialchars($f['id']); ?>">
                                    <button type="submit" class="btn btn-sm"
                                        style="background: #d1fae5; color: #065f46; border-radius: 7px; font-size: 0.8rem;"
                                        title="Mark as signed">
                                        <i class="fas fa-check"></i> Sign
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if (in_array($role, ['admin', 'doctor']) && ($f['status'] ?? '') !== 'revoked'): ?>
                                <!-- Revoke -->
                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Revoke this consent form? This cannot be undone.');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action"  value="revoke">
                                    <input type="hidden" name="form_id" value="<?php echo htmlspecialchars($f['id']); ?>">
                                    <button type="submit" class="btn btn-sm"
                                        style="background: #fee2e2; color: #991b1b; border-radius: 7px; font-size: 0.8rem;"
                                        title="Revoke consent">
                                        <i class="fas fa-ban"></i> Revoke
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Print -->
                                <button class="btn btn-sm"
                                    style="background: #f3f4f6; color: #374151; border-radius: 7px; font-size: 0.8rem;"
                                    onclick="printConsentForm(<?php echo $modal_data; ?>)"
                                    title="Print / Download">
                                    <i class="fas fa-print"></i>
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

<!-- ── MODAL: Create Consent Form ──────────────────────────────────────────── -->
<?php if (in_array($role, $allowed_staff)): ?>
<div id="modal-create" class="modal-overlay" onclick="if(event.target===this) closeModal('modal-create')">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-plus-circle" style="color:#4f46e5; margin-right:8px;"></i>New Consent Form</h5>
            <button class="btn-close-modal" onclick="closeModal('modal-create')">&times;</button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create">

                <div class="form-grid-2" style="margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Patient <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="patient_id" class="form-control" required style="border-radius: 8px;">
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($all_patients as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['id']); ?>">
                                    <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Consent Type <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="consent_type" class="form-control" required style="border-radius: 8px;">
                            <option value="">-- Select Type --</option>
                            <?php foreach (['Surgery','Procedure','Anesthesia','Blood Transfusion','HIV Test','General Treatment','Research','Photography'] as $ct): ?>
                                <option value="<?php echo htmlspecialchars($ct); ?>"><?php echo htmlspecialchars($ct); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2" style="margin-bottom: 16px;">
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Procedure Name <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="text" name="procedure_name" class="form-control" required
                            placeholder="e.g. Appendectomy" style="border-radius: 8px;">
                    </div>
                    <div class="form-group">
                        <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                            Responsible Doctor
                        </label>
                        <select name="doctor_id" class="form-control" style="border-radius: 8px;">
                            <option value="">-- Select Doctor --</option>
                            <?php foreach ($all_doctors as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['id']); ?>">
                                    Dr. <?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                        Consent Text / Details <span style="color:#dc2626;">*</span>
                    </label>
                    <textarea name="consent_text" class="form-control" rows="6" required
                        placeholder="Enter full consent text, risks, benefits, and patient acknowledgment..."
                        style="border-radius: 8px; resize: vertical;"></textarea>
                </div>

                <div class="form-group">
                    <label style="font-weight: 600; font-size: 0.88rem; color: #374151; display: block; margin-bottom: 6px;">
                        Witness Name
                    </label>
                    <input type="text" name="witness_name" class="form-control"
                        placeholder="Name of witness present" style="border-radius: 8px;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" style="background:#f3f4f6; color:#374151; border-radius: 8px;" onclick="closeModal('modal-create')">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="border-radius: 8px;">
                    <i class="fas fa-save"></i> Create Consent Form
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── MODAL: View Consent Form ────────────────────────────────────────────── -->
<div id="modal-view" class="modal-overlay" onclick="if(event.target===this) closeModal('modal-view')">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="fas fa-file-signature" style="color:#4f46e5; margin-right:8px;"></i>Consent Form Details</h5>
            <button class="btn-close-modal" onclick="closeModal('modal-view')">&times;</button>
        </div>
        <div class="modal-body" id="view-modal-body">
            <!-- Populated by JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" style="background:#f3f4f6; color:#374151; border-radius: 8px;" onclick="closeModal('modal-view')">
                Close
            </button>
            <button type="button" class="btn btn-primary" style="border-radius: 8px;" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<!-- ── PRINT TEMPLATE ─────────────────────────────────────────────────────── -->
<div id="print-frame" style="display:none;">
    <!-- Filled by JS before window.print() -->
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

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

function viewConsentForm(data) {
    var statusBadge = {
        'pending': '<span class="badge-status-pending"><i class="fas fa-clock" style="margin-right:4px;"></i>Pending Signature</span>',
        'signed':  '<span class="badge-status-signed"><i class="fas fa-check" style="margin-right:4px;"></i>Signed</span>',
        'revoked': '<span class="badge-status-revoked"><i class="fas fa-ban" style="margin-right:4px;"></i>Revoked</span>',
    }[data.status] || '';

    var html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Form ID</label>'
        + '<p style="margin:2px 0;font-family:monospace;background:#f3f4f6;padding:4px 8px;border-radius:5px;display:inline-block;">' + escHtml(data.short_id) + '</p></div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Status</label>'
        + '<p style="margin:2px 0;">' + statusBadge + '</p></div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Patient</label>'
        + '<p style="margin:2px 0;font-weight:500;">' + escHtml(data.patient) + '</p></div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Consent Type</label>'
        + '<p style="margin:2px 0;">' + escHtml(data.consent_type) + '</p></div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Procedure</label>'
        + '<p style="margin:2px 0;">' + escHtml(data.procedure_name) + '</p></div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Doctor</label>'
        + '<p style="margin:2px 0;">' + escHtml(data.doctor || '—') + '</p></div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Created</label>'
        + '<p style="margin:2px 0;">' + escHtml(data.created_at) + '</p></div>'
        + (data.signed_at ? '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Signed At</label>'
        + '<p style="margin:2px 0;">' + escHtml(data.signed_at) + '</p></div>' : '<div></div>')
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;">Witness</label>'
        + '<p style="margin:2px 0;">' + escHtml(data.witness_name || '—') + '</p></div>'
        + '</div>'
        + '<div><label style="font-size:0.78rem;color:#6b7280;font-weight:600;text-transform:uppercase;display:block;margin-bottom:8px;">Consent Text</label>'
        + '<div class="consent-text-preview">' + escHtml(data.consent_text) + '</div></div>';

    document.getElementById('view-modal-body').innerHTML = html;
    openModal('modal-view');
}

function printConsentForm(data) {
    var printHtml = '<!DOCTYPE html><html><head><title>Consent Form</title>'
        + '<style>body{font-family:Georgia,serif;max-width:700px;margin:40px auto;color:#111;}'
        + 'h1{font-size:1.4rem;text-align:center;border-bottom:2px solid #333;padding-bottom:10px;}'
        + '.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:20px 0;}'
        + '.field label{font-size:0.75rem;text-transform:uppercase;color:#555;font-weight:bold;}'
        + '.field p{margin:2px 0;font-size:0.95rem;}'
        + '.consent-block{border:1px solid #ccc;padding:16px;border-radius:6px;white-space:pre-wrap;line-height:1.7;font-size:0.92rem;margin-top:10px;}'
        + '.sig-line{border-top:1px solid #333;margin-top:40px;padding-top:8px;font-size:0.85rem;}'
        + '</style></head><body>'
        + '<h1>Patient Consent Form</h1>'
        + '<div class="grid">'
        + '<div class="field"><label>Patient</label><p>' + data.patient + '</p></div>'
        + '<div class="field"><label>Consent Type</label><p>' + data.consent_type + '</p></div>'
        + '<div class="field"><label>Procedure</label><p>' + data.procedure_name + '</p></div>'
        + '<div class="field"><label>Doctor</label><p>' + (data.doctor || '—') + '</p></div>'
        + '<div class="field"><label>Date</label><p>' + data.created_at + '</p></div>'
        + '<div class="field"><label>Status</label><p>' + data.status.toUpperCase() + (data.signed_at ? ' on ' + data.signed_at : '') + '</p></div>'
        + '</div>'
        + '<label style="font-size:0.75rem;text-transform:uppercase;font-weight:bold;color:#555;">Consent Text</label>'
        + '<div class="consent-block">' + data.consent_text + '</div>'
        + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:50px;">'
        + '<div class="sig-line">Patient Signature &amp; Date</div>'
        + '<div class="sig-line">Witness: ' + (data.witness_name || '_______________') + '</div>'
        + '</div>'
        + '</body></html>';

    var w = window.open('', '_blank');
    w.document.write(printHtml);
    w.document.close();
    w.focus();
    w.print();
}
</script>

<?php include '../../includes/footer.php'; ?>
