<?php
session_start();
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

$allowed_roles = ['admin', 'receptionist', 'nurse'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Dispatch & Fleet Management";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

// Handle New Call
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_call'])) {
    try {
        db_insert('emergency_calls', [
            'caller_name' => $_POST['caller_name'],
            'caller_phone' => $_POST['caller_phone'],
            'location' => $_POST['location'],
            'description' => $_POST['description'],
            'status' => 'received'
        ]);
        $success_msg = "Call logged successfully!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Handle Dispatch Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_dispatch'])) {
    try {
        $call_id = $_POST['call_id'];
        $ambulance_id = $_POST['ambulance_id'];
        $status = $_POST['status']; // dispatched, resolved, cancelled

        // Update Call
        db_update('emergency_calls', 
            ['status' => $status, 'ambulance_id' => ($ambulance_id ?: null)], 
            ['id' => $call_id]
        );

        // Update Ambulance Status if assigned
        if ($ambulance_id) {
            $amb_status = ($status == 'dispatched') ? 'on_mission' : 'available';
            db_update('ambulance', ['status' => $amb_status], ['id' => $ambulance_id]);
        }

        $success_msg = "Dispatch status updated!";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Handle Add Ambulance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ambulance'])) {
    try {
        db_insert('ambulance', [
            'vehicle_number' => $_POST['vehicle_number'],
            'driver_name' => $_POST['driver_name'],
            'driver_phone' => $_POST['driver_phone'],
            'status' => 'available',
            'current_location' => 'Hospital Station'
        ]);
        $success_msg = "New ambulance added to fleet.";
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

$calls = db_select("SELECT * FROM emergency_calls WHERE status != 'resolved' ORDER BY created_at DESC");
$ambulances = db_select("SELECT * FROM ambulance");
$available_ambulances = array_filter($ambulances, function($a) { return $a['status'] == 'available'; });

?>

<div class="main-content">
    <div class="page-header">
        <h1>Dispatch Center</h1>
        <a href="dashboard.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success"><?php echo $success_msg; ?></div> <?php endif; ?>

    <div class="grid-layout">
        <!-- New Call Form -->
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h3><i class="fas fa-phone-volume"></i> Log Emergency Call</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Caller Name</label>
                        <input type="text" name="caller_name" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="caller_phone" required>
                    </div>
                    <div class="form-group">
                        <label>Location / Address</label>
                        <textarea name="location" rows="2" required placeholder="Exact location..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Description of Emergency</label>
                        <textarea name="description" rows="3" placeholder="Nature of emergency..."></textarea>
                    </div>
                    <button type="submit" name="log_call" class="btn-primary btn-block">Log Call & Alert</button>
                </form>
            </div>
        </div>

        <!-- Active Dispatch Management -->
        <div class="card span-2">
            <div class="card-header">
                <h3>Active Emergencies</h3>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Assigned Unit</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calls as $call): ?>
                        <tr>
                            <td><?php echo date('H:i', strtotime($call['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($call['location']); ?></td>
                            <td><span class="badge badge-<?php echo $call['status']; ?>"><?php echo strtoupper($call['status']); ?></span></td>
                            <td>
                                <?php 
                                    $assigned = array_filter($ambulances, function($a) use ($call) { return $a['id'] == $call['ambulance_id']; });
                                    $unit = reset($assigned);
                                    echo $unit ? $unit['vehicle_number'] : '<span class="text-danger">None</span>';
                                ?>
                            </td>
                            <td>
                                <button class="btn-small" onclick="openDispatchModal('<?php echo $call['id']; ?>')">Update</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($calls)): ?>
                            <tr><td colspan="5" class="text-center">No active emergency calls.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <h4>Registered Fleet</h4>
                <div class="fleet-list">
                    <?php foreach ($ambulances as $amb): ?>
                        <span class="badge badge-<?php echo $amb['status'] == 'available' ? 'success' : 'warning'; ?>">
                            <?php echo $amb['vehicle_number']; ?> (<?php echo $amb['status']; ?>)
                        </span>
                    <?php endforeach; ?>
                    <button class="btn-small btn-secondary ml-2" onclick="document.getElementById('ambulanceModal').style.display='block'">+ Add Vehicle</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Update Dispatch Modal -->
<div id="dispatchModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('dispatchModal').style.display='none'">&times;</span>
        <h3>Update Dispatch Status</h3>
        <form method="POST">
            <input type="hidden" name="call_id" id="modal_call_id">
            <div class="form-group">
                <label>Assign Ambulance</label>
                <select name="ambulance_id">
                    <option value="">-- Start Triage / No Dispatch --</option>
                    <?php foreach ($available_ambulances as $amb): ?>
                        <option value="<?php echo $amb['id']; ?>"><?php echo $amb['vehicle_number']; ?> - <?php echo $amb['driver_name']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" required>
                    <option value="received">Received (Pending)</option>
                    <option value="dispatched">Dispatched (Vehicle En Route)</option>
                    <option value="resolved">Resolved (Returned)</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <button type="submit" name="update_dispatch" class="btn-primary">Update</button>
        </form>
    </div>
</div>

<!-- Add Ambulance Modal -->
<div id="ambulanceModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="document.getElementById('ambulanceModal').style.display='none'">&times;</span>
        <h3>Register New Ambulance</h3>
        <form method="POST">
            <div class="form-group">
                <label>Vehicle Number</label>
                <input type="text" name="vehicle_number" required placeholder="KA-01-AB-1234">
            </div>
            <div class="form-group">
                <label>Driver Name</label>
                <input type="text" name="driver_name" required>
            </div>
            <div class="form-group">
                <label>Driver Phone</label>
                <input type="tel" name="driver_phone" required>
            </div>
            <button type="submit" name="add_ambulance" class="btn-primary">Register Vehicle</button>
        </form>
    </div>
</div>

<script>
function openDispatchModal(id) {
    document.getElementById('modal_call_id').value = id;
    document.getElementById('dispatchModal').style.display = 'block';
}
</script>

<style>
.grid-layout {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 1.5rem;
}
.card-header.bg-danger { background: #e74c3c; color: white; }
.span-2 { grid-column: span 1; }
@media(max-width: 900px) { .grid-layout { grid-template-columns: 1fr; } }
.fleet-list { display: flex; gap: 0.5rem; flex-wrap: wrap; }
</style>

<?php require_once '../../includes/footer.php'; ?>
