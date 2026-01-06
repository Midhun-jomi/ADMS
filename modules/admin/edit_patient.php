<?php
// modules/admin/edit_patient.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Edit Patient";
include '../../includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "<div class='alert alert-danger'>Invalid Patient ID.</div>";
    include '../../includes/footer.php';
    exit;
}

// Fetch Patient & User Info
$patient = db_select_one("SELECT p.*, u.email FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = $1", [$id]);

if (!$patient) {
    echo "<div class='alert alert-danger'>Patient not found.</div>";
    include '../../includes/footer.php';
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $blood_group = $_POST['blood_group'];
    $gender = $_POST['gender'];
    $dob = $_POST['date_of_birth'];
    $address = $_POST['address'];
    
    try {
        // Update Patient Table
        db_update('patients', [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'blood_group' => $blood_group,
            'gender' => $gender,
            'date_of_birth' => $dob,
            'address' => $address
        ], ['id' => $id]);
        
        // Update Users Table (Email)
        db_update('users', [
            'email' => $email
        ], ['id' => $patient['user_id']]);
        
        $success = "Patient details updated successfully.";
        // Refresh data
        $patient = db_select_one("SELECT p.*, u.email FROM patients p JOIN users u ON p.user_id = u.id WHERE p.id = $1", [$id]);
        
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}
?>

<div class="card">
    <div class="card-header">
        Edit Patient
        <a href="../ehr/patients.php" class="btn btn-sm" style="float: right; background: #6c757d; color: white;">Back</a>
    </div>
    <div class="card-body">
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST" action="">
            <div style="display: flex; gap: 20px;">
                <div style="flex: 1;">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($patient['first_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($patient['last_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($patient['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($patient['phone']); ?>">
                    </div>
                </div>
                <div style="flex: 1;">
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?php echo $patient['date_of_birth']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" class="form-control">
                            <option value="Male" <?php if($patient['gender'] == 'Male') echo 'selected'; ?>>Male</option>
                            <option value="Female" <?php if($patient['gender'] == 'Female') echo 'selected'; ?>>Female</option>
                            <option value="Other" <?php if($patient['gender'] == 'Other') echo 'selected'; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Blood Group</label>
                        <select name="blood_group" class="form-control">
                            <?php 
                            $bgs = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                            foreach ($bgs as $bg) {
                                $sel = ($bg == $patient['blood_group']) ? 'selected' : '';
                                echo "<option value='$bg' $sel>$bg</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" class="form-control"><?php echo htmlspecialchars($patient['address']); ?></textarea>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Update Patient</button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
