<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin, Doctor, Nurse
$allowed_roles = ['admin', 'doctor', 'nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Operation Theatre Schedule";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle Surgery Booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_surgery'])) {
    try {
        db_insert('surgeries', [
            'patient_id' => $_POST['patient_id'],
            'doctor_id' => $_POST['doctor_id'],
            'theatre_id' => $_POST['theatre_id'],
            'surgery_name' => $_POST['surgery_name'],
            'scheduled_start' => $_POST['scheduled_start'],
            'scheduled_end' => $_POST['scheduled_end'],
            'status' => 'scheduled'
        ]);
        $success_msg = "Surgery booked successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Fetch Data
$patients = db_select("SELECT id, first_name, last_name FROM patients");
$doctors = db_select("SELECT id, first_name, last_name FROM staff WHERE role = 'doctor'");
$theatres = db_select("SELECT * FROM theatres");
$surgeries = db_select("
    SELECT s.*, 
           p.first_name as p_fname, p.last_name as p_lname,
           d.first_name as d_fname, d.last_name as d_lname,
           t.name as theatre_name
    FROM surgeries s
    JOIN patients p ON s.patient_id = p.id
    JOIN staff d ON s.doctor_id = d.id
    LEFT JOIN theatres t ON s.theatre_id = t.id
    ORDER BY s.scheduled_start ASC
");

if (empty($theatres)) {
    // Seed some theatres if empty
    db_insert('theatres', ['name' => 'General OT 1', 'type' => 'General', 'status' => 'available']);
    db_insert('theatres', ['name' => 'General OT 2', 'type' => 'General', 'status' => 'available']);
    $theatres = db_select("SELECT * FROM theatres");
}
?>

<div class="main-content">
    <div class="page-header">
        <h1>Surgery Schedule</h1>
        <button class="btn-primary" onclick="showModal('bookingModal')">
            <i class="fas fa-calendar-plus"></i> Book Surgery
        </button>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="schedule-grid">
        <!-- Upcoming List -->
        <div class="card span-2">
            <div class="card-header">
                <h3>Upcoming Operations</h3>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>DateTime</th>
                            <th>Theatre</th>
                            <th>Surgery</th>
                            <th>Patient</th>
                            <th>Surgeon</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($surgeries as $op): ?>
                        <tr>
                            <td>
                                <?php echo date('M d, H:i', strtotime($op['scheduled_start'])); ?> - 
                                <?php echo date('H:i', strtotime($op['scheduled_end'])); ?>
                            </td>
                            <td><?php echo htmlspecialchars($op['theatre_name']); ?></td>
                            <td><?php echo htmlspecialchars($op['surgery_name']); ?></td>
                            <td><?php echo htmlspecialchars($op['p_fname'] . ' ' . $op['p_lname']); ?></td>
                            <td>Dr. <?php echo htmlspecialchars($op['d_fname'] . ' ' . $op['d_lname']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $op['status'] === 'scheduled' ? 'info' : ($op['status'] === 'completed' ? 'success' : 'warning'); ?>">
                                    <?php echo ucfirst($op['status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- OT Status -->
        <div class="card">
            <div class="card-header">
                <h3>Theatre Status</h3>
            </div>
            <div class="room-list">
                <?php foreach ($theatres as $ot): ?>
                <div class="room-item status-<?php echo $ot['status']; ?>">
                    <div class="room-icon"><i class="fas fa-procedures"></i></div>
                    <div class="room-details">
                        <h4><?php echo htmlspecialchars($ot['name']); ?></h4>
                        <p><?php echo htmlspecialchars($ot['type']); ?> - <strong><?php echo ucfirst($ot['status']); ?></strong></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Booking Modal -->
<div id="bookingModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('bookingModal')">&times;</span>
        <h3>Schedule Surgery</h3>
        <form method="POST">
            <div class="form-group">
                <label>Surgery Name/Type</label>
                <input type="text" name="surgery_name" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Patient</label>
                    <select name="patient_id" required>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo $p['first_name'] . ' ' . $p['last_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Lead Surgeon</label>
                    <select name="doctor_id" required>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>">Dr. <?php echo $d['first_name'] . ' ' . $d['last_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Operation Theatre</label>
                <select name="theatre_id" required>
                    <?php foreach ($theatres as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo $t['name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="datetime-local" name="scheduled_start" required>
                </div>
                <div class="form-group">
                    <label>End Time (Est)</label>
                    <input type="datetime-local" name="scheduled_end" required>
                </div>
            </div>
            <button type="submit" name="book_surgery" class="btn-primary">Book Schedule</button>
        </form>
    </div>
</div>

<script>
function showModal(id) { document.getElementById(id).style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
</script>

<style>
.schedule-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }
@media(max-width: 900px) { .schedule-grid { grid-template-columns: 1fr; } }
.room-list { display: flex; flex-direction: column; gap: 1rem; }
.room-item { display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 1px solid #eee; border-radius: 8px; }
.room-item.status-available { border-left: 5px solid #2ecc71; }
.room-item.status-occupied { border-left: 5px solid #e74c3c; background: #fff5f5; }
.room-icon { width: 40px; height: 40px; background: #f0f2f5; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #555; }
</style>

<?php require_once '../../includes/footer.php'; ?>
