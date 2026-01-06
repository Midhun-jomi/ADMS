<?php
// modules/admin/edit_staff.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Edit Staff";
include '../../includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<div class='alert alert-danger'>Invalid Staff ID.</div>";
    include '../../includes/footer.php';
    exit;
}

// Fetch Staff & User Info
$staff = db_select_one("SELECT s.*, u.email FROM staff s JOIN users u ON s.user_id = u.id WHERE s.id = $1", [$id]);

if (!$staff) {
    echo "<div class='alert alert-danger'>Staff member not found.</div>";
    include '../../includes/footer.php';
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $specialization = $_POST['specialization'];
    
    try {
        // Update Staff Table
        db_update('staff', [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'role' => $role,
            'specialization' => $specialization,
            'status' => $_POST['status']
        ], ['id' => $id]);
        
        // Update Users Table (Email & Role)
        db_update('users', [
            'email' => $email,
            'role' => $role
        ], ['id' => $staff['user_id']]);
        
        $success = "Staff details updated successfully.";
        // Refresh data
        $staff = db_select_one("SELECT s.*, u.email FROM staff s JOIN users u ON s.user_id = u.id WHERE s.id = $1", [$id]);
        
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}
?>

<div class="card">
    <div class="card-header">
        Edit Staff Member
        <a href="staff_management.php" class="btn btn-sm" style="float: right; background: #6c757d; color: white;">Back</a>
    </div>
    <div class="card-body">
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>First Name</label>
                <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($staff['first_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Last Name</label>
                <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($staff['last_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($staff['email']); ?>" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" class="form-control">
                    <?php 
                    $roles = ['doctor', 'nurse', 'receptionist', 'pharmacist', 'lab_tech', 'radiologist', 'admin'];
                    foreach ($roles as $r) {
                        $selected = ($r == $staff['role']) ? 'selected' : '';
                        echo "<option value='$r' $selected>" . ucfirst($r) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Specialization</label>
                <select name="specialization" class="form-control">
                    <option value="">-- Select --</option>
                    <?php 
                    require_once '../../includes/specializations.php';
                    $specs = get_specializations();
                    foreach ($specs as $spec) {
                        $selected = ($spec == $staff['specialization']) ? 'selected' : '';
                        echo "<option value=\"" . htmlspecialchars($spec) . "\" $selected>" . htmlspecialchars($spec) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <?php 
                    $statuses = ['active', 'inactive', 'on_leave'];
                    foreach ($statuses as $st) {
                        $sel = ($st == $staff['status']) ? 'selected' : '';
                        echo "<option value='$st' $sel>" . ucfirst(str_replace('_', ' ', $st)) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Update Details</button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
