<?php
// modules/admin/staff_management.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin']);

$page_title = "Staff Management";
include '../../includes/header.php';

$error = '';
$success = '';

// Handle Add Staff
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $role_sel = $_POST['role'];
    $specialization = $_POST['specialization'];
    $password = $_POST['password'];

    // Check email
    $exists = db_select_one("SELECT id FROM users WHERE email = $1", [$email]);
    if ($exists) {
        $error = "Email already exists.";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert User
        $sql_user = "INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id";
        try {
            $res = db_query($sql_user, [$email, $password_hash, $role_sel]);
            $user_row = pg_fetch_assoc($res);
            $user_id = $user_row['id'];
            
            // Insert Staff
            $staff_data = [
                'user_id' => $user_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'role' => $role_sel,
                'specialization' => $specialization,
                'department' => 'General' // Default for now
            ];
            db_insert('staff', $staff_data);
            $success = "Staff member added successfully.";
        } catch (Exception $e) {
            $error = "Failed to add staff: " . $e->getMessage();
        }
    }
}

// Fetch Staff
$staff_list = db_select("SELECT * FROM staff ORDER BY role, last_name");
?>

    <div class="row">
        <!-- Add Staff Form -->
        <div class="col-md-12 mb-4">
            <div class="card-header-styled">
                <i class="fas fa-user-md"></i>
                <h2>Manage Staff</h2>
                <p>Add and manage doctors, nurses, and administrative staff.</p>
            </div>
            
            <div class="staff-form-container">
                <h4 class="mb-4"><i class="fas fa-plus-circle"></i> Add New Staff Member</h4>
                <form method="POST" action="" class="staff-grid-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" class="form-control" placeholder="First Name" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" class="form-control" placeholder="Last Name" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="name@hospital.com" required>
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <select name="role" class="form-control" required>
                                <option value="doctor">Doctor</option>
                                <option value="nurse">Nurse</option>
                                <option value="receptionist">Receptionist</option>
                                <option value="pharmacist">Pharmacist</option>
                                <option value="lab_tech">Lab Tech</option>
                                <option value="radiologist">Radiologist</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Specialization</label>
                            <select name="specialization" class="form-control">
                                <option value="">-- Consulant / Specialist --</option>
                                <?php 
                                require_once '../../includes/specializations.php';
                                $specs = get_specializations();
                                foreach ($specs as $spec) {
                                    echo "<option value=\"" . htmlspecialchars($spec) . "\">" . htmlspecialchars($spec) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Default Password</label>
                            <input type="text" name="password" class="form-control" value="Staff123!" required>
                        </div>
                    </div>

                    <div class="form-actions text-right">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus"></i> Add Staff Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Staff List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3>Staff Directory</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr style="background-color: #f8f9fa;">
                        <th style="padding: 15px;">Name</th>
                        <th style="padding: 15px;">Role</th>
                        <th style="padding: 15px;">Specialization</th>
                        <th style="padding: 15px;">Status</th>
                        <th style="padding: 15px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_list as $s): ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 15px; font-weight: 600;">
                                <?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>
                            </td>
                            <td style="padding: 15px;">
                                <span class="badge badge-<?php echo ($s['role']=='doctor')?'primary':(($s['role']=='admin')?'danger':'info'); ?>">
                                    <?php echo ucfirst(htmlspecialchars($s['role'])); ?>
                                </span>
                            </td>
                            <td style="padding: 15px;color: #666;"><?php echo htmlspecialchars($s['specialization'] ?: '-'); ?></td>
                            <td style="padding: 15px;">
                                <?php 
                                $status_color = '#6c757d'; // Default gray
                                if ($s['status'] === 'active') $status_color = '#2dce89'; // Green
                                elseif ($s['status'] === 'inactive') $status_color = '#f5365c'; // Red
                                elseif ($s['status'] === 'on_leave') $status_color = '#fb6340'; // Orange
                                ?>
                                <span class="badge" style="background: rgba(0,0,0,0.05); color: #333; padding: 8px 12px; border-radius: 20px; font-weight: 600; text-transform: capitalize;">
                                    <span style="display: inline-block; width: 10px; height: 10px; background-color: <?php echo $status_color; ?>; border-radius: 50%; margin-right: 6px;"></span>
                                    <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($s['status']))); ?>
                                </span>
                            </td>
                            <td style="padding: 15px;">
                                <a href="edit_staff.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    /* Reuse premium styles */
    .card-header-styled {
        background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(37, 117, 252, 0.2);
    }
    .card-header-styled i { font-size: 40px; margin-bottom: 10px; opacity: 0.8; }
    .card-header-styled h2 { margin: 0; font-weight: 700; }
    
    .staff-form-container {
        background: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    }
    
    .staff-grid-form .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        margin-bottom: 25px;
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
        box-sizing: border-box; /* Fix overlay overlap */
    }
    .form-control:focus {
        border-color: #2575fc;
        outline: none;
    }
    
    .status-active { color: #2dce89; font-weight: 600; font-size: 0.9em; }
    
    @media (max-width: 768px) {
        .staff-grid-form .form-row { grid-template-columns: 1fr; }
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
