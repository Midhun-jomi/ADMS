<?php
// includes/auth_session.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "/index.php");
        exit();
    }
}

function check_role($allowed_roles) {
    check_auth();
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        // Redirect to unauthorized page or dashboard
        header("Location: " . BASE_URL . "/index.php?error=unauthorized");
        exit();
    }
}

function get_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_user_role() {
    return $_SESSION['role'] ?? null;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}
?>
