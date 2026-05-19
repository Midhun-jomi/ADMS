<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

$allowed_roles = ['admin', 'doctor', 'nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Operation Theatre Schedule";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_surgery'])) {
    try {
        db_insert('surgeries', [
            'patient_id'      => $_POST['patient_id'],
            'doctor_id'       => $_POST['doctor_id'],
            'theatre_id'      => $_POST['theatre_id'],
            'surgery_name'    => trim($_POST['surgery_name']),
            'scheduled_start' => $_POST['scheduled_start'],
            'scheduled_end'   => $_POST['scheduled_end'],
            'status'          => 'scheduled'
        ]);
        $success_msg = "Surgery scheduled successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$patients  = db_select("SELECT id, first_name, last_name FROM patients ORDER BY first_name");
$doctors   = db_select("SELECT id, first_name, last_name FROM staff WHERE role = 'doctor' ORDER BY first_name");
$theatres  = db_select("SELECT * FROM theatres ORDER BY name");
$surgeries = db_select("
    SELECT s.*,
           p.first_name AS p_fname, p.last_name AS p_lname,
           d.first_name AS d_fname, d.last_name AS d_lname,
           t.name AS theatre_name, t.type AS theatre_type
    FROM surgeries s
    JOIN patients p ON s.patient_id = p.id
    JOIN staff d ON s.doctor_id = d.id
    LEFT JOIN theatres t ON s.theatre_id = t.id
    ORDER BY s.scheduled_start ASC
");

if (empty($theatres)) {
    db_insert('theatres', ['name' => 'General OT 1', 'type' => 'General',  'status' => 'available']);
    db_insert('theatres', ['name' => 'General OT 2', 'type' => 'General',  'status' => 'available']);
    $theatres = db_select("SELECT * FROM theatres ORDER BY name");
}

// Count stats
$total      = count($surgeries);
$scheduled  = count(array_filter($surgeries, fn($s) => $s['status'] === 'scheduled'));
$inProgress = count(array_filter($surgeries, fn($s) => $s['status'] === 'in_progress'));
$completed  = count(array_filter($surgeries, fn($s) => $s['status'] === 'completed'));
$availableOTs = count(array_filter($theatres, fn($t) => $t['status'] === 'available'));
?>

<div class="main-content">

    <!-- Page Header -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="margin:0; font-size:1.7rem; font-weight:700; color:#111827;">
                <i class="fas fa-procedures" style="color:#7c3aed;"></i> Operation Theatre Schedule
            </h1>
            <p style="margin:5px 0 0; color:#6b7280; font-size:0.9em;">Manage surgical bookings and theatre availability</p>
        </div>
        <button class="ot-btn ot-btn-primary" onclick="showModal('bookingModal')">
            <i class="fas fa-calendar-plus"></i> Book OT
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="ot-alert ot-alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="ot-alert ot-alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="ot-stats-row">
        <div class="ot-stat-card" style="--accent:#7c3aed;">
            <div class="ot-stat-icon" style="background:#ede9fe;"><i class="fas fa-calendar-alt" style="color:#7c3aed;"></i></div>
            <div class="ot-stat-info"><div class="ot-stat-value"><?php echo $total; ?></div><div class="ot-stat-label">Total Surgeries</div></div>
        </div>
        <div class="ot-stat-card" style="--accent:#2563eb;">
            <div class="ot-stat-icon" style="background:#dbeafe;"><i class="fas fa-clock" style="color:#2563eb;"></i></div>
            <div class="ot-stat-info"><div class="ot-stat-value"><?php echo $scheduled; ?></div><div class="ot-stat-label">Scheduled</div></div>
        </div>
        <div class="ot-stat-card" style="--accent:#d97706;">
            <div class="ot-stat-icon" style="background:#fef3c7;"><i class="fas fa-spinner" style="color:#d97706;"></i></div>
            <div class="ot-stat-info"><div class="ot-stat-value"><?php echo $inProgress; ?></div><div class="ot-stat-label">In Progress</div></div>
        </div>
        <div class="ot-stat-card" style="--accent:#059669;">
            <div class="ot-stat-icon" style="background:#d1fae5;"><i class="fas fa-check-double" style="color:#059669;"></i></div>
            <div class="ot-stat-info"><div class="ot-stat-value"><?php echo $completed; ?></div><div class="ot-stat-label">Completed</div></div>
        </div>
        <div class="ot-stat-card" style="--accent:#0891b2;">
            <div class="ot-stat-icon" style="background:#cffafe;"><i class="fas fa-door-open" style="color:#0891b2;"></i></div>
            <div class="ot-stat-info"><div class="ot-stat-value"><?php echo $availableOTs; ?></div><div class="ot-stat-label">OTs Available</div></div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="ot-main-grid">

        <!-- Surgery Table -->
        <div class="ot-card">
            <div class="ot-card-header">
                <div>
                    <h3><i class="fas fa-list-alt"></i> Upcoming Operations</h3>
                    <p>All scheduled and ongoing procedures</p>
                </div>
            </div>
            <?php if (empty($surgeries)): ?>
                <div class="ot-empty">
                    <i class="fas fa-calendar-times"></i>
                    <p>No surgeries scheduled yet.</p>
                </div>
            <?php else: ?>
            <div class="ot-table-wrap">
                <table class="ot-table">
                    <thead>
                        <tr>
                            <th>Surgery</th>
                            <th>Patient</th>
                            <th>Lead Surgeon</th>
                            <th>Theatre</th>
                            <th>Date & Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($surgeries as $op):
                            $statusClass = match($op['status']) {
                                'scheduled'   => 'blue',
                                'in_progress' => 'orange',
                                'completed'   => 'green',
                                'cancelled'   => 'red',
                                default       => 'gray'
                            };
                            $isPast = strtotime($op['scheduled_end']) < time();
                        ?>
                        <tr class="<?php echo $isPast && $op['status'] === 'scheduled' ? 'ot-row-muted' : ''; ?>">
                            <td>
                                <div class="ot-surgery-name">
                                    <i class="fas fa-scalpel-path" style="color:#7c3aed; margin-right:6px;"></i>
                                    <strong><?php echo htmlspecialchars($op['surgery_name']); ?></strong>
                                </div>
                            </td>
                            <td>
                                <div class="ot-person">
                                    <div class="ot-avatar ot-avatar-blue"><?php echo strtoupper(substr($op['p_fname'],0,1)); ?></div>
                                    <span><?php echo htmlspecialchars($op['p_fname'] . ' ' . $op['p_lname']); ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="ot-person">
                                    <div class="ot-avatar ot-avatar-purple"><?php echo strtoupper(substr($op['d_fname'],0,1)); ?></div>
                                    <span>Dr. <?php echo htmlspecialchars($op['d_fname'] . ' ' . $op['d_lname']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="ot-theatre-badge">
                                    <i class="fas fa-door-closed"></i>
                                    <?php echo htmlspecialchars($op['theatre_name'] ?? '—'); ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-size:0.88em;">
                                    <div style="font-weight:600; color:#111827;"><?php echo date('M d, Y', strtotime($op['scheduled_start'])); ?></div>
                                    <div style="color:#6b7280;"><?php echo date('H:i', strtotime($op['scheduled_start'])); ?> – <?php echo date('H:i', strtotime($op['scheduled_end'])); ?></div>
                                </div>
                            </td>
                            <td>
                                <span class="ot-pill ot-pill-<?php echo $statusClass; ?>">
                                    <?php echo ucfirst(str_replace('_',' ',$op['status'])); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Theatre Status Panel -->
        <div class="ot-side-panel">
            <div class="ot-card">
                <div class="ot-card-header">
                    <div>
                        <h3><i class="fas fa-hospital-symbol"></i> Theatre Status</h3>
                        <p>Live OT availability</p>
                    </div>
                </div>
                <div style="padding:16px; display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ($theatres as $ot):
                        $statusColor = match($ot['status']) {
                            'available'   => '#059669',
                            'occupied'    => '#dc2626',
                            'cleaning'    => '#d97706',
                            'maintenance' => '#6b7280',
                            default       => '#6b7280'
                        };
                        $statusBg = match($ot['status']) {
                            'available'   => '#d1fae5',
                            'occupied'    => '#fee2e2',
                            'cleaning'    => '#fef3c7',
                            'maintenance' => '#f3f4f6',
                            default       => '#f3f4f6'
                        };
                        $statusIcon = match($ot['status']) {
                            'available'   => 'fa-check-circle',
                            'occupied'    => 'fa-user-md',
                            'cleaning'    => 'fa-broom',
                            'maintenance' => 'fa-tools',
                            default       => 'fa-circle'
                        };
                    ?>
                    <div class="ot-theatre-card" style="border-left:4px solid <?php echo $statusColor; ?>;">
                        <div class="ot-theatre-icon" style="background:<?php echo $statusBg; ?>; color:<?php echo $statusColor; ?>;">
                            <i class="fas fa-procedures"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:700; color:#111827; font-size:0.95em;"><?php echo htmlspecialchars($ot['name']); ?></div>
                            <div style="font-size:0.8em; color:#6b7280;"><?php echo htmlspecialchars($ot['type']); ?></div>
                        </div>
                        <span style="display:inline-flex; align-items:center; gap:5px; font-size:0.78em; font-weight:700; color:<?php echo $statusColor; ?>; background:<?php echo $statusBg; ?>; padding:4px 10px; border-radius:20px;">
                            <i class="fas <?php echo $statusIcon; ?>"></i>
                            <?php echo ucfirst($ot['status']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Tips Card -->
            <div class="ot-card" style="margin-top:0;">
                <div class="ot-card-header">
                    <div><h3><i class="fas fa-info-circle" style="color:#2563eb;"></i> OT Guidelines</h3></div>
                </div>
                <div style="padding:16px; font-size:0.85em; color:#4b5563; line-height:1.8;">
                    <div><i class="fas fa-dot-circle" style="color:#7c3aed; margin-right:6px;"></i> Standard surgery buffer: 30 mins</div>
                    <div><i class="fas fa-dot-circle" style="color:#7c3aed; margin-right:6px;"></i> Pre-op check: 1 hour before</div>
                    <div><i class="fas fa-dot-circle" style="color:#7c3aed; margin-right:6px;"></i> Clean-up time: 15–20 mins post-op</div>
                    <div><i class="fas fa-dot-circle" style="color:#7c3aed; margin-right:6px;"></i> Confirm anaesthesiologist availability</div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ===== BOOKING MODAL ===== -->
<div id="bookingModal" class="ot-overlay" onclick="overlayClose(event,'bookingModal')">
    <div class="ot-modal">
        <div class="ot-modal-header">
            <div>
                <h3><i class="fas fa-calendar-plus" style="color:#7c3aed;"></i> Schedule Surgery</h3>
                <p>Book an operation theatre slot</p>
            </div>
            <button class="ot-close-btn" onclick="closeModal('bookingModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="ot-modal-body">
            <div class="ot-form-group">
                <label>Surgery Name / Type <span class="ot-req">*</span></label>
                <input type="text" name="surgery_name" required class="ot-input" placeholder="e.g. Appendectomy, Knee Replacement...">
            </div>
            <div class="ot-form-row">
                <div class="ot-form-group">
                    <label>Patient <span class="ot-req">*</span></label>
                    <select name="patient_id" required class="ot-select">
                        <option value="">— Select Patient —</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ot-form-group">
                    <label>Lead Surgeon <span class="ot-req">*</span></label>
                    <select name="doctor_id" required class="ot-select">
                        <option value="">— Select Surgeon —</option>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>">Dr. <?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="ot-form-group">
                <label>Operation Theatre <span class="ot-req">*</span></label>
                <select name="theatre_id" required class="ot-select">
                    <option value="">— Select OT —</option>
                    <?php foreach ($theatres as $t): ?>
                        <option value="<?php echo $t['id']; ?>" <?php echo $t['status'] !== 'available' ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($t['name']); ?> (<?php echo ucfirst($t['status']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ot-form-row">
                <div class="ot-form-group">
                    <label>Start Time <span class="ot-req">*</span></label>
                    <input type="datetime-local" name="scheduled_start" required class="ot-input">
                </div>
                <div class="ot-form-group">
                    <label>Estimated End Time <span class="ot-req">*</span></label>
                    <input type="datetime-local" name="scheduled_end" required class="ot-input">
                </div>
            </div>
            <div class="ot-modal-footer">
                <button type="button" onclick="closeModal('bookingModal')" class="ot-btn ot-btn-ghost">Cancel</button>
                <button type="submit" name="book_surgery" class="ot-btn ot-btn-primary">
                    <i class="fas fa-calendar-check"></i> Confirm Booking
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* === OT Page Styles === */
.ot-stats-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:14px; margin-bottom:24px; }
.ot-stat-card { background:#fff; border-radius:12px; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 6px rgba(0,0,0,0.06); border:1px solid #f3f4f6; transition:transform 0.2s; }
.ot-stat-card:hover { transform:translateY(-2px); }
.ot-stat-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1em; flex-shrink:0; }
.ot-stat-value { font-size:1.6rem; font-weight:800; color:#111827; line-height:1; }
.ot-stat-label { font-size:0.76em; color:#6b7280; margin-top:3px; }

.ot-main-grid { display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start; }
@media(max-width:1024px) { .ot-main-grid { grid-template-columns:1fr; } }

.ot-card { background:#fff; border-radius:14px; box-shadow:0 1px 8px rgba(0,0,0,0.07); overflow:hidden; margin-bottom:16px; }
.ot-card-header { padding:18px 20px 14px; border-bottom:1px solid #f3f4f6; }
.ot-card-header h3 { margin:0 0 3px; font-size:1rem; color:#111827; display:flex; align-items:center; gap:8px; }
.ot-card-header p { margin:0; font-size:0.82em; color:#9ca3af; }

.ot-table-wrap { overflow-x:auto; }
.ot-table { width:100%; border-collapse:collapse; }
.ot-table th { background:#f9fafb; padding:11px 16px; text-align:left; font-size:0.78em; text-transform:uppercase; letter-spacing:0.5px; color:#6b7280; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.ot-table td { padding:14px 16px; border-bottom:1px solid #f3f4f6; font-size:0.88em; vertical-align:middle; }
.ot-table tr:last-child td { border:none; }
.ot-table tr:hover td { background:#fafafa; }
.ot-row-muted td { opacity:0.55; }

.ot-person { display:flex; align-items:center; gap:9px; }
.ot-avatar { width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8em; flex-shrink:0; }
.ot-avatar-blue { background:#dbeafe; color:#1d4ed8; }
.ot-avatar-purple { background:#ede9fe; color:#6d28d9; }
.ot-surgery-name { display:flex; align-items:center; }

.ot-theatre-badge { background:#f3f4f6; color:#374151; padding:4px 10px; border-radius:6px; font-size:0.82em; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.ot-pill { display:inline-block; padding:4px 12px; border-radius:20px; font-size:0.78em; font-weight:700; white-space:nowrap; }
.ot-pill-blue { background:#dbeafe; color:#1d4ed8; }
.ot-pill-green { background:#d1fae5; color:#065f46; }
.ot-pill-orange { background:#fef3c7; color:#92400e; }
.ot-pill-red { background:#fee2e2; color:#b91c1c; }
.ot-pill-gray { background:#f3f4f6; color:#374151; }

.ot-theatre-card { display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px; background:#fafafa; border:1px solid #e5e7eb; border-left-width:4px; }
.ot-theatre-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0; }

.ot-side-panel { display:flex; flex-direction:column; gap:0; }
.ot-empty { padding:60px 20px; text-align:center; color:#9ca3af; }
.ot-empty i { font-size:2.5rem; margin-bottom:12px; display:block; opacity:0.35; }
.ot-empty p { margin:0 0 14px; font-size:0.95em; }

/* Alerts */
.ot-alert { padding:12px 18px; border-radius:10px; margin-bottom:18px; display:flex; align-items:center; gap:10px; font-size:0.9em; font-weight:500; }
.ot-alert-success { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.ot-alert-danger  { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }

/* Buttons */
.ot-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 20px; border-radius:9px; border:none; font-size:0.9em; font-weight:600; cursor:pointer; transition:all 0.2s; text-decoration:none; }
.ot-btn-primary { background: linear-gradient(135deg,#7c3aed,#4f46e5); color:#fff; box-shadow:0 2px 8px rgba(124,58,237,0.3); }
.ot-btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(124,58,237,0.35); }
.ot-btn-ghost { background:transparent; color:#6b7280; border:1px solid #e5e7eb; }
.ot-btn-ghost:hover { background:#f9fafb; }

/* Modal */
.ot-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:2000; padding:20px; align-items:flex-start; justify-content:center; }
.ot-overlay.open { display:flex; }
.ot-modal { background:#fff; border-radius:16px; width:100%; max-width:580px; margin:auto; box-shadow:0 20px 60px rgba(0,0,0,0.2); overflow:hidden; }
.ot-modal-header { padding:22px 24px 16px; border-bottom:1px solid #f3f4f6; display:flex; justify-content:space-between; align-items:flex-start; }
.ot-modal-header h3 { margin:0 0 4px; font-size:1.15rem; display:flex; align-items:center; gap:8px; }
.ot-modal-header p { margin:0; color:#6b7280; font-size:0.85em; }
.ot-close-btn { background:none; border:none; font-size:1.1rem; color:#9ca3af; cursor:pointer; padding:4px 8px; border-radius:6px; }
.ot-close-btn:hover { background:#f3f4f6; color:#374151; }
.ot-modal-body { padding:20px 24px 0; }
.ot-modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:22px; padding:16px 0 24px; border-top:1px solid #f3f4f6; }

.ot-form-group { margin-bottom:16px; }
.ot-form-group label { display:block; font-size:0.85em; font-weight:600; color:#374151; margin-bottom:5px; }
.ot-form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.ot-input, .ot-select { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.9em; color:#111; background:#fff; box-sizing:border-box; transition:border 0.2s; }
.ot-input:focus, .ot-select:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,0.1); }
.ot-req { color:#e53e3e; }
</style>

<script>
function showModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    setTimeout(() => m.classList.add('open'), 10);
}
function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('open');
    m.style.display = 'none';
}
function overlayClose(e, id) {
    if (e.target === document.getElementById(id)) closeModal(id);
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.ot-overlay.open').forEach(m => closeModal(m.id));
});
</script>

<?php require_once '../../includes/footer.php'; ?>
