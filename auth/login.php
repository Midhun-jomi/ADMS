<?php
// auth/login.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $user = db_select_one("SELECT * FROM users WHERE email = $1", [$email]);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Login Success
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = strtolower($user['role']); // Normalize to lowercase
        $_SESSION['email'] = $user['email'];

        // Network Data
        $ip = $_SERVER['REMOTE_ADDR'];
        $ua = $_SERVER['HTTP_USER_AGENT'];

        // Log Success with Device Data
        log_audit($user['id'], 'LOGIN_SUCCESS', json_encode([
            'message' => 'User logged in successfully.',
            'ip' => $ip,
            'browser' => $ua
        ]));

        // Update Staff status to Active
        // Check if user is staff before updating
        $is_staff = db_select_one("SELECT id FROM staff WHERE user_id = $1", [$user['id']]);
        if ($is_staff) {
            db_update('staff', ['status' => 'active'], ['user_id' => $user['id']]);
        }

        // Small delay to ensure DB propagation
        usleep(100000); 

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
        // Log Failure with Device Data
        $target_user_id = $user ? $user['id'] : null;
        log_audit($target_user_id, 'LOGIN_FAILED', json_encode([
            'attempted_email' => $email,
            'reason' => 'Invalid credentials',
            'ip' => $_SERVER['REMOTE_ADDR'],
            'browser' => $_SERVER['HTTP_USER_AGENT']
        ]));
        
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-header h2 {
            margin: 0;
            color: #1a202c;
            font-weight: 800;
        }
        .login-header p {
            color: #718096;
            margin-bottom: 30px;
        }
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.9em;
        }
        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        input:focus {
            border-color: #667eea;
            outline: none;
        }
        button {
            width: 100%;
            background: #667eea;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #5a67d8;
        }
        .alert {
            background: #fed7d7;
            color: #c53030;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
        a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2>HOSPITAL+</h2>
            <p>Secure Staff & Patient Portal</p>
        </div>

        <?php if ($error): ?>
            <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required placeholder="name@example.com">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>

            <button type="submit">Log In</button>
        </form>

        <p style="margin-top: 20px; font-size: 0.9em; color: #718096;">
            New to Hospital+? <a href="signup.php">Create Patient Account</a>
        </p>
    </div>
</body>
</html>
