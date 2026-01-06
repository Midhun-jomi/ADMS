<?php
// auth/logout.php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth_session.php'; // ensure we have session helpers if needed

if (isset($_SESSION['user_id'])) {
    // Update Staff status to Inactive
    db_update('staff', ['status' => 'inactive'], ['user_id' => $_SESSION['user_id']]);
}

session_unset();
session_destroy();
header("Location: /index.php");
exit();
?>
