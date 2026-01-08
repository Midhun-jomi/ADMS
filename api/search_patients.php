<?php
// api/search_patients.php
require_once '../includes/db.php';
require_once '../includes/auth_session.php';
check_auth();

$q = $_GET['q'] ?? '';
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Search by name or UHID
$sql = "SELECT id, first_name, last_name, uhid 
        FROM patients 
        WHERE first_name ILIKE $1 
           OR last_name ILIKE $1 
           OR CAST(uhid AS TEXT) ILIKE $1 
        LIMIT 10";

$results = db_select($sql, ["%$q%"]);
header('Content-Type: application/json');
echo json_encode($results ?: []);
?>
