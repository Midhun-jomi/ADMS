<?php
// includes/auth_session.php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    // Harden session cookie flags
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// --- Security Headers ---
header('X-Frame-Options: SAMEORIGIN'); // Changed from DENY to allow clinical notes iframe
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://www.gstatic.com https://meet.jit.si; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://ui-avatars.com; frame-src 'self' https://meet.jit.si; connect-src 'self' http://localhost:5001");

// --- Session Timeout (30 minutes of inactivity) ---
const SESSION_TIMEOUT = 1800;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    // Session expired — destroy and redirect to login
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "/index.php?error=session_expired");
    exit();
}
$_SESSION['last_activity'] = time();

// --- Core Auth Functions ---

function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "/index.php");
        exit();
    }
}

function check_role($allowed_roles) {
    check_auth();
    if (!in_array($_SESSION['role'], $allowed_roles)) {
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

// --- Session Regeneration (call after login) ---
function regenerate_session() {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}

// --- CSRF Protection ---

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token()) . '">';
}
?>
