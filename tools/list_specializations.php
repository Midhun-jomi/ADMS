<?php
require_once 'includes/db.php';
try {
    $specializations = db_select("SELECT DISTINCT specialization FROM staff WHERE role = 'doctor'");
    echo "--- Specializations ---\n";
    foreach ($specializations as $s) {
        echo $s['specialization'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
