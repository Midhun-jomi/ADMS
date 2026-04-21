<?php
// modules/outcomes/clinical_outcomes.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor']);

$page_title = "Clinical Outcomes";

$error   = '';
$success = '';
$role    = get_user_role();
$user_id = get_user_id();

// Resolve staff record for doctor role
$staff_row = null;
if ($role === 'doctor') {
    $staff_row = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
}

// ─── POST: record outcome ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_outcome'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $patient_id       = trim($_POST['patient_id']       ?? '');
        $doctor_id        = trim($_POST['doctor_id']        ?? '');
        $appointment_id   = trim($_POST['appointment_id']   ?? '') ?: null;
        $outcome_type     = trim($_POST['outcome_type']     ?? '');
        $diagnosis_treated = trim($_POST['diagnosis_treated'] ?? '');
        $treatment_given  = trim($_POST['treatment_given']  ?? '');
        $outcome_date     = trim($_POST['outcome_date']      ?? '');
        $notes            = trim($_POST['notes']             ?? '');
        $follow_up_req    = isset($_POST['follow_up_required']) ? true : false;
        $follow_up_date   = trim($_POST['follow_up_date']    ?? '') ?: null;

        $allowed_outcomes = ['Recovered','Improved','Unchanged','Deteriorated','Readmitted','Deceased'];
        if (!in_array($outcome_type, $allowed_outcomes)) {
            $error = "Invalid outcome type.";
        } elseif (empty($patient_id) || empty($doctor_id) || empty($outcome_date)) {
            $error = "Patient, doctor and outcome date are required.";
        } else {
            // Doctor can only record for their own patients
            if ($role === 'doctor' && $staff_row && $doctor_id !== $staff_row['id']) {
                $error = "You may only record outcomes for your own patients.";
            } else {
                try {
                    db_insert('clinical_outcomes', [
                        'patient_id'       => $patient_id,
                        'doctor_id'        => $doctor_id,
                        'appointment_id'   => $appointment_id,
                        'outcome_type'     => $outcome_type,
                        'diagnosis_treated' => $diagnosis_treated,
                        'treatment_given'  => $treatment_given,
                        'outcome_date'     => $outcome_date,
                        'notes'            => $notes,
                        'follow_up_required' => $follow_up_req ? 'true' : 'false',
                        'follow_up_date'   => $follow_up_date,
                        'created_by'       => $user_id,
                    ]);
                    $success = "Outcome recorded successfully.";
                } catch (Exception $e) {
                    $error = "Failed to record outcome: " . $e->getMessage();
                }
            }
        }
    }
}

// ─── Filters ──────────────────────────────────────────────────────────────────
$filter_outcome = $_GET['filter_outcome'] ?? '';
$filter_doctor  = $_GET['filter_doctor']  ?? '';
$filter_from    = $_GET['filter_from']    ?? '';
$filter_to      = $_GET['filter_to']      ?? '';

// ─── Build outcomes query ─────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$p_idx  = 1;

if ($role === 'doctor' && $staff_row) {
    $where[]  = "co.doctor_id = \$$p_idx";
    $params[] = $staff_row['id'];
    $p_idx++;
} elseif ($filter_doctor) {
    $where[]  = "co.doctor_id = \$$p_idx";
    $params[] = $filter_doctor;
    $p_idx++;
}

if ($filter_outcome) {
    $where[]  = "co.outcome_type = \$$p_idx";
    $params[] = $filter_outcome;
    $p_idx++;
}
if ($filter_from) {
    $where[]  = "co.outcome_date >= \$$p_idx";
    $params[] = $filter_from;
    $p_idx++;
}
if ($filter_to) {
    $where[]  = "co.outcome_date <= \$$p_idx";
    $params[] = $filter_to;
    $p_idx++;
}

$where_sql = implode(' AND ', $where);

$outcomes = db_select(
    "SELECT co.*,
            p.first_name AS pat_first, p.last_name AS pat_last,
            s.first_name AS doc_first, s.last_name AS doc_last
     FROM clinical_outcomes co
     JOIN patients p ON co.patient_id = p.id
     JOIN staff   s ON co.doctor_id   = s.id
     WHERE $where_sql
     ORDER BY co.outcome_date DESC, co.created_at DESC
     LIMIT 200",
    $params
);

// ─── Stats ────────────────────────────────────────────────────────────────────
$doctor_scope_cond  = '';
$doctor_scope_params = [];
if ($role === 'doctor' && $staff_row) {
    $doctor_scope_cond   = 'WHERE doctor_id = $1';
    $doctor_scope_params = [$staff_row['id']];
}

$stat_total      = db_select_one("SELECT COUNT(*) AS cnt FROM clinical_outcomes $doctor_scope_cond", $doctor_scope_params);
$stat_recovered  = db_select_one("SELECT COUNT(*) AS cnt FROM clinical_outcomes " . ($doctor_scope_cond ? $doctor_scope_cond . " AND outcome_type='Recovered'" : "WHERE outcome_type='Recovered'"), $doctor_scope_params);
$stat_improved   = db_select_one("SELECT COUNT(*) AS cnt FROM clinical_outcomes " . ($doctor_scope_cond ? $doctor_scope_cond . " AND outcome_type='Improved'" : "WHERE outcome_type='Improved'"), $doctor_scope_params);
$stat_unchanged  = db_select_one("SELECT COUNT(*) AS cnt FROM clinical_outcomes " . ($doctor_scope_cond ? $doctor_scope_cond . " AND outcome_type='Unchanged'" : "WHERE outcome_type='Unchanged'"), $doctor_scope_params);
$stat_deteriorated = db_select_one("SELECT COUNT(*) AS cnt FROM clinical_outcomes " . ($doctor_scope_cond ? $doctor_scope_cond . " AND outcome_type='Deteriorated'" : "WHERE outcome_type='Deteriorated'"), $doctor_scope_params);
$stat_deceased   = db_select_one("SELECT COUNT(*) AS cnt FROM clinical_outcomes " . ($doctor_scope_cond ? $doctor_scope_cond . " AND outcome_type='Deceased'" : "WHERE outcome_type='Deceased'"), $doctor_scope_params);

// ─── Dropdowns ────────────────────────────────────────────────────────────────
$all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY last_name, first_name");
$all_doctors  = db_select("SELECT s.id, s.first_name, s.last_name FROM staff s JOIN users u ON s.user_id = u.id WHERE u.role = 'doctor' ORDER BY s.last_name");

// Appointments for modal (filtered per role client-side via JS)
$all_appointments = db_select(
    "SELECT a.id, a.appointment_time AS appointment_date, p.first_name AS p_first, p.last_name AS p_last
     FROM appointments a
     JOIN patients p ON a.patient_id = p.id
     ORDER BY a.appointment_time DESC LIMIT 500"
);

// ─── Chart data ───────────────────────────────────────────────────────────────
$chart_query_base = $doctor_scope_cond
    ? "SELECT outcome_type, COUNT(*) AS cnt FROM clinical_outcomes $doctor_scope_cond GROUP BY outcome_type"
    : "SELECT outcome_type, COUNT(*) AS cnt FROM clinical_outcomes GROUP BY outcome_type";
$chart_rows  = db_select($chart_query_base, $doctor_scope_params);
$chart_data  = [];
foreach ($chart_rows as $cr) {
    $chart_data[$cr['outcome_type']] = (int)$cr['cnt'];
}

include '../../includes/header.php';
?>

<style>
.out-stat-card {
    background:#fff; border-radius:12px; padding:18px 20px;
    display:flex; align-items:center; gap:14px;
    box-shadow:0 2px 8px rgba(0,0,0,0.06); border:1px solid #f0f0f0;
}
.out-stat-icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.out-stat-val  { font-size:1.6rem; font-weight:800; color:#1e293b; }
.out-stat-lbl  { font-size:0.8rem; color:#64748b; margin-top:2px; }
.outcome-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:0.78rem; font-weight:600; }
.ob-Recovered   { background:#d1fae5; color:#065f46; }
.ob-Improved    { background:#ccfbf1; color:#0f766e; }
.ob-Unchanged   { background:#f1f5f9; color:#475569; }
.ob-Deteriorated { background:#ffedd5; color:#9a3412; }
.ob-Readmitted  { background:#fef9c3; color:#854d0e; }
.ob-Deceased    { background:#fee2e2; color:#991b1b; }
.section-title  { font-size:1rem; font-weight:700; color:#1e293b; margin-bottom:14px; padding-bottom:8px; border-bottom:2px solid #e2e8f0; display:flex; align-items:center; gap:8px; }
</style>

<div style="padding:24px;">

    <!-- Page header -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="font-size:1.5rem; font-weight:800; color:#1e293b; margin:0;">
                <i class="fas fa-chart-line" style="color:#0891b2; margin-right:8px;"></i>
                Clinical Outcomes
            </h1>
            <p style="color:#64748b; font-size:0.88rem; margin:4px 0 0;">Track and analyse patient treatment outcomes</p>
        </div>
        <button class="btn btn-primary" onclick="openOutcomeModal()">
            <i class="fas fa-plus"></i> Record Outcome
        </button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:16px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom:16px;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:14px; margin-bottom:24px;">
        <div class="out-stat-card">
            <div class="out-stat-icon" style="background:#ede9fe;"><i class="fas fa-notes-medical" style="color:#7c3aed;"></i></div>
            <div><div class="out-stat-val"><?php echo (int)($stat_total['cnt'] ?? 0); ?></div><div class="out-stat-lbl">Total Outcomes</div></div>
        </div>
        <div class="out-stat-card">
            <div class="out-stat-icon" style="background:#d1fae5;"><i class="fas fa-smile" style="color:#059669;"></i></div>
            <div><div class="out-stat-val"><?php echo (int)($stat_recovered['cnt'] ?? 0); ?></div><div class="out-stat-lbl">Recovered</div></div>
        </div>
        <div class="out-stat-card">
            <div class="out-stat-icon" style="background:#ccfbf1;"><i class="fas fa-arrow-up" style="color:#0d9488;"></i></div>
            <div><div class="out-stat-val"><?php echo (int)($stat_improved['cnt'] ?? 0); ?></div><div class="out-stat-lbl">Improved</div></div>
        </div>
        <div class="out-stat-card">
            <div class="out-stat-icon" style="background:#f1f5f9;"><i class="fas fa-minus" style="color:#64748b;"></i></div>
            <div><div class="out-stat-val"><?php echo (int)($stat_unchanged['cnt'] ?? 0); ?></div><div class="out-stat-lbl">Unchanged</div></div>
        </div>
        <div class="out-stat-card">
            <div class="out-stat-icon" style="background:#ffedd5;"><i class="fas fa-arrow-down" style="color:#ea580c;"></i></div>
            <div><div class="out-stat-val"><?php echo (int)($stat_deteriorated['cnt'] ?? 0); ?></div><div class="out-stat-lbl">Deteriorated</div></div>
        </div>
        <div class="out-stat-card">
            <div class="out-stat-icon" style="background:#fee2e2;"><i class="fas fa-times-circle" style="color:#dc2626;"></i></div>
            <div><div class="out-stat-val"><?php echo (int)($stat_deceased['cnt'] ?? 0); ?></div><div class="out-stat-lbl">Deceased</div></div>
        </div>
    </div>

    <!-- Outcomes table + chart -->
    <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start;">

        <!-- Table -->
        <div class="card" style="padding:20px;">
            <div class="section-title">
                <i class="fas fa-table" style="color:#0891b2;"></i> Outcomes Log
            </div>

            <!-- Filters -->
            <form method="GET" action="" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; align-items:flex-end;">
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem; color:#64748b;">Outcome</label>
                    <select name="filter_outcome" class="form-control" style="font-size:0.85rem;">
                        <option value="">All Types</option>
                        <?php foreach (['Recovered','Improved','Unchanged','Deteriorated','Readmitted','Deceased'] as $ot): ?>
                        <option value="<?php echo $ot; ?>" <?php echo $filter_outcome === $ot ? 'selected' : ''; ?>><?php echo $ot; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($role === 'admin'): ?>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem; color:#64748b;">Doctor</label>
                    <select name="filter_doctor" class="form-control" style="font-size:0.85rem;">
                        <option value="">All Doctors</option>
                        <?php foreach ($all_doctors as $dr): ?>
                        <option value="<?php echo htmlspecialchars($dr['id']); ?>"
                                <?php echo $filter_doctor === $dr['id'] ? 'selected' : ''; ?>>
                            Dr. <?php echo htmlspecialchars($dr['first_name'] . ' ' . $dr['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem; color:#64748b;">From</label>
                    <input type="date" name="filter_from" class="form-control" style="font-size:0.85rem;" value="<?php echo htmlspecialchars($filter_from); ?>">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-size:0.8rem; color:#64748b;">To</label>
                    <input type="date" name="filter_to" class="form-control" style="font-size:0.85rem;" value="<?php echo htmlspecialchars($filter_to); ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="font-size:0.85rem;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="?" class="btn" style="font-size:0.85rem; background:#f1f5f9; color:#374151;">
                    <i class="fas fa-times"></i> Clear
                </a>
            </form>

            <div class="table-responsive">
                <table style="width:100%; border-collapse:collapse; font-size:0.84rem;">
                    <thead>
                        <tr style="background:#f8fafc; text-align:left;">
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Patient</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Doctor</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Diagnosis</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Treatment</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Outcome</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Date</th>
                            <th style="padding:10px 12px; border-bottom:2px solid #e2e8f0;">Follow-up</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outcomes)): ?>
                            <tr><td colspan="7" style="padding:14px 12px; color:#94a3b8; text-align:center;">No outcomes recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($outcomes as $o): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:9px 12px; font-weight:500; color:#374151;">
                                    <?php echo htmlspecialchars($o['pat_first'] . ' ' . $o['pat_last']); ?>
                                </td>
                                <td style="padding:9px 12px; color:#64748b;">
                                    Dr. <?php echo htmlspecialchars($o['doc_first'] . ' ' . $o['doc_last']); ?>
                                </td>
                                <td style="padding:9px 12px; color:#374151; max-width:120px; word-break:break-word;">
                                    <?php echo htmlspecialchars(mb_strimwidth($o['diagnosis_treated'] ?? '', 0, 50, '...')); ?>
                                </td>
                                <td style="padding:9px 12px; color:#64748b; max-width:120px; word-break:break-word;">
                                    <?php echo htmlspecialchars(mb_strimwidth($o['treatment_given'] ?? '', 0, 50, '...')); ?>
                                </td>
                                <td style="padding:9px 12px;">
                                    <span class="outcome-badge ob-<?php echo htmlspecialchars($o['outcome_type']); ?>">
                                        <?php echo htmlspecialchars($o['outcome_type']); ?>
                                    </span>
                                </td>
                                <td style="padding:9px 12px; white-space:nowrap; color:#64748b; font-size:0.82rem;">
                                    <?php echo htmlspecialchars(date('d M Y', strtotime($o['outcome_date']))); ?>
                                </td>
                                <td style="padding:9px 12px; text-align:center;">
                                    <?php if ($o['follow_up_required']): ?>
                                        <span style="color:#0891b2; font-size:0.82rem;">
                                            <i class="fas fa-calendar-check"></i>
                                            <?php echo $o['follow_up_date'] ? htmlspecialchars(date('d M Y', strtotime($o['follow_up_date']))) : 'TBD'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1; font-size:0.82rem;"><i class="fas fa-minus"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Chart -->
        <div class="card" style="padding:20px;">
            <div class="section-title">
                <i class="fas fa-chart-pie" style="color:#0891b2;"></i> Outcomes Distribution
            </div>
            <canvas id="outcomesChart" style="max-height:300px;"></canvas>
            <div style="margin-top:16px;">
                <?php
                $badge_map = [
                    'Recovered'   => ['bg'=>'#d1fae5','color'=>'#065f46'],
                    'Improved'    => ['bg'=>'#ccfbf1','color'=>'#0f766e'],
                    'Unchanged'   => ['bg'=>'#f1f5f9','color'=>'#475569'],
                    'Deteriorated'=> ['bg'=>'#ffedd5','color'=>'#9a3412'],
                    'Readmitted'  => ['bg'=>'#fef9c3','color'=>'#854d0e'],
                    'Deceased'    => ['bg'=>'#fee2e2','color'=>'#991b1b'],
                ];
                foreach ($badge_map as $type => $style): ?>
                <div style="display:flex; align-items:center; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f1f5f9; font-size:0.83rem;">
                    <span class="outcome-badge ob-<?php echo $type; ?>"><?php echo $type; ?></span>
                    <span style="font-weight:700; color:#1e293b;"><?php echo $chart_data[$type] ?? 0; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ─── Record Outcome Modal ──────────────────────────────────────────────────── -->
<div id="outcomeModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; align-items:center; justify-content:center; overflow-y:auto;">
    <div style="background:#fff; border-radius:14px; padding:28px 32px; width:560px; max-width:96%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.18); margin:20px auto;">
        <h3 style="margin:0 0 20px; font-size:1.1rem; color:#1e293b;">
            <i class="fas fa-plus-circle" style="color:#0891b2;"></i> Record Clinical Outcome
        </h3>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="record_outcome" value="1">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div class="form-group">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Patient <span style="color:red">*</span></label>
                    <select name="patient_id" id="sel_patient" class="form-control" style="font-size:0.88rem;" required>
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($all_patients as $pt): ?>
                        <option value="<?php echo htmlspecialchars($pt['id']); ?>">
                            <?php echo htmlspecialchars($pt['first_name'] . ' ' . $pt['last_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Doctor <span style="color:red">*</span></label>
                    <select name="doctor_id" class="form-control" style="font-size:0.88rem;" required
                            <?php echo ($role === 'doctor' && $staff_row) ? 'disabled' : ''; ?>>
                        <?php if ($role === 'doctor' && $staff_row): ?>
                            <?php
                            $own = null;
                            foreach ($all_doctors as $dr) { if ($dr['id'] === $staff_row['id']) { $own = $dr; break; } }
                            ?>
                            <option value="<?php echo htmlspecialchars($staff_row['id']); ?>" selected>
                                Dr. <?php echo $own ? htmlspecialchars($own['first_name'] . ' ' . $own['last_name']) : 'You'; ?>
                            </option>
                        <?php else: ?>
                            <option value="">-- Select Doctor --</option>
                            <?php foreach ($all_doctors as $dr): ?>
                            <option value="<?php echo htmlspecialchars($dr['id']); ?>">
                                Dr. <?php echo htmlspecialchars($dr['first_name'] . ' ' . $dr['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if ($role === 'doctor' && $staff_row): ?>
                        <input type="hidden" name="doctor_id" value="<?php echo htmlspecialchars($staff_row['id']); ?>">
                    <?php endif; ?>
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Related Appointment (optional)</label>
                    <select name="appointment_id" class="form-control" style="font-size:0.88rem;">
                        <option value="">-- None --</option>
                        <?php foreach ($all_appointments as $ap): ?>
                        <option value="<?php echo htmlspecialchars($ap['id']); ?>">
                            <?php echo htmlspecialchars($ap['p_first'] . ' ' . $ap['p_last'] . ' — ' . date('d M Y', strtotime($ap['appointment_date']))); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Outcome Type <span style="color:red">*</span></label>
                    <select name="outcome_type" class="form-control" style="font-size:0.88rem;" required>
                        <option value="">-- Select --</option>
                        <?php foreach (['Recovered','Improved','Unchanged','Deteriorated','Readmitted','Deceased'] as $ot): ?>
                        <option value="<?php echo $ot; ?>"><?php echo $ot; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Outcome Date <span style="color:red">*</span></label>
                    <input type="date" name="outcome_date" class="form-control" style="font-size:0.88rem;" required
                           value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Diagnosis Treated</label>
                    <input type="text" name="diagnosis_treated" class="form-control" style="font-size:0.88rem;" placeholder="e.g. Type 2 Diabetes">
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Treatment Given</label>
                    <input type="text" name="treatment_given" class="form-control" style="font-size:0.88rem;" placeholder="e.g. Metformin + lifestyle changes">
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" style="font-size:0.88rem;" placeholder="Additional observations..."></textarea>
                </div>

                <div class="form-group" style="grid-column:span 2;">
                    <label style="display:flex; align-items:center; gap:8px; font-size:0.88rem; color:#374151; cursor:pointer;">
                        <input type="checkbox" name="follow_up_required" id="chk_followup" onchange="toggleFollowup(this)" style="width:16px; height:16px;">
                        <strong>Follow-up Required</strong>
                    </label>
                </div>

                <div class="form-group" id="followup_date_wrap" style="grid-column:span 2; display:none;">
                    <label style="font-weight:600; color:#374151; font-size:0.88rem;">Follow-up Date</label>
                    <input type="date" name="follow_up_date" class="form-control" style="font-size:0.88rem;">
                </div>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                <button type="button" onclick="closeOutcomeModal()"
                        class="btn" style="background:#f1f5f9; color:#374151;">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Record Outcome
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Chart
(function() {
    var data = <?php echo json_encode($chart_data); ?>;
    var labels = Object.keys(data);
    var values = Object.values(data);
    var colorMap = {
        'Recovered':    '#10b981',
        'Improved':     '#14b8a6',
        'Unchanged':    '#94a3b8',
        'Deteriorated': '#f97316',
        'Readmitted':   '#eab308',
        'Deceased':     '#ef4444'
    };
    var colors = labels.map(function(l){ return colorMap[l] || '#ccc'; });
    if (labels.length === 0) {
        document.getElementById('outcomesChart').parentElement.innerHTML += '<p style="color:#94a3b8; text-align:center; font-size:0.85rem; margin-top:20px;">No data available.</p>';
        return;
    }
    new Chart(document.getElementById('outcomesChart'), {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });
})();

function openOutcomeModal() {
    document.getElementById('outcomeModal').style.display = 'flex';
}
function closeOutcomeModal() {
    document.getElementById('outcomeModal').style.display = 'none';
}
function toggleFollowup(cb) {
    document.getElementById('followup_date_wrap').style.display = cb.checked ? 'block' : 'none';
}
document.getElementById('outcomeModal').addEventListener('click', function(e) {
    if (e.target === this) closeOutcomeModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
