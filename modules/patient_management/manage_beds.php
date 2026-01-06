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
                    <div class="card" style="text-align: center; border-top: 4px solid <?php echo $statusColor; ?>; padding: 15px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                        <div style="font-size: 2em; color: <?php echo $statusColor; ?>; margin-bottom: 10px;">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <h4 style="margin: 0; font-weight: 700; color: #333;"><?php echo htmlspecialchars($room['room_number']); ?></h4>
                        <div style="font-size: 0.85em; color: #666; margin: 5px 0;">
                            <?php echo htmlspecialchars($room['ward'] ?: '-'); ?><br>
                            <?php echo htmlspecialchars($room['floor'] ?: '-'); ?>
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

<?php include '../../includes/footer.php'; ?>
