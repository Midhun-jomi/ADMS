<?php
// modules/ehr/timeline.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();

$role    = get_user_role();
$user_id = get_user_id();

$page_title = "Patient Health Timeline";
include '../../includes/header.php';

$patient_id   = null;
$patient_name = '';
$all_patients = [];

if ($role === 'patient') {
    $pat = db_select_one("SELECT id, first_name, last_name FROM patients WHERE user_id = $1", [$user_id]);
    if ($pat) {
        $patient_id   = $pat['id'];
        $patient_name = $pat['first_name'] . ' ' . $pat['last_name'];
    }
} else {
    $all_patients = db_select("SELECT id, first_name, last_name FROM patients ORDER BY first_name");
    $pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
    if ($pid) {
        $pat = db_select_one("SELECT id, first_name, last_name FROM patients WHERE id = $1", [$pid]);
        if ($pat) {
            $patient_id   = $pat['id'];
            $patient_name = $pat['first_name'] . ' ' . $pat['last_name'];
        }
    }
}

// --- Build unified timeline events ---
$events = [];

if ($patient_id) {
    // Appointments
    $appts = db_select(
        "SELECT a.id, a.appointment_time as ts, a.status, a.reason as notes,
                s.first_name || ' ' || s.last_name as doctor_name, s.specialization
         FROM appointments a
         LEFT JOIN staff s ON a.doctor_id = s.id
         WHERE a.patient_id = $1",
        [$patient_id]
    );
    foreach ($appts as $r) {
        $events[] = [
            'ts'      => $r['ts'],
            'type'    => 'appointment',
            'icon'    => 'fas fa-calendar-check',
            'color'   => '#6366f1',
            'bg'      => '#ede9fe',
            'title'   => 'Appointment: ' . ($r['specialization'] ?: 'General Medicine'),
            'detail'  => 'Dr. ' . ($r['doctor_name'] ?? 'Unknown') . ' — Status: ' . ucfirst($r['status'] ?? 'unknown'),
            'note'    => $r['notes'] ?? '',
            'badge'   => ucfirst($r['status'] ?? ''),
            'badge_c' => ['completed' => '#dcfce7', 'scheduled' => '#dbeafe', 'cancelled' => '#fee2e2'][$r['status']] ?? '#f3f4f6',
        ];
    }

    // Prescriptions
    $rxs = db_select(
        "SELECT p.id, p.created_at as ts, p.notes, p.medication_details,
                s.first_name || ' ' || s.last_name as doctor_name, s.specialization
         FROM prescriptions p
         LEFT JOIN staff s ON p.doctor_id = s.id
         WHERE p.patient_id = $1",
        [$patient_id]
    );
    foreach ($rxs as $r) {
        $meds = json_decode($r['medication_details'] ?? '[]', true) ?: [];
        $med_names = implode(', ', array_filter(array_column($meds, 'name')));
        $events[] = [
            'ts'      => $r['ts'],
            'type'    => 'prescription',
            'icon'    => 'fas fa-prescription-bottle-alt',
            'color'   => '#10b981',
            'bg'      => '#d1fae5',
            'title'   => 'Prescription Issued',
            'detail'  => 'By Dr. ' . ($r['doctor_name'] ?? 'Unknown') . ($r['specialization'] ? ' (' . $r['specialization'] . ')' : ''),
            'note'    => $med_names ?: ($r['notes'] ?? ''),
            'badge'   => 'Rx',
            'badge_c' => '#d1fae5',
        ];
    }

    // Admissions
    $admissions = db_select(
        "SELECT a.id, a.admission_date as ts, a.discharge_date, a.diagnosis,
                r.room_number, r.room_type
         FROM admissions a
         LEFT JOIN rooms r ON a.room_id = r.id
         WHERE a.patient_id = $1",
        [$patient_id]
    );
    foreach ($admissions as $r) {
        $events[] = [
            'ts'      => $r['ts'],
            'type'    => 'admission',
            'icon'    => 'fas fa-hospital',
            'color'   => '#ef4444',
            'bg'      => '#fee2e2',
            'title'   => 'Hospital Admission' . ($r['room_number'] ? ' — Room ' . $r['room_number'] : ''),
            'detail'  => $r['diagnosis'] ?: 'Admitted for treatment',
            'note'    => $r['diagnosis'] ? 'Diagnosis: ' . $r['diagnosis'] : '',
            'badge'   => $r['discharge_date'] ? 'Discharged' : 'Active',
            'badge_c' => $r['discharge_date'] ? '#dcfce7' : '#fef3c7',
        ];
        // Discharge event
        if ($r['discharge_date']) {
            $events[] = [
                'ts'      => $r['discharge_date'],
                'type'    => 'discharge',
                'icon'    => 'fas fa-walking',
                'color'   => '#0ea5e9',
                'bg'      => '#e0f2fe',
                'title'   => 'Discharged from Hospital',
                'detail'  => ($r['room_number'] ? 'Room ' . $r['room_number'] : 'Ward') . ' — ' . ucfirst($r['room_type'] ?? 'General'),
                'note'    => $r['diagnosis'] ? 'Final diagnosis: ' . $r['diagnosis'] : '',
                'badge'   => 'Discharged',
                'badge_c' => '#e0f2fe',
            ];
        }
    }

    // Billing events
    $bills = db_select(
        "SELECT id, created_at as ts, total_amount, status, service_description, transaction_id
         FROM billing WHERE patient_id = $1",
        [$patient_id]
    );
    foreach ($bills as $r) {
        $events[] = [
            'ts'      => $r['ts'],
            'type'    => 'billing',
            'icon'    => $r['status'] === 'paid' ? 'fas fa-receipt' : 'fas fa-file-invoice-dollar',
            'color'   => $r['status'] === 'paid' ? '#16a34a' : '#f59e0b',
            'bg'      => $r['status'] === 'paid' ? '#dcfce7' : '#fef9c3',
            'title'   => ($r['service_description'] ?? 'Medical Invoice') . ' — ₹' . number_format($r['total_amount'], 2),
            'detail'  => 'Invoice #' . str_pad($r['id'], 5, '0', STR_PAD_LEFT),
            'note'    => $r['transaction_id'] ? 'Txn: ' . $r['transaction_id'] : '',
            'badge'   => ucfirst($r['status']),
            'badge_c' => $r['status'] === 'paid' ? '#dcfce7' : '#fef9c3',
        ];
    }

    // Sort all events newest-first
    usort($events, fn($a, $b) => strtotime($b['ts']) - strtotime($a['ts']));

    // Group by year-month
    $grouped = [];
    foreach ($events as $ev) {
        $group = date('F Y', strtotime($ev['ts']));
        $grouped[$group][] = $ev;
    }
}
?>

<style>
.tl-wrap { max-width: 860px; margin: 0 auto; padding: 20px; }
.tl-selector { background: white; border-radius: 14px; padding: 20px 24px; border: 1px solid #e5e7eb; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); }

/* Timeline structure */
.tl-month { font-size: 0.78em; font-weight: 800; color: #9ca3af; text-transform: uppercase; letter-spacing: 1.5px; margin: 32px 0 16px; padding-left: 58px; }
.tl-item { display: flex; gap: 0; margin-bottom: 0; position: relative; }
.tl-line { width: 58px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; }
.tl-dot { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85em; z-index: 1; flex-shrink: 0; border: 3px solid white; box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
.tl-connector { flex: 1; width: 2px; background: #e5e7eb; min-height: 20px; }
.tl-card { flex: 1; background: white; border-radius: 12px; border: 1px solid #e5e7eb; padding: 14px 18px; margin-bottom: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); transition: box-shadow 0.2s; }
.tl-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.1); }
.tl-card-title { font-weight: 700; color: #111827; font-size: 0.95em; margin-bottom: 3px; }
.tl-card-detail { color: #6b7280; font-size: 0.85em; }
.tl-card-note { color: #374151; font-size: 0.83em; background: #f9fafb; border-radius: 6px; padding: 6px 10px; margin-top: 8px; border-left: 3px solid #e5e7eb; }
.tl-time { font-size: 0.75em; color: #9ca3af; margin-top: 4px; }
.tl-badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 0.75em; font-weight: 700; margin-top: 6px; }
.empty-state { text-align: center; padding: 70px 20px; color: #9ca3af; }

/* Filter pills */
.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; }
.fpill { border: 1px solid #e5e7eb; background: white; border-radius: 99px; padding: 5px 14px; font-size: 0.82em; font-weight: 600; cursor: pointer; color: #374151; transition: all 0.15s; }
.fpill:hover, .fpill.active { background: #6366f1; color: white; border-color: #6366f1; }
</style>

<div class="tl-wrap">
    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 24px;">
        <div style="width: 46px; height: 46px; background: linear-gradient(135deg, #0ea5e9, #6366f1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2em;">
            <i class="fas fa-stream"></i>
        </div>
        <div>
            <h2 style="margin: 0; font-size: 1.3em; font-weight: 800; color: #111827;">Patient Health Timeline</h2>
            <p style="margin: 0; color: #6b7280; font-size: 0.85em;">Chronological view of all medical events</p>
        </div>
    </div>

    <?php if ($role !== 'patient'): ?>
    <div class="tl-selector">
        <form method="GET" action="" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <label style="font-weight: 600; color: #374151; font-size: 0.88em;">Select Patient:</label>
            <select name="patient_id" class="form-control" style="border-radius: 8px; height: 40px; min-width: 240px;" onchange="this.form.submit()">
                <option value="">-- Choose Patient --</option>
                <?php foreach ($all_patients as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo ($patient_id == $p['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($patient_id): ?>
                <span style="color: #6366f1; font-weight: 600; font-size: 0.88em;"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($patient_name); ?></span>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$patient_id): ?>
        <div class="empty-state">
            <i class="fas fa-stream" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
            <h4 style="color: #374151;">No Patient Selected</h4>
            <p>Select a patient to view their health timeline.</p>
        </div>
    <?php elseif (empty($events)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
            <h4 style="color: #374151;">No Events Found</h4>
            <p>No medical history recorded for <?php echo htmlspecialchars($patient_name); ?> yet.</p>
        </div>
    <?php else: ?>

    <!-- Stats bar -->
    <?php
    $type_counts = array_count_values(array_column($events, 'type'));
    $type_labels = ['appointment' => 'Appointments', 'prescription' => 'Prescriptions', 'admission' => 'Admissions', 'discharge' => 'Discharges', 'billing' => 'Billing'];
    $type_colors = ['appointment' => '#6366f1', 'prescription' => '#10b981', 'admission' => '#ef4444', 'discharge' => '#0ea5e9', 'billing' => '#f59e0b'];
    ?>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
        <?php foreach ($type_counts as $type => $cnt): ?>
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 16px; display: flex; align-items: center; gap: 8px;">
            <span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $type_colors[$type] ?? '#9ca3af'; ?>; flex-shrink: 0;"></span>
            <span style="font-weight: 700; color: #111827;"><?php echo $cnt; ?></span>
            <span style="color: #6b7280; font-size: 0.82em;"><?php echo $type_labels[$type] ?? ucfirst($type); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter pills -->
    <div class="filter-pills">
        <button class="fpill active" onclick="filterTimeline('all', this)">All Events</button>
        <?php foreach (array_unique(array_column($events, 'type')) as $t): ?>
            <button class="fpill" onclick="filterTimeline('<?php echo $t; ?>', this)"><?php echo $type_labels[$t] ?? ucfirst($t); ?></button>
        <?php endforeach; ?>
    </div>

    <!-- Timeline -->
    <div id="timeline">
        <?php foreach ($grouped as $month => $month_events): ?>
        <div class="tl-month"><?php echo $month; ?></div>
        <?php foreach ($month_events as $ev): ?>
        <div class="tl-item" data-type="<?php echo $ev['type']; ?>">
            <div class="tl-line">
                <div class="tl-dot" style="background: <?php echo $ev['bg']; ?>; color: <?php echo $ev['color']; ?>;">
                    <i class="<?php echo $ev['icon']; ?>"></i>
                </div>
                <div class="tl-connector"></div>
            </div>
            <div class="tl-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
                    <div class="tl-card-title"><?php echo htmlspecialchars($ev['title']); ?></div>
                    <span class="tl-badge" style="background: <?php echo $ev['badge_c']; ?>; color: #374151;"><?php echo htmlspecialchars($ev['badge']); ?></span>
                </div>
                <div class="tl-card-detail"><?php echo htmlspecialchars($ev['detail']); ?></div>
                <?php if ($ev['note']): ?>
                <div class="tl-card-note"><?php echo htmlspecialchars($ev['note']); ?></div>
                <?php endif; ?>
                <div class="tl-time"><i class="fas fa-clock" style="margin-right: 4px;"></i><?php echo date('d M Y, h:i A', strtotime($ev['ts'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<script>
function filterTimeline(type, btn) {
    document.querySelectorAll('.fpill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tl-item').forEach(el => {
        el.style.display = (type === 'all' || el.dataset.type === type) ? 'flex' : 'none';
    });
    // Show/hide month headers
    document.querySelectorAll('.tl-month').forEach(header => {
        let next = header.nextElementSibling;
        let hasVisible = false;
        while (next && !next.classList.contains('tl-month')) {
            if (next.style.display !== 'none') hasVisible = true;
            next = next.nextElementSibling;
        }
        header.style.display = hasVisible ? '' : 'none';
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
