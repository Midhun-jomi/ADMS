<?php
// modules/infection_control/dashboard.php
error_reporting(0);
ini_set('display_errors', 0);
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse', 'head_nurse']);

$role    = get_user_role();
$user_id = get_user_id();

$success = '';
$error   = '';

// ── POST: Add new infection case ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'];

        if ($action === 'add_case') {
            try {
                db_query(
                    "INSERT INTO infection_cases
                        (patient_id, infection_type, source, date_identified, ward,
                         isolation_required, antibiotic_prescribed, resistance_pattern,
                         notes, assigned_doctor_id, reported_by)
                     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)",
                    [
                        $_POST['patient_id'],
                        $_POST['infection_type'],
                        $_POST['source'],
                        $_POST['date_identified'],
                        $_POST['ward'],
                        isset($_POST['isolation_required']) ? 'true' : 'false',
                        $_POST['antibiotic_prescribed'],
                        implode(', ', $_POST['resistance_pattern'] ?? []),
                        $_POST['notes'],
                        !empty($_POST['assigned_doctor_id']) ? $_POST['assigned_doctor_id'] : null,
                        $user_id,
                    ]
                );
                $success = "Infection case reported successfully.";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        } elseif ($action === 'resolve_case') {
            try {
                db_query(
                    "UPDATE infection_cases SET status='resolved', resolution_date=$1, resolution_notes=$2 WHERE id=$3",
                    [$_POST['resolution_date'], $_POST['resolution_notes'], $_POST['case_id']]
                );
                $success = "Case marked as resolved.";
            } catch (Exception $e) {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$filter_type   = $_GET['type']   ?? '';
$filter_ward   = $_GET['ward']   ?? '';
$filter_status = $_GET['status'] ?? '';

$stats       = ['total'=>0,'active'=>0,'new_this_week'=>0,'resolved'=>0,'high_risk'=>0];
$cases       = [];
$outbreaks   = [];
$antibiotics = [];
$wards       = [];
$table_exists = false;

try {
    // Check if table exists
    $tcheck = db_select_one(
        "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='infection_cases'"
    );
    $table_exists = !empty($tcheck);
} catch (Exception $e) { $table_exists = false; }

if ($table_exists) {
    $where  = [];
    $params = [];
    $i      = 1;
    if ($filter_type)   { $where[] = "ic.infection_type = \$$i"; $params[] = $filter_type;   $i++; }
    if ($filter_ward)   { $where[] = "ic.ward = \$$i";           $params[] = $filter_ward;   $i++; }
    if ($filter_status) { $where[] = "ic.status = \$$i";         $params[] = $filter_status; $i++; }
    $whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

    try {
        $cases = db_select("
            SELECT ic.*, p.first_name || ' ' || p.last_name AS patient_name,
                   s.first_name || ' ' || s.last_name AS doctor_name
            FROM infection_cases ic
            LEFT JOIN patients p ON ic.patient_id = p.id
            LEFT JOIN staff s    ON ic.assigned_doctor_id = s.id
            $whereSQL
            ORDER BY ic.created_at DESC", $params);
    } catch (Exception $e) { $cases = []; }

    try {
        $stats = db_select_one("
            SELECT
                COUNT(*) AS total,
                COUNT(CASE WHEN status='active' THEN 1 END) AS active,
                COUNT(CASE WHEN created_at >= NOW()-INTERVAL '7 days' AND status='active' THEN 1 END) AS new_this_week,
                COUNT(CASE WHEN status='resolved' THEN 1 END) AS resolved,
                COUNT(CASE WHEN isolation_required=true AND status='active' THEN 1 END) AS high_risk
            FROM infection_cases") ?? $stats;
    } catch (Exception $e) {}

    try {
        $outbreaks = db_select("
            SELECT infection_type, ward, COUNT(*) AS cnt
            FROM infection_cases
            WHERE status = 'active'
            GROUP BY infection_type, ward
            HAVING COUNT(*) >= 3");
    } catch (Exception $e) { $outbreaks = []; }

    try {
        $antibiotics = db_select("
            SELECT antibiotic_prescribed, resistance_pattern, COUNT(*) AS cnt
            FROM infection_cases
            WHERE antibiotic_prescribed IS NOT NULL AND antibiotic_prescribed <> ''
            GROUP BY antibiotic_prescribed, resistance_pattern
            ORDER BY cnt DESC LIMIT 20");
    } catch (Exception $e) { $antibiotics = []; }

    try {
        $wards = db_select("SELECT DISTINCT ward FROM infection_cases WHERE ward IS NOT NULL ORDER BY ward");
    } catch (Exception $e) { $wards = []; }
}

// Dropdown data (these tables always exist)
$patients = db_select("SELECT id, first_name || ' ' || last_name AS name FROM patients ORDER BY first_name");
$doctors  = db_select("SELECT id, first_name || ' ' || last_name AS name FROM staff WHERE role='doctor' OR specialization IS NOT NULL ORDER BY first_name");
$types    = ['MRSA','C.difficile','VRSA','UTI','SSI','VAP','CLABSI','COVID-19','Influenza','Other'];

$page_title = "Infection Control";
include '../../includes/header.php';
?>

<style>
.ic-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:18px; margin-bottom:28px; }
.ic-stat-card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 4px 15px rgba(0,0,0,.05); text-align:center; }
.ic-stat-card .ic-num { font-size:2.2rem; font-weight:700; }
.ic-stat-card .ic-lbl { font-size:.82rem; color:#888; margin-top:4px; }
.ic-active  { color:#ef4444; }
.ic-new     { color:#f97316; }
.ic-resolved{ color:#22c55e; }
.ic-risk    { color:#a855f7; }
.ic-total   { color:#3b82f6; }

.badge-community  { background:#dbeafe; color:#1d4ed8; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }
.badge-hospital   { background:#fee2e2; color:#b91c1c; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }
.badge-unknown    { background:#f3f4f6; color:#6b7280; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }
.badge-active     { background:#fee2e2; color:#b91c1c; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }
.badge-resolved   { background:#dcfce7; color:#166534; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }
.badge-transferred{ background:#fef9c3; color:#854d0e; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }
.badge-isolation  { background:#fce7f3; color:#9d174d; padding:3px 10px; border-radius:99px; font-size:.78rem; font-weight:600; }

.outbreak-alert { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px 18px; margin-bottom:20px; color:#991b1b; }
.outbreak-alert i { color:#ef4444; margin-right:8px; }
</style>

<?php if (!$table_exists): ?>
    <div style="background:#fef9c3;border:1px solid #fde047;border-radius:10px;padding:14px 18px;margin-bottom:20px;color:#854d0e;font-size:0.9em;">
        <i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i>
        <strong>Setup required:</strong> The <code>infection_cases</code> table does not exist yet.
        Run the schema migration (<code>db_schema_new_modules.sql</code>) to enable this module.
    </div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Outbreak Alerts -->
<?php foreach ($outbreaks as $ob): ?>
<div class="outbreak-alert">
    <i class="fas fa-radiation-alt"></i>
    <strong>OUTBREAK ALERT:</strong> <?= htmlspecialchars($ob['cnt']) ?> active cases of
    <strong><?= htmlspecialchars($ob['infection_type']) ?></strong>
    in ward <strong><?= htmlspecialchars($ob['ward']) ?></strong> — immediate review required.
</div>
<?php endforeach; ?>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
    <div>
        <h1 style="margin:0;">Infection Control</h1>
        <p style="color:#888;margin:4px 0 0;">Monitor and manage hospital-acquired and community infections.</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').style.display='flex'">
        <i class="fas fa-plus"></i> Report Infection Case
    </button>
</div>

<!-- Stats -->
<div class="ic-stats">
    <div class="ic-stat-card"><div class="ic-num ic-total"><?= $stats['total'] ?? 0 ?></div><div class="ic-lbl">Total Cases</div></div>
    <div class="ic-stat-card"><div class="ic-num ic-active"><?= $stats['active'] ?? 0 ?></div><div class="ic-lbl">Active</div></div>
    <div class="ic-stat-card"><div class="ic-num ic-new"><?= $stats['new_this_week'] ?? 0 ?></div><div class="ic-lbl">New This Week</div></div>
    <div class="ic-stat-card"><div class="ic-num ic-resolved"><?= $stats['resolved'] ?? 0 ?></div><div class="ic-lbl">Resolved</div></div>
    <div class="ic-stat-card"><div class="ic-num ic-risk"><?= $stats['high_risk'] ?? 0 ?></div><div class="ic-lbl">Isolation Required</div></div>
</div>

<!-- Filters -->
<div class="card" style="padding:16px;margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group" style="margin:0;">
            <label style="font-size:.82rem;color:#666;">Infection Type</label>
            <select name="type" style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;">
                <option value="">All Types</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= $t ?>" <?= $filter_type===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:.82rem;color:#666;">Ward</label>
            <select name="ward" style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;">
                <option value="">All Wards</option>
                <?php foreach ($wards as $w): ?>
                    <option value="<?= htmlspecialchars($w['ward']) ?>" <?= $filter_ward===$w['ward']?'selected':'' ?>><?= htmlspecialchars($w['ward']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:.82rem;color:#666;">Status</label>
            <select name="status" style="padding:7px 12px;border:1px solid #ddd;border-radius:8px;">
                <option value="">All Statuses</option>
                <option value="active"     <?= $filter_status==='active'?'selected':''      ?>>Active</option>
                <option value="resolved"   <?= $filter_status==='resolved'?'selected':''    ?>>Resolved</option>
                <option value="transferred"<?= $filter_status==='transferred'?'selected':'' ?>>Transferred</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding:8px 18px;">Filter</button>
        <a href="?" class="btn" style="padding:8px 18px;border:1px solid #ddd;">Reset</a>
    </form>
</div>

<!-- Cases Table -->
<div class="card">
    <div style="padding:18px 20px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">Infection Cases (<?= count($cases) ?>)</h3>
        <input type="text" id="searchIC" onkeyup="filterTable('searchIC','tbl-ic')" placeholder="Search..." style="padding:7px 14px;border:1px solid #e5e7eb;border-radius:8px;font-size:.88em;width:220px;outline:none;">
    </div>
    <div class="table-responsive">
        <table id="tbl-ic" style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Patient</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Infection</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Source</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Date</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Ward</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Isolation</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Antibiotic</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Status</th>
                    <th style="padding:12px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cases)): ?>
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;"><i class="fas fa-biohazard" style="font-size:2rem;opacity:.3;margin-bottom:10px;display:block;"></i>No infection cases found.</td></tr>
                <?php else: ?>
                    <?php foreach ($cases as $c): ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:12px 16px;font-weight:600;"><?= htmlspecialchars($c['patient_name'] ?? 'N/A') ?></td>
                        <td style="padding:12px 16px;">
                            <span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:99px;font-size:.78rem;font-weight:600;">
                                <?= htmlspecialchars($c['infection_type']) ?>
                            </span>
                        </td>
                        <td style="padding:12px 16px;">
                            <?php
                                $srcClass = match($c['source']) {
                                    'Community'        => 'badge-community',
                                    'Hospital-acquired'=> 'badge-hospital',
                                    default            => 'badge-unknown'
                                };
                            ?>
                            <span class="<?= $srcClass ?>"><?= htmlspecialchars($c['source']) ?></span>
                        </td>
                        <td style="padding:12px 16px;color:#555;"><?= date('d M Y', strtotime($c['date_identified'])) ?></td>
                        <td style="padding:12px 16px;color:#555;"><?= htmlspecialchars($c['ward'] ?? 'N/A') ?></td>
                        <td style="padding:12px 16px;">
                            <?php if ($c['isolation_required']): ?>
                                <span class="badge-isolation"><i class="fas fa-ban"></i> Yes</span>
                            <?php else: ?>
                                <span style="color:#888;font-size:.85rem;">No</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 16px;color:#555;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($c['antibiotic_prescribed'] ?? '') ?>">
                            <?= htmlspecialchars($c['antibiotic_prescribed'] ?? 'None') ?>
                        </td>
                        <td style="padding:12px 16px;">
                            <span class="badge-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span>
                        </td>
                        <td style="padding:12px 16px;">
                            <div style="display:flex;gap:6px;">
                                <button class="btn" style="font-size:.8rem;padding:5px 10px;border:1px solid #ddd;"
                                    onclick="viewCase(<?= htmlspecialchars(json_encode($c)) ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if ($c['status'] === 'active'): ?>
                                <button class="btn btn-primary" style="font-size:.8rem;padding:5px 10px;"
                                    onclick="resolveCase('<?= $c['id'] ?>')">
                                    <i class="fas fa-check"></i> Resolve
                                </button>
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

<!-- Antibiotic Stewardship -->
<?php if (!empty($antibiotics)): ?>
<div class="card" style="margin-top:24px;">
    <div style="padding:18px 20px;border-bottom:1px solid #f3f4f6;">
        <h3 style="margin:0;"><i class="fas fa-pills" style="color:#f97316;margin-right:8px;"></i>Antibiotic Stewardship Summary</h3>
    </div>
    <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f9fafb;">
                    <th style="padding:10px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Antibiotic</th>
                    <th style="padding:10px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Resistance Pattern</th>
                    <th style="padding:10px 16px;text-align:left;font-size:.78rem;color:#6b7280;text-transform:uppercase;">Cases</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($antibiotics as $ab): ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:10px 16px;font-weight:600;"><?= htmlspecialchars($ab['antibiotic_prescribed']) ?></td>
                    <td style="padding:10px 16px;color:#ef4444;font-size:.85rem;"><?= htmlspecialchars($ab['resistance_pattern'] ?? 'None documented') ?></td>
                    <td style="padding:10px 16px;"><strong><?= $ab['cnt'] ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Add Case Modal -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;width:640px;max-height:90vh;overflow-y:auto;position:relative;">
        <h3 style="margin:0 0 20px;"><i class="fas fa-biohazard" style="color:#ef4444;margin-right:8px;"></i>Report Infection Case</h3>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_case">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label>Patient *</label>
                    <select name="patient_id" required style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Infection Type *</label>
                    <select name="infection_type" required style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                        <option value="">-- Select --</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t ?>"><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Source *</label>
                    <select name="source" required style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                        <option value="Unknown">Unknown</option>
                        <option value="Community">Community</option>
                        <option value="Hospital-acquired">Hospital-acquired</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date Identified *</label>
                    <input type="date" name="date_identified" required value="<?= date('Y-m-d') ?>" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                </div>
                <div class="form-group">
                    <label>Ward / Location</label>
                    <input type="text" name="ward" placeholder="e.g. ICU, Ward 3" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                </div>
                <div class="form-group">
                    <label>Assigned Doctor</label>
                    <select name="assigned_doctor_id" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                        <option value="">-- None --</option>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Antibiotic Prescribed</label>
                    <input type="text" name="antibiotic_prescribed" placeholder="e.g. Vancomycin" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:10px;margin-top:24px;">
                    <input type="checkbox" name="isolation_required" id="iso" value="1">
                    <label for="iso" style="font-size:.9rem;cursor:pointer;">Isolation Required</label>
                </div>
            </div>
            <div class="form-group">
                <label>Antibiotic Resistance Pattern</label>
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;">
                    <?php foreach (['Penicillin','Methicillin','Vancomycin','Carbapenem','Fluoroquinolone','Aminoglycoside'] as $r): ?>
                        <label style="display:flex;align-items:center;gap:6px;font-size:.88rem;cursor:pointer;">
                            <input type="checkbox" name="resistance_pattern[]" value="<?= $r ?>"> <?= $r ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3" placeholder="Clinical observations, symptoms..." style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:10px;margin-top:10px;">
                <button type="submit" class="btn btn-primary">Report Case</button>
                <button type="button" class="btn" style="border:1px solid #ddd;" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Resolve Modal -->
<div id="resolveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;width:460px;">
        <h3 style="margin:0 0 20px;"><i class="fas fa-check-circle" style="color:#22c55e;margin-right:8px;"></i>Resolve Infection Case</h3>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="resolve_case">
            <input type="hidden" name="case_id" id="resolve_case_id">
            <div class="form-group">
                <label>Resolution Date *</label>
                <input type="date" name="resolution_date" required value="<?= date('Y-m-d') ?>" style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;">
            </div>
            <div class="form-group">
                <label>Resolution Notes</label>
                <textarea name="resolution_notes" rows="3" placeholder="Describe outcome and treatment..." style="width:100%;padding:9px;border:1px solid #ddd;border-radius:8px;resize:vertical;"></textarea>
            </div>
            <div style="display:flex;gap:10px;margin-top:10px;">
                <button type="submit" class="btn btn-primary">Mark Resolved</button>
                <button type="button" class="btn" style="border:1px solid #ddd;" onclick="document.getElementById('resolveModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;width:560px;max-height:85vh;overflow-y:auto;">
        <h3 style="margin:0 0 20px;"><i class="fas fa-biohazard" style="color:#ef4444;margin-right:8px;"></i>Infection Case Details</h3>
        <div id="viewContent"></div>
        <button class="btn" style="margin-top:20px;border:1px solid #ddd;" onclick="document.getElementById('viewModal').style.display='none'">Close</button>
    </div>
</div>

<script>
function resolveCase(id) {
    document.getElementById('resolve_case_id').value = id;
    document.getElementById('resolveModal').style.display = 'flex';
}

function viewCase(c) {
    const yesNo = v => v ? '<span style="color:#22c55e;font-weight:600;">Yes</span>' : '<span style="color:#888;">No</span>';
    document.getElementById('viewContent').innerHTML = `
        <table style="width:100%;border-collapse:collapse;">
            <tr><td style="padding:8px 0;color:#888;width:45%;">Patient</td><td style="padding:8px 0;font-weight:600;">${escHtml(c.patient_name||'N/A')}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Infection Type</td><td style="padding:8px 0;">${escHtml(c.infection_type)}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Source</td><td style="padding:8px 0;">${escHtml(c.source)}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Date Identified</td><td style="padding:8px 0;">${escHtml(c.date_identified)}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Ward</td><td style="padding:8px 0;">${escHtml(c.ward||'N/A')}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Isolation Required</td><td style="padding:8px 0;">${yesNo(c.isolation_required)}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Antibiotic</td><td style="padding:8px 0;">${escHtml(c.antibiotic_prescribed||'None')}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Resistance Pattern</td><td style="padding:8px 0;color:#ef4444;">${escHtml(c.resistance_pattern||'None documented')}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Assigned Doctor</td><td style="padding:8px 0;">${escHtml(c.doctor_name||'N/A')}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Status</td><td style="padding:8px 0;">${escHtml(c.status)}</td></tr>
            <tr><td style="padding:8px 0;color:#888;">Notes</td><td style="padding:8px 0;">${escHtml(c.notes||'—')}</td></tr>
            ${c.resolution_date ? `<tr><td style="padding:8px 0;color:#888;">Resolution Date</td><td style="padding:8px 0;">${escHtml(c.resolution_date)}</td></tr>` : ''}
            ${c.resolution_notes ? `<tr><td style="padding:8px 0;color:#888;">Resolution Notes</td><td style="padding:8px 0;">${escHtml(c.resolution_notes)}</td></tr>` : ''}
        </table>`;
    document.getElementById('viewModal').style.display = 'flex';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include '../../includes/footer.php'; ?>
