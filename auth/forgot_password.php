<?php
// auth/forgot_password.php
session_start();
require_once __DIR__ . '/../includes/db.php';

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Check if user exists
        $user = db_select_one("SELECT * FROM users WHERE email = $1", [$email]);
        
        if ($user) {
            // Generate a secure reset token
            $token = bin2hex(random_bytes(32));
            
            // Set expiration to 1 hour from now
            $expiration = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Update database
            $sql = "UPDATE users SET reset_token = $1, reset_expires = $2 WHERE id = $3";
            db_query($sql, [$token, $expiration, $user['id']]);
            
            // Prepare reset link
            // Determine base URL dynamically (works for localhost and production)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domainName = $_SERVER['HTTP_HOST'];
            // Remove /auth/forgot_password.php to get base path
            $basePath = str_replace("/auth/forgot_password.php", "", $_SERVER['PHP_SELF']); 
            $resetLink = $protocol . $domainName . $basePath . "/auth/reset_password.php?token=" . $token;
            
            // Send email
            $subject = "Password Reset Request - ADMS Hospital";
            $message = "Hello,\n\n";
            $message .= "You have requested to reset your password. Please click the link below to set a new password:\n\n";
            $message .= $resetLink . "\n\n";
            $message .= "This link will expire in 1 hour. If you did not request a password reset, please ignore this email.\n\n";
            $message .= "Regards,\nADMS Hospital Team";
            
            $headers = "From: noreply@" . $domainName . "\r\n";
            $headers .= "Reply-To: support@" . $domainName . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            // Include the mail service
            require_once __DIR__ . '/../includes/mail_service.php';
            
            // In a real production environment with properly configured sendmail, this will work.
            // On localhost without a mail server, mail() often fails or does nothing.
            // @mail($email, $subject, $message, $headers);
            
            // Use PHPMailer SMTP instead
            $emailSent = send_email_smtp($email, $subject, $message);
            
            if ($emailSent) {
                $success = "If an account exists for this email, a reset link will be sent.";
            } else {
                $success = "Failed to send reset email due to server configuration. Check PHP logs.";
            }
        } else {
             // For security, don't reveal if email doesn't exist, show generic success message
             $success = "If an account exists for this email, a reset link will be sent.";
        }
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
