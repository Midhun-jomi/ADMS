<?php
require_once 'includes/db.php';
$rx = db_select("SELECT * FROM prescriptions ORDER BY created_at DESC LIMIT 5");
echo "<pre>";
print_r($rx);
echo "</pre>";
?>
