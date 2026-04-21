<?php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$can_manage = in_array($role, ['admin', 'head_nurse']);

$page_title = "Duty Roster";
include '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// --- Week calculation ---
$week_start_param = $_GET['week_start'] ?? '';
if ($week_start_param && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start_param)) {
    $week_start = new DateTime($week_start_param);
    // Adjust to Monday
    $dow = (int)$week_start->format('N');
    if ($dow !== 1) {
        $week_start->modify('last monday');
    }
} else {
    $week_start = new DateTime();
    $dow = (int)$week_start->format('N');
    if ($dow !== 1) {
        $week_start->modify('last monday');
    }
}
$week_end = clone $week_start;
$week_end->modify('+6 days');

$week_start_str = $week_start->format('Y-m-d');
$week_end_str   = $week_end->format('Y-m-d');

$prev_week = (clone $week_start)->modify('-7 days')->format('Y-m-d');
$next_week = (clone $week_start)->modify('+7 days')->format('Y-m-d');

// Build day list Mon-Sun
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = clone $week_start;
    $d->modify("+$i days");
    $days[] = $d->format('Y-m-d');
}

// --- Handle Create Shift (single) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_shift']) && $can_manage) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid CSRF token. Please refresh and try again.";
    } else {
        $staff_id   = trim($_POST['staff_id'] ?? '');
        $shift_date = trim($_POST['shift_date'] ?? '');
        $shift_type = trim($_POST['shift_type'] ?? '');
        $dept       = trim($_POST['department'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        $start_time = null;
        $end_time   = null;
        if ($shift_type === 'Morning') {
            $start_time = '06:00';
            $end_time   = '14:00';
        } elseif ($shift_type === 'Evening') {
            $start_time = '14:00';
            $end_time   = '22:00';
        } elseif ($shift_type === 'Night') {
            $start_time = '22:00';
            $end_time   = '06:00';
        } else {
            // Custom
            $start_time = trim($_POST['start_time'] ?? '');
            $end_time   = trim($_POST['end_time'] ?? '');
        }

        if (!$staff_id || !$shift_date || !$shift_type) {
            $error_msg = "Staff, date, and shift type are required.";
        } else {
            // Check for duplicate
            $exists = db_select_one(
                "SELECT id FROM duty_roster WHERE staff_id = $1 AND shift_date = $2 AND shift_type = $3",
                [$staff_id, $shift_date, $shift_type]
            );
            if ($exists) {
                $error_msg = "This shift assignment already exists for the selected staff on that date.";
            } else {
                try {
                    db_insert('duty_roster', [
                        'staff_id'   => $staff_id,
                        'shift_date' => $shift_date,
                        'shift_type' => $shift_type,
                        'start_time' => $start_time,
                        'end_time'   => $end_time,
                        'department' => $dept,
                        'notes'      => $notes,
                        'created_by' => $user_id,
                    ]);
                    $success_msg = "Shift assigned successfully.";
                } catch (Exception $e) {
                    $error_msg = "Failed to assign shift: " . $e->getMessage();
                }
            }
        }
    }
}

// --- Handle Bulk Assign ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_assign']) && $can_manage) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "Invalid CSRF token. Please refresh and try again.";
    } else {
        $bulk_staff  = $_POST['bulk_staff_ids'] ?? [];
        $bulk_from   = trim($_POST['bulk_date_from'] ?? '');
        $bulk_to     = trim($_POST['bulk_date_to'] ?? '');
        $bulk_shift  = trim($_POST['bulk_shift_type'] ?? '');
        $bulk_dept   = trim($_POST['bulk_department'] ?? '');

        if (empty($bulk_staff) || !$bulk_from || !$bulk_to || !$bulk_shift) {
            $error_msg = "Please fill all bulk assign fields.";
        } else {
            $st = null; $et = null;
            if ($bulk_shift === 'Morning')      { $st = '06:00'; $et = '14:00'; }
            elseif ($bulk_shift === 'Evening')  { $st = '14:00'; $et = '22:00'; }
            elseif ($bulk_shift === 'Night')    { $st = '22:00'; $et = '06:00'; }

            $cur = new DateTime($bulk_from);
            $end = new DateTime($bulk_to);
            $inserted = 0;
            $skipped  = 0;
            while ($cur <= $end) {
                $d = $cur->format('Y-m-d');
                foreach ($bulk_staff as $sid) {
                    $sid = trim($sid);
                    $exists = db_select_one(
                        "SELECT id FROM duty_roster WHERE staff_id = $1 AND shift_date = $2 AND shift_type = $3",
                        [$sid, $d, $bulk_shift]
                    );
                    if ($exists) {
                        $skipped++;
                    } else {
                        try {
                            db_insert('duty_roster', [
                                'staff_id'   => $sid,
                                'shift_date' => $d,
                                'shift_type' => $bulk_shift,
                                'start_time' => $st,
                                'end_time'   => $et,
                                'department' => $bulk_dept,
                                'notes'      => '',
                                'created_by' => $user_id,
                            ]);
                            $inserted++;
                        } catch (Exception $e) {
                            $skipped++;
                        }
                    }
                }
                $cur->modify('+1 day');
            }
            $success_msg = "Bulk assign complete: $inserted shifts added, $skipped skipped (duplicates/errors).";
        }
    }
}

// --- Delete shift ---
if (isset($_GET['delete_id']) && $can_manage) {
    $del_id = trim($_GET['delete_id']);
    try {
        db_query("DELETE FROM duty_roster WHERE id = $1", [$del_id]);
        $success_msg = "Shift removed.";
    } catch (Exception $e) {
        $error_msg = "Failed to remove shift: " . $e->getMessage();
    }
}

// --- Data fetching ---
$staff_list = db_select("SELECT id, first_name, last_name, role AS designation FROM staff ORDER BY first_name, last_name");

// For grid: fetch all shifts this week
if ($can_manage) {
    $week_shifts = db_select(
        "SELECT dr.*, s.first_name, s.last_name FROM duty_roster dr
         JOIN staff s ON dr.staff_id = s.id
         WHERE dr.shift_date BETWEEN $1 AND $2
         ORDER BY s.first_name, s.last_name, dr.shift_date",
        [$week_start_str, $week_end_str]
    );
} else {
    // Staff view: own shifts this week
    $staff_row = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user_id]);
    $my_staff_id = $staff_row['id'] ?? null;
    $week_shifts = $my_staff_id ? db_select(
        "SELECT dr.*, s.first_name, s.last_name FROM duty_roster dr
         JOIN staff s ON dr.staff_id = s.id
         WHERE dr.shift_date BETWEEN $1 AND $2 AND dr.staff_id = $3
         ORDER BY dr.shift_date",
        [$week_start_str, $week_end_str, $my_staff_id]
    ) : [];
}

// Stats for the week (based on visible shifts)
$all_week = $can_manage
    ? db_select(
        "SELECT shift_type FROM duty_roster WHERE shift_date BETWEEN $1 AND $2",
        [$week_start_str, $week_end_str]
      )
    : $week_shifts;

$stat_total = count($all_week);
$stat_morning = 0; $stat_evening = 0; $stat_night = 0;
foreach ($all_week as $s) {
    if ($s['shift_type'] === 'Morning') $stat_morning++;
    elseif ($s['shift_type'] === 'Evening') $stat_evening++;
    elseif ($s['shift_type'] === 'Night') $stat_night++;
}

// Build grid: [staff_id][date] = shift info
$grid = [];
$staff_in_grid = [];
foreach ($week_shifts as $sh) {
    $sid = $sh['staff_id'];
    $sd  = $sh['shift_date'];
    if (!isset($grid[$sid])) {
        $grid[$sid] = [];
        $staff_in_grid[$sid] = $sh['first_name'] . ' ' . $sh['last_name'];
    }
    $grid[$sid][$sd] = $sh;
}

// Upcoming shifts for own view
$upcoming_shifts = [];
if (!$can_manage && $my_staff_id) {
    $upcoming_shifts = db_select(
        "SELECT * FROM duty_roster WHERE staff_id = $1 AND shift_date >= $2 ORDER BY shift_date LIMIT 20",
        [$my_staff_id, date('Y-m-d')]
    );
}
?>

<style>
/* ── Roster Page ─────────────────────────────────────────────────── */
.roster-wrap { max-width: 1300px; margin: 0 auto; padding: 24px; }

/* Header */
.roster-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.roster-title-block h2 { margin: 0; font-size: 1.5em; font-weight: 800; color: #111827; }
.roster-title-block p  { margin: 4px 0 0; color: #6b7280; font-size: 0.88em; }
.roster-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* Buttons */
.btn-roster-primary { background: #6366f1; color: #fff; border: none; border-radius: 10px; padding: 10px 20px; font-weight: 600; font-size: 0.9em; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: background 0.2s; }
.btn-roster-primary:hover { background: #4f46e5; }
.btn-roster-outline { background: #fff; color: #374151; border: 1.5px solid #d1d5db; border-radius: 10px; padding: 10px 20px; font-weight: 600; font-size: 0.9em; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s; }
.btn-roster-outline:hover { border-color: #6366f1; color: #6366f1; }

/* Alerts */
.roster-alert { border-radius: 10px; padding: 13px 18px; margin-bottom: 18px; font-size: 0.9em; display: flex; align-items: center; gap: 10px; }
.roster-alert.success { background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; }
.roster-alert.error   { background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; }

/* Week nav */
.week-nav { background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; box-shadow: 0 1px 6px rgba(0,0,0,.05); gap: 12px; flex-wrap: wrap; }
.week-nav a { text-decoration: none; background: #f3f4f6; color: #374151; border-radius: 8px; padding: 7px 16px; font-weight: 600; font-size: 0.88em; transition: background 0.2s; }
.week-nav a:hover { background: #e5e7eb; }
.week-nav-jump { display: flex; align-items: center; gap: 10px; }
.week-nav-jump input { padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.88em; outline: none; }
.week-nav-jump button { background: #6366f1; color: #fff; border: none; border-radius: 8px; padding: 7px 16px; font-weight: 600; font-size: 0.88em; cursor: pointer; }

/* Stat cards */
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
@media(max-width:900px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
.stat-card { background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; padding: 20px 22px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 6px rgba(0,0,0,.05); }
.stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.3em; flex-shrink: 0; }
.stat-icon.indigo { background: #ede9fe; color: #6366f1; }
.stat-icon.amber  { background: #fffbeb; color: #d97706; }
.stat-icon.sky    { background: #e0f2fe; color: #0284c7; }
.stat-icon.slate  { background: #1e293b; color: #e2e8f0; }
.stat-val  { font-size: 1.8em; font-weight: 800; color: #111827; line-height: 1; }
.stat-lbl  { font-size: 0.8em; color: #6b7280; margin-top: 3px; }

/* Roster card */
.roster-card { background: #fff; border-radius: 14px; border: 1px solid #e5e7eb; box-shadow: 0 1px 6px rgba(0,0,0,.05); margin-bottom: 24px; overflow: hidden; }
.roster-card-header { padding: 16px 22px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #f8fafc; }
.roster-card-header h5 { margin: 0; font-size: 0.95em; font-weight: 700; color: #1e293b; }

/* Grid table */
.roster-table { width: 100%; border-collapse: collapse; font-size: 0.88em; }
.roster-table th { background: #f8fafc; padding: 10px 8px; text-align: center; font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb; font-size: 0.82em; text-transform: uppercase; letter-spacing: 0.03em; }
.roster-table th.staff-col { text-align: left; padding-left: 18px; min-width: 170px; }
.roster-table td { padding: 10px 8px; text-align: center; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.roster-table td.staff-cell { text-align: left; padding-left: 18px; font-weight: 600; color: #1e293b; }
.roster-table tr:hover td { background: #fafafa; }
.roster-table .today-col { background: #f0f4ff; }

/* Shift pills */
.shift-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 20px; font-size: 0.78em; font-weight: 700; cursor: pointer; transition: opacity 0.15s; white-space: nowrap; }
.shift-pill:hover { opacity: 0.82; }
.shift-pill.morning { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
.shift-pill.evening { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
.shift-pill.night   { background: #1e293b; color: #e2e8f0; border: 1px solid #334155; }
.shift-pill.custom  { background: #f3e8ff; color: #7c3aed; border: 1px solid #c4b5fd; }
.del-link { color: #fca5a5; font-size: 0.75em; margin-left: 4px; text-decoration: none; vertical-align: middle; }
.del-link:hover { color: #dc2626; }
.no-shift { color: #d1d5db; font-size: 0.8em; }

/* Legend */
.roster-legend { padding: 14px 22px; border-top: 1px solid #f1f5f9; display: flex; gap: 20px; flex-wrap: wrap; font-size: 0.82em; color: #6b7280; }

/* Upcoming shifts table */
.upcoming-table { width: 100%; border-collapse: collapse; font-size: 0.88em; }
.upcoming-table th { background: #f8fafc; padding: 10px 16px; text-align: left; font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb; font-size: 0.82em; text-transform: uppercase; }
.upcoming-table td { padding: 11px 16px; border-bottom: 1px solid #f1f5f9; color: #374151; }
.upcoming-table tr:last-child td { border-bottom: none; }

/* Modals */
.rm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
.rm-overlay.open { display: flex; }
.rm-modal { background: #fff; border-radius: 18px; padding: 0; max-width: 540px; width: 95%; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 60px rgba(0,0,0,0.2); animation: rmSlide 0.22s ease; }
.rm-modal.wide { max-width: 720px; }
@keyframes rmSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.rm-modal-header { padding: 22px 26px 16px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
.rm-modal-header h4 { margin: 0; font-size: 1.05em; font-weight: 700; color: #111827; }
.rm-close { background: #f3f4f6; border: none; border-radius: 8px; width: 32px; height: 32px; cursor: pointer; font-size: 1em; color: #6b7280; display: flex; align-items: center; justify-content: center; }
.rm-close:hover { background: #fee2e2; color: #dc2626; }
.rm-modal-body { padding: 22px 26px; }
.rm-modal-footer { padding: 16px 26px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 10px; }
.rm-field { margin-bottom: 16px; }
.rm-field label { display: block; font-size: 0.82em; font-weight: 600; color: #374151; margin-bottom: 5px; }
.rm-field input, .rm-field select, .rm-field textarea {
    width: 100%; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 0.9em; outline: none; box-sizing: border-box; transition: border 0.2s;
}
.rm-field input:focus, .rm-field select:focus, .rm-field textarea:focus { border-color: #6366f1; }
.rm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.rm-staff-list { border: 1px solid #e5e7eb; border-radius: 8px; max-height: 220px; overflow-y: auto; padding: 10px 12px; }
.rm-staff-check { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px solid #f9fafb; font-size: 0.88em; color: #374151; cursor: pointer; }
.rm-staff-check:last-child { border-bottom: none; }
.rm-staff-check input { width: 15px; height: 15px; cursor: pointer; accent-color: #6366f1; }
.rm-select-links { margin-top: 6px; font-size: 0.8em; }
.rm-select-links a { color: #6366f1; text-decoration: none; margin-right: 12px; }
.rm-select-links a:hover { text-decoration: underline; }

/* Shift detail popup */
.sd-popup { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1100; align-items: center; justify-content: center; }
.sd-popup.open { display: flex; }
.sd-box { background: #fff; border-radius: 16px; padding: 26px; width: 320px; box-shadow: 0 20px 50px rgba(0,0,0,.2); animation: rmSlide 0.2s ease; }
.sd-box h4 { margin: 0 0 18px; font-size: 1em; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.sd-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.88em; }
.sd-row:last-of-type { border-bottom: none; }
.sd-row .lbl { color: #6b7280; }
.sd-row .val { font-weight: 600; color: #1e293b; text-align: right; }
.sd-close-btn { margin-top: 18px; width: 100%; background: #f3f4f6; border: none; border-radius: 8px; padding: 9px; font-weight: 600; cursor: pointer; color: #374151; }
.sd-close-btn:hover { background: #e5e7eb; }

/* Empty state */
.empty-state { text-align: center; padding: 50px 20px; color: #9ca3af; }
.empty-state i { font-size: 2.5em; margin-bottom: 14px; opacity: 0.4; }
.empty-state p { margin: 0; font-size: 0.92em; }
.empty-state a { color: #6366f1; text-decoration: none; }

/* Print */
@media print {
    .no-print, .sidebar, nav, .roster-actions, .week-nav-jump, .del-link { display: none !important; }
    .roster-table th, .roster-table td { font-size: 10px; padding: 5px 4px; }
}
</style>

<div class="roster-wrap">

    <!-- Header -->
    <div class="roster-header no-print">
        <div class="roster-title-block">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:4px;">
                <div style="width:46px;height:46px;background:linear-gradient(135deg,#6366f1,#0ea5e9);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.3em;flex-shrink:0;">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div>
                    <h2>Duty Roster</h2>
                    <p><i class="fas fa-calendar-alt" style="margin-right:5px;color:#6366f1;"></i>
                       <?php echo $week_start->format('d M Y'); ?> &mdash; <?php echo $week_end->format('d M Y'); ?></p>
                </div>
            </div>
        </div>
        <div class="roster-actions">
            <?php if ($can_manage): ?>
            <button class="btn-roster-primary" onclick="openModal('createShiftModal')">
                <i class="fas fa-plus"></i> Assign Shift
            </button>
            <button class="btn-roster-outline" onclick="openModal('bulkAssignModal')">
                <i class="fas fa-layer-group"></i> Bulk Assign
            </button>
            <?php endif; ?>
            <button class="btn-roster-outline" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="roster-alert success no-print"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="roster-alert error no-print"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Week Navigation -->
    <div class="week-nav no-print">
        <a href="?week_start=<?php echo $prev_week; ?>"><i class="fas fa-chevron-left"></i> Previous Week</a>
        <form method="GET" class="week-nav-jump">
            <label style="font-size:0.85em;color:#6b7280;white-space:nowrap;">Jump to:</label>
            <input type="date" name="week_start" value="<?php echo htmlspecialchars($week_start_str); ?>">
            <button type="submit">Go</button>
        </form>
        <a href="?week_start=<?php echo $next_week; ?>">Next Week <i class="fas fa-chevron-right"></i></a>
    </div>

    <!-- Stats -->
    <div class="stat-grid no-print">
        <div class="stat-card">
            <div class="stat-icon indigo"><i class="fas fa-calendar-check"></i></div>
            <div><div class="stat-val"><?php echo $stat_total; ?></div><div class="stat-lbl">Total Shifts</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fas fa-sun"></i></div>
            <div><div class="stat-val"><?php echo $stat_morning; ?></div><div class="stat-lbl">Morning Shifts</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon sky"><i class="fas fa-cloud-sun"></i></div>
            <div><div class="stat-val"><?php echo $stat_evening; ?></div><div class="stat-lbl">Evening Shifts</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon slate"><i class="fas fa-moon"></i></div>
            <div><div class="stat-val"><?php echo $stat_night; ?></div><div class="stat-lbl">Night Shifts</div></div>
        </div>
    </div>

    <!-- Roster Grid -->
    <?php $today = date('Y-m-d'); ?>
    <div class="roster-card printable-section">
        <div class="roster-card-header">
            <h5><i class="fas fa-table" style="color:#6366f1;margin-right:8px;"></i>Weekly Roster Grid</h5>
            <span style="font-size:0.82em;color:#6b7280;"><?php echo $week_start->format('d M'); ?> – <?php echo $week_end->format('d M Y'); ?></span>
        </div>
        <div style="overflow-x:auto;">
            <table class="roster-table">
                <thead>
                    <tr>
                        <th class="staff-col">Staff Member</th>
                        <?php foreach ($days as $d):
                            $isToday = ($d === $today);
                        ?>
                        <th class="<?php echo $isToday ? 'today-col' : ''; ?>">
                            <div><?php echo date('D', strtotime($d)); ?></div>
                            <div style="font-weight:400;color:#9ca3af;font-size:0.9em;"><?php echo date('d M', strtotime($d)); ?></div>
                            <?php if ($isToday): ?><div style="width:6px;height:6px;background:#6366f1;border-radius:50%;margin:3px auto 0;"></div><?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grid)): ?>
                    <tr><td colspan="<?php echo count($days)+1; ?>">
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <p>No shifts assigned for this week.
                            <?php if ($can_manage): ?><br><a href="#" onclick="openModal('createShiftModal')">Assign one now &rarr;</a><?php endif; ?></p>
                        </div>
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($grid as $sid => $shifts_by_day): ?>
                    <tr>
                        <td class="staff-cell">
                            <i class="fas fa-user-circle" style="color:#a5b4fc;margin-right:7px;"></i>
                            <?php echo htmlspecialchars($staff_in_grid[$sid]); ?>
                        </td>
                        <?php foreach ($days as $d):
                            $isToday = ($d === $today);
                        ?>
                        <td class="<?php echo $isToday ? 'today-col' : ''; ?>">
                            <?php if (isset($shifts_by_day[$d])): $sh = $shifts_by_day[$d];
                                $type = $sh['shift_type'];
                                $pillClass = match($type) {
                                    'Morning' => 'morning', 'Evening' => 'evening',
                                    'Night'   => 'night',   default   => 'custom'
                                };
                                $icon = match($type) {
                                    'Morning' => 'fa-sun', 'Evening' => 'fa-cloud-sun',
                                    'Night'   => 'fa-moon', default  => 'fa-clock'
                                };
                                $st = substr($sh['start_time'],0,5);
                                $et = substr($sh['end_time'],0,5);
                            ?>
                            <span class="shift-pill <?php echo $pillClass; ?>"
                                  onclick="showShiftDetail('<?php echo htmlspecialchars($sh['id']); ?>','<?php echo htmlspecialchars($type); ?>','<?php echo $st; ?>','<?php echo $et; ?>','<?php echo htmlspecialchars(addslashes($sh['department']??'')); ?>','<?php echo htmlspecialchars(addslashes($sh['notes']??'')); ?>')"
                                  title="<?php echo htmlspecialchars($type.' '.$st.'–'.$et); ?>">
                                <i class="fas <?php echo $icon; ?>"></i> <?php echo $type; ?>
                            </span>
                            <?php if ($can_manage): ?>
                            <a href="?week_start=<?php echo $week_start_str; ?>&delete_id=<?php echo htmlspecialchars($sh['id']); ?>"
                               class="del-link no-print" title="Remove shift"
                               onclick="return confirm('Remove this shift?');"><i class="fas fa-times-circle"></i></a>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="no-shift">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="roster-legend no-print">
            <span><span class="shift-pill morning" style="cursor:default;"><i class="fas fa-sun"></i> Morning</span> 6:00–14:00</span>
            <span><span class="shift-pill evening" style="cursor:default;"><i class="fas fa-cloud-sun"></i> Evening</span> 14:00–22:00</span>
            <span><span class="shift-pill night" style="cursor:default;"><i class="fas fa-moon"></i> Night</span> 22:00–6:00</span>
            <span><span class="shift-pill custom" style="cursor:default;"><i class="fas fa-clock"></i> Custom</span> User-defined</span>
        </div>
    </div>

    <!-- Own Upcoming Shifts -->
    <?php if (!$can_manage): ?>
    <div class="roster-card">
        <div class="roster-card-header">
            <h5><i class="fas fa-clock" style="color:#6366f1;margin-right:8px;"></i>My Upcoming Shifts</h5>
        </div>
        <?php if (empty($upcoming_shifts)): ?>
        <div class="empty-state"><i class="fas fa-calendar-check"></i><p>No upcoming shifts found for your account.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="upcoming-table">
                <thead>
                    <tr><th>Date</th><th>Day</th><th>Shift</th><th>Time</th><th>Department</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming_shifts as $us):
                        $t = $us['shift_type'];
                        $pc = match($t) { 'Morning'=>'morning','Evening'=>'evening','Night'=>'night',default=>'custom' };
                        $ic = match($t) { 'Morning'=>'fa-sun','Evening'=>'fa-cloud-sun','Night'=>'fa-moon',default=>'fa-clock' };
                    ?>
                    <tr>
                        <td><strong><?php echo date('d M Y', strtotime($us['shift_date'])); ?></strong></td>
                        <td><?php echo date('l', strtotime($us['shift_date'])); ?></td>
                        <td><span class="shift-pill <?php echo $pc; ?>" style="cursor:default;"><i class="fas <?php echo $ic; ?>"></i> <?php echo htmlspecialchars($t); ?></span></td>
                        <td style="color:#6b7280;"><?php echo substr($us['start_time'],0,5); ?> – <?php echo substr($us['end_time'],0,5); ?></td>
                        <td><?php echo htmlspecialchars($us['department'] ?: '—'); ?></td>
                        <td style="color:#6b7280;"><?php echo htmlspecialchars($us['notes'] ?: '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Shift Detail Popup -->
<div class="sd-popup" id="shiftDetailPopup" onclick="if(event.target===this)closeSdPopup()">
    <div class="sd-box">
        <h4><i class="fas fa-info-circle" style="color:#6366f1;"></i> Shift Details</h4>
        <div class="sd-row"><span class="lbl">Type</span><span class="val" id="sd-type"></span></div>
        <div class="sd-row"><span class="lbl">Time</span><span class="val" id="sd-time"></span></div>
        <div class="sd-row"><span class="lbl">Department</span><span class="val" id="sd-dept"></span></div>
        <div class="sd-row"><span class="lbl">Notes</span><span class="val" id="sd-notes"></span></div>
        <button class="sd-close-btn" onclick="closeSdPopup()">Close</button>
    </div>
</div>

<?php if ($can_manage): ?>
<!-- Assign Shift Modal -->
<div class="rm-overlay" id="createShiftModal" onclick="if(event.target===this)closeModal('createShiftModal')">
    <div class="rm-modal">
        <div class="rm-modal-header">
            <h4><i class="fas fa-plus-circle" style="color:#6366f1;margin-right:8px;"></i>Assign Shift</h4>
            <button class="rm-close" onclick="closeModal('createShiftModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="create_shift" value="1">
            <div class="rm-modal-body">
                <div class="rm-field">
                    <label>Staff Member <span style="color:#dc2626;">*</span></label>
                    <select name="staff_id" required>
                        <option value="">— Select Staff —</option>
                        <?php foreach ($staff_list as $st): ?>
                        <option value="<?php echo htmlspecialchars($st['id']); ?>">
                            <?php echo htmlspecialchars($st['first_name'].' '.$st['last_name']); ?>
                            <?php if ($st['designation']): ?>(<?php echo htmlspecialchars(ucfirst($st['designation'])); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rm-row">
                    <div class="rm-field">
                        <label>Shift Date <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="shift_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="rm-field">
                        <label>Shift Type <span style="color:#dc2626;">*</span></label>
                        <select name="shift_type" required id="shiftTypeSelect" onchange="toggleCustomTimes(this.value)">
                            <option value="Morning">🌅 Morning (6am–2pm)</option>
                            <option value="Evening">🌤 Evening (2pm–10pm)</option>
                            <option value="Night">🌙 Night (10pm–6am)</option>
                            <option value="Custom">⏱ Custom</option>
                        </select>
                    </div>
                </div>
                <div id="customTimesGroup" style="display:none;">
                    <div class="rm-row">
                        <div class="rm-field"><label>Start Time</label><input type="time" name="start_time"></div>
                        <div class="rm-field"><label>End Time</label><input type="time" name="end_time"></div>
                    </div>
                </div>
                <div class="rm-field">
                    <label>Department / Ward</label>
                    <input type="text" name="department" placeholder="e.g. ICU, Ward B">
                </div>
                <div class="rm-field">
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Any special instructions..."></textarea>
                </div>
            </div>
            <div class="rm-modal-footer">
                <button type="button" class="btn-roster-outline" onclick="closeModal('createShiftModal')">Cancel</button>
                <button type="submit" class="btn-roster-primary"><i class="fas fa-save"></i> Assign Shift</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div class="rm-overlay" id="bulkAssignModal" onclick="if(event.target===this)closeModal('bulkAssignModal')">
    <div class="rm-modal wide">
        <div class="rm-modal-header">
            <h4><i class="fas fa-layer-group" style="color:#6366f1;margin-right:8px;"></i>Bulk Shift Assignment</h4>
            <button class="rm-close" onclick="closeModal('bulkAssignModal')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="bulk_assign" value="1">
            <div class="rm-modal-body">
                <div class="rm-field">
                    <label>Select Staff Members <span style="color:#dc2626;">*</span></label>
                    <div class="rm-staff-list">
                        <?php foreach ($staff_list as $st): ?>
                        <label class="rm-staff-check">
                            <input type="checkbox" name="bulk_staff_ids[]" value="<?php echo htmlspecialchars($st['id']); ?>">
                            <?php echo htmlspecialchars($st['first_name'].' '.$st['last_name']); ?>
                            <?php if ($st['designation']): ?><span style="color:#9ca3af;font-size:0.85em;">(<?php echo htmlspecialchars(ucfirst($st['designation'])); ?>)</span><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="rm-select-links">
                        <a href="#" onclick="selectAllBulkStaff(true);return false;">Select All</a>
                        <a href="#" onclick="selectAllBulkStaff(false);return false;">Deselect All</a>
                    </div>
                </div>
                <div class="rm-row">
                    <div class="rm-field"><label>Date From <span style="color:#dc2626;">*</span></label><input type="date" name="bulk_date_from" required value="<?php echo $week_start_str; ?>"></div>
                    <div class="rm-field"><label>Date To <span style="color:#dc2626;">*</span></label><input type="date" name="bulk_date_to" required value="<?php echo $week_end_str; ?>"></div>
                </div>
                <div class="rm-row">
                    <div class="rm-field">
                        <label>Shift Type <span style="color:#dc2626;">*</span></label>
                        <select name="bulk_shift_type" required>
                            <option value="Morning">🌅 Morning (6am–2pm)</option>
                            <option value="Evening">🌤 Evening (2pm–10pm)</option>
                            <option value="Night">🌙 Night (10pm–6am)</option>
                        </select>
                    </div>
                    <div class="rm-field">
                        <label>Department / Ward</label>
                        <input type="text" name="bulk_department" placeholder="e.g. Ward A">
                    </div>
                </div>
            </div>
            <div class="rm-modal-footer">
                <button type="button" class="btn-roster-outline" onclick="closeModal('bulkAssignModal')">Cancel</button>
                <button type="submit" class="btn-roster-primary"><i class="fas fa-layer-group"></i> Bulk Assign</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }

function showShiftDetail(id, type, start, end, dept, notes) {
    document.getElementById('sd-type').textContent  = type;
    document.getElementById('sd-time').textContent  = start + ' – ' + end;
    document.getElementById('sd-dept').textContent  = dept  || '—';
    document.getElementById('sd-notes').textContent = notes || '—';
    document.getElementById('shiftDetailPopup').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeSdPopup() {
    document.getElementById('shiftDetailPopup').classList.remove('open');
    document.body.style.overflow = '';
}

function toggleCustomTimes(val) {
    document.getElementById('customTimesGroup').style.display = val === 'Custom' ? 'block' : 'none';
}
function selectAllBulkStaff(state) {
    document.querySelectorAll('input[name="bulk_staff_ids[]"]').forEach(cb => cb.checked = state);
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.rm-overlay.open').forEach(m => m.classList.remove('open'));
        document.getElementById('shiftDetailPopup').classList.remove('open');
        document.body.style.overflow = '';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
