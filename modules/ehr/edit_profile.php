<?php

require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';

// Access: Admin, Patient
$allowed_roles = ['admin', 'patient'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: /index.php");
    exit();
}

$page_title = "Edit Profile";
require_once '../../includes/header.php';

$success_msg = '';
$error_msg = '';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$patient_id = null;

// Determine Patient ID
if ($role === 'patient') {
    // Patients can only edit themselves
    $res = db_select_one("SELECT id FROM patients WHERE user_id = $1", [$user_id]);
    $patient_id = $res['id'] ?? null;
} elseif ($role === 'admin') {
    // Admins can edit anyone passed in ID
    $patient_id = $_GET['id'] ?? null;
}

if (!$patient_id) {
    echo "<div class='main-content'><div class='alert alert-danger'>Patient not found.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

// Fetch existing data
$patient = db_select_one("SELECT * FROM patients WHERE id = $1", [$patient_id]);

if (!$patient) {
    echo "<div class='main-content'><div class='alert alert-danger'>Patient record not found.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $emergency_contact = $_POST['emergency_contact'];
    // Admin can edit Blood Group, Patient cannot (usually)
    $blood_group = ($role === 'admin') ? $_POST['blood_group'] : $patient['blood_group'];
    
    // Update DB
    $gender = $_POST['gender'];
    // Update DB
    $sql = "UPDATE patients SET first_name = $1, last_name = $2, phone = $3, address = $4, emergency_contact = $5, blood_group = $6, gender = $7 WHERE id = $8";
    try {
        db_query($sql, [$first_name, $last_name, $phone, $address, $emergency_contact, $blood_group, $gender, $patient_id]);
        $success_msg = "Profile updated successfully!";
        // Refresh data
        $patient = db_select_one("SELECT * FROM patients WHERE id = $1", [$patient_id]);
    } catch (Exception $e) {
        $error_msg = "Update failed: " . $e->getMessage();
    }
    // Include redirect logic
    $redirect_url = ($role === 'admin') ? 'patients.php' : '/dashboards/patient_dashboard.php';
    if ($success_msg) {
        echo "<script>setTimeout(function(){ window.location.href = '$redirect_url'; }, 3000);</script>";
    }
}
?>

<div class="main-content">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card profile-card">
                <div class="card-header-styled">
                    <div style="flex:1; display:flex; align-items:center; gap:20px;">
                        <div class="header-icon">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div>
                            <h2>Edit Profile</h2>
                            <p>Update personal information for <strong><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></strong></p>
                        </div>
                    </div>
                    <?php if ($role === 'admin'): ?>
                        <a href="patients.php" class="back-link"><i class="fas fa-arrow-left"></i> Back</a>
                    <?php else: ?>
                        <a href="/dashboards/patient_dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back</a>
                    <?php endif; ?>
                </div>

                <?php if ($success_msg): ?> 
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                        <br><small>Redirecting you back in 3 seconds...</small>
                    </div> 
                <?php endif; ?>
                <?php if ($error_msg): ?> <div class="alert alert-danger"><?php echo $error_msg; ?></div> <?php endif; ?>

                <form method="POST" class="styled-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> First Name</label>
                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($patient['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Last Name</label>
                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($patient['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone Number</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($patient['phone']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-venus-mars"></i> Gender</label>
                            <select name="gender" required>
                                <option value="">-- Select --</option>
                                <?php 
                                $genders = ['Male', 'Female', 'Other'];
                                foreach ($genders as $g) {
                                    $sel = ($patient['gender'] == $g) ? 'selected' : '';
                                    echo "<option value='$g' $sel>$g</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-tint"></i> Blood Group</label>
                            <?php if ($role === 'admin'): ?>
                                <select name="blood_group">
                                    <?php 
                                    $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                                    foreach ($groups as $g) {
                                        $sel = ($patient['blood_group'] == $g) ? 'selected' : '';
                                        echo "<option value='$g' $sel>$g</option>";
                                    }
                                    ?>
                                </select>
                            <?php else: ?>
                                <input type="text" value="<?php echo htmlspecialchars($patient['blood_group']); ?>" disabled style="background: #f8f9fa;">
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone-square-alt"></i> Emergency Contact</label>
                            <input type="text" name="emergency_contact" value="<?php echo htmlspecialchars($patient['emergency_contact'] ?? ''); ?>" placeholder="Name & Phone">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <textarea name="address" rows="3"><?php echo htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary btn-lg">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <?php if ($role === 'admin'): ?>
                            <a href="patients.php" class="btn-secondary">Cancel</a>
                        <?php else: ?>
                            <a href="/dashboards/patient_dashboard.php" class="btn-secondary">Back to Dashboard</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .profile-card {
        border: none;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .card-header-styled {
        background: linear-gradient(135deg, var(--primary-color) 0%, #3498db 100%);
        color: white;
        padding: 30px;
        display: flex;
        align-items: center;
        gap: 20px;
    }
    .header-icon {
        background: rgba(255,255,255,0.2);
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        backdrop-filter: blur(5px);
    }
    .back-link { color: rgba(255,255,255,0.8); text-decoration: none; font-size: 1.1rem; transition: color 0.2s; }
    .back-link:hover { color: white; }
    
    .card-header-styled h2 { margin: 0 0 5px 0; font-size: 24px; }
    .card-header-styled p { margin: 0; opacity: 0.9; font-size: 14px; }
    
    .styled-form { padding: 30px; }
    .styled-form * { box-sizing: border-box; } /* Prevent padding from breaking layout */
    
    .form-row { 
        display: grid; 
        grid-template-columns: 1fr 1fr; 
        gap: 30px; /* Increased from 20px */
        margin-bottom: 25px; /* Increased from 20px */
    }
    @media(max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
    
    
    .form-group {
        margin-bottom: 25px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #2c3e50;
        font-size: 0.9rem;
    }
    .form-group label i { color: var(--primary-color); margin-right: 5px; width: 16px; text-align: center; }
    
    .form-group input, .form-group select, .form-group textarea {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        outline: none;
    }
    
    .form-actions {
        margin-top: 30px;
        display: flex;
        gap: 15px;
        align-items: center;
    }
    .btn-lg { padding: 12px 30px; font-size: 1.1rem; }
</style>

<?php require_once '../../includes/footer.php'; ?>
