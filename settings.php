<?php
// settings.php
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_auth();

$page_title = "Account Settings";
include 'includes/header.php';

$user_id = get_user_id();
$role = get_user_role();
$email = $_SESSION['email'];

$success = '';
$error = '';


// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check for Max Size Violation
    if (empty($_POST) && empty($_FILES) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $error = "The uploaded file exceeds the post_max_size directive in php.ini (Limit is " . ini_get('post_max_size') . ").";
    }
    
    // Check for Upload Max Filesize Violation in specific file
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_INI_SIZE) {
        $error = "The uploaded file exceeds the upload_max_filesize directive in php.ini (Limit is " . ini_get('upload_max_filesize') . ").";
    }

    if (isset($_POST['update_profile']) && empty($error)) {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $phone = $_POST['phone'];
    $address = $_POST['address'] ?? ''; // Only patients usually have address in schema
    
    // Handle File Upload
    if (isset($_FILES['profile_image'])) {
        error_log("DEBUG: File upload initiated. Name: " . $_FILES['profile_image']['name']);
        if (!empty($_FILES['profile_image']['name'])) {
            if ($_FILES['profile_image']['error'] == 0) {
                // Use absolute path for upload dir
                $upload_dir = __DIR__ . '/assets/uploads/profiles/'; 
                error_log("DEBUG: Upload dir: " . $upload_dir);

                if (!is_dir($upload_dir)) {
                    error_log("DEBUG: Directory missing, attempting create.");
                    if (!mkdir($upload_dir, 0777, true)) {
                        $error = "Failed to create upload directory.";
                        error_log("DEBUG: Failed to create directory.");
                    }
                }
                
                if (empty($error)) {
                    $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($ext, $allowed)) {
                        $filename = "profile_" . $user_id . "." . $ext;
                        $target_file = $upload_dir . $filename;
                        error_log("DEBUG: Target file: " . $target_file);
                        
                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                            // Success - Update DB
                            $db_path = "/assets/uploads/profiles/" . $filename;
                            error_log("DEBUG: Move success. Updating DB with path: " . $db_path);
                            db_update('users', ['profile_image' => $db_path], ['id' => $user_id]);
                        } else {
                            $error = "Failed to move uploaded file. Check directory permissions.";
                            error_log("DEBUG: move_uploaded_file failed. Error: " . print_r(error_get_last(), true));
                        }
                    } else {
                        $error = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
                        error_log("DEBUG: Invalid file extension: " . $ext);
                    }
                }
            } else {
                $error = "File upload error code: " . $_FILES['profile_image']['error'];
                error_log("DEBUG: Upload error code: " . $_FILES['profile_image']['error']);
            }
        } else {
            error_log("DEBUG: Empty file name.");
        }
    } else {
        error_log("DEBUG: No profile_image in FILES.");
    }

    try {
        if ($role === 'patient') {
            db_query("UPDATE patients SET first_name = $1, last_name = $2, phone = $3, address = $4 WHERE user_id = $5", 
                     [$first_name, $last_name, $phone, $address, $user_id]);
        } else {
            // Staff
            db_query("UPDATE staff SET first_name = $1, last_name = $2, phone = $3 WHERE user_id = $4", 
                     [$first_name, $last_name, $phone, $user_id]);
        }
        $success = "Profile updated successfully.";
    } catch (Exception $e) {
        $error = "Failed to update profile: " . $e->getMessage();
    }
    }
}

// Fetch current details
$user_details = [];
if ($role === 'patient') {
    $user_details = db_select_one("SELECT * FROM patients WHERE user_id = $1", [$user_id]);
} else {
    $user_details = db_select_one("SELECT * FROM staff WHERE user_id = $1", [$user_id]);
}

// Check for profile image
$profile_img = "https://ui-avatars.com/api/?name=" . urlencode($email) . "&background=random";
$user_rec = db_select_one("SELECT profile_image FROM users WHERE id = $1", [$user_id]);
if ($user_rec && !empty($user_rec['profile_image'])) {
    $profile_img = $user_rec['profile_image'] . "?t=" . time();
}
?>

<div class="card">
    <div class="card-header">
        <i class="fas fa-user-cog"></i> Account Settings
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="form-row" style="display: flex; gap: 40px; margin-top: 20px; flex-wrap: wrap;">
        <!-- Profile Info -->
        <div style="flex: 1; min-width: 300px;">
            <h5 style="border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 20px;">Profile Information</h5>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="update_profile" value="1">
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
                    <img src="<?php echo $profile_img; ?>" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #eee;">
                    <div>
                        <label class="btn btn-outline-primary btn-sm" style="display: inline-block; cursor: pointer;">
                            <i class="fas fa-camera"></i> Change Photo
                            <input type="file" name="profile_image" style="display: none;" accept="image/*" onchange="this.form.submit();"> <!-- Auto submit on file select for preview feel? Or just rely on main update -->
                        </label>
                        <div style="font-size: 0.8em; color: #888; margin-top: 5px;">JPG, PNG. Max 2MB.</div>
                    </div>
                </div>

                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user_details['first_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user_details['last_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user_details['phone'] ?? ''); ?>">
                </div>
                <?php if ($role === 'patient'): ?>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($user_details['address'] ?? ''); ?></textarea>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($email); ?>" disabled style="background: #f9fafb; color: #6b7280;">
                    <small style="color: #9ca3af;">Email cannot be changed directly.</small>
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary" style="margin-top: 10px;">Save Profile Changes</button>
            </form>
        </div>

        <!-- Security -->
        <div style="flex: 1; min-width: 300px; border-left: 1px solid #eee; padding-left: 40px;">
            <h5 style="border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 20px;">Security</h5>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                <button type="submit" name="change_password" class="btn btn-outline-danger" style="margin-top: 10px;">Update Password</button>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
