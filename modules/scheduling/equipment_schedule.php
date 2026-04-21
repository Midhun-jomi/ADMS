<?php
// modules/scheduling/equipment_schedule.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'nurse', 'head_nurse']);

$page_title = "Equipment & Room Scheduling";
include '../../includes/header.php';

$role    = get_user_role();
$user_id = get_user_id();

$error   = '';
$success = '';

// ─── POST HANDLERS ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token. Please refresh and try again.";
    } else {

        $action = $_POST['action'] ?? '';

        // ── Add Resource (admin only) ────────────────────────────────────────
        if ($action === 'add_resource') {
            if ($role !== 'admin') {
                $error = "Only administrators can add resources.";
            } else {
                $name     = trim($_POST['name'] ?? '');
                $type     = $_POST['type'] ?? '';
                $location = trim($_POST['location'] ?? '');
                $capacity = (int)($_POST['capacity'] ?? 0);
                $notes    = trim($_POST['notes'] ?? '');
                $status   = $_POST['status'] ?? 'available';

                if ($name === '' || $type === '') {
                    $error = "Resource name and type are required.";
                } else {
                    try {
                        db_query(
                            "INSERT INTO schedulable_resources (name, type, location, capacity, notes, status)
                             VALUES ($1,$2,$3,$4,$5,$6)",
                            [$name, $type, $location, $capacity ?: null, $notes, $status]
                        );
                        $success = "Resource \"" . htmlspecialchars($name) . "\" added successfully.";
                    } catch (Exception $e) {
                        $error = "Failed to add resource: " . $e->getMessage();
                    }
                }
            }
        }

        // ── Book Resource ────────────────────────────────────────────────────
        elseif ($action === 'book_resource') {
            $resource_id = trim($_POST['resource_id'] ?? '');
            $booked_date = trim($_POST['booked_date'] ?? '');
            $start_time  = trim($_POST['start_time'] ?? '');
            $end_time    = trim($_POST['end_time'] ?? '');
            $booked_for  = trim($_POST['booked_for'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($resource_id === '' || $booked_date === '' || $start_time === '' || $end_time === '' || $booked_for === '') {
                $error = "All required fields must be filled.";
            } elseif ($end_time <= $start_time) {
                $error = "End time must be after start time.";
            } else {
                try {
                    // Conflict detection: check for overlap on same resource and date
                    $conflict = db_select_one(
                        "SELECT id FROM resource_bookings
                         WHERE resource_id = $1
                           AND booked_date = $2
                           AND status != 'cancelled'
                           AND start_time < $4
                           AND end_time   > $3",
                        [$resource_id, $booked_date, $start_time, $end_time]
                    );

                    if ($conflict) {
                        $error = "This resource is already booked during the selected time slot. Please choose a different time.";
                    } else {
                        // Check resource is not in maintenance
                        $res = db_select_one("SELECT status, name FROM schedulable_resources WHERE id=$1", [$resource_id]);
                        if (!$res) {
                            $error = "Resource not found.";
                        } elseif ($res['status'] === 'maintenance') {
                            $error = "Cannot book \"" . htmlspecialchars($res['name']) . "\" — it is currently under maintenance.";
                        } else {
                            db_query(
                                "INSERT INTO resource_bookings
                                 (resource_id, booked_date, start_time, end_time, booked_for, booked_by, notes, status)
                                 VALUES ($1,$2,$3,$4,$5,$6,$7,'upcoming')",
                                [$resource_id, $booked_date, $start_time, $end_time, $booked_for, $user_id, $notes]
                            );
                            $success = "Resource booked successfully for " . htmlspecialchars($booked_date) . ".";
                        }
                    }
                } catch (Exception $e) {
                    $error = "Failed to book resource: " . $e->getMessage();
                }
            }
        }

        // ── Cancel Booking ───────────────────────────────────────────────────
        elseif ($action === 'cancel_booking') {
            $booking_id = trim($_POST['booking_id'] ?? '');
            if ($booking_id !== '') {
                try {
                    // Only cancel own bookings unless admin
                    $booking = db_select_one("SELECT booked_by, status FROM resource_bookings WHERE id=$1", [$booking_id]);
                    if (!$booking) {
                        $error = "Booking not found.";
                    } elseif ($booking['status'] !== 'upcoming') {
                        $error = "Only upcoming bookings can be cancelled.";
                    } elseif ($role !== 'admin' && $booking['booked_by'] !== $user_id) {
                        $error = "You can only cancel your own bookings.";
                    } else {
                        db_query("UPDATE resource_bookings SET status='cancelled' WHERE id=$1", [$booking_id]);
                        $success = "Booking cancelled successfully.";
                    }
                } catch (Exception $e) {
                    $error = "Failed to cancel booking: " . $e->getMessage();
                }
            }
        }

        // ── Update Resource Status (admin only) ──────────────────────────────
        elseif ($action === 'update_resource_status') {
            if ($role !== 'admin') {
                $error = "Only administrators can update resource status.";
            } else {
                $resource_id = trim($_POST['resource_id'] ?? '');
                $new_status  = $_POST['new_status'] ?? '';
                if ($resource_id !== '' && in_array($new_status, ['available', 'maintenance'])) {
                    try {
                        db_query("UPDATE schedulable_resources SET status=$1 WHERE id=$2", [$new_status, $resource_id]);
                        $success = "Resource status updated to \"" . htmlspecialchars(ucfirst($new_status)) . "\".";
                    } catch (Exception $e) {
                        $error = "Failed to update resource: " . $e->getMessage();
                    }
                }
            }
        }

    } // end CSRF block
}

// Auto-update booking statuses based on current time
try {
    db_query(
        "UPDATE resource_bookings
         SET status = CASE
             WHEN booked_date = CURRENT_DATE AND start_time <= CURRENT_TIME AND end_time > CURRENT_TIME THEN 'in_progress'
             WHEN (booked_date < CURRENT_DATE) OR (booked_date = CURRENT_DATE AND end_time <= CURRENT_TIME) THEN 'completed'
             ELSE status
         END
         WHERE status IN ('upcoming','in_progress')"
    );
} catch (Exception $e) {
    // Silent fail — status update is non-critical
}

// ─── FETCH DATA ───────────────────────────────────────────────────────────────

$resources = db_select("SELECT * FROM schedulable_resources ORDER BY type, name");

// Today's bookings with resource and user info
$filter_date = trim($_GET['date'] ?? '') ?: date('Y-m-d');
$filter_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date) ? $filter_date : date('Y-m-d');

$bookings = db_select(
    "SELECT rb.*,
            sr.name    AS resource_name,
            sr.type    AS resource_type,
            sr.location,
            u.email    AS booked_by_email
     FROM resource_bookings rb
     JOIN schedulable_resources sr ON rb.resource_id = sr.id
     LEFT JOIN users u ON rb.booked_by = u.id
     WHERE rb.booked_date = $1
     ORDER BY rb.start_time ASC",
    [$filter_date]
);

// Stats
$stat_total       = count($resources);
$stat_available   = count(array_filter($resources, fn($r) => $r['status'] === 'available'));
$stat_maintenance = count(array_filter($resources, fn($r) => $r['status'] === 'maintenance'));

// Booked today = distinct resources with upcoming or in_progress bookings today
$booked_today_rows = db_select_one(
    "SELECT COUNT(DISTINCT resource_id) AS c FROM resource_bookings
     WHERE booked_date = CURRENT_DATE AND status IN ('upcoming','in_progress')"
);
$stat_booked_today = $booked_today_rows['c'] ?? 0;

// Split resources by tab type
$equipment_types = ['Medical Equipment', 'Diagnostic', 'Surgical', 'Rehabilitation'];
$room_types      = ['Meeting Room', 'Procedure Room', 'Consultation Room'];

$equipment_resources = array_filter($resources, fn($r) => in_array($r['type'], $equipment_types));
$room_resources      = array_filter($resources, fn($r) => in_array($r['type'], $room_types));
?>

<style>
/* ── Layout ─────────────────────────────────────────── */
.sch-main { padding: 24px; max-width: 1400px; margin: 0 auto; }
.sch-page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.sch-page-header h1 { margin: 0; font-size: 1.6rem; color: #111827; }
.sch-page-header p  { margin: 4px 0 0; color: #6b7280; font-size: 0.9em; }
.sch-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Stats ──────────────────────────────────────────── */
.sch-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 16px; margin-bottom: 24px; }
.sch-stat-card {
    background: #fff; border-radius: 12px; padding: 18px 20px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07); border: 1px solid #f3f4f6;
    display: flex; align-items: center; gap: 14px;
}
.sch-stat-icon {
    width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
}
.sch-stat-card h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #111827; }
.sch-stat-card p  { margin: 2px 0 0; font-size: 0.78rem; color: #6b7280; }

/* ── Tabs ───────────────────────────────────────────── */
.sch-tab-bar { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px; }
.sch-tab-btn {
    padding: 10px 20px; background: none; border: none; cursor: pointer;
    font-size: 0.92rem; font-weight: 500; color: #6b7280; border-bottom: 2px solid transparent;
    margin-bottom: -2px; transition: color .2s, border-color .2s; border-radius: 4px 4px 0 0;
}
.sch-tab-btn:hover  { color: #374151; }
.sch-tab-btn.active { color: #0284c7; border-bottom-color: #0284c7; background: #f0f9ff; }
.sch-tab-content    { display: none; }
.sch-tab-content.active { display: block; }

/* ── Cards ──────────────────────────────────────────── */
.sch-card {
    background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 20px;
}
.sch-card-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px; border-bottom: 1px solid #f3f4f6; flex-wrap: wrap; gap: 10px;
}
.sch-card-header h4 { margin: 0; font-size: 1rem; color: #111827; }
.sch-card-body { padding: 20px; }

/* ── Resource Grid ──────────────────────────────────── */
.sch-resource-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap: 14px; padding: 16px; }
.sch-resource-card {
    border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px;
    transition: box-shadow .2s; background: #fff;
}
.sch-resource-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.sch-resource-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
.sch-resource-name { font-weight: 600; font-size: 0.92rem; color: #111827; }
.sch-resource-meta { font-size: 0.78rem; color: #6b7280; margin: 3px 0; }
.sch-resource-actions { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }

/* ── Table ──────────────────────────────────────────── */
.sch-table-wrap { overflow-x: auto; }
.sch-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.sch-table thead th {
    background: #f0f9ff; padding: 10px 12px; text-align: left;
    font-weight: 600; color: #0369a1; border-bottom: 2px solid #bae6fd; white-space: nowrap;
}
.sch-table tbody tr:hover { background: #f9fafb; }
.sch-table tbody td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }

/* ── Badges ─────────────────────────────────────────── */
.sch-badge {
    display: inline-block; padding: 3px 9px; border-radius: 20px;
    font-size: 0.72rem; font-weight: 600; text-transform: capitalize; white-space: nowrap;
}
.badge-available    { background: #d1fae5; color: #065f46; }
.badge-maintenance  { background: #fee2e2; color: #991b1b; }
.badge-upcoming     { background: #dbeafe; color: #1e40af; }
.badge-in_progress  { background: #fef3c7; color: #92400e; }
.badge-completed    { background: #f3f4f6; color: #374151; }
.badge-cancelled    { background: #fee2e2; color: #991b1b; }

/* ── Type Badges ─────────────────────────────────────── */
.sch-type-badge {
    display: inline-block; padding: 2px 8px; border-radius: 6px;
    font-size: 0.7rem; font-weight: 600; background: #e0f2fe; color: #0369a1;
}

/* ── Alerts ─────────────────────────────────────────── */
.sch-alert {
    padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 10px; font-size: 0.9rem;
}
.sch-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.sch-alert-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Modal ──────────────────────────────────────────── */
.sch-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 1050; overflow-y: auto;
    padding: 40px 16px;
}
.sch-modal-overlay.open { display: flex; align-items: flex-start; justify-content: center; }
.sch-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 560px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2); animation: schSlideIn .2s ease;
}
@keyframes schSlideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.sch-modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 22px; border-bottom: 1px solid #e5e7eb;
}
.sch-modal-header h5 { margin: 0; font-size: 1.1rem; font-weight: 600; color: #111827; }
.sch-modal-close {
    background: none; border: none; cursor: pointer; font-size: 1.2rem;
    color: #6b7280; line-height: 1; padding: 4px; border-radius: 6px;
}
.sch-modal-close:hover { background: #f3f4f6; color: #111827; }
.sch-modal-body   { padding: 22px; }
.sch-modal-footer {
    padding: 16px 22px; border-top: 1px solid #e5e7eb;
    display: flex; justify-content: flex-end; gap: 10px;
}
.sch-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.sch-form-row.single { grid-template-columns: 1fr; }
.sch-form-group { display: flex; flex-direction: column; gap: 5px; }
.sch-form-group label { font-size: 0.82rem; font-weight: 600; color: #374151; }
.sch-form-group input,
.sch-form-group select,
.sch-form-group textarea {
    padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 0.875rem; outline: none; font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
}
.sch-form-group input:focus,
.sch-form-group select:focus,
.sch-form-group textarea:focus {
    border-color: #0284c7; box-shadow: 0 0 0 2px rgba(2,132,199,.15);
}
.sch-form-group textarea { resize: vertical; min-height: 75px; }
.sch-form-hint { font-size: 0.76rem; color: #6b7280; margin-top: 3px; }

/* ── Buttons ─────────────────────────────────────────── */
.sch-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;
    font-size: 0.875rem; font-weight: 500; transition: opacity .2s, transform .1s; text-decoration: none;
}
.sch-btn:hover { opacity: .88; transform: translateY(-1px); }
.sch-btn-primary   { background: #0284c7; color: #fff; }
.sch-btn-success   { background: #16a34a; color: #fff; }
.sch-btn-warning   { background: #d97706; color: #fff; }
.sch-btn-danger    { background: #dc2626; color: #fff; }
.sch-btn-secondary { background: #6b7280; color: #fff; }
.sch-btn-ghost     { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.sch-btn-sm { padding: 5px 10px; font-size: 0.78rem; }

/* ── Date Filter ─────────────────────────────────────── */
.sch-date-filter {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.sch-date-filter input[type="date"] {
    padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    font-size: 0.875rem; outline: none;
}
.sch-date-filter input[type="date"]:focus { border-color: #0284c7; }

/* ── Search ─────────────────────────────────────────── */
.sch-search {
    padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px;
    font-size: 0.875rem; outline: none; width: 240px; max-width: 100%;
}

/* ── View Modal Detail ───────────────────────────────── */
.sch-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sch-detail-item label { font-size: 0.78rem; color: #6b7280; display: block; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.sch-detail-item span  { font-size: 0.9rem; color: #111827; display: block; margin-top: 2px; }

/* ── Duration chip ───────────────────────────────────── */
.sch-duration { font-size: 0.78rem; color: #6b7280; white-space: nowrap; }

/* ── Empty ───────────────────────────────────────────── */
.sch-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.sch-empty i { font-size: 2rem; margin-bottom: 8px; display: block; }

@media (max-width: 640px) {
    .sch-form-row { grid-template-columns: 1fr; }
    .sch-detail-grid { grid-template-columns: 1fr; }
    .sch-stats { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="sch-main">

    <!-- Page Header -->
    <div class="sch-page-header">
        <div>
            <h1><i class="fas fa-calendar-alt" style="color:#0284c7;margin-right:8px;"></i>Equipment &amp; Room Scheduling</h1>
            <p>Book and manage hospital equipment, procedure rooms, and facilities</p>
        </div>
        <div class="sch-header-actions">
            <?php if ($role === 'admin'): ?>
            <button class="sch-btn sch-btn-secondary" onclick="schOpenModal('addResourceModal')">
                <i class="fas fa-plus-circle"></i> Add Resource
            </button>
            <?php endif; ?>
            <button class="sch-btn sch-btn-primary" onclick="schOpenModal('bookResourceModal')">
                <i class="fas fa-calendar-plus"></i> Book Resource
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="sch-alert sch-alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="sch-alert sch-alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="sch-stats">
        <div class="sch-stat-card">
            <div class="sch-stat-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fas fa-boxes"></i></div>
            <div><h3><?php echo $stat_total; ?></h3><p>Total Resources</p></div>
        </div>
        <div class="sch-stat-card">
            <div class="sch-stat-icon" style="background:#d1fae5;color:#065f46;"><i class="fas fa-check-circle"></i></div>
            <div><h3><?php echo $stat_available; ?></h3><p>Available Now</p></div>
        </div>
        <div class="sch-stat-card">
            <div class="sch-stat-icon" style="background:#fef3c7;color:#92400e;"><i class="fas fa-calendar-check"></i></div>
            <div><h3><?php echo $stat_booked_today; ?></h3><p>Booked Today</p></div>
        </div>
        <div class="sch-stat-card">
            <div class="sch-stat-icon" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-tools"></i></div>
            <div><h3><?php echo $stat_maintenance; ?></h3><p>Maintenance</p></div>
        </div>
    </div>

    <!-- Bookings section — date filter -->
    <div class="sch-card" style="margin-bottom:20px;">
        <div class="sch-card-header">
            <h4><i class="fas fa-clock" style="margin-right:6px;color:#0284c7;"></i>
                Bookings for
                <span style="color:#0284c7;"><?php echo htmlspecialchars($filter_date === date('Y-m-d') ? 'Today (' . $filter_date . ')' : $filter_date); ?></span>
            </h4>
            <form method="GET" class="sch-date-filter">
                <label for="date_filter" style="font-size:0.82rem;font-weight:600;color:#374151;">View date:</label>
                <input type="date" id="date_filter" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                <button type="submit" class="sch-btn sch-btn-primary sch-btn-sm"><i class="fas fa-search"></i> Filter</button>
                <?php if ($filter_date !== date('Y-m-d')): ?>
                <a href="<?php echo BASE_URL; ?>/modules/scheduling/equipment_schedule.php" class="sch-btn sch-btn-ghost sch-btn-sm">
                    <i class="fas fa-calendar-day"></i> Today
                </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($bookings)): ?>
            <div class="sch-empty">
                <i class="fas fa-calendar-times"></i>
                <p>No bookings for this date.</p>
            </div>
        <?php else: ?>
        <div class="sch-table-wrap">
            <table class="sch-table">
                <thead>
                    <tr>
                        <th>Resource</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Booked By</th>
                        <th>For (Patient / Reason)</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b):
                    // Calculate duration
                    $start_ts  = strtotime($b['booked_date'] . ' ' . $b['start_time']);
                    $end_ts    = strtotime($b['booked_date'] . ' ' . $b['end_time']);
                    $diff_min  = ($end_ts - $start_ts) / 60;
                    $dur_h     = floor($diff_min / 60);
                    $dur_m     = $diff_min % 60;
                    $duration  = ($dur_h > 0 ? $dur_h . 'h ' : '') . ($dur_m > 0 ? $dur_m . 'm' : '');
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($b['resource_name']); ?></strong></td>
                        <td><span class="sch-type-badge"><?php echo htmlspecialchars($b['resource_type']); ?></span></td>
                        <td><?php echo htmlspecialchars($b['location'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($b['booked_by_email'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($b['booked_for']); ?></td>
                        <td><?php echo htmlspecialchars(substr($b['start_time'], 0, 5)); ?></td>
                        <td><?php echo htmlspecialchars(substr($b['end_time'], 0, 5)); ?></td>
                        <td><span class="sch-duration"><?php echo htmlspecialchars($duration ?: '—'); ?></span></td>
                        <td><span class="sch-badge badge-<?php echo htmlspecialchars($b['status']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($b['status']))); ?></span></td>
                        <td>
                            <div style="display:flex;gap:5px;">
                                <!-- View -->
                                <button class="sch-btn sch-btn-ghost sch-btn-sm"
                                    onclick="schViewBooking(<?php echo htmlspecialchars(json_encode($b)); ?>)"
                                    title="View"><i class="fas fa-eye"></i></button>

                                <!-- Cancel (only upcoming) -->
                                <?php if ($b['status'] === 'upcoming' && ($role === 'admin' || $b['booked_by'] === $user_id)): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this booking?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="cancel_booking">
                                    <input type="hidden" name="booking_id" value="<?php echo htmlspecialchars($b['id']); ?>">
                                    <button type="submit" class="sch-btn sch-btn-danger sch-btn-sm" title="Cancel"><i class="fas fa-times"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Resource Tabs -->
    <div class="sch-tab-bar">
        <button class="sch-tab-btn active" data-tab="tab-equipment">
            <i class="fas fa-stethoscope" style="margin-right:6px;"></i>Equipment
        </button>
        <button class="sch-tab-btn" data-tab="tab-rooms">
            <i class="fas fa-door-open" style="margin-right:6px;"></i>Rooms &amp; Facilities
        </button>
    </div>

    <!-- ── Tab: Equipment ───────────────────────────────────────────────────── -->
    <div id="tab-equipment" class="sch-tab-content active">
        <div class="sch-card">
            <div class="sch-card-header">
                <h4><i class="fas fa-stethoscope" style="margin-right:6px;color:#0284c7;"></i>Medical Equipment</h4>
                <input type="text" class="sch-search" id="filter-equip" placeholder="Search equipment..."
                    onkeyup="filterResourceCards('filter-equip','equip-grid')">
            </div>
            <?php if (empty($equipment_resources)): ?>
                <div class="sch-empty">
                    <i class="fas fa-stethoscope"></i>
                    <p>No equipment resources found.<?php echo $role === 'admin' ? ' Click "Add Resource" to add one.' : ''; ?></p>
                </div>
            <?php else: ?>
            <div id="equip-grid" class="sch-resource-grid">
                <?php foreach ($equipment_resources as $r): ?>
                <div class="sch-resource-card" data-name="<?php echo htmlspecialchars(strtolower($r['name'] . ' ' . $r['type'] . ' ' . $r['location'])); ?>">
                    <div class="sch-resource-card-header">
                        <div>
                            <div class="sch-resource-name"><?php echo htmlspecialchars($r['name']); ?></div>
                            <span class="sch-type-badge" style="margin-top:4px;display:inline-block;"><?php echo htmlspecialchars($r['type']); ?></span>
                        </div>
                        <span class="sch-badge badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span>
                    </div>
                    <?php if ($r['location']): ?>
                    <div class="sch-resource-meta"><i class="fas fa-map-marker-alt" style="width:14px;color:#9ca3af;"></i> <?php echo htmlspecialchars($r['location']); ?></div>
                    <?php endif; ?>
                    <?php if ($r['notes']): ?>
                    <div class="sch-resource-meta" style="font-style:italic;"><?php echo htmlspecialchars($r['notes']); ?></div>
                    <?php endif; ?>
                    <div class="sch-resource-actions">
                        <button class="sch-btn sch-btn-primary sch-btn-sm" onclick="schPreSelectResource('<?php echo htmlspecialchars($r['id']); ?>')">
                            <i class="fas fa-calendar-plus"></i> Book
                        </button>
                        <?php if ($role === 'admin'): ?>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update_resource_status">
                            <input type="hidden" name="resource_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                            <input type="hidden" name="new_status" value="<?php echo $r['status']==='available' ? 'maintenance' : 'available'; ?>">
                            <button type="submit" class="sch-btn sch-btn-sm <?php echo $r['status']==='available' ? 'sch-btn-warning' : 'sch-btn-success'; ?>"
                                title="<?php echo $r['status']==='available' ? 'Set Maintenance' : 'Set Available'; ?>">
                                <i class="fas fa-<?php echo $r['status']==='available' ? 'tools' : 'check'; ?>"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Tab: Rooms ───────────────────────────────────────────────────────── -->
    <div id="tab-rooms" class="sch-tab-content">
        <div class="sch-card">
            <div class="sch-card-header">
                <h4><i class="fas fa-door-open" style="margin-right:6px;color:#0284c7;"></i>Rooms &amp; Facilities</h4>
                <input type="text" class="sch-search" id="filter-rooms" placeholder="Search rooms..."
                    onkeyup="filterResourceCards('filter-rooms','rooms-grid')">
            </div>
            <?php if (empty($room_resources)): ?>
                <div class="sch-empty">
                    <i class="fas fa-door-open"></i>
                    <p>No room resources found.<?php echo $role === 'admin' ? ' Click "Add Resource" to add one.' : ''; ?></p>
                </div>
            <?php else: ?>
            <div id="rooms-grid" class="sch-resource-grid">
                <?php foreach ($room_resources as $r): ?>
                <div class="sch-resource-card" data-name="<?php echo htmlspecialchars(strtolower($r['name'] . ' ' . $r['type'] . ' ' . $r['location'])); ?>">
                    <div class="sch-resource-card-header">
                        <div>
                            <div class="sch-resource-name"><?php echo htmlspecialchars($r['name']); ?></div>
                            <span class="sch-type-badge" style="margin-top:4px;display:inline-block;background:#f3e8ff;color:#6b21a8;"><?php echo htmlspecialchars($r['type']); ?></span>
                        </div>
                        <span class="sch-badge badge-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars(ucfirst($r['status'])); ?></span>
                    </div>
                    <?php if ($r['location']): ?>
                    <div class="sch-resource-meta"><i class="fas fa-map-marker-alt" style="width:14px;color:#9ca3af;"></i> <?php echo htmlspecialchars($r['location']); ?></div>
                    <?php endif; ?>
                    <?php if ($r['capacity']): ?>
                    <div class="sch-resource-meta"><i class="fas fa-users" style="width:14px;color:#9ca3af;"></i> Capacity: <?php echo (int)$r['capacity']; ?></div>
                    <?php endif; ?>
                    <?php if ($r['notes']): ?>
                    <div class="sch-resource-meta" style="font-style:italic;"><?php echo htmlspecialchars($r['notes']); ?></div>
                    <?php endif; ?>
                    <div class="sch-resource-actions">
                        <button class="sch-btn sch-btn-primary sch-btn-sm" onclick="schPreSelectResource('<?php echo htmlspecialchars($r['id']); ?>')">
                            <i class="fas fa-calendar-plus"></i> Book
                        </button>
                        <?php if ($role === 'admin'): ?>
                        <form method="POST" style="display:inline;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="update_resource_status">
                            <input type="hidden" name="resource_id" value="<?php echo htmlspecialchars($r['id']); ?>">
                            <input type="hidden" name="new_status" value="<?php echo $r['status']==='available' ? 'maintenance' : 'available'; ?>">
                            <button type="submit" class="sch-btn sch-btn-sm <?php echo $r['status']==='available' ? 'sch-btn-warning' : 'sch-btn-success'; ?>"
                                title="<?php echo $r['status']==='available' ? 'Set Maintenance' : 'Set Available'; ?>">
                                <i class="fas fa-<?php echo $r['status']==='available' ? 'tools' : 'check'; ?>"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /sch-main -->

<!-- ═══════════════════════════════ MODALS ═══════════════════════════════════ -->

<?php if ($role === 'admin'): ?>
<!-- Add Resource Modal -->
<div id="addResourceModal" class="sch-modal-overlay">
    <div class="sch-modal">
        <div class="sch-modal-header">
            <h5><i class="fas fa-plus-circle" style="margin-right:8px;color:#0284c7;"></i>Add New Resource</h5>
            <button class="sch-modal-close" onclick="schCloseModal('addResourceModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="add_resource">
            <div class="sch-modal-body">
                <div class="sch-form-row">
                    <div class="sch-form-group">
                        <label>Resource Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="name" placeholder="e.g. MRI Scanner #2" required>
                    </div>
                    <div class="sch-form-group">
                        <label>Type <span style="color:#dc2626;">*</span></label>
                        <select name="type" required>
                            <option value="">-- Select Type --</option>
                            <optgroup label="Equipment">
                                <option value="Medical Equipment">Medical Equipment</option>
                                <option value="Diagnostic">Diagnostic</option>
                                <option value="Surgical">Surgical</option>
                                <option value="Rehabilitation">Rehabilitation</option>
                            </optgroup>
                            <optgroup label="Rooms">
                                <option value="Meeting Room">Meeting Room</option>
                                <option value="Procedure Room">Procedure Room</option>
                                <option value="Consultation Room">Consultation Room</option>
                            </optgroup>
                        </select>
                    </div>
                </div>
                <div class="sch-form-row">
                    <div class="sch-form-group">
                        <label>Location</label>
                        <input type="text" name="location" placeholder="e.g. Ward B, Floor 2">
                    </div>
                    <div class="sch-form-group">
                        <label>Capacity <span class="sch-form-hint">(for rooms)</span></label>
                        <input type="number" name="capacity" min="0" placeholder="e.g. 10">
                    </div>
                </div>
                <div class="sch-form-row">
                    <div class="sch-form-group">
                        <label>Initial Status</label>
                        <select name="status">
                            <option value="available">Available</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>
                <div class="sch-form-row single">
                    <div class="sch-form-group">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Any relevant information..."></textarea>
                    </div>
                </div>
            </div>
            <div class="sch-modal-footer">
                <button type="button" class="sch-btn sch-btn-ghost" onclick="schCloseModal('addResourceModal')">Cancel</button>
                <button type="submit" class="sch-btn sch-btn-primary"><i class="fas fa-save"></i> Save Resource</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Book Resource Modal -->
<div id="bookResourceModal" class="sch-modal-overlay">
    <div class="sch-modal">
        <div class="sch-modal-header">
            <h5><i class="fas fa-calendar-plus" style="margin-right:8px;color:#16a34a;"></i>Book Resource</h5>
            <button class="sch-modal-close" onclick="schCloseModal('bookResourceModal')">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="book_resource">
            <div class="sch-modal-body">
                <div class="sch-form-row single">
                    <div class="sch-form-group">
                        <label>Resource <span style="color:#dc2626;">*</span></label>
                        <select name="resource_id" id="book_resource_id" required>
                            <option value="">-- Select Resource --</option>
                            <?php
                            $grouped = [];
                            foreach ($resources as $r) {
                                if ($r['status'] !== 'maintenance') {
                                    $grouped[$r['type']][] = $r;
                                }
                            }
                            foreach ($grouped as $type => $items): ?>
                            <optgroup label="<?php echo htmlspecialchars($type); ?>">
                                <?php foreach ($items as $r): ?>
                                <option value="<?php echo htmlspecialchars($r['id']); ?>">
                                    <?php echo htmlspecialchars($r['name']); ?>
                                    <?php echo $r['location'] ? ' — ' . htmlspecialchars($r['location']) : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <span class="sch-form-hint">Only available resources are listed.</span>
                    </div>
                </div>
                <div class="sch-form-row single">
                    <div class="sch-form-group">
                        <label>Date <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="booked_date" id="book_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="sch-form-row">
                    <div class="sch-form-group">
                        <label>Start Time <span style="color:#dc2626;">*</span></label>
                        <input type="time" name="start_time" id="book_start_time" required>
                    </div>
                    <div class="sch-form-group">
                        <label>End Time <span style="color:#dc2626;">*</span></label>
                        <input type="time" name="end_time" id="book_end_time" required>
                    </div>
                </div>
                <div class="sch-form-row single">
                    <div class="sch-form-group">
                        <label>Booked For (Patient Name / Reason) <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="booked_for" placeholder="e.g. John Doe — Chest X-Ray" required>
                    </div>
                </div>
                <div class="sch-form-row single">
                    <div class="sch-form-group">
                        <label>Booked By</label>
                        <input type="text" value="<?php echo htmlspecialchars($_SESSION['email'] ?? 'Current User'); ?>" readonly style="background:#f9fafb;color:#374151;">
                        <span class="sch-form-hint">Auto-filled with your account.</span>
                    </div>
                </div>
                <div class="sch-form-row single">
                    <div class="sch-form-group">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Any special requirements..."></textarea>
                    </div>
                </div>
            </div>
            <div class="sch-modal-footer">
                <button type="button" class="sch-btn sch-btn-ghost" onclick="schCloseModal('bookResourceModal')">Cancel</button>
                <button type="submit" class="sch-btn sch-btn-success"><i class="fas fa-calendar-check"></i> Confirm Booking</button>
            </div>
        </form>
    </div>
</div>

<!-- View Booking Modal -->
<div id="viewBookingModal" class="sch-modal-overlay">
    <div class="sch-modal">
        <div class="sch-modal-header">
            <h5><i class="fas fa-calendar-check" style="margin-right:8px;color:#0284c7;"></i>Booking Details</h5>
            <button class="sch-modal-close" onclick="schCloseModal('viewBookingModal')">&times;</button>
        </div>
        <div class="sch-modal-body">
            <div class="sch-detail-grid" id="viewBooking_body"></div>
        </div>
        <div class="sch-modal-footer">
            <button class="sch-btn sch-btn-ghost" onclick="schCloseModal('viewBookingModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.sch-tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.sch-tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.sch-tab-content').forEach(function(c) { c.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

// ── Modal helpers ─────────────────────────────────────────────────────────────
function schOpenModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function schCloseModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }

document.querySelectorAll('.sch-modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) schCloseModal(overlay.id);
    });
});

// ── Pre-select resource in book modal ─────────────────────────────────────────
function schPreSelectResource(resourceId) {
    var sel = document.getElementById('book_resource_id');
    if (sel) {
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === resourceId) { sel.selectedIndex = i; break; }
        }
    }
    schOpenModal('bookResourceModal');
}

// ── Resource card filter ───────────────────────────────────────────────────────
function filterResourceCards(inputId, gridId) {
    var val   = document.getElementById(inputId).value.toLowerCase();
    var cards = document.querySelectorAll('#' + gridId + ' .sch-resource-card');
    cards.forEach(function(card) {
        card.style.display = card.dataset.name.includes(val) ? '' : 'none';
    });
}

// ── Time validation ───────────────────────────────────────────────────────────
document.getElementById('book_end_time') && document.getElementById('book_end_time').addEventListener('change', function() {
    var start = document.getElementById('book_start_time').value;
    var end   = this.value;
    if (start && end && end <= start) {
        alert('End time must be after start time.');
        this.value = '';
    }
});

// ── View Booking ──────────────────────────────────────────────────────────────
function schViewBooking(b) {
    var statusClass = 'badge-' + (b.status || 'upcoming');
    var typeClass   = 'sch-type-badge';

    document.getElementById('viewBooking_body').innerHTML =
        schDetailItem('Resource',   escHtml(b.resource_name)) +
        schDetailItem('Type',       '<span class="' + typeClass + '">' + escHtml(b.resource_type) + '</span>') +
        schDetailItem('Location',   escHtml(b.location || '—')) +
        schDetailItem('Date',       escHtml(b.booked_date)) +
        schDetailItem('Start Time', escHtml((b.start_time||'').substring(0,5))) +
        schDetailItem('End Time',   escHtml((b.end_time||'').substring(0,5))) +
        schDetailItem('Booked By',  escHtml(b.booked_by_email || '—')) +
        schDetailItem('For',        escHtml(b.booked_for)) +
        schDetailItem('Status',     '<span class="sch-badge ' + statusClass + '">' + escHtml((b.status||'').replace(/_/g,' ')) + '</span>') +
        '<div class="sch-detail-item" style="grid-column:1/-1;"><label>Notes</label><span>' + escHtml(b.notes || '—') + '</span></div>';

    schOpenModal('viewBookingModal');
}

function schDetailItem(label, val) {
    return '<div class="sch-detail-item"><label>' + label + '</label><span>' + val + '</span></div>';
}

function escHtml(str) {
    if (str === null || str === undefined) return '—';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>

<?php include '../../includes/footer.php'; ?>
