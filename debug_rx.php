<?php
// debug_rx.php — Admin-only debug tool
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_role(['admin']);

$rx = db_select("SELECT * FROM prescriptions ORDER BY created_at DESC LIMIT 5");
echo "<pre>";
print_r($rx);
echo "</pre>";
?>
