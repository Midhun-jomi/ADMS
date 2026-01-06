<?php
// modules/admin/nurse_allocation.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access Control
check_role(['admin', 'head_nurse']);

$page_title = "Nurse Allocation";
include '../../includes/header.php';

// Initialize variables for Edit Mode
$edit_mode = false;
$edit_data = [
    'id' => '',
    'nurse_id' => '',
    'doctor_id' => '',
    'department_id' => '',
    'shift' => ''
];

// Handle GET for Edit
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $fetched = db_select_one("SELECT * FROM nurse_allocations WHERE id = $1", [$edit_id]);
    if ($fetched) {
        $edit_mode = true;
        $edit_data = $fetched;
    }
}

// Handle Allocation (Insert or Update)
$msg = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['allocate'])) {
        $nurse_id = $_POST['nurse_id'];
        $doctor_id = $_POST['doctor_id'] ?: null;
        $department_id = $_POST['department_id'] ?: null;
        $shift = $_POST['shift'];
        $allocation_id = $_POST['allocation_id'] ?? null;
        
        try {
            if ($allocation_id) {
                // Update Existing
                db_query(
                    "UPDATE nurse_allocations SET nurse_id=$1, doctor_id=$2, department_id=$3, shift=$4, assigned_by=$5, updated_at=NOW() WHERE id=$6",
                    [$nurse_id, $doctor_id, $department_id, $shift, $_SESSION['user_id'], $allocation_id]
                );
                $msg = "Allocation Updated Successfully.";
            } else {
                // Insert New
                // Remove existing allocation for this shift to prevent duplicates/constraint error
                db_query("DELETE FROM nurse_allocations WHERE nurse_id = $1 AND shift = $2", [$nurse_id, $shift]);
                
                db_insert('nurse_allocations', [
                    'nurse_id' => $nurse_id,
                    'doctor_id' => $doctor_id,
                    'department_id' => $department_id,
                    'shift' => $shift,
                    'assigned_by' => $_SESSION['user_id']
                ]);
                $msg = "Nurse Allocated Successfully.";
            }
            $msg_type = "success";
            
            // Clear edit mode after save
            if ($edit_mode) {
                echo "<script>window.location.href = 'nurse_allocation.php';</script>";
                exit; 
            }
            
        } catch (Exception $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
    
    if (isset($_POST['delete_id'])) {
        db_query("DELETE FROM nurse_allocations WHERE id = $1", [$_POST['delete_id']]);
        $msg = "Allocation Removed.";
        $msg_type = "success";
    }
}

// Fetch Lists
$nurses = db_select("SELECT id, first_name, last_name FROM staff WHERE role = 'nurse' ORDER BY first_name");
$doctors = db_select("SELECT id, first_name, last_name, specialization FROM staff WHERE role = 'doctor' ORDER BY first_name");
$departments = db_select("SELECT id, name FROM departments WHERE status='active' ORDER BY name");

// Fetch Current Allocations
$allocations = db_select("
    SELECT na.id, na.shift, na.created_at,
           n.first_name as n_first, n.last_name as n_last,
           d.first_name as d_first, d.last_name as d_last, d.specialization,
           dept.name as dept_name
    FROM nurse_allocations na
    JOIN staff n ON na.nurse_id = n.id
    LEFT JOIN staff d ON na.doctor_id = d.id
    LEFT JOIN departments dept ON na.department_id = dept.id
    ORDER BY na.created_at DESC
");
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-user-check"></i> Nurse Allocation Management</h1>
        <p class="text-muted">Manage nursing staff duties, doctor assignments, and ward coverage.</p>
    </div>
    
    <?php if ($msg): ?> 
        <div class="alert alert-<?php echo $msg_type; ?> shadow-sm border-0"><?php echo $msg; ?></div> 
    <?php endif; ?>

    <div class="row">
        <!-- Application Form -->
        <div class="col-md-4">
            <div class="card premium-card">
                <div class="card-header bg-gradient-primary text-white">
                    <i class="fas fa-plus-circle"></i> <?php echo $edit_mode ? 'Edit Allocation' : 'Assign Nurse'; ?>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="allocation_id" value="<?php echo $edit_data['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-uppercase text-xs" style="font-size: 0.8rem; color: #8898aa;">Select Nurse <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fas fa-user-nurse"></i></span>
                                </div>
                                <select name="nurse_id" class="form-control" required>
                                    <option value="">-- Choose Nurse --</option>
                                    <?php foreach ($nurses as $n): ?>
                                        <option value="<?php echo $n['id']; ?>" <?php echo ($edit_data['nurse_id'] == $n['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($n['first_name'] . ' ' . $n['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-uppercase text-xs" style="font-size: 0.8rem; color: #8898aa;">Assign to Doctor</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fas fa-user-md"></i></span>
                                </div>
                                <select name="doctor_id" class="form-control">
                                    <option value="">-- None --</option>
                                    <?php foreach ($doctors as $d): ?>
                                        <option value="<?php echo $d['id']; ?>" <?php echo ($edit_data['doctor_id'] == $d['id']) ? 'selected' : ''; ?>>
                                            Dr. <?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name'] . ' (' . $d['specialization'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-uppercase text-xs" style="font-size: 0.8rem; color: #8898aa;">Assign to Ward</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fas fa-building"></i></span>
                                </div>
                                <select name="department_id" class="form-control">
                                    <option value="">-- None --</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo ($edit_data['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-uppercase text-xs" style="font-size: 0.8rem; color: #8898aa;">Shift <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fas fa-clock"></i></span>
                                </div>
                                <select name="shift" class="form-control" required>
                                    <option value="Morning" <?php echo ($edit_data['shift'] == 'Morning') ? 'selected' : ''; ?>>Morning (8AM - 4PM)</option>
                                    <option value="Evening" <?php echo ($edit_data['shift'] == 'Evening') ? 'selected' : ''; ?>>Evening (4PM - 12AM)</option>
                                    <option value="Night" <?php echo ($edit_data['shift'] == 'Night') ? 'selected' : ''; ?>>Night (12AM - 8AM)</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" name="allocate" class="btn btn-primary btn-block btn-lg shadow-hover">
                            <?php echo $edit_mode ? 'Update Allocation' : 'Allocate Nurse'; ?>
                        </button>
                        
                        <?php if ($edit_mode): ?>
                            <a href="nurse_allocation.php" class="btn btn-outline-secondary btn-block btn-sm mt-3 border-0">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- List -->
        <div class="col-md-8">
            <div class="card premium-card">
                <div class="card-header border-0 bg-white">
                    <h5 class="mb-0" style="color: #32325d;">Current Allocations</h5>
                </div>
                <div class="table-responsive">
                    <table class="table align-items-center table-flush table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Nurse</th>
                                <th>Shift</th>
                                <th>Assignment Details</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocations as $a): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar rounded-circle mr-3 bg-soft-primary text-primary">
                                            <?php echo strtoupper(substr($a['n_first'], 0, 1) . substr($a['n_last'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <span class="d-block font-weight-bold text-dark"><?php echo htmlspecialchars($a['n_first'] . ' ' . $a['n_last']); ?></span>
                                            <small class="text-muted">ID: #<?php echo substr($a['id'], 0, 6); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        $badge_class = 'primary';
                                        $icon = 'sun';
                                        if ($a['shift'] == 'Evening') { $badge_class = 'warning'; $icon = 'cloud-sun'; }
                                        if ($a['shift'] == 'Night') { $badge_class = 'dark'; $icon = 'moon'; }
                                    ?>
                                    <span class="badge badge-pill badge-<?php echo $badge_class; ?> text-uppercase">
                                        <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo $a['shift']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($a['d_first']): ?>
                                        <div class="allocation-tag text-primary">
                                            <i class="fas fa-user-md mr-1"></i> 
                                            <strong>Dr. <?php echo htmlspecialchars($a['d_first'] . ' ' . $a['d_last']); ?></strong>
                                            <div class="small text-muted pl-3"><?php echo htmlspecialchars($a['specialization']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($a['dept_name']): ?>
                                        <div class="allocation-tag text-success mt-1">
                                            <i class="fas fa-building mr-1"></i> 
                                            Ward: <strong><?php echo htmlspecialchars($a['dept_name']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!$a['d_first'] && !$a['dept_name']): ?>
                                        <span class="text-muted font-italic">General Duty</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <div class="d-flex justify-content-end">
                                        <a href="?edit_id=<?php echo $a['id']; ?>" class="btn btn-icon btn-sm btn-outline-primary mr-2" data-toggle="tooltip" title="Edit Allocation">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to remove this allocation?');">
                                            <input type="hidden" name="delete_id" value="<?php echo $a['id']; ?>">
                                            <button class="btn btn-icon btn-sm btn-outline-danger" data-toggle="tooltip" title="Remove Allocation">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($allocations)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-user-slash fa-3x mb-3" style="opacity: 0.3;"></i>
                                            <p>No active allocations found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Premium UI Overrides */
.premium-card {
    border: none;
    border-radius: 1rem;
    box-shadow: 0 15px 35px rgba(50, 50, 93, 0.1), 0 5px 15px rgba(0, 0, 0, 0.07);
    transition: all 0.3s ease;
    background: #fff;
    overflow: hidden;
    margin-bottom: 30px;
}

.card-header.bg-gradient-primary {
    background: linear-gradient(87deg, #5e72e4 0, #825ee4 100%) !important;
    padding: 1.5rem !important; /* Fix squashed header */
}

/* Fix Input Group Flex Alignment */
.input-group {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    width: 100%;
    margin-bottom: 1rem; /* Spacing below inputs */
}

.input-group-prepend {
    margin-right: -1px;
    display: flex;
}

.input-group-text {
    display: flex;
    align-items: center;
    padding: 0.625rem 0.75rem;
    margin-bottom: 0;
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.5;
    color: #8898aa;
    text-align: center;
    white-space: nowrap;
    background-color: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem 0 0 0.375rem;
    border-right: none; /* Merge with input */
}

.form-control {
    position: relative;
    flex: 1 1 auto;
    width: 1%;
    min-width: 0;
    margin-bottom: 0;
    height: auto !important; /* Remove fixed height causing overlap */
    padding: 0.625rem 0.75rem;
    border: 1px solid #dee2e6;
    border-radius: 0 0.375rem 0.375rem 0;
    box-shadow: none;
    transition: all 0.15s ease;
}

.form-control:focus {
    border-color: #5e72e4;
    box-shadow: 0 0 0 0.2rem rgba(94, 114, 228, 0.25);
    z-index: 3;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #525f7f;
}

.shadow-hover:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 14px rgba(50, 50, 93, 0.1), 0 3px 6px rgba(0, 0, 0, 0.08);
}

/* Table Enhancements */
.table thead th {
    background-color: #f6f9fc;
    color: #8898aa;
    border-bottom: 1px solid #e9ecef;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 1px;
    padding: 1rem;
    border-top: none;
}

.table td {
    vertical-align: middle;
    padding: 1.2rem 1rem;
    border-top: 1px solid #e9ecef;
}

.avatar {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
}

.bg-soft-primary {
    background-color: rgba(94, 114, 228, 0.1) !important;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.text-xs {
    font-size: 0.75rem !important;
}
</style>

<?php include '../../includes/footer.php'; ?>
