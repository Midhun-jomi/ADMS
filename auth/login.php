<?php
// auth/login.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $user = db_select_one("SELECT * FROM users WHERE email = $1", [$email]);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Login Success
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = strtolower($user['role']); // Normalize to lowercase
        $_SESSION['email'] = $user['email'];

        // Update Staff status to Active
        db_update('staff', ['status' => 'active'], ['user_id' => $user['id']]);

        // Small delay to ensure DB propagation (rarely needed but safe)
        usleep(100000); // 100ms

        // Redirect based on role
        // Redirect based on role
        if ($user['role'] === 'nurse') {
            header("Location: ../modules/patient_management/nursing_station.php");
        } elseif ($user['role'] === 'head_nurse') {
            header("Location: ../modules/admin/nurse_allocation.php");
        } else {
            header("Location: ../dashboards/" . $user['role'] . "_dashboard.php");
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
    <title>Login - Hospital MS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2>Hospital Login</h2>
            <p>Please enter your credentials</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login</button>
        </form>

        <p style="text-align: center; margin-top: 15px;">
            <a href="signup.php">Register as New Patient</a>
        </p>
    </div>
</body>
</html>
