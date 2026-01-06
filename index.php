<?php
// index.php
require_once 'includes/db.php';
require_once 'includes/auth_session.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $user = db_select_one("SELECT * FROM users WHERE email = $1", [$email]);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Login Success
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['email'] = $user['email'];

        // Redirect based on role
        if ($user['role'] === 'nurse') {
            header("Location: modules/patient_management/nursing_station.php");
        } elseif ($user['role'] === 'head_nurse') {
            header("Location: modules/admin/nurse_allocation.php");
        } else {
            header("Location: dashboards/" . $user['role'] . "_dashboard.php");
        }
        exit();
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Management System - Login</title>
    <link rel="stylesheet" href="assets/css/login_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-wrapper">
        <div class="login-left">
            <div class="circle-deco circle-1"></div>
            <div class="circle-deco circle-2"></div>
            <div class="circle-deco circle-3"></div>
            
            <div class="login-left-content">
                <h1>HELLO <span style="color: #00cba9;">!</span></h1>
                <p>Please enter your details to continue</p>
            </div>
            <img src="assets/images/doctor_3d.png" alt="Doctor" class="doctor-img">
        </div>
        
        <div class="login-right">
            <div class="login-logo">
                <span>ADMS</span> Hospital
            </div>

            <?php if ($error): ?>
                <div class="error-text" style="text-align: center; margin-bottom: 15px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="login-form-group">
                    <label for="email" class="login-label">Username or E-mail</label>
                    <input type="email" id="email" name="email" class="login-input <?php echo $error ? 'login-input-error' : ''; ?>" placeholder="name@email.com" required>
                </div>

                <div class="login-form-group">
                    <label for="password" class="login-label">Password</label>
                    <input type="password" id="password" name="password" class="login-input <?php echo $error ? 'login-input-error' : ''; ?>" placeholder="********" required>
                    <?php if ($error): ?>
                        <span class="error-text">The username or password is incorrect</span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="login-btn">Log in</button>
            </form>

            <div class="login-links">
                <a href="auth/forgot_password.php">Forget Password?</a>
                <div class="signup-text">
                    Do Not Have Account? <a href="auth/signup.php">Sign Up</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
