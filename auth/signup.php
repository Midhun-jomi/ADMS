<?php
// auth/signup.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF check
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $first_name = trim($_POST['first_name']);
        $last_name  = trim($_POST['last_name']);
        $email      = trim($_POST['email']);
        $password   = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $dob        = $_POST['dob'];
        $gender     = $_POST['gender'] ?? '';
        $phone      = trim($_POST['phone'] ?? '');

        if (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Check if email exists
            $existing_user = db_select_one("SELECT id FROM users WHERE email = $1", [$email]);
            if ($existing_user) {
                $error = "Email already registered.";
            } else {
                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Insert into users with RETURNING id
                $sql = "INSERT INTO users (email, password_hash, role) VALUES ($1, $2, $3) RETURNING id";
                $result = db_query($sql, [$email, $password_hash, 'patient']);
                $user_row = pg_fetch_assoc($result);
                $user_id = $user_row['id'];

                if ($user_id) {
                    // Insert into patients
                    $patient_data = [
                        'user_id'       => $user_id,
                        'first_name'    => $first_name,
                        'last_name'     => $last_name,
                        'date_of_birth' => $dob,
                        'gender'        => $gender,
                        'phone'         => $phone
                    ];
                    db_insert('patients', $patient_data);

                    $success = "Registration successful! You can now login.";
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Signup - Hospital Management System</title>
    <link rel="stylesheet" href="../assets/css/login_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-wrapper">
        <div class="login-left">
            <div class="circle-deco circle-1"></div>
            <div class="circle-deco circle-2"></div>
            <div class="circle-deco circle-3"></div>
            <div class="login-left-content">
                <h1>JOIN US <span style="color: #00cba9;">!</span></h1>
                <p>Create your account to access healthcare services</p>
            </div>
            <img src="../assets/images/doctor_3d.png" alt="Doctor" class="doctor-img">
        </div>

        <div class="login-right" style="overflow-y: auto;">
            <div class="login-logo">
                <span>ADMS</span> Hospital
            </div>

            <h3>Create Patient Account</h3>

            <?php if ($error): ?>
                <div class="error-text"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($success); ?></div>
                <p style="text-align: center;"><a href="../index.php" class="login-btn" style="display: block; text-decoration: none;">Proceed to Login</a></p>
            <?php else: ?>

            <form method="POST" action="">
                <?php echo csrf_input(); ?>
                <div class="form-grid" style="margin-bottom: 20px;">
                    <div class="login-form-group">
                        <label for="first_name" class="login-label">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="login-input" required placeholder="John">
                    </div>
                    <div class="login-form-group">
                        <label for="last_name" class="login-label">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="login-input" required placeholder="Doe">
                    </div>
                </div>

                <div class="form-grid" style="margin-bottom: 20px;">
                    <div class="login-form-group">
                        <label for="email" class="login-label">Email Address</label>
                        <input type="email" id="email" name="email" class="login-input" required placeholder="john@example.com">
                    </div>
                    <div class="login-form-group">
                        <label for="phone" class="login-label">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="login-input" placeholder="+91 00000 00000">
                    </div>
                </div>

                <div class="form-grid" style="margin-bottom: 20px;">
                    <div class="login-form-group">
                        <label for="dob" class="login-label">Date of Birth</label>
                        <input type="date" id="dob" name="dob" class="login-input" required>
                    </div>
                    <div class="login-form-group">
                        <label for="gender" class="login-label">Gender</label>
                        <select name="gender" id="gender" class="login-input" required>
                            <option value="" disabled selected>Select gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid" style="margin-bottom: 20px;">
                    <div class="login-form-group">
                        <label for="password" class="login-label">Password</label>
                        <input type="password" id="password" name="password" class="login-input" required minlength="8" placeholder="********">
                    </div>
                    <div class="login-form-group">
                        <label for="confirm_password" class="login-label">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="login-input" required minlength="8" placeholder="********">
                    </div>
                </div>

                <button type="submit" class="login-btn">Create Account</button>
            </form>

            <div class="login-links">
                <div class="signup-text">
                    Already have an account? <a href="../index.php">Login here</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
