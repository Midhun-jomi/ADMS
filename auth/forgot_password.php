<?php
// auth/forgot_password.php
session_start();
$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Placeholder for password reset logic
    $email = $_POST['email'];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $success = "If an account exists for this email, a reset link will be sent.";
    } else {
        $error = "Please enter a valid email address.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Hospital Management System</title>
    <link rel="stylesheet" href="../assets/css/login_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-wrapper" style="min-height: 500px;">
        <div class="login-left">
            <div class="circle-deco circle-1"></div>
            <div class="circle-deco circle-2"></div>
            <div class="circle-deco circle-3"></div>
            
            
            <img src="../assets/images/doctor_3d.png" alt="Doctor" class="doctor-img">
        </div>
        
        <div class="login-right">
            <div class="login-logo">
                <span>ADMS</span> Hospital
            </div>

            <h3 style="text-align: center; margin-bottom: 20px; color: #333;">Forgot Password</h3>

            <?php if ($error): ?>
                <div class="error-text" style="text-align: center; margin-bottom: 15px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="color: green; text-align: center; margin-bottom: 15px;"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="login-form-group">
                    <label for="email" class="login-label">Email Address</label>
                    <input type="email" id="email" name="email" class="login-input" placeholder="Enter your email" required>
                </div>

                <button type="submit" class="login-btn">Send Reset Link</button>
            </form>

            <div class="login-links">
                <div class="signup-text">
                    Remember your password? <a href="../index.php">Login here</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
