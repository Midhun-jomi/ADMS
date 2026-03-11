<?php
// auth/reset_password.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_session.php';

$error = '';
$success = '';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    die("Invalid or missing token.");
}

// Verify token is valid and not expired
$user = db_select_one("SELECT * FROM users WHERE reset_token = $1 AND reset_expires > NOW()", [$token]);

if (!$user) {
    die("Invalid or expired token. Please request a new password reset.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF check
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Update password and clear reset token
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET password_hash = $1, reset_token = NULL, reset_expires = NULL WHERE id = $2";
            db_query($sql, [$password_hash, $user['id']]);

            $success = "Your password has been successfully reset. You can now login.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - ADMS Hospital</title>
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

            <h3 style="text-align: center; margin-bottom: 20px; color: #333;">Set New Password</h3>

            <?php if ($error): ?>
                <div class="error-text" style="text-align: center; margin-bottom: 15px;"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success" style="color: green; text-align: center; margin-bottom: 15px;"><?php echo htmlspecialchars($success); ?></div>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="../index.php" class="login-btn" style="text-decoration: none; display: inline-block;">Go to Login</a>
                </div>
            <?php else: ?>

            <form method="POST" action="">
                <?php echo csrf_input(); ?>
                <div class="login-form-group">
                    <label for="password" class="login-label">New Password (min 8 characters)</label>
                    <input type="password" id="password" name="password" class="login-input" placeholder="Enter new password" required minlength="8">
                </div>

                <div class="login-form-group">
                    <label for="confirm_password" class="login-label">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="login-input" placeholder="Confirm new password" required minlength="8">
                </div>

                <button type="submit" class="login-btn">Reset Password</button>
            </form>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>
