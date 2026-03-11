<?php
// check_schema.php — Admin-only debug tool
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_role(['admin']);

$table = 'notifications';
$sql = "SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = $1";

$columns = db_select($sql, [$table]);

echo "Columns in '$table':\n";
foreach ($columns as $col) {
    echo "- " . $col['column_name'] . " (" . $col['data_type'] . ")\n";
}
?>
