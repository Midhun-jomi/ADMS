<?php
// tools/test_vitals_query.php
require_once __DIR__ . '/../includes/db.php';

echo "Testing Select Query on patient_health_metrics...\n";

try {
    $res = db_select("SELECT * FROM patient_health_metrics LIMIT 1");
    echo "SUCCESS: Query executed. Rows found: " . count($res) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "Testing Table Existence via information_schema...\n";
$check = db_select("SELECT table_name FROM information_schema.tables WHERE table_name = 'patient_health_metrics'");
if ($check) {
    echo "CONFIRMED: Table exists in information_schema.\n";
} else {
    echo "FAILED: Table NOT found in information_schema.\n";
}
?>
