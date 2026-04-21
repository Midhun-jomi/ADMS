<?php
// modules/handover/shift_handover.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['nurse', 'head_nurse', 'admin']);

$page_title = "Shift Handover Notes";
include '../../includes/header.php';

$role    = get_user_role();
$user_id = get_user_id();
$error   = '';
$success = '';

// ── Handle: Submit Handover Note ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        $shift_date      = trim($_POST['shift_date'] ?? '');
        $shift_type      = trim($_POST['shift_type'] ?? '');
        $ward            = trim($_POST['ward'] ?? '');
        $general_notes   = trim($_POST['general_notes'] ?? '');
        $pending_tasks   = trim($_POST['pending_tasks'] ?? '');

        // Build critical patients JSON from dynamic rows
        $cp_names    = $_POST['cp_name']    ?? [];
        $cp_beds     = $_POST['cp_bed']     ?? [];
        $cp_concerns = $_POST['cp_concern'] ?? [];
        $critical_patients = [];
        foreach ($cp_names as $i => $name) {
            $name = trim($name);
            if ($name) {
                $critical_patients[] = [
                    'name'    => $name,
                    'bed'     => trim($cp_beds[$i] ?? ''),
                    'concern' => trim($cp_concerns[$i] ?? ''),
                ];
            }
        }

        $allowed_shifts = ['Morning', 'Evening', 'Night'];
        if (empty($shift_date) || !in_array($shift_type, $allowed_shifts) || empty($general_notes)) {
            $error = "Date, shift type and general notes are required.";
        } else {
            try {
                db_insert('shift_handover_notes', [
                    'shift_date'       => $shift_date,
                    'shift_type'       => $shift_type,
                    'ward'             => $ward ?: null,
                    'general_notes'    => $general_notes,
                    'critical_patients'=> json_encode($critical_patients),
                    'pending_tasks'    => $pending_tasks ?: null,
                    'written_by'       => $user_id,
                ]);
                $success = "Handover note submitted successfully.";
            } catch (Exception $e) {
                $error = "Failed to save: " . $e->getMessage();
            }
        }
    }
}

// ── Handle: Acknowledge ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'acknowledge') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $note_id = trim($_POST['note_id'] ?? '');
        if ($note_id) {
            try {
                db_update('shift_handover_notes',
                    ['acknowledged_by' => $user_id, 'acknowledged_at' => date('Y-m-d H:i:s')],
                    ['id' => $note_id]
                );
                $success = "Handover acknowledged.";
            } catch (Exception $e) {
                $error = "Failed to acknowledge: " . $e->getMessage();
            }
        }
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$filter_date  = $_GET['date'] ?? date('Y-m-d');
$filter_shift = $_GET['shift'] ?? '';
$allowed_shifts = ['Morning', 'Evening', 'Night'];

$sql    = "SELECT n.*, u.email AS writer_email, a.email AS ack_email
           FROM shift_handover_notes n
           LEFT JOIN users u ON n.written_by = u.id
           LEFT JOIN users a ON n.acknowledged_by = a.id
           WHERE n.shift_date = $1";
$params = [$filter_date];

if (in_array($filter_shift, $allowed_shifts)) {
    $params[] = $filter_shift;
    $sql .= " AND n.shift_type = $" . count($params);
}
$sql .= " ORDER BY n.created_at DESC";
$notes = db_select($sql, $params);

// Latest unacknowledged note for banner
$unacked = db_select_one(
    "SELECT n.*, u.email AS writer_email FROM shift_handover_notes n
     LEFT JOIN users u ON n.written_by = u.id
     WHERE n.acknowledged_by IS NULL
     ORDER BY n.created_at DESC LIMIT 1"
);
?>

<style>
.hw-wrap { max-width: 1100px; margin: 0 auto; padding: 20px; }
.hw-grid { display: grid; grid-template-columns: 1fr 1.4fr; gap: 24px; align-items: start; }
.hw-card { background: white; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 28px; }
.hw-title { font-size: 1em; font-weight: 700; color: #374151; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 0.82em; font-weight: 600; color: #374151; margin-bottom: 5px; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 0.9em; outline: none; transition: border 0.2s; box-sizing: border-box;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #6366f1; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.btn-submit { background: #6366f1; color: white; border: none; border-radius: 10px; padding: 11px 26px; font-weight: 700; cursor: pointer; font-size: 0.95em; width: 100%; margin-top: 6px; }
.btn-submit:hover { background: #4f46e5; }
.note-card { background: #f9fafb; border-radius: 12px; border: 1px solid #e5e7eb; padding: 18px 20px; margin-bottom: 14px; }
.shift-badge { display: inline-block; padding: 3px 12px; border-radius: 99px; font-size: 0.78em; font-weight: 700; }
.shift-Morning { background: #fef9c3; color: #854d0e; }
.shift-Evening { background: #fce7f3; color: #9d174d; }
.shift-Night   { background: #ede9fe; color: #4c1d95; }
.cp-row { display: grid; grid-template-columns: 1fr 80px 1fr auto; gap: 8px; margin-bottom: 8px; align-items: center; }
.cp-row input { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85em; }
.btn-rm { background: #fee2e2; color: #dc2626; border: none; border-radius: 6px; padding: 6px 10px; cursor: pointer; font-weight: 700; }
.btn-add-cp { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; border-radius: 8px; padding: 7px 16px; font-size: 0.85em; font-weight: 600; cursor: pointer; margin-bottom: 14px; }
.unacked-banner { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; }
.ack-btn { background: #10b981; color: white; border: none; border-radius: 8px; padding: 7px 18px; font-weight: 700; cursor: pointer; font-size: 0.85em; }
.filter-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 18px; flex-wrap: wrap; }
.filter-bar input, .filter-bar select { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.88em; }
.acked { color: #10b981; font-size: 0.78em; font-weight: 600; }
.pending-ack { color: #f59e0b; font-size: 0.78em; font-weight: 600; }
@media (max-width: 800px) { .hw-grid { grid-template-columns: 1fr; } }
</style>

<div class="hw-wrap">
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:24px;">
        <div style="width:46px;height:46px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.3em;">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div>
            <h2 style="margin:0;font-size:1.4em;font-weight:800;color:#111827;">Shift Handover Notes</h2>
            <p style="margin:0;color:#6b7280;font-size:0.88em;">Structured nursing shift-to-shift communication</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#dc2626;font-size:0.9em;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#065f46;font-size:0.9em;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($unacked): ?>
    <div class="unacked-banner">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
            <div>
                <div style="font-weight:700;color:#92400e;margin-bottom:4px;">
                    <i class="fas fa-bell" style="margin-right:6px;"></i>
                    Unacknowledged Handover — <?php echo htmlspecialchars($unacked['shift_type']); ?> shift, <?php echo date('d M Y', strtotime($unacked['shift_date'])); ?>
                </div>
                <div style="font-size:0.85em;color:#78350f;">Written by: <?php echo htmlspecialchars($unacked['writer_email']); ?></div>
                <div style="font-size:0.88em;color:#374151;margin-top:8px;"><?php echo nl2br(htmlspecialchars(substr($unacked['general_notes'], 0, 200))); ?><?php echo strlen($unacked['general_notes']) > 200 ? '...' : ''; ?></div>
            </div>
            <form method="POST" style="flex-shrink:0;">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="acknowledge">
                <input type="hidden" name="note_id" value="<?php echo $unacked['id']; ?>">
                <button type="submit" class="ack-btn"><i class="fas fa-check" style="margin-right:5px;"></i>Acknowledge</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="hw-grid">
        <!-- Left: Write Note -->
        <div class="hw-card">
            <div class="hw-title"><i class="fas fa-pen" style="color:#6366f1;"></i> Write Handover Note</div>
            <form method="POST" action="">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create">
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Shift Date *</label>
                        <input type="date" name="shift_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Shift Type *</label>
                        <select name="shift_type" required>
                            <option value="">-- Select --</option>
                            <option value="Morning">Morning</option>
                            <option value="Evening">Evening</option>
                            <option value="Night">Night</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Ward / Unit</label>
                    <input type="text" name="ward" placeholder="e.g. ICU, General Ward A">
                </div>
                <div class="form-group">
                    <label>General Notes *</label>
                    <textarea name="general_notes" rows="4" placeholder="Overall shift summary, incidents, observations..." required></textarea>
                </div>

                <div class="hw-title" style="margin-top:6px;"><i class="fas fa-user-injured" style="color:#ef4444;"></i> Critical Patients</div>
                <div id="cp-list">
                    <div class="cp-row">
                        <input type="text" name="cp_name[]" placeholder="Patient name">
                        <input type="text" name="cp_bed[]" placeholder="Bed">
                        <input type="text" name="cp_concern[]" placeholder="Key concern">
                        <button type="button" class="btn-rm" onclick="this.closest('.cp-row').remove()">✕</button>
                    </div>
                </div>
                <button type="button" class="btn-add-cp" onclick="addCPRow()"><i class="fas fa-plus"></i> Add Patient</button>

                <div class="form-group">
                    <label>Pending Tasks for Next Shift</label>
                    <textarea name="pending_tasks" rows="3" placeholder="Tasks that need follow-up..."></textarea>
                </div>
                <button type="submit" class="btn-submit"><i class="fas fa-paper-plane" style="margin-right:6px;"></i>Submit Handover</button>
            </form>
        </div>

        <!-- Right: View Notes -->
        <div class="hw-card">
            <div class="hw-title"><i class="fas fa-history" style="color:#6366f1;"></i> Previous Handovers</div>
            <div class="filter-bar">
                <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;">
                    <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    <select name="shift">
                        <option value="">All Shifts</option>
                        <?php foreach (['Morning','Evening','Night'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $filter_shift === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" style="background:#6366f1;color:white;border:none;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;">Filter</button>
                </form>
            </div>

            <?php if (empty($notes)): ?>
                <div style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fas fa-clipboard" style="font-size:2.5rem;margin-bottom:12px;display:block;opacity:0.3;"></i>
                    No handover notes for this date.
                </div>
            <?php else: ?>
                <?php foreach ($notes as $n):
                    $cp = json_decode($n['critical_patients'] ?? '[]', true) ?: [];
                ?>
                <div class="note-card">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                        <div>
                            <span class="shift-badge shift-<?php echo $n['shift_type']; ?>"><?php echo $n['shift_type']; ?></span>
                            <span style="font-size:0.82em;color:#6b7280;margin-left:8px;"><?php echo date('d M Y', strtotime($n['shift_date'])); ?></span>
                            <?php if ($n['ward']): ?>
                                <span style="font-size:0.8em;background:#e0f2fe;color:#0369a1;border-radius:99px;padding:2px 10px;margin-left:6px;"><?php echo htmlspecialchars($n['ward']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($n['acknowledged_at']): ?>
                            <span class="acked"><i class="fas fa-check-circle"></i> Acknowledged</span>
                        <?php else: ?>
                            <span class="pending-ack"><i class="fas fa-clock"></i> Pending</span>
                        <?php endif; ?>
                    </div>

                    <div style="font-size:0.88em;color:#374151;margin-bottom:10px;"><?php echo nl2br(htmlspecialchars($n['general_notes'])); ?></div>

                    <?php if (!empty($cp)): ?>
                    <div style="margin-bottom:10px;">
                        <div style="font-size:0.78em;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;"><i class="fas fa-user-injured"></i> Critical Patients</div>
                        <?php foreach ($cp as $c): ?>
                            <div style="display:flex;gap:10px;background:#fff1f2;border-radius:6px;padding:6px 10px;margin-bottom:4px;font-size:0.83em;">
                                <span style="font-weight:600;color:#111827;"><?php echo htmlspecialchars($c['name']); ?></span>
                                <?php if ($c['bed']): ?><span style="color:#6b7280;">Bed: <?php echo htmlspecialchars($c['bed']); ?></span><?php endif; ?>
                                <?php if ($c['concern']): ?><span style="color:#dc2626;">— <?php echo htmlspecialchars($c['concern']); ?></span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($n['pending_tasks']): ?>
                    <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:0 6px 6px 0;font-size:0.83em;color:#374151;margin-bottom:8px;">
                        <strong>Pending:</strong> <?php echo nl2br(htmlspecialchars($n['pending_tasks'])); ?>
                    </div>
                    <?php endif; ?>

                    <div style="font-size:0.75em;color:#9ca3af;margin-top:6px;">
                        Written by <?php echo htmlspecialchars($n['writer_email'] ?? 'Unknown'); ?>
                        · <?php echo date('H:i', strtotime($n['created_at'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function addCPRow() {
    const div = document.createElement('div');
    div.className = 'cp-row';
    div.innerHTML = `
        <input type="text" name="cp_name[]" placeholder="Patient name">
        <input type="text" name="cp_bed[]" placeholder="Bed">
        <input type="text" name="cp_concern[]" placeholder="Key concern">
        <button type="button" class="btn-rm" onclick="this.closest('.cp-row').remove()">✕</button>`;
    document.getElementById('cp-list').appendChild(div);
}
</script>

<?php include '../../includes/footer.php'; ?>
