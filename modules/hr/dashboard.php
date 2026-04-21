<?php
// modules/hr/dashboard.php — merged into doctor_availability / Leave Management
require_once '../../includes/db.php';
require_once '../../includes/auth_session.php';
check_auth();
header("Location: /modules/ehr/doctor_availability.php");
exit();
?>
