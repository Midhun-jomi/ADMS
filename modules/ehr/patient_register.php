<?php
// modules/ehr/patient_register.php
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_role(['admin', 'doctor', 'receptionist']);

$page_title = "Register New Patient";
include '../../includes/header.php';

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $password = $_POST['password']; // In real app, auto-generate or email link
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $blood_group = $_POST['blood_group'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $medical_history = $_POST['medical_history'];

    // Check if email exists
    $existing_user = db_select_one("SELECT id FROM users WHERE email = $1", [$email]);
    if ($existing_user) {
        $error = "Email already registered.";
    } else {
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Begin transaction (simulated)
        // Insert into users
        $sql_user = "INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id";
        try {
            $result = db_query($sql_user, [$email, $password_hash, 'patient']);
            $user_row = pg_fetch_assoc($result);
            $user_id = $user_row['id'];

            if ($user_id) {
                // Insert into patients
                $patient_data = [
                    'user_id' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'date_of_birth' => $dob,
                    'gender' => $gender,
                    'blood_group' => $blood_group,
                    'phone' => $phone,
                    'address' => $address,
                    'medical_history' => $medical_history
                ];
                db_insert('patients', $patient_data);
                
                log_audit($_SESSION['user_id'], 'REGISTER_PATIENT', "Registered patient: $first_name $last_name");
                
                $success = "Patient registered successfully!";
            }
        } catch (Exception $e) {
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}
?>

<div class="card">
    <div class="card-header">Patient Registration Form</div>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-row" style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 1;">
                <label for="first_name">First Name</label>
                <input type="text" id="first_name" name="first_name" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="last_name">Last Name</label>
                <input type="text" id="last_name" name="last_name" required>
            </div>
        </div>

        <div class="form-row" style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 1;">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="password">Temporary Password</label>
                <input type="text" id="password" name="password" value="Patient123!" required>
            </div>
        </div>

        <div class="form-row" style="display: flex; gap: 20px;">
            <div class="form-group" style="flex: 1;">
                <label for="dob">Date of Birth</label>
                <input type="date" id="dob" name="dob" required>
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label>Gender</label>
                <select name="gender" class="form-control" required>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label>Blood Group</label>
                <select name="blood_group" class="form-control">
                    <option value="">Unknown</option>
                    <option value="A+">A+</option>
                    <option value="A-">A-</option>
                    <option value="B+">B+</option>
                    <option value="B-">B-</option>
                    <option value="AB+">AB+</option>
                    <option value="AB-">AB-</option>
                    <option value="O+">O+</option>
                    <option value="O-">O-</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label>Phone</label>
                <input type="text" name="phone" class="form-control">
            </div>
        </div>

        <div class="form-group">
            <label for="address">Address</label>
            <textarea id="address" name="address" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-group">
            <label for="medical_history">Initial Medical History</label>
            <textarea id="medical_history" name="medical_history" class="form-control" rows="3"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Register Patient</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
