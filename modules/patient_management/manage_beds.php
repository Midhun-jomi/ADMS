<?php
// modules/patient_management/manage_beds.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'nurse', 'head_nurse']);

$page_title = "Bed Management";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle Add Bed
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_bed'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
    $room_num = trim($_POST['room_number']);
    $type = $_POST['room_type'];
    $floor = trim($_POST['floor']);
    $ward = trim($_POST['ward']);

    if (empty($room_num)) {
        $error = "Bed/Room Number is required.";
    } else {
        // Check duplicate
        $exists = db_select_one("SELECT id FROM rooms WHERE room_number = $1", [$room_num]);
        if ($exists) {
            $error = "Bed $room_num already exists.";
        } else {
            try {
                db_insert('rooms', [
                    'room_number' => $room_num,
                    'room_type' => $type,
                    'floor' => $floor,
                    'ward' => $ward,
                    'status' => 'available'
                ]);
                $success = "Bed added successfully.";
            } catch (Exception $e) {
                $error = "Failed to add bed: " . $e->getMessage();
            }
        }
    }
    } // end CSRF check
}

// Handle Delete Bed
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_bed'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $room_id = $_POST['room_id'];
        // Check if occupied
        $room = db_select_one("SELECT status FROM rooms WHERE id = $1", [$room_id]);
        if ($room && $room['status'] === 'occupied') {
            $error = "Cannot delete an occupied bed. Please discharge the patient first.";
        } else {
            try {
                db_query("DELETE FROM rooms WHERE id = $1", [$room_id]);
                $success = "Bed removed successfully.";
            } catch (Exception $e) {
                $error = "Failed to remove bed: " . $e->getMessage();
            }
        }
    }
}

// Handle Edit Bed
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_bed'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request.";
    } else {
        $room_id = $_POST['room_id'];
        $room_num = trim($_POST['room_number']);
        $type = $_POST['room_type'];
        $floor = trim($_POST['floor']);
        $ward = trim($_POST['ward']);
        $status = $_POST['status'];

        try {
            db_update('rooms', [
                'room_number' => $room_num,
                'room_type' => $type,
                'floor' => $floor,
                'ward' => $ward,
                'status' => $status
            ], ['id' => $room_id]);
            $success = "Bed updated successfully.";
        } catch (Exception $e) {
            $error = "Failed to update bed: " . $e->getMessage();
        }
    }
}

// Fetch all rooms
$rooms = db_select("SELECT * FROM rooms ORDER BY room_number");
?>

<div class="row">
    <!-- Add Bed Form (Admin/Nurse only) -->
    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'nurse'): ?>
    <div class="col-md-4 mb-4">
        <div class="card-header-styled">
            <i class="fas fa-bed"></i>
            <h2>Manage Beds</h2>
            <p>Add and track hospital beds.</p>
        </div>
        
        <div class="staff-form-container">
            <h4 class="mb-4"><i class="fas fa-plus-circle"></i> Add New Bed</h4>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="staff-grid-form" style="display: block;">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="add_bed" value="1">
                
                <div class="form-group mb-3">
                    <label>Bed / Room Number</label>
                    <input type="text" name="room_number" class="form-control" placeholder="e.g. B-101" required>
                </div>

                <div class="form-group mb-3">
                    <label>Type</label>
                    <select name="room_type" class="form-control">
                        <option value="General Ward">General Ward</option>
                        <option value="Private Room">Private Room</option>
                        <option value="ICU">ICU</option>
                        <option value="Emergency">Emergency</option>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label>Floor</label>
                    <input type="text" name="floor" class="form-control" placeholder="e.g. 1st Floor" required>
                </div>

                <div class="form-group mb-4">
                    <label>Ward / Department</label>
                    <input type="text" name="ward" class="form-control" placeholder="e.g. Cardiology Ward" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="width: 100%;">
                    <i class="fas fa-save"></i> Add Bed
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bed List -->
    <div class="<?php echo ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'nurse') ? 'col-md-8' : 'col-md-12'; ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3>Bed Status Overview</h3>
                <a href="admit_patient.php" class="btn btn-primary btn-sm"><i class="fas fa-procedures"></i> Admit Patient</a>
            </div>
            
            <div style="margin-bottom: 14px; padding: 20px 20px 0;">
                <input type="text" id="filter-beds" onkeyup="filterTable('filter-beds','tbl-beds')" placeholder="Search..." style="padding: 8px 14px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.88em; width: 260px; outline: none;">
            </div>
            <table id="tbl-beds" style="display:none;"></table>
            <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; padding: 20px;">
                <?php foreach ($rooms as $room): ?>
                    <?php 
                        $statusColor = '#2dce89'; // Available (Green)
                        $icon = 'fa-bed';
                        $statusText = 'Available';
                        
                        if ($room['status'] === 'occupied') {
                            $statusColor = '#f5365c'; // Occupied (Red)
                            $icon = 'fa-user-injured';
                            $statusText = 'Occupied';
                        } elseif ($room['status'] === 'maintenance') {
                            $statusColor = '#fb6340'; // Maintenance (Orange)
                            $icon = 'fa-tools';
                            $statusText = 'Maintenance';
                        }
                    ?>
                    <div class="card bed-card" style="text-align: center; border-top: 4px solid <?php echo $statusColor; ?>; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); position: relative;">
                        <!-- Action Buttons -->
                        <div class="bed-actions" style="position: absolute; top: 5px; right: 5px; display: flex; gap: 5px;">
                            <button onclick='openEditModal(<?php echo json_encode($room); ?>)' class="btn btn-sm text-primary" style="padding: 2px 5px;"><i class="fas fa-edit"></i></button>
                            <form method="POST" action="" onsubmit="return confirm('Permanently remove this bed?')" style="display:inline;">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="delete_bed" value="1">
                                <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                <button type="submit" class="btn btn-sm text-danger" style="padding: 2px 5px;"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </div>

                        <div style="font-size: 2em; color: <?php echo $statusColor; ?>; margin-bottom: 10px;">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <h4 style="margin: 0; font-weight: 700; color: #333;"><?php echo htmlspecialchars($room['room_number']); ?></h4>
                        <div style="font-size: 0.85em; color: #666; margin: 5px 0;">
                            <?php echo htmlspecialchars($room['room_type']); ?><br>
                            <?php echo htmlspecialchars($room['ward'] ?: '-'); ?> (<?php echo htmlspecialchars($room['floor'] ?: '-'); ?>)
                        </div>
                        <span class="badge" style="background: <?php echo $statusColor; ?>; color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.75em; margin-top: 5px;">
                            <?php echo $statusText; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* Reuse premium styles */
    .card-header-styled {
        background: linear-gradient(135deg, #89f7fe 0%, #66a6ff 100%); /* Blue gradient for beds */
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(102, 166, 255, 0.2);
    }
    .card-header-styled i { font-size: 40px; margin-bottom: 10px; opacity: 0.8; }
    .card-header-styled h2 { margin: 0; font-weight: 700; }
    
    .staff-form-container {
        background: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #344767;
    }
    .form-control {
        width: 100%;
        padding: 12px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        transition: all 0.2s;
        box-sizing: border-box; 
    }
    .form-control:focus {
        border-color: #66a6ff;
        outline: none;
    }
    
    /* Force full width for this page */
    .content-wrapper {
        max-width: 100% !important;
        width: 100% !important;
        padding-right: 30px;
    }
    .row {
        margin-right: 0;
        margin-left: 0;
    }
</style>

<!-- Edit Bed Modal -->
<div id="editBedModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: white; padding: 30px; border-radius: 15px; width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h4 class="mb-4">Edit Bed Details</h4>
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="edit_bed" value="1">
            <input type="hidden" name="room_id" id="edit_room_id">
            
            <div class="form-group mb-3">
                <label>Bed Number</label>
                <input type="text" name="room_number" id="edit_room_number" class="form-control" required>
            </div>
            <div class="form-group mb-3">
                <label>Type</label>
                <select name="room_type" id="edit_room_type" class="form-control">
                    <option value="General Ward">General Ward</option>
                    <option value="Private Room">Private Room</option>
                    <option value="ICU">ICU</option>
                    <option value="Emergency">Emergency</option>
                </select>
            </div>
            <div class="form-group mb-3">
                <label>Floor</label>
                <input type="text" name="floor" id="edit_floor" class="form-control" required>
            </div>
            <div class="form-group mb-3">
                <label>Ward</label>
                <input type="text" name="ward" id="edit_ward" class="form-control" required>
            </div>
            <div class="form-group mb-4">
                <label>Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Save Changes</button>
                <button type="button" onclick="closeEditModal()" class="btn btn-light">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(room) {
    document.getElementById('edit_room_id').value = room.id;
    document.getElementById('edit_room_number').value = room.room_number;
    document.getElementById('edit_room_type').value = room.room_type;
    document.getElementById('edit_floor').value = room.floor;
    document.getElementById('edit_ward').value = room.ward;
    document.getElementById('edit_status').value = room.status;
    document.getElementById('editBedModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editBedModal').style.display = 'none';
}

// Close on backdrop click
document.getElementById('editBedModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<style>
    .bed-card:hover .bed-actions { opacity: 1 !important; }
    .bed-actions { opacity: 0; transition: opacity 0.2s; }
    .btn-light { background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; }
</style>

<?php include '../../includes/footer.php'; ?>
